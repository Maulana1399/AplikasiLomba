<?php

namespace App\Livewire\Competition\ParticipantClass;

use App\Models\MasterParticipantClass;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Index extends Component
{
    public bool $showCreateForm = false;

    public string $newName = '';

    public string $newCode = '';

    public string $newSortOrder = '';

    public ?int $editId = null;

    public string $editName = '';

    public string $editCode = '';

    public string $editSortOrder = '';

    public bool $processing = false;

    public function toggleCreateForm(): void
    {
        $this->showCreateForm = ! $this->showCreateForm;
        $this->reset(['newName', 'newCode', 'newSortOrder']);
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
                'newName' => ['required', 'string', 'max:255', Rule::unique('master_participant_classes', 'name')],
                'newCode' => 'nullable|string|max:50',
                'newSortOrder' => 'nullable|integer|min:0',
            ]);

            MasterParticipantClass::create([
                'name' => $this->newName,
                'code' => $this->newCode ?: null,
                'sort_order' => $this->newSortOrder !== '' ? (int) $this->newSortOrder : 0,
            ]);

            $this->showCreateForm = false;
            $this->reset(['newName', 'newCode', 'newSortOrder']);
            session()->flash('success', 'Kelas peserta berhasil dibuat.');
        } finally {
            $this->processing = false;
        }
    }

    public function edit(int $id): void
    {
        $class = MasterParticipantClass::findOrFail($id);
        $this->editId = $class->id;
        $this->editName = $class->name;
        $this->editCode = $class->code ?? '';
        $this->editSortOrder = (string) $class->sort_order;
    }

    public function update(): void
    {
        Gate::authorize('manage-events');

        $this->validate([
            'editName' => ['required', 'string', 'max:255', Rule::unique('master_participant_classes', 'name')->ignore($this->editId)],
            'editCode' => 'nullable|string|max:50',
            'editSortOrder' => 'nullable|integer|min:0',
        ]);

        $class = MasterParticipantClass::findOrFail($this->editId);
        $class->update([
            'name' => $this->editName,
            'code' => $this->editCode ?: null,
            'sort_order' => $this->editSortOrder !== '' ? (int) $this->editSortOrder : 0,
        ]);

        $this->cancelEdit();
        session()->flash('success', 'Kelas peserta berhasil diperbarui.');
    }

    public function cancelEdit(): void
    {
        $this->reset(['editId', 'editName', 'editCode', 'editSortOrder']);
    }

    public function toggleActive(int $id): void
    {
        Gate::authorize('manage-events');

        $class = MasterParticipantClass::findOrFail($id);
        $class->update(['is_active' => ! $class->is_active]);
    }

    public function render()
    {
        return view('livewire.competition.participant-class.index', [
            'classes' => MasterParticipantClass::orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ]);
    }
}