<?php

namespace App\Livewire\Competition;

use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\Participation;
use App\Models\Person;
use App\Services\Competition\CompetitionRegistrationService;
use App\Support\ActiveEventContext;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Component;

class Registration extends Component
{
    public bool $stepSearch = true;

    public bool $stepRegister = false;

    public bool $stepSuccess = false;

    public ?int $selectedPersonId = null;

    public string $nama = '';

    public string $kelas = '';

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

    #[On('personSelected')]
    public function onPersonSelected(int $personId): void
    {
        $person = Person::with(['desa', 'kelompok'])->findOrFail($personId);

        $this->selectedPersonId = $person->id;
        $this->nama = $person->nama;
        $this->kelas = $person->kelas ?? '';
        $this->jenisKelamin = $person->jenis_kelamin;
        $this->tanggalLahir = $person->tanggal_lahir?->format('Y-m-d') ?? '';
        $this->desaId = (string) ($person->desa_id ?? '');
        $this->kelompokId = (string) ($person->kelompok_id ?? '');

        $this->loadParticipations();
        $this->checkDuplicate();
        $this->checkConflict();
        $this->stepSearch = false;
        $this->stepRegister = true;
    }

    #[On('personSelectionCleared')]
    public function onPersonSelectionCleared(): void
    {
        $this->resetSelection();
        $this->stepSearch = true;
        $this->stepRegister = false;
    }

    #[On('personCreated')]
    public function onPersonCreated(): void {}

    public function loadParticipations(): void
    {
        if (! $this->selectedPersonId) {
            $this->personParticipations = null;

            return;
        }

        $event = app(ActiveEventContext::class)->current();

        $participations = Participation::where('person_id', $this->selectedPersonId)
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

    public function checkDuplicate(): void
    {
        $this->alreadyRegistered = false;

        if (! $this->selectedPersonId || ! $this->competitionClassId) {
            return;
        }

        $event = app(ActiveEventContext::class)->current();
        $participation = Participation::where('person_id', $this->selectedPersonId)
            ->where('event_id', $event?->id)
            ->first();

        if ($participation) {
            $existing = \App\Models\CompetitionRegistration::where('participation_id', $participation->id)
                ->where('competition_class_id', $this->competitionClassId)
                ->exists();
            $this->alreadyRegistered = $existing;
        }
    }

    public function checkConflict(): void
    {
        $this->conflictMessage = '';

        if (! $this->selectedPersonId || ! $this->competitionCategoryId) {
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
        $participation = Participation::where('person_id', $this->selectedPersonId)
            ->where('event_id', $event?->id)
            ->first();

        if (! $participation) {
            return;
        }

        $conflictNames = CompetitionRegistration::where('participation_id', $participation->id)
            ->whereIn('competition_category_id', $conflictCategoryIds)
            ->with('competitionCategory')
            ->get()
            ->pluck('competitionCategory.name')
            ->implode(', ');

        if ($conflictNames) {
            $this->conflictMessage = "Konflik dengan kategori yang sudah diikuti: {$conflictNames}";
        }
    }

    public function updatedCompetitionClassId(): void
    {
        $this->checkDuplicate();
    }

    public function updatedCompetitionCategoryId(): void
    {
        $this->competitionClassId = '';
        $this->checkConflict();
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
                'competitionCategoryId' => 'required|exists:competition_categories,id',
                'competitionClassId' => 'required|exists:competition_classes,id',
            ]);

            $event = app(ActiveEventContext::class)->requireCurrent();

            if ($this->selectedPersonId) {
                $person = Person::findOrFail($this->selectedPersonId);
            } else {
                $this->validate([
                    'nama' => 'required|string|max:255',
                    'kelas' => 'required|string|max:50',
                    'jenisKelamin' => 'required|in:L,P',
                    'tanggalLahir' => 'nullable|date',
                    'desaId' => 'required|exists:desas,id',
                    'kelompokId' => 'nullable|exists:kelompoks,id',
                ]);

                $person = Person::create([
                    'nama' => $this->nama,
                    'kelas' => $this->kelas,
                    'jenis_kelamin' => $this->jenisKelamin,
                    'tanggal_lahir' => $this->tanggalLahir ?: null,
                    'desa_id' => (int) $this->desaId,
                    'kelompok_id' => $this->kelompokId ? (int) $this->kelompokId : null,
                ]);
            }

            // Validate person has kelas and it matches the competition class
            $class = CompetitionClass::findOrFail($this->competitionClassId);
            if (blank($person->kelas)) {
                $this->addError('competitionClassId', 'Peserta belum memiliki kelas. Silakan edit data peserta terlebih dahulu.');
                return;
            }
            if ($person->kelas !== $class->name) {
                $this->addError('competitionClassId', "Kelas peserta ({$person->kelas}) tidak sesuai dengan kelas lomba ({$class->name}).");
                return;
            }

            $service = app(CompetitionRegistrationService::class);
            $result = $service->registerForPerson(
                person: $person,
                eventId: $event->id,
                competitionCategoryId: (int) $this->competitionCategoryId,
                competitionClassId: (int) $this->competitionClassId,
            );

            $category = CompetitionCategory::find($this->competitionCategoryId);
            $class = CompetitionClass::find($this->competitionClassId);

            $this->successData = [
                'person_name' => $person->nama,
                'kelas' => $person->kelas ?? '-',
                'category_name' => $category?->name ?? '-',
                'class_name' => $class?->name ?? '-',
                'participant_number' => $result['participation']->participant_number ?? '-',
                'status' => $this->selectedPersonId ? 'Participation baru dibuat untuk peserta existing.' : 'Peserta baru dan Participation dibuat.',
            ];

            $this->reset(['competitionCategoryId', 'competitionClassId']);
            $this->stepRegister = false;
            $this->stepSuccess = true;
        } finally {
            $this->processing = false;
        }
    }

    public function resetSelection(): void
    {
        $this->reset([
            'selectedPersonId', 'nama', 'kelas', 'jenisKelamin', 'tanggalLahir',
            'desaId', 'kelompokId', 'competitionCategoryId', 'competitionClassId',
            'alreadyRegistered', 'conflictMessage', 'personParticipations',
        ]);
    }

    public function resetAll(): void
    {
        $this->reset([
            'selectedPersonId', 'nama', 'kelas', 'jenisKelamin', 'tanggalLahir',
            'desaId', 'kelompokId', 'competitionCategoryId', 'competitionClassId',
            'stepSearch', 'stepRegister', 'stepSuccess', 'alreadyRegistered',
            'conflictMessage', 'successData', 'personParticipations',
        ]);
        $this->stepSearch = true;
        $this->resetErrorBag();
    }

    public function goBack(): void
    {
        $this->resetSelection();
        $this->stepSearch = true;
        $this->stepRegister = false;
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
            'categories' => $this->categories,
            'classes' => $this->classes,
        ]);
    }
}
