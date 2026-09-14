<?php

namespace App\Livewire\Competition\Kelompok;

use App\Models\desa;
use App\Models\kelompok;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Index extends Component
{
    public bool $showCreateForm = false;

    public string $newName = '';

    public string $newDesaId = '';

    public string $newSortOrder = '';

    public ?int $editId = null;

    public string $editName = '';

    public string $editDesaId = '';

    public string $editSortOrder = '';

    public bool $processing = false;

    public function toggleCreateForm(): void
    {
        $this->showCreateForm = ! $this->showCreateForm;
        $this->reset(['newName', 'newDesaId', 'newSortOrder']);
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
                    Rule::unique('kelompoks', 'kelompok_asal')->where('desa_id', $this->newDesaId),
                ],
                'newDesaId' => 'required|exists:desas,id',
                'newSortOrder' => 'nullable|integer|min:0',
            ]);

            kelompok::create([
                'kelompok_asal' => $this->newName,
                'desa_id' => $this->newDesaId,
                'sort_order' => $this->newSortOrder !== '' ? (int) $this->newSortOrder : 0,
            ]);

            $this->showCreateForm = false;
            $this->reset(['newName', 'newDesaId', 'newSortOrder']);
            session()->flash('success', 'Kelompok berhasil dibuat.');
        } finally {
            $this->processing = false;
        }
    }

    public function edit(int $id): void
    {
        $kelompok = kelompok::findOrFail($id);
        $this->editId = $kelompok->id;
        $this->editName = $kelompok->kelompok_asal;
        $this->editDesaId = (string) $kelompok->desa_id;
        $this->editSortOrder = (string) $kelompok->sort_order;
    }

    public function update(): void
    {
        Gate::authorize('manage-events');

        $this->validate([
            'editName' => [
                'required', 'string', 'max:255',
                Rule::unique('kelompoks', 'kelompok_asal')->where('desa_id', $this->editDesaId)->ignore($this->editId),
            ],
            'editDesaId' => 'required|exists:desas,id',
            'editSortOrder' => 'nullable|integer|min:0',
        ]);

        $kelompok = kelompok::findOrFail($this->editId);
        $kelompok->update([
            'kelompok_asal' => $this->editName,
            'desa_id' => $this->editDesaId,
            'sort_order' => $this->editSortOrder !== '' ? (int) $this->editSortOrder : 0,
        ]);

        $this->cancelEdit();
        session()->flash('success', 'Kelompok berhasil diperbarui.');
    }

    public function cancelEdit(): void
    {
        $this->reset(['editId', 'editName', 'editDesaId', 'editSortOrder']);
    }

    public function toggleActive(int $id): void
    {
        Gate::authorize('manage-events');

        $kelompok = kelompok::findOrFail($id);
        $kelompok->update(['is_active' => ! $kelompok->is_active]);
    }

    public function delete(int $id): void
    {
        Gate::authorize('manage-events');

        $kelompok = kelompok::findOrFail($id);

        if (\App\Models\Person::where('kelompok_id', $kelompok->id)->exists()) {
            session()->flash('error', 'Kelompok tidak dapat dihapus karena masih digunakan oleh data peserta.');

            return;
        }

        if (\App\Models\CompetitionTeam::where('kelompok_id', $kelompok->id)->exists()) {
            session()->flash('error', 'Kelompok tidak dapat dihapus karena masih digunakan oleh tim lomba.');

            return;
        }

        $kelompok->delete();
        session()->flash('success', 'Kelompok berhasil dihapus.');
    }

    public function render()
    {
        return view('livewire.competition.kelompok.index', [
            'kelompoks' => kelompok::with('desa')
                ->orderBy('sort_order')
                ->orderBy('kelompok_asal')
                ->get(),
            'desas' => desa::orderBy('sort_order')
                ->orderBy('desa_asal')
                ->get(),
        ]);
    }
}