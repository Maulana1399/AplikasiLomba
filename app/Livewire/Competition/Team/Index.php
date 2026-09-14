<?php

namespace App\Livewire\Competition\Team;

use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionTeam;
use App\Models\CompetitionTeamMember;
use App\Services\Competition\CompetitionTeamFormationService;
use App\Services\Competition\CompetitionTeamService;
use App\Support\ActiveEventContext;
use App\Support\CompetitionFormat;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Index extends Component
{
    public string $competitionCategoryId = '';

    public string $competitionClassId = '';

    public string $formationMode = 'group';

    public string $teamSizeInput = '';

    public bool $processing = false;

    public bool $showPreview = false;

    /** @var array<string, mixed>|null */
    public ?array $preview = null;

    /** @var array<int, int> peta memberId => memberId target (swap) */
    public array $swapWith = [];

    public function mount(): void
    {
        app(ActiveEventContext::class)->requireCurrent();
        Gate::authorize('manage-registration');
    }

    public function updatedCompetitionCategoryId(): void
    {
        $this->competitionClassId = '';
        $this->showPreview = false;
        $this->preview = null;
        $this->swapWith = [];
    }

    public function updatedCompetitionClassId(): void
    {
        $this->showPreview = false;
        $this->preview = null;
        $this->swapWith = [];
        $this->teamSizeInput = $this->selectedClass?->team_size !== null
            ? (string) $this->selectedClass->team_size
            : '';
    }

    public function updatedFormationMode(): void
    {
        $this->showPreview = false;
        $this->preview = null;
    }

    public function getCategoriesProperty()
    {
        $event = app(ActiveEventContext::class)->current();

        return CompetitionCategory::whereHas('events', fn ($q) => $q->where('events.id', $event?->id))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Kelas yang membutuhkan tim dan tersedia di 5 format UI = `team_vs_team`.
     * `team_mass`/`team_heat` tidak diekspos di menu Pembagian Tim (legacy/engine).
     */
    public function getClassesProperty()
    {
        if (blank($this->competitionCategoryId)) {
            return collect();
        }

        return CompetitionClass::where('competition_category_id', $this->competitionCategoryId)
            ->where('format', CompetitionFormat::TEAM_VS_TEAM)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function getTeamsProperty()
    {
        $class = $this->selectedClass;

        if ($class === null) {
            return collect();
        }

        return app(CompetitionTeamService::class)->listForClass($class->event_id, $class->id);
    }

    public function getSelectedClassProperty(): ?CompetitionClass
    {
        if (blank($this->competitionClassId) || blank($this->competitionCategoryId)) {
            return null;
        }

        return CompetitionClass::where('id', $this->competitionClassId)
            ->where('competition_category_id', $this->competitionCategoryId)
            ->where('format', CompetitionFormat::TEAM_VS_TEAM)
            ->where('is_active', true)
            ->first();
    }

    public function getFormatLabelProperty(): string
    {
        return $this->selectedClass !== null
            ? CompetitionFormat::label($this->selectedClass->format)
            : '';
    }

    public function getAvailableRegistrationsProperty()
    {
        $class = $this->selectedClass;

        if ($class === null) {
            return collect();
        }

        $assignedRegistrationIds = CompetitionTeamMember::whereHas('team', function ($query) use ($class) {
            $query->where('event_id', $class->event_id)
                ->where('competition_class_id', $class->id);
        })->pluck('competition_registration_id');

        return \App\Models\CompetitionRegistration::with('participation.person.kelompok')
            ->where('competition_class_id', $class->id)
            ->whereNotIn('id', $assignedRegistrationIds)
            ->orderBy('id')
            ->get();
    }

    /** @return array<int, array{id: int, label: string}>|array<int, list<array{id:int,label:string}>> */
    public function getSwapOptionsProperty(): array
    {
        $teams = $this->teams;

        if ($teams->count() < 2) {
            return [];
        }

        $rows = $teams->flatMap(fn ($team) => $team->members->map(fn ($member) => [
            'member' => $member,
            'team' => $team,
        ]));

        $options = [];

        foreach ($rows as $row) {
            $candidates = $rows
                ->filter(fn ($other) => $other['team']->id !== $row['team']->id)
                ->map(fn ($other) => [
                    'id' => $other['member']->id,
                    'label' => ($other['member']->competitionRegistration?->participation?->person?->nama ?? '#'.$other['member']->id)
                        .' ('.$other['team']->name.')',
                ])
                ->sortBy(fn ($candidate) => $candidate['label'])
                ->values()
                ->all();

            $options[$row['member']->id] = $candidates;
        }

        return $options;
    }

    /**
     * Ukuran tim default:
     * - `group`: `class.team_size` ?? jumlah kelompok terkecil.
     * - `balanced`: `class.team_size` (wajib diisi lebih dulu).
     */
    public function getDefaultTeamSizeProperty(): ?int
    {
        if ($this->selectedClass === null) {
            return null;
        }

        if ($this->selectedClass->team_size !== null) {
            return $this->selectedClass->team_size;
        }

        if ($this->formationMode === 'group') {
            $event = app(ActiveEventContext::class)->requireCurrent();

            $counts = \App\Models\CompetitionRegistration::where('competition_class_id', $this->selectedClass->id)
                ->whereHas('participation.person', fn ($query) => $query->whereNotNull('kelompok_id'))
                ->with('participation.person.kelompok')
                ->get()
                ->groupBy(fn ($registration) => $registration->participation->person->kelompok_id)
                ->map->count();

            return $counts->isNotEmpty() ? $counts->min() : null;
        }

        return null;
    }

    public function previewFormation(): void
    {
        Gate::authorize('manage-registration');

        if ($this->selectedClass === null) {
            return;
        }

        $class = $this->selectedClass;
        $service = app(CompetitionTeamFormationService::class);

        $forcedTeamSize = $this->teamSizeInput !== '' ? (int) $this->teamSizeInput : null;

        try {
            $result = $this->formationMode === 'balanced'
                ? $service->previewBalancedForClass($class->event_id, $class->id, $forcedTeamSize)
                : $service->previewForClass($class->event_id, $class->id, $forcedTeamSize);

            unset($result['class']);

            $this->preview = $result;
            $this->showPreview = true;
        } catch (ValidationException $e) {
            $this->addError('formationMode', $e->getMessage());
        }
    }

    public function clearPreview(): void
    {
        $this->showPreview = false;
        $this->preview = null;
    }

    /** Rincian preview siap tampil (kelas/gender untuk mode balanced). */
    public function getPreviewBreakdownProperty(): array
    {
        if (! $this->showPreview || $this->preview === null || $this->selectedClass === null) {
            return [];
        }

        $preview = $this->preview;

        if ($this->formationMode === 'group') {
            return [
                'mode' => 'group',
                'team_size' => $preview['team_size'],
                'teams' => $preview['teams'],
            ];
        }

        $ids = collect($preview['teams'])->flatMap(fn ($team) => $team['members'])->all();

        $registrations = \App\Models\CompetitionRegistration::with('participation.person')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $rows = [];

        foreach ($preview['teams'] as $team) {
            $kelasCounts = [];
            $laki = 0;
            $perempuan = 0;

            foreach ($team['members'] as $memberId) {
                $person = $registrations[$memberId]?->participation?->person;

                if ($person === null) {
                    continue;
                }

                $kelas = $person->kelas ?? '(tanpa kelas)';
                $kelasCounts[$kelas] = ($kelasCounts[$kelas] ?? 0) + 1;

                $person->jenis_kelamin === 'P' ? $perempuan++ : $laki++;
            }

            $rows[] = [
                'name' => $team['name'],
                'players' => $team['players'],
                'substitutes' => $team['substitutes'],
                'kelas' => $kelasCounts,
                'laki' => $laki,
                'perempuan' => $perempuan,
            ];
        }

        return [
            'mode' => 'balanced',
            'team_size' => $preview['team_size'],
            'team_count' => $preview['team_count'],
            'teams' => $rows,
        ];
    }

    /**
     * Persist team size bila operator mengisinya (kelas.update), lalu eksekusi
     * pembentukan sesuai mode. Tim yang sudah ada hanya ditimpa via rebuild eksplisit.
     */
    public function generateFormation(): void
    {
        Gate::authorize('manage-registration');

        if ($this->selectedClass === null) {
            return;
        }

        $this->processing = true;

        try {
            $this->validate([
                'teamSizeInput' => 'nullable|integer|min:1|max:100',
            ]);

            $class = $this->selectedClass;
            $service = app(CompetitionTeamFormationService::class);

            if ($this->teamSizeInput !== '') {
                $class->update(['team_size' => (int) $this->teamSizeInput]);
            }

            $forcedTeamSize = $this->teamSizeInput !== '' ? (int) $this->teamSizeInput : null;
            $force = $this->isFormed;

            if ($this->formationMode === 'balanced') {
                $result = $service->formBalancedForClass($class->event_id, $class->id, $forcedTeamSize, $force);
                session()->flash('success', 'Pembagian tim Random & Balanced selesai. Ukuran tim: '.$result['team_size'].' pemain ('.$result['team_count'].' tim).');
            } else {
                $result = $service->formForClass($class->event_id, $class->id, $forcedTeamSize, $force);
                session()->flash('success', 'Pembagian tim berdasarkan kelompok selesai. Ukuran tim: '.$result['team_size'].' pemain ('.count($result['teams']).' tim).');
            }

            $this->showPreview = false;
            $this->preview = null;
            $this->swapWith = [];
        } catch (ValidationException $e) {
            session()->flash('error', $e->getMessage());
        } finally {
            $this->processing = false;
        }
    }

    // ---------------------------------------------------------------------------
    // Manual adjustment
    // ---------------------------------------------------------------------------

    public function addMember(int $teamId, int $registrationId, bool $asSubstitute = false): void
    {
        Gate::authorize('manage-registration');

        $team = CompetitionTeam::where('event_id', $this->selectedClass?->event_id)->findOrFail($teamId);

        try {
            app(CompetitionTeamService::class)->addMember($team, $registrationId, $asSubstitute);
            session()->flash('success', 'Anggota ditambahkan.');
        } catch (ValidationException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function removeMember(int $teamId, int $memberId): void
    {
        Gate::authorize('manage-registration');

        $team = CompetitionTeam::where('event_id', $this->selectedClass?->event_id)->findOrFail($teamId);

        try {
            app(CompetitionTeamService::class)->removeMember($team, $memberId);
            session()->flash('success', 'Anggota dihapus dari tim.');
        } catch (ValidationException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function moveToPlayers(int $teamId, int $memberId): void
    {
        $this->setSubstitute($teamId, $memberId, false);
    }

    public function moveToSubstitutes(int $teamId, int $memberId): void
    {
        $this->setSubstitute($teamId, $memberId, true);
    }

    public function setSubstitute(int $teamId, int $memberId, bool $asSubstitute): void
    {
        Gate::authorize('manage-registration');

        $team = CompetitionTeam::where('event_id', $this->selectedClass?->event_id)->findOrFail($teamId);

        try {
            app(CompetitionTeamService::class)->setSubstitute($team, $memberId, $asSubstitute);
        } catch (ValidationException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function shuffle(int $teamId): void
    {
        Gate::authorize('manage-registration');

        $team = CompetitionTeam::where('event_id', $this->selectedClass?->event_id)->findOrFail($teamId);

        try {
            app(CompetitionTeamService::class)->shuffleMembers($team);
            session()->flash('success', 'Urutan anggota diacak.');
        } catch (ValidationException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function swapMember(int $teamId, int $memberId): void
    {
        Gate::authorize('manage-registration');

        $target = (int) ($this->swapWith[$memberId] ?? 0);

        if ($target < 1) {
            session()->flash('error', 'Pilih anggota dari tim lain untuk ditukar.');

            return;
        }

        $eventId = $this->selectedClass?->event_id;

        try {
            $teamA = CompetitionTeam::where('event_id', $eventId)->findOrFail($teamId);
            $targetMember = CompetitionTeamMember::with('team')->findOrFail($target);

            app(CompetitionTeamService::class)->swapMembers(
                $teamA,
                $memberId,
                CompetitionTeam::where('event_id', $eventId)->findOrFail($targetMember->team->id),
                $target,
            );

            unset($this->swapWith[$memberId]);

            session()->flash('success', 'Anggota berhasil ditukar antar tim.');
        } catch (ValidationException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function getIsFormedProperty(): bool
    {
        return $this->teams->isNotEmpty();
    }

    /**
     * Ringkasan: total team + team size (pemain terkecil di antara team).
     */
    public function getSummaryProperty(): array
    {
        $teams = $this->teams;

        if ($teams->isEmpty()) {
            return ['total_teams' => 0, 'team_size' => null];
        }

        return [
            'total_teams' => $teams->count(),
            'team_size' => $teams->map(fn ($team) => $team->players->count())->min(),
        ];
    }

    public function render()
    {
        return view('livewire.competition.team.index', [
            'categories' => $this->categories,
            'classes' => $this->classes,
            'teams' => $this->teams,
            'selectedClass' => $this->selectedClass,
            'formatLabel' => $this->formatLabel,
            'availableRegistrations' => $this->availableRegistrations,
            'isFormed' => $this->isFormed,
            'summary' => $this->summary,
            'defaultTeamSize' => $this->defaultTeamSize,
            'swapOptions' => $this->swapOptions,
            'previewBreakdown' => $this->previewBreakdown,
        ]);
    }
}