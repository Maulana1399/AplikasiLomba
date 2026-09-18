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

    public ?int $activeEventId = null;

    public ?string $activeEventName = null;

    public string $competitionId = '';

    public ?array $resolvedClass = null;

    public ?string $resolveError = null;

    public array $candidateClasses = [];

    public string $selectedClassId = '';

    public bool $processing = false;

    public bool $alreadyRegistered = false;

    public string $conflictMessage = '';

    public ?array $successData = null;

    public ?array $personParticipations = null;

    public function mount(): void
    {
        $event = app(ActiveEventContext::class)->requireCurrent();
        $this->activeEventId = $event->id;
        $this->activeEventName = $event->name;
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

    /**
     * Gender efektif: gender Person existing (L/P) menang; bila belum ada,
     * gunakan pilihan operator. Null berarti operator harus memilih dulu.
     */
    public function effectiveGender(): ?string
    {
        $person = $this->findPerson();

        $personGender = mb_strtoupper(trim((string) ($person?->jenis_kelamin)));

        if ($personGender === 'L' || $personGender === 'P') {
            return $personGender;
        }

        $selected = mb_strtoupper(trim($this->jenisKelamin));

        return in_array($selected, ['L', 'P'], true) ? $selected : null;
    }

    /**
     * Field gender hanya perlu ditampilkan bila Person belum punya gender L/P
     * (atau Person belum ditemukan).
     */
    public function getRequiresGenderSelectionProperty(): bool
    {
        $person = $this->findPerson();

        if (! $person) {
            return true;
        }

        return ! in_array($person->jenis_kelamin, ['L', 'P'], true);
    }

    public function getKnownPersonGenderLabelProperty(): ?string
    {
        $person = $this->findPerson();

        return match ($person?->jenis_kelamin) {
            'L' => 'Laki - Laki',
            'P' => 'Perempuan',
            default => null,
        };
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

    public function resolveCompetitionClass(): void
    {
        $this->resolvedClass = null;
        $this->resolveError = null;
        $this->candidateClasses = [];
        $this->alreadyRegistered = false;
        $this->conflictMessage = '';

        if (blank($this->competitionId)) {
            return;
        }

        $event = \App\Models\Event::where('id', $this->competitionId)
            ->where('event_type', 'competition')->first();

        if (! $event) {
            $this->resolveError = 'Lomba tidak ditemukan.';

            return;
        }

        if (blank($this->participantClassId)) {
            return;
        }

        $participantClass = MasterParticipantClass::find($this->participantClassId);

        if (! $participantClass) {
            $this->resolveError = 'Kelas peserta tidak ditemukan.';

            return;
        }

        // Gender efektif: dari data Person bila sudah ada, selain itu pilihan operator.
        $mapped = $this->effectiveGender();

        if ($mapped === null) {
            return;
        }

        $categoryIds = CompetitionCategory::where('event_id', $event->id)
            ->where('is_active', true)
            ->whereHas('masterParticipantClasses', fn ($q) => $q->where('master_participant_classes.id', $participantClass->id))
            ->pluck('id');

        if ($categoryIds->isEmpty()) {
            $this->resolveError = 'Tidak ada kategori lomba yang sesuai dengan kelas peserta untuk lomba ini.';

            return;
        }

        $candidates = CompetitionClass::where('event_id', $event->id)
            ->where('is_active', true)
            ->whereIn('competition_category_id', $categoryIds)
            ->whereHas('competitionCategory', fn ($q) => $q->where('is_active', true))
            ->with('competitionCategory')
            ->get()->filter(function ($cls) use ($mapped) {
                if ($cls->gender === 'M') {
                    return true;
                }

                return $mapped !== null && $cls->gender === $mapped;
            })->values();

        if ($candidates->isEmpty()) {
            $this->resolveError = 'Tidak ada kelas lomba yang sesuai dengan kelas/gender peserta untuk lomba ini.';

            return;
        }

        $this->candidateClasses = $candidates->map(fn ($cls) => [
            'id' => $cls->id,
            'name' => $cls->name,
            'category_id' => $cls->competition_category_id,
            'category_name' => $cls->competitionCategory?->name ?? '-',
            'format' => $cls->format,
            'format_label' => \App\Support\CompetitionFormat::label($cls->format),
            'result_type' => $cls->resultType(),
            'result_label' => \App\Support\CompetitionResultType::label($cls->resultType()),
            'gender' => $cls->gender,
        ])->values()->all();

        if ($this->candidateClasses === []) {
            $this->resolveError = 'Tidak ada kelas lomba yang sesuai dengan kelas/gender peserta untuk lomba ini.';

            return;
        }

        if (count($this->candidateClasses) === 1) {
            if (blank($this->selectedClassId)) {
                $this->selectedClassId = (string) $this->candidateClasses[0]['id'];
            }

            if (! collect($this->candidateClasses)->contains(fn ($c) => (string) $c['id'] === $this->selectedClassId)) {
                $this->selectedClassId = (string) $this->candidateClasses[0]['id'];
            }

            $cls = $candidates->firstWhere('id', (int) $this->selectedClassId) ?? $candidates->first();
        } else {
            if (blank($this->selectedClassId) || ! collect($this->candidateClasses)->contains(fn ($c) => (string) $c['id'] === $this->selectedClassId)) {
                $this->resolveError = null;
                $this->resolvedClass = null;

                return;
            }

            $cls = $candidates->firstWhere('id', (int) $this->selectedClassId);

            if (! $cls) {
                $this->resolveError = 'Kelas lomba tidak valid.';

                return;
            }
        }

        $cls->load('competitionCategory');

        $this->resolvedClass = [
            'id' => $cls->id,
            'name' => $cls->name,
            'category_id' => $cls->competition_category_id,
            'category_name' => $cls->competitionCategory?->name ?? '-',
            'format' => $cls->format,
            'format_label' => \App\Support\CompetitionFormat::label($cls->format),
            'result_type' => $cls->resultType(),
            'result_label' => \App\Support\CompetitionResultType::label($cls->resultType()),
            'gender' => $cls->gender,
        ];

        $person = $this->findPerson();

        if ($person) {
            $participation = Participation::where('person_id', $person->id)
                ->where('event_id', $event->id)->first();

            if ($participation) {
                if (\App\Models\CompetitionRegistration::where('participation_id', $participation->id)
                    ->where('competition_class_id', $cls->id)->exists()) {
                    $this->alreadyRegistered = true;
                }

                $category = CompetitionCategory::find($cls->competition_category_id);

                if ($category) {
                    $conflictIds = $category->allExclusiveCategoryIds();

                    if (! empty($conflictIds)) {
                        $conflictNames = \App\Models\CompetitionRegistration::where('participation_id', $participation->id)
                            ->whereIn('competition_category_id', $conflictIds)
                            ->with('competitionCategory')->get()
                            ->pluck('competitionCategory.name')->implode(', ');

                        if ($conflictNames) {
                            $this->conflictMessage = "Konflik dengan kategori yang sudah diikuti: {$conflictNames}";
                        }
                    }
                }
            }
        }
    }

    public function checkDuplicate(?Person $person): void
    {
        $this->alreadyRegistered = false;

        if (! $person || ! $this->resolvedClass) {
            return;
        }

        $event = blank($this->competitionId) ? app(ActiveEventContext::class)->current() : \App\Models\Event::find($this->competitionId);

        $participation = Participation::where('person_id', $person->id)
            ->where('event_id', $event?->id)
            ->first();

        if ($participation && isset($this->resolvedClass['id'])) {
            $this->alreadyRegistered = \App\Models\CompetitionRegistration::where('participation_id', $participation->id)
                ->where('competition_class_id', $this->resolvedClass['id'])->exists();
        }
    }

    public function checkConflict(?Person $person): void
    {
    }

    public function refreshPersonState(): void
    {
        $person = $this->findPerson();

        // Person yang sudah punya gender L/P tidak perlu memilih gender lagi.
        if ($person && in_array($person->jenis_kelamin, ['L', 'P'], true)) {
            $this->jenisKelamin = '';
        }

        $this->loadParticipations($person);
        $this->resolveCompetitionClass();
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

    public function updatedParticipantClassId(): void
    {
        $this->refreshPersonState();
    }

    public function updatedJenisKelamin(): void
    {
        $this->refreshPersonState();
    }

    public function updatedCompetitionId(): void
    {
        $this->selectedClassId = '';
        $this->refreshPersonState();
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
                'jenisKelamin' => 'nullable|in:L,P',
                'tanggalLahir' => 'nullable|date',
                'desaId' => 'required|exists:desas,id',
                'kelompokId' => 'nullable|exists:kelompoks,id',
                'competitionId' => 'required|exists:events,id',
            ]);

            // Gender wajib tersedia: dari data Person, atau pilihan operator.
            if ($this->effectiveGender() === null) {
                $this->addError('jenisKelamin', 'Jenis kelamin wajib dipilih.');

                return;
            }

            $event = \App\Models\Event::where('id', $this->competitionId)
                ->where('event_type', 'competition')->first();

            if (! $event) {
                $this->addError('competitionId', 'Lomba tidak ditemukan.');

                return;
            }

            $this->resolveCompetitionClass();

            if (! $this->resolvedClass) {
                $this->addError('competitionId', $this->resolveError ?? 'Tidak ada kelas lomba yang sesuai dengan kelas/gender peserta untuk lomba ini.');

                return;
            }

            if ($this->alreadyRegistered || $this->conflictMessage) {
                if ($this->alreadyRegistered) {
                    $this->addError('competitionId', 'Peserta sudah terdaftar di kelas ini.');
                }
                if ($this->conflictMessage) {
                    $this->addError('competitionId', $this->conflictMessage);
                }

                return;
            }

            if (! $event->competitionClasses()->where('is_active', true)->exists()) {
                $this->addError('competitionId', 'Lomba tidak memiliki kelas lomba yang aktif.');

                return;
            }

            $class = CompetitionClass::with('competitionCategory')->findOrFail($this->resolvedClass['id']);
            $category = $class->competitionCategory;

            if (! $category || ! $category->is_active || ! $class->is_active) {
                $this->addError('competitionId', 'Kelas lomba tidak aktif.');

                return;
            }

            $participantClass = MasterParticipantClass::findOrFail($this->participantClassId);
            $service = app(CompetitionRegistrationService::class);

            $result = $service->register(
                nama: trim($this->nama),
                jenisKelamin: $this->jenisKelamin ?: null,
                tanggalLahir: $this->tanggalLahir ?: null,
                desaId: (int) $this->desaId,
                eventId: $event->id,
                competitionCategoryId: (int) $category->id,
                competitionClassId: (int) $class->id,
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

    public function updatedSelectedClassId(): void
    {
        $this->resolveCompetitionClass();
    }

    public function resetForm(): void
    {
        $this->reset([
            'nama', 'participantClassId', 'jenisKelamin', 'tanggalLahir',
            'desaId', 'kelompokId', 'competitionId', 'resolvedClass', 'resolveError',
            'candidateClasses', 'selectedClassId',
            'alreadyRegistered', 'conflictMessage', 'personParticipations', 'successData',
        ]);
        $this->resetErrorBag();
        $this->resolveCompetitionClass();
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

    public function getCompetitionsProperty()
    {
        return \App\Models\Event::where('event_type', 'competition')
            ->where('status', 'active')
            ->whereHas('competitionClasses', function ($query) {
                $query->where('is_active', true);
            })
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
            'competitions' => $this->competitions,
        ]);
    }
}
