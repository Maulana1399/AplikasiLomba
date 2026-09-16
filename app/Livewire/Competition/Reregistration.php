<?php

namespace App\Livewire\Competition;

use App\Models\desa;
use App\Models\Event;
use App\Models\kelompok;
use App\Models\MasterParticipantClass;
use App\Models\Participation;
use App\Models\Person;
use App\Support\ActiveEventContext;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class Reregistration extends Component
{
    private const RESULT_LIMIT = 25;

    public array $eventIds = [];

    public string $search = '';

    public ?int $selectedPersonId = null;

    public bool $editing = false;

    public string $editNama = '';

    public string $editJenisKelamin = '';

    public string $editDesaId = '';

    public string $editKelompokId = '';

    public string $editKelas = '';

    public bool $processing = false;

    public function mount(): void
    {
        Gate::authorize('view-dashboard');

        app(ActiveEventContext::class)->requireCurrent();

        $this->eventIds = $this->competitionEventIds();
    }

    /**
     * Lingkup operasional registrasi ulang: seluruh event lomba (competition)
     * yang aktif. Satu peserta dapat mengikuti banyak Lomba, dan setiap Lomba
     * adalah Event tersendiri, sehingga pencarian tidak boleh terkunci pada
     * satu event saja.
     */
    private function competitionEventIds(): array
    {
        return Event::query()
            ->where('event_type', 'competition')
            ->where('status', 'active')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function select(int $personId): void
    {
        if (! $this->personQuery()->find($personId)) {
            return;
        }

        $this->selectedPersonId = $personId;
        $this->editing = false;
        $this->resetErrorBag();
    }

    public function closeDetail(): void
    {
        $this->selectedPersonId = null;
        $this->editing = false;
        $this->resetErrorBag();
    }

    public function startEdit(): void
    {
        $person = $this->selectedPerson();

        if (! $person) {
            return;
        }

        $this->editNama = (string) ($person->nama ?? '');
        $this->editJenisKelamin = (string) ($person->jenis_kelamin ?? '');
        $this->editDesaId = (string) ($person->desa_id ?? '');
        $this->editKelompokId = (string) ($person->kelompok_id ?? '');
        $this->editKelas = (string) ($person->kelas ?? '');
        $this->editing = true;
        $this->resetErrorBag();
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
        $this->resetErrorBag();
    }

    public function updatedEditDesaId(): void
    {
        $this->editKelompokId = '';
    }

    public function saveEdit(): void
    {
        Gate::authorize('manage-registration');

        $person = $this->selectedPerson();

        if (! $person) {
            $this->addError('editNama', 'Peserta tidak ditemukan.');

            return;
        }

        $this->validate([
            'editNama' => 'required|string|max:255',
            'editJenisKelamin' => 'required|in:L,P',
            'editDesaId' => 'nullable|exists:desas,id',
            'editKelompokId' => 'nullable|exists:kelompoks,id',
            'editKelas' => 'nullable|string|max:255',
        ]);

        if ($this->editKelompokId !== '' && $this->editDesaId !== '') {
            $valid = kelompok::where('id', (int) $this->editKelompokId)
                ->where('desa_id', (int) $this->editDesaId)
                ->exists();

            if (! $valid) {
                $this->addError('editKelompokId', 'Kelompok tidak sesuai dengan desa yang dipilih.');

                return;
            }
        }

        $person->update([
            'nama' => trim($this->editNama),
            'jenis_kelamin' => $this->editJenisKelamin,
            'desa_id' => $this->editDesaId !== '' ? (int) $this->editDesaId : null,
            'kelompok_id' => $this->editKelompokId !== '' ? (int) $this->editKelompokId : null,
            'kelas' => $this->editKelas !== '' ? $this->editKelas : null,
        ]);

        $this->editing = false;
        $this->resetErrorBag();
        session()->flash('success', 'Data peserta berhasil diperbarui.');
    }

    /**
     * Tandai peserta hadir/daftar ulang pada seluruh Participation miliknya di
     * lingkup lomba yang aktif. Status tetap tersimpan per Participation.
     */
    public function markPresent(): void
    {
        Gate::authorize('manage-registration');

        $person = $this->selectedPerson();

        if (! $person) {
            return;
        }

        $pending = $person->participations
            ->reject(fn (Participation $participation) => $participation->isReregistered());

        if ($pending->isEmpty()) {
            return;
        }

        Participation::whereIn('id', $pending->pluck('id'))->update([
            'status_registrasi' => Participation::STATUS_SUDAH_DAFTAR_ULANG,
        ]);

        session()->flash('success', 'Peserta ditandai Sudah Daftar Ulang.');
    }

    public function isPersonReregistered(Person $person): bool
    {
        return $person->participations->isNotEmpty()
            && $person->participations->every(fn (Participation $participation) => $participation->isReregistered());
    }

    private function personQuery()
    {
        return Person::query()
            ->whereHas('participations', fn ($query) => $query->whereIn('event_id', $this->eventIds));
    }

    private function selectedPerson(): ?Person
    {
        if ($this->selectedPersonId === null) {
            return null;
        }

        return Person::with($this->relations())->find($this->selectedPersonId);
    }

    private function relations(): array
    {
        return [
            'desa',
            'kelompok',
            'participations' => fn ($query) => $query
                ->whereIn('event_id', $this->eventIds)
                ->orderBy('id')
                ->with([
                    'event',
                    'competitionRegistrations.competitionCategory',
                    'competitionRegistrations.competitionClass',
                ]),
        ];
    }

    public function getResultsProperty()
    {
        $term = trim($this->search);

        if (mb_strlen($term) < 2 || $this->eventIds === []) {
            return collect();
        }

        $needle = '%'.mb_strtolower($term).'%';

        return Person::query()
            ->whereRaw('LOWER(nama) LIKE ?', [$needle])
            ->whereHas('participations', fn ($query) => $query->whereIn('event_id', $this->eventIds))
            ->with($this->relations())
            ->orderBy('id')
            ->limit(self::RESULT_LIMIT)
            ->get();
    }

    public function getDesasProperty()
    {
        return desa::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('desa_asal')
            ->get();
    }

    public function getKelompoksProperty()
    {
        $query = kelompok::query()->where('is_active', true);

        if ($this->editDesaId !== '') {
            $query->where('desa_id', (int) $this->editDesaId);
        }

        return $query->orderBy('sort_order')->orderBy('kelompok_asal')->get();
    }

    public function getParticipantClassesProperty()
    {
        return MasterParticipantClass::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function render()
    {
        return view('livewire.competition.reregistration', [
            'results' => $this->results,
            'selected' => $this->selectedPerson(),
            'desas' => $this->desas,
            'kelompoks' => $this->kelompoks,
            'participantClasses' => $this->participantClasses,
        ]);
    }
}
