<?php

namespace App\Livewire\Competition\Desa;

use App\Models\desa;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Index extends Component
{
    public bool $showCreateForm = false;

    public string $newName = '';

    public string $newSortOrder = '';

    public ?int $editId = null;

    public string $editName = '';

    public string $editSortOrder = '';

    public bool $processing = false;

    public function toggleCreateForm(): void
    {
        $this->showCreateForm = ! $this->showCreateForm;
        $this->reset(['newName', 'newSortOrder']);
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
                'newName' => ['required', 'string', 'max:255', Rule::unique('desas', 'desa_asal')],
                'newSortOrder' => 'nullable|integer|min:0',
            ]);

            desa::create([
                'desa_asal' => $this->newName,
                'sort_order' => $this->newSortOrder !== '' ? (int) $this->newSortOrder : 0,
            ]);

            $this->showCreateForm = false;
            $this->reset(['newName', 'newSortOrder']);
            session()->flash('success', 'Desa berhasil dibuat.');
        } finally {
            $this->processing = false;
        }
    }

    public function edit(int $id): void
    {
        $desa = desa::findOrFail($id);
        $this->editId = $desa->id;
        $this->editName = $desa->desa_asal;
        $this->editSortOrder = (string) $desa->sort_order;
    }

    public function update(): void
    {
        Gate::authorize('manage-events');

        $this->validate([
            'editName' => ['required', 'string', 'max:255', Rule::unique('desas', 'desa_asal')->ignore($this->editId)],
            'editSortOrder' => 'nullable|integer|min:0',
        ]);

        $desa = desa::findOrFail($this->editId);
        $desa->update([
            'desa_asal' => $this->editName,
            'sort_order' => $this->editSortOrder !== '' ? (int) $this->editSortOrder : 0,
        ]);

        $this->cancelEdit();
        session()->flash('success', 'Desa berhasil diperbarui.');
    }

    public function cancelEdit(): void
    {
        $this->reset(['editId', 'editName', 'editSortOrder']);
    }

    public function toggleActive(int $id): void
    {
        Gate::authorize('manage-events');

        $desa = desa::findOrFail($id);
        $desa->update(['is_active' => ! $desa->is_active]);
    }

    public function render()
    {
        return view('livewire.competition.desa.index', [
            'desas' => desa::orderBy('sort_order')
                ->orderBy('desa_asal')
                ->get(),
        ]);
    }
}