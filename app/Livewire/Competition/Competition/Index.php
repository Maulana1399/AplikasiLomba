<?php

namespace App\Livewire\Competition\Competition;

use App\Models\Event;
use App\Support\ActiveEventContext;
use App\Support\CompetitionBootstrap;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Index extends Component
{
    public bool $showCreateForm = false;

    public string $newName = '';

    public string $newCode = '';

    public string $newSortOrder = '';

    public array $newCategoryIds = [];

    public ?int $editId = null;

    public string $editName = '';

    public string $editCode = '';

    public string $editSortOrder = '';

    public array $editCategoryIds = [];

    public bool $processing = false;

    public function mount(): void
    {
        app(ActiveEventContext::class)->requireCurrent();
    }

    public function toggleCreateForm(): void
    {
        $this->showCreateForm = ! $this->showCreateForm;
        $this->reset(['newName', 'newCode', 'newSortOrder', 'newCategoryIds']);
        $this->resetErrorBag();
    }

    public function create(): void
    {
        Gate::authorize('manage-events');

        if ($this->processing) {
            return;
        }
        $this->processing = true;

        try {
            $this->validate([
                'newName' => [
                    'required', 'string', 'max:255',
                    Rule::unique('events', 'name')->where('event_type', 'competition'),
                ],
                'newCode' => 'nullable|string|max:50|unique:events,code',
                'newSortOrder' => 'nullable|integer|min:0',
                'newCategoryIds' => 'nullable|array',
                'newCategoryIds.*' => 'exists:competition_categories,id',
            ]);

            $event = Event::create([
                'name' => $this->newName,
                'slug' => \Illuminate\Support\Str::slug($this->newName).'-'.uniqid(),
                'code' => $this->newCode !== '' ? $this->newCode : null,
                'event_type' => 'competition',
                'status' => 'active',
                'sort_order' => $this->newSortOrder !== '' ? (int) $this->newSortOrder : null,
                'start_date' => now()->toDateString(),
                'end_date' => now()->toDateString(),
            ]);

            $event->competitionCategories()->sync($this->newCategoryIds);

            $this->showCreateForm = false;
            $this->reset(['newName', 'newCode', 'newSortOrder', 'newCategoryIds']);
            session()->flash('success', 'Lomba berhasil dibuat.');
        } finally {
            $this->processing = false;
        }
    }

    public function edit(int $id): void
    {
        $event = Event::where('event_type', 'competition')->findOrFail($id);
        $this->editId = $event->id;
        $this->editName = $event->name;
        $this->editCode = $event->code ?? '';
        $this->editSortOrder = (string) ($event->sort_order ?? '');
        $this->editCategoryIds = Event::where('id', $event->id)->first()->competitionCategories()->pluck('competition_categories.id')->map(fn ($v) => (int) $v)->all();
    }

    public function update(): void
    {
        Gate::authorize('manage-events');

        $event = Event::where('event_type', 'competition')->findOrFail($this->editId);

        $this->validate([
            'editName' => [
                'required', 'string', 'max:255',
                Rule::unique('events', 'name')
                    ->where('event_type', 'competition')
                    ->ignore($event->id),
            ],
            'editCode' => [
                'nullable', 'string', 'max:50',
                Rule::unique('events', 'code')->ignore($event->id),
            ],
            'editSortOrder' => 'nullable|integer|min:0',
            'editCategoryIds' => 'nullable|array',
            'editCategoryIds.*' => 'exists:competition_categories,id',
        ]);

        $desiredIds = collect($this->editCategoryIds)->map(fn ($v) => (int) $v)->unique()->values()->all();
        $currentIds = $event->competitionCategories()->pluck('competition_categories.id')->map(fn ($v) => (int) $v)->all();
        $toDetach = collect($currentIds)->diff($desiredIds)->values()->all();

        foreach ($toDetach as $catId) {
            if (\App\Models\CompetitionClass::where('event_id', $event->id)
                ->where('competition_category_id', $catId)->exists()) {
                $cat = \App\Models\CompetitionCategory::find($catId);
                $this->addError('editCategoryIds', "Kategori {$cat?->name} masih digunakan kelas lomba di event ini, tidak dapat dilepas.");

                return;
            }
        }

        $event->update([
            'name' => $this->editName,
            'code' => $this->editCode !== '' ? $this->editCode : null,
            'sort_order' => $this->editSortOrder !== '' ? (int) $this->editSortOrder : null,
        ]);

        $event->competitionCategories()->sync($desiredIds);

        $this->reset(['editId', 'editName', 'editCode', 'editSortOrder', 'editCategoryIds']);
        session()->flash('success', 'Lomba berhasil diperbarui.');
    }

    public function cancelEdit(): void
    {
        $this->reset(['editId', 'editName', 'editCode', 'editSortOrder', 'editCategoryIds']);
    }

    public function updatedNewCategoryIds(): void
    {
        $this->resetValidation('newCategoryIds');
    }

    public function updatedEditCategoryIds(): void
    {
        $this->resetValidation('editCategoryIds');
    }

    public function toggleActive(int $id): void
    {
        Gate::authorize('manage-events');

        $event = Event::where('event_type', 'competition')->findOrFail($id);

        $activeCtx = app(ActiveEventContext::class);

        if ($event->isActive() && $activeCtx->current()?->id === $event->id) {
            session()->flash('error', 'Tidak dapat menonaktifkan lomba yang sedang aktif sebagai konteks utama.');

            return;
        }

        $newStatus = $event->isActive() ? 'archived' : 'active';
        $event->update(['status' => $newStatus]);
    }

    public function delete(int $id): void
    {
        Gate::authorize('manage-events');

        $event = Event::where('event_type', 'competition')->findOrFail($id);

        $activeCtx = app(ActiveEventContext::class);

        if ($activeCtx->current()?->id === $event->id) {
            session()->flash('error', 'Tidak dapat menghapus lomba yang sedang aktif.');

            return;
        }

        $dependencies = [];

        if ($event->competitionCategories()->exists()) {
            $dependencies[] = 'kategori';
        }
        if ($event->competitionClasses()->exists()) {
            $dependencies[] = 'kelas lomba';
        }
        if ($event->participations()->exists()) {
            $dependencies[] = 'peserta';
        }
        if ($event->venues()->exists()) {
            $dependencies[] = 'venue';
        }

        if (! empty($dependencies)) {
            $list = implode(', ', $dependencies);
            session()->flash('error', "Tidak dapat menghapus lomba yang masih memiliki data {$list}. Gunakan Nonaktifkan.");

            return;
        }

        $event->delete();
        session()->flash('success', 'Lomba berhasil dihapus.');
    }

    public function render()
    {
        return view('livewire.competition.competition.index', [
            'competitions' => Event::where('event_type', 'competition')
                ->withCount('competitionCategories')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'allCategories' => \App\Models\CompetitionCategory::where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ]);
    }
}
