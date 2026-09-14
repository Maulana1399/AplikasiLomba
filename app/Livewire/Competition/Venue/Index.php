<?php

namespace App\Livewire\Competition\Venue;

use App\Models\Venue;
use App\Support\ActiveEventContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Index extends Component
{
    public bool $showCreateForm = false;

    public string $newName = '';

    public string $newCode = '';

    public string $newLocationDetail = '';

    public string $newSortOrder = '';

    public ?int $editId = null;

    public string $editName = '';

    public string $editCode = '';

    public string $editLocationDetail = '';

    public string $editSortOrder = '';

    public bool $processing = false;

    public function mount(): void
    {
        app(ActiveEventContext::class)->requireCurrent();
    }

    public function toggleCreateForm(): void
    {
        $this->showCreateForm = ! $this->showCreateForm;
        $this->reset(['newName', 'newCode', 'newLocationDetail', 'newSortOrder']);
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
                    Rule::unique('venues', 'name')->where('event_id', $event->id),
                ],
                'newCode' => [
                    'nullable', 'string', 'max:50',
                    Rule::unique('venues', 'code')->where('event_id', $event->id),
                ],
                'newLocationDetail' => 'nullable|string|max:500',
                'newSortOrder' => 'nullable|integer|min:0',
            ]);

            Venue::create([
                'event_id' => $event->id,
                'name' => $this->newName,
                'code' => $this->newCode ?: null,
                'location_detail' => $this->newLocationDetail ?: null,
                'sort_order' => $this->newSortOrder !== '' ? (int) $this->newSortOrder : null,
                'is_active' => true,
            ]);

            $this->showCreateForm = false;
            $this->reset(['newName', 'newCode', 'newLocationDetail', 'newSortOrder']);
            session()->flash('success', 'Venue berhasil dibuat.');
        } finally {
            $this->processing = false;
        }
    }

    public function edit(int $id): void
    {
        $event = app(ActiveEventContext::class)->requireCurrent();
        $venue = Venue::where('event_id', $event->id)->findOrFail($id);
        $this->editId = $venue->id;
        $this->editName = $venue->name;
        $this->editCode = $venue->code ?? '';
        $this->editLocationDetail = $venue->location_detail ?? '';
        $this->editSortOrder = (string) ($venue->sort_order ?? '');
    }

    public function update(): void
    {
        Gate::authorize('manage-events');

        $event = app(ActiveEventContext::class)->requireCurrent();
        $venue = Venue::where('event_id', $event->id)->findOrFail($this->editId);

        $this->validate([
            'editName' => [
                'required', 'string', 'max:255',
                Rule::unique('venues', 'name')
                    ->where('event_id', $event->id)
                    ->ignore($venue->id),
            ],
            'editCode' => [
                'nullable', 'string', 'max:50',
                Rule::unique('venues', 'code')
                    ->where('event_id', $event->id)
                    ->ignore($venue->id),
            ],
            'editLocationDetail' => 'nullable|string|max:500',
            'editSortOrder' => 'nullable|integer|min:0',
        ]);

        $venue->update([
            'name' => $this->editName,
            'code' => $this->editCode ?: null,
            'location_detail' => $this->editLocationDetail ?: null,
            'sort_order' => $this->editSortOrder !== '' ? (int) $this->editSortOrder : null,
        ]);

        $this->reset(['editId', 'editName', 'editCode', 'editLocationDetail', 'editSortOrder']);
        session()->flash('success', 'Venue berhasil diperbarui.');
    }

    public function cancelEdit(): void
    {
        $this->reset(['editId', 'editName', 'editCode', 'editLocationDetail', 'editSortOrder']);
    }

    public function toggleActive(int $id): void
    {
        Gate::authorize('manage-events');

        $event = app(ActiveEventContext::class)->requireCurrent();
        $venue = Venue::where('event_id', $event->id)->findOrFail($id);
        $venue->update(['is_active' => ! $venue->is_active]);
    }

    public function delete(int $id): void
    {
        Gate::authorize('manage-events');

        $event = app(ActiveEventContext::class)->requireCurrent();
        $venue = Venue::where('event_id', $event->id)->findOrFail($id);

        if ($venue->competitionSchedules()->exists()) {
            session()->flash('error', 'Venue tidak dapat dihapus karena masih digunakan oleh jadwal perlombaan.');

            return;
        }

        $venue->delete();
        session()->flash('success', 'Venue berhasil dihapus.');
    }

    public function render()
    {
        $event = app(ActiveEventContext::class)->current();

        return view('livewire.competition.venue.index', [
            'venues' => Venue::where('event_id', $event?->id)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ]);
    }
}
