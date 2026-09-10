<?php

namespace App\Livewire\Competition;

use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\desa;
use App\Models\kelompok;
use App\Models\MasterParticipantClass;
use App\Models\Participation;
use App\Models\Person;
use App\Services\Competition\CompetitionRegistrationService;
use App\Support\ActiveEventContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Registration extends Component
{
    public string $nama = '';

    public string $participantClassId = '';

    public string $jenisKelamin = '';

    public string $tanggalLahir = '';

    public string $desaId = '';

    public string $kelompokId = '';

    public string $competitionCategoryId = '';

    public string $competitionClassId = '';

    public bool $processing = false;

    public bool $alreadyRegistered = false;

    public string $conflictMessage = '';

    public ?array $successData = null;

    public ?array $personParticipations = null;

    public function mount(): void
    {
        app(ActiveEventContext::class)->requireCurrent();
    }

    public function findPerson(): ?Person
    {
        if (blank($this->nama) || blank($this->desaId)) {
            return null;
        }

        return Person::where('nama', trim($this->nama))
            ->where('desa_id', $this->desaId)
            ->first();
    }

    public function loadParticipations(?Person $person): void
    {
        if (! $person) {
            $this->personParticipations = null;

            return;
        }

        $event = app(ActiveEventContext::class)->current();

        $participations = Participation::where('person_id', $person->id)
            ->where('event_id', $event?->id)
            ->pluck('id');

        $regs = \App\Models\CompetitionRegistration::with([
            'competitionCategory', 'competitionClass',
        ])->whereIn('participation_id', $participations)->get();

        $this->personParticipations = $regs->map(fn ($r) => [
            'event_name' => $event?->name ?? '-',
            'category_name' => $r->competitionCategory?->name ?? '-',
            'class_name' => $r->competitionClass?->name ?? '-',
            'category_id' => $r->competition_category_id,
        ])->toArray();
    }

    public function checkDuplicate(?Person $person): void
    {
        $this->alreadyRegistered = false;

        if (! $person || blank($this->competitionClassId)) {
            return;
        }

        $event = app(ActiveEventContext::class)->current();
        $participation = Participation::where('person_id', $person->id)
            ->where('event_id', $event?->id)
            ->first();

        if ($participation) {
            $existing = \App\Models\CompetitionRegistration::where('participation_id', $participation->id)
                ->where('competition_class_id', $this->competitionClassId)
                ->exists();
            $this->alreadyRegistered = $existing;
        }
    }

    public function checkConflict(?Person $person): void
    {
        $this->conflictMessage = '';

        if (! $person || blank($this->competitionCategoryId)) {
            return;
        }

        $category = CompetitionCategory::find($this->competitionCategoryId);
        if (! $category) {
            return;
        }

        $conflictCategoryIds = $category->allExclusiveCategoryIds();
        if (empty($conflictCategoryIds)) {
            return;
        }

        $event = app(ActiveEventContext::class)->current();
        $participation = Participation::where('person_id', $person->id)
            ->where('event_id', $event?->id)
            ->first();

        if (! $participation) {
            return;
        }

        $conflictNames = \App\Models\CompetitionRegistration::where('participation_id', $participation->id)
            ->whereIn('competition_category_id', $conflictCategoryIds)
            ->with('competitionCategory')
            ->get()
            ->pluck('competitionCategory.name')
            ->implode(', ');

        if ($conflictNames) {
            $this->conflictMessage = "Konflik dengan kategori yang sudah diikuti: {$conflictNames}";
        }
    }

    public function refreshPersonState(): void
    {
        $person = $this->findPerson();
        $this->loadParticipations($person);
        $this->checkDuplicate($person);
        $this->checkConflict($person);
    }

    public function updatedNama(): void
    {
        $this->refreshPersonState();
    }

    public function updatedDesaId(): void
    {
        $this->kelompokId = '';
        $this->refreshPersonState();
    }

    public function updatedCompetitionClassId(): void
    {
        $this->checkDuplicate($this->findPerson());
    }

    public function updatedCompetitionCategoryId(): void
    {
        $this->competitionClassId = '';
        $this->checkConflict($this->findPerson());
    }

    public function submit(): void
    {
        Gate::authorize('manage-registration');

        if ($this->processing) {
            return;
        }
        $this->processing = true;

        try {
            $this->validate([
                'nama' => 'required|string|max:255',
                'participantClassId' => 'required|exists:master_participant_classes,id',
                'jenisKelamin' => 'required|in:L,P',
                'tanggalLahir' => 'nullable|date',
                'desaId' => 'required|exists:desas,id',
                'kelompokId' => 'nullable|exists:kelompoks,id',
                'competitionCategoryId' => 'required|exists:competition_categories,id',
                'competitionClassId' => 'required|exists:competition_classes,id',
            ]);

            $event = app(ActiveEventContext::class)->requireCurrent();

            $category = CompetitionCategory::where('id', $this->competitionCategoryId)
                ->where('event_id', $event->id)
                ->first();

            if (! $category) {
                $this->addError('competitionCategoryId', 'Kategori harus berasal dari event aktif.');

                return;
            }

            $class = CompetitionClass::where('id', $this->competitionClassId)
                ->where('competition_category_id', $category->id)
                ->first();

            if (! $class) {
                $this->addError('competitionClassId', 'Kelas harus berasal dari kategori yang dipilih.');

                return;
            }

            $participantClass = MasterParticipantClass::findOrFail($this->participantClassId);

            $service = app(CompetitionRegistrationService::class);

            $result = $service->register(
                nama: trim($this->nama),
                jenisKelamin: $this->jenisKelamin,
                tanggalLahir: $this->tanggalLahir ?: null,
                desaId: (int) $this->desaId,
                eventId: $event->id,
                competitionCategoryId: (int) $this->competitionCategoryId,
                competitionClassId: (int) $this->competitionClassId,
                kelompokId: $this->kelompokId !== '' ? (int) $this->kelompokId : null,
                kelas: $participantClass->name,
            );

            $person = $result['person'];

            $this->resetForm();

            $this->successData = [
                'person_name' => $person->nama,
                'kelas' => $person->kelas ?? '-',
                'category_name' => $category->name,
                'class_name' => $class->name,
                'participant_number' => $result['participation']->participant_number ?? '-',
                'status' => $result['status'] === 'registered' ? 'Peserta berhasil didaftarkan ke lomba.' : $result['status'],
            ];
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0] ?? '');
            }

            if ($e->errors()['nama'] ?? null) {
                $this->alreadyRegistered = true;
            }

            if ($e->errors()['competitionCategoryId'] ?? null) {
                $this->conflictMessage = $e->errors()['competitionCategoryId'][0];
            }
        } finally {
            $this->processing = false;
        }
    }

    public function resetForm(): void
    {
        $this->reset([
            'nama', 'participantClassId', 'jenisKelamin', 'tanggalLahir',
            'desaId', 'kelompokId', 'competitionCategoryId', 'competitionClassId',
            'alreadyRegistered', 'conflictMessage', 'personParticipations', 'successData',
        ]);
        $this->resetErrorBag();
    }

    public function getParticipantClassesProperty()
    {
        return MasterParticipantClass::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
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
        if (blank($this->desaId)) {
            return collect();
        }

        return kelompok::where('desa_id', $this->desaId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('kelompok_asal')
            ->get();
    }

    public function getCategoriesProperty()
    {
        $event = app(ActiveEventContext::class)->current();

        return CompetitionCategory::where('event_id', $event?->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function getClassesProperty()
    {
        if (blank($this->competitionCategoryId)) {
            return collect();
        }

        return CompetitionClass::where('competition_category_id', $this->competitionCategoryId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function render()
    {
        return view('livewire.competition.registration', [
            'participantClasses' => $this->participantClasses,
            'desas' => $this->desas,
            'kelompoks' => $this->kelompoks,
            'categories' => $this->categories,
            'classes' => $this->classes,
        ]);
    }
}