<?php

namespace App\Livewire\Person;

use App\Models\Person;
use Livewire\Component;

class PersonSearch extends Component
{
    public string $query = '';

    public bool $showCreateForm = false;

    public string $newNama = '';

    public string $newKelas = '';

    public string $newJenisKelamin = '';

    public string $newTanggalLahir = '';

    public string $newDesaId = '';

    public string $newKelompokId = '';

    public ?int $selectedPersonId = null;

    public function selectPerson(int $personId): void
    {
        $person = Person::with(['desa', 'kelompok'])->findOrFail($personId);
        $this->selectedPersonId = $person->id;
        $this->dispatch('personSelected', personId: $person->id);
    }

    public function clearSelection(): void
    {
        $this->selectedPersonId = null;
        $this->query = '';
        $this->dispatch('personSelectionCleared');
    }

    public function toggleCreateForm(): void
    {
        $this->showCreateForm = ! $this->showCreateForm;
        $this->reset(['newNama', 'newKelas', 'newJenisKelamin', 'newTanggalLahir', 'newDesaId', 'newKelompokId']);
        $this->resetErrorBag();
    }

    public function createPerson(): void
    {
        $this->validate([
            'newNama' => 'required|string|max:255',
            'newKelas' => 'nullable|string|max:50',
            'newJenisKelamin' => 'required|in:L,P',
            'newTanggalLahir' => 'nullable|date',
            'newDesaId' => 'required|exists:desas,id',
            'newKelompokId' => 'nullable|exists:kelompoks,id',
        ]);

        $person = Person::create([
            'nama' => $this->newNama,
            'kelas' => $this->newKelas ?: null,
            'jenis_kelamin' => $this->newJenisKelamin,
            'tanggal_lahir' => $this->newTanggalLahir ?: null,
            'desa_id' => (int) $this->newDesaId,
            'kelompok_id' => $this->newKelompokId ? (int) $this->newKelompokId : null,
        ]);

        $this->selectedPersonId = $person->id;
        $this->showCreateForm = false;
        $this->dispatch('personSelected', personId: $person->id);
        $this->dispatch('personCreated');
    }

    public function getResultsProperty()
    {
        if (strlen($this->query) < 2) {
            return collect();
        }

        return Person::with(['desa', 'kelompok'])
            ->where('nama', 'like', '%'.$this->query.'%')
            ->orderBy('nama')
            ->limit(10)
            ->get();
    }

    public function render()
    {
        return view('livewire.person.person-search', [
            'results' => $this->results,
        ]);
    }
}
