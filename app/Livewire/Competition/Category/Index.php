<?php

namespace App\Livewire\Competition\Category;

use App\Models\CompetitionCategory;
use App\Models\CompetitionCategoryExclusive;
use App\Support\ActiveEventContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public bool $showCreateForm = false;

    public string $newName = '';

    public string $newCode = '';

    public string $newSortOrder = '';

    public array $newMasterParticipantClassIds = [];

    public ?int $editId = null;

    public string $editName = '';

    public string $editCode = '';

    public string $editSortOrder = '';

    public array $editMasterParticipantClassIds = [];

    public array $editExclusiveIds = [];

    public bool $processing = false;

    public function mount(): void
    {
        // Kategori bersifat event-scoped: wajib ada konteks lomba aktif.
        app(ActiveEventContext::class)->requireCurrent();
    }

    public function toggleCreateForm(): void
    {
        $this->showCreateForm = ! $this->showCreateForm;
        $this->reset(['newName', 'newCode', 'newSortOrder', 'newMasterParticipantClassIds']);
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
            $event = app(ActiveEventContext::class)->requireCurrent();

            $this->validate([
                'newName' => [
                    'required', 'string', 'max:255',
                    Rule::unique('competition_categories', 'name')->where('event_id', $event->id),
                ],
                'newCode' => 'nullable|string|max:50',
                'newSortOrder' => 'nullable|integer|min:0',
                'newMasterParticipantClassIds' => 'nullable|array',
                'newMasterParticipantClassIds.*' => 'exists:master_participant_classes,id',
            ]);

            $category = CompetitionCategory::create([
                'event_id' => $event->id,
                'name' => $this->newName,
                'code' => $this->newCode ?: null,
                'sort_order' => $this->newSortOrder !== '' ? (int) $this->newSortOrder : null,
            ]);

            $validIds = \App\Models\MasterParticipantClass::whereIn('id', $this->newMasterParticipantClassIds ?? [])->pluck('id');
            $category->masterParticipantClasses()->sync($validIds);

            $this->showCreateForm = false;
            $this->reset(['newName', 'newCode', 'newSortOrder', 'newMasterParticipantClassIds']);
            session()->flash('success', 'Kategori berhasil dibuat.');
        } finally {
            $this->processing = false;
        }
    }

    public function edit(int $id): void
    {
        $category = CompetitionCategory::with('masterParticipantClasses')->findOrFail($id);
        $this->editId = $category->id;
        $this->editName = $category->name;
        $this->editCode = $category->code ?? '';
        $this->editSortOrder = $category->sort_order ?? '';
        $this->editMasterParticipantClassIds = $category->masterParticipantClasses->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->editExclusiveIds = array_map('intval', $category->allExclusiveCategoryIds());
    }

    public function update(): void
    {
        Gate::authorize('manage-events');

        $category = CompetitionCategory::findOrFail($this->editId);

        $event = app(ActiveEventContext::class)->requireCurrent();

            $this->validate([
                'editName' => [
                    'required', 'string', 'max:255',
                    Rule::unique('competition_categories', 'name')->where('event_id', $event->id)->ignore($category->id),
                ],
                'editCode' => 'nullable|string|max:50',
                'editSortOrder' => 'nullable|integer|min:0',
                'editMasterParticipantClassIds' => 'nullable|array',
                'editMasterParticipantClassIds.*' => 'exists:master_participant_classes,id',
                'editExclusiveIds' => 'nullable|array',
            ]);

        $category->update([
            'name' => $this->editName,
            'code' => $this->editCode ?: null,
            'sort_order' => $this->editSortOrder !== '' ? (int) $this->editSortOrder : null,
        ]);

        $mpcIds = \App\Models\MasterParticipantClass::whereIn('id', $this->editMasterParticipantClassIds ?? [])->pluck('id');
        $category->masterParticipantClasses()->sync($mpcIds);

        $selectedIds = collect($this->editExclusiveIds)
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($selectedIds->contains($category->id)) {
            $this->addError('editExclusiveIds', 'Kategori tidak boleh konflik dengan dirinya sendiri.');

            return;
        }

        $selectedIds = $selectedIds->filter(fn ($id) => $id !== $category->id)
            ->unique()
            ->values();

        $validIds = CompetitionCategory::whereIn('id', $selectedIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();

        if (! $selectedIds->isEmpty() && $validIds->count() !== $selectedIds->count()) {
            $this->addError('editExclusiveIds', 'Kategori tidak ditemukan.');

            return;
        }

        DB::transaction(function () use ($category, $validIds) {
            CompetitionCategoryExclusive::where('competition_category_id', $category->id)->delete();
            CompetitionCategoryExclusive::where('exclusive_with_category_id', $category->id)->delete();

            foreach ($validIds as $otherId) {
                CompetitionCategoryExclusive::firstOrCreate([
                    'competition_category_id' => $category->id,
                    'exclusive_with_category_id' => $otherId,
                ]);
                CompetitionCategoryExclusive::firstOrCreate([
                    'competition_category_id' => $otherId,
                    'exclusive_with_category_id' => $category->id,
                ]);
            }
        });

        $this->reset(['editId', 'editName', 'editCode', 'editSortOrder', 'editMasterParticipantClassIds', 'editExclusiveIds']);
        session()->flash('success', 'Kategori berhasil diperbarui.');
    }

    public function cancelEdit(): void
    {
        $this->reset(['editId', 'editName', 'editCode', 'editSortOrder', 'editMasterParticipantClassIds', 'editExclusiveIds']);
    }

    public function toggleActive(int $id): void
    {
        Gate::authorize('manage-events');

        $category = CompetitionCategory::findOrFail($id);
        $category->update(['is_active' => ! $category->is_active]);
    }

    public function delete(int $id): void
    {
        Gate::authorize('manage-events');

        $category = CompetitionCategory::findOrFail($id);

        if ($category->competitionClasses()->exists()) {
            session()->flash('error', 'Kategori tidak dapat dihapus karena masih digunakan oleh kelas lomba. Gunakan Nonaktifkan.');

            return;
        }

        if ($category->competitionRegistrations()->exists()) {
            session()->flash('error', 'Kategori tidak dapat dihapus karena masih memiliki pendaftaran peserta. Gunakan Nonaktifkan.');

            return;
        }

        $category->delete();
        session()->flash('success', 'Kategori berhasil dihapus.');
    }

    public function render()
    {
        $event = app(ActiveEventContext::class)->current();

        return view('livewire.competition.category.index', [
            'categories' => CompetitionCategory::with('masterParticipantClasses')
                ->where('event_id', $event?->id)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'candidateCategories' => CompetitionCategory::where('event_id', $event?->id)
                ->when($this->editId, fn ($query) => $query->where('id', '!=', $this->editId))
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'masterParticipantClasses' => \App\Models\MasterParticipantClass::where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ]);
    }
}
