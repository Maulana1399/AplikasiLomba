<?php

namespace App\Livewire\Competition\Heat;

use App\Models\CompetitionClass;
use App\Models\CompetitionSchedule;
use App\Models\CompetitionTeam;
use App\Services\Competition\CompetitionHeatManagerService;
use App\Services\Competition\CompetitionMultiRoundHeatService;
use App\Support\ActiveEventContext;
use App\Support\CompetitionResultType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Index extends Component
{
    public string $selectedClassId = '';

    public ?string $categoryId = null;

    public string $statusFilter = '';

    public bool $showFormatForm = false;

    public int $formatRound = 1;

    public int $formatParticipants = 7;

    public int $formatMinParticipants = 2;

    public int $formatQualifiers = 3;

    public int $teamRound = 1;

    /** @var array<int, int> peta teamId => heatIndex tujuan assignment */
    public array $assignTargets = [];

    /** @var array<int, int> peta teamId => heatIndex tujuan pindah */
    public array $moveTargets = [];

    public string $teamSearch = '';

    public function mount(): void
    {
        app(ActiveEventContext::class)->requireCurrent();
    }

    public function updatedTeamRound(): void
    {
        $this->assignTargets = [];
        $this->moveTargets = [];
    }

    public function selectClass($classId): void
    {
        $this->selectedClassId = (string) $classId;
        $this->reset(['showFormatForm', 'formatRound', 'formatParticipants', 'formatMinParticipants', 'formatQualifiers']);
        $this->formatRound = 1;
        $this->formatParticipants = 7;
        $this->formatMinParticipants = 2;
        $this->formatQualifiers = 3;
        $this->teamRound = 1;
        $this->assignTargets = [];
        $this->moveTargets = [];
        $this->teamSearch = '';
        $this->resetErrorBag();
    }

    public function backToList(): void
    {
        $this->selectedClassId = '';
        $this->showFormatForm = false;
        $this->resetErrorBag();
    }

    public function toggleFormatForm(): void
    {
        $this->showFormatForm = ! $this->showFormatForm;
        $this->reset(['formatRound', 'formatParticipants', 'formatMinParticipants', 'formatQualifiers']);
        $this->formatRound = 1;
        $this->formatParticipants = 7;
        $this->formatMinParticipants = 2;
        $this->formatQualifiers = 3;
        $this->resetErrorBag();
    }

    public function createFormat(): void
    {
        Gate::authorize('manage-events');

        $this->validate([
            'formatRound' => 'required|integer|min:1',
            'formatParticipants' => 'required|integer|min:1|max:99',
            'formatMinParticipants' => 'required|integer|min:1|max:99',
            'formatQualifiers' => 'required|integer|min:1|max:99',
        ]);

        $this->validateFormat($this->formatParticipants, $this->formatMinParticipants, $this->formatQualifiers);

        $service = app(CompetitionHeatManagerService::class);

        $service->upsertFormat(
            $this->selectedEventId(),
            (int) $this->selectedClassId,
            $this->formatRound,
            $this->formatParticipants,
            $this->formatQualifiers,
            $this->formatMinParticipants,
        );

        $this->showFormatForm = false;
        session()->flash('success', "Format heat Round {$this->formatRound} disimpan.");
        $this->reset(['formatRound', 'formatParticipants', 'formatMinParticipants', 'formatQualifiers']);
        $this->formatRound = 1;
        $this->formatParticipants = 7;
        $this->formatMinParticipants = 2;
        $this->formatQualifiers = 3;
    }

    public function deleteFormat(int $formatId): void
    {
        Gate::authorize('manage-events');

        app(CompetitionHeatManagerService::class)->deleteFormat($this->selectedEventId(), $formatId);

        session()->flash('success', 'Format heat dihapus.');
    }

    public function generateRound(int $round): void
    {
        Gate::authorize('manage-events');

        $service = app(CompetitionHeatManagerService::class);

        $result = $service->generateRound($this->selectedEventId(), (int) $this->selectedClassId, $round);

        if (! $result['generated']) {
            session()->flash('error', $this->generateMessage($result['reason'] ?? 'unknown', $round));

            return;
        }

        $class = CompetitionClass::find((int) $this->selectedClassId);

        session()->flash('success', $class?->isTeamFormat()
            ? "Round {$round} dibuat: {$result['heat_count']} heat (kosong). Silakan masukkan Team ke heat."
            : "Round {$round} dibuat: {$result['heat_count']} heat, {$result['competitors_used']} kompetitor dipasang ke heat.");
    }

    public function advanceRound(int $round): void
    {
        Gate::authorize('manage-events');

        $service = app(CompetitionHeatManagerService::class);

        $result = $service->generateNextRound($this->selectedEventId(), (int) $this->selectedClassId, $round);

        if (! $result['advanced']) {
            session()->flash('error', $this->advanceMessage($result['reason'] ?? 'unknown', $round, $result['next_round'] ?? $round + 1));

            return;
        }

        session()->flash('success', "Advancement Round {$result['round']} → Round {$result['next_round']}: {$result['qualifiers']} lolos, {$result['heat_count']} heat baru dibuat, {$result['assigned']} dijadwalkan.");
    }

    public function removeRound(int $round): void
    {
        Gate::authorize('manage-events');

        $service = app(CompetitionHeatManagerService::class);

        $result = $service->removeRoundSchedules($this->selectedEventId(), (int) $this->selectedClassId, $round);

        if (! $result['deleted']) {
            session()->flash('error', $this->removeMessage($result['reason'] ?? 'unknown', $round));

            return;
        }

        session()->flash('success', "Round {$result['round']} heat dihapus (".count($result['schedule_ids']).' heat).');
    }

    public function rebuildRound(int $round): void
    {
        Gate::authorize('manage-events');

        $service = app(CompetitionHeatManagerService::class);

        $result = $service->rebuildRound($this->selectedEventId(), (int) $this->selectedClassId, $round);

        if (! $result['rebuilt']) {
            session()->flash('error', $this->rebuildMessage($result['reason'] ?? 'unknown', $round));

            return;
        }

        $class = CompetitionClass::find((int) $this->selectedClassId);

        session()->flash('success', $class?->isTeamFormat()
            ? "Round {$round} dibangun ulang dari format: {$result['heat_count']} heat (kosong). Silakan distribusikan Team."
            : "Round {$round} dibangun ulang dari format: {$result['heat_count']} heat, {$result['competitors_used']} kompetitor dipasang.");
    }

    // -------------------------------------------------------------------------
    // Team Heat — assignment manual
    // -------------------------------------------------------------------------

    public function assignTeam(int $round, int $teamId): void
    {
        Gate::authorize('manage-events');

        $heatIndex = (int) ($this->assignTargets[$teamId] ?? 0);

        if ($heatIndex < 1) {
            session()->flash('error', 'Pilih heat tujuan untuk team ini terlebih dahulu.');

            return;
        }

        try {
            $result = app(CompetitionHeatManagerService::class)
                ->assignTeamToHeat($this->selectedEventId(), (int) $this->selectedClassId, $round, $heatIndex, $teamId);

            unset($this->assignTargets[$teamId]);

            session()->flash('success', 'Team dipasang ke Heat '.str_pad((string) $result['heat_index'], 2, '0', STR_PAD_LEFT).'.');
        } catch (ValidationException $e) {
            session()->flash('error', $this->firstValidationMessage($e));
        }
    }

    public function removeTeam(int $round, int $heatIndex, int $teamId): void
    {
        Gate::authorize('manage-events');

        try {
            app(CompetitionHeatManagerService::class)
                ->removeTeamFromHeat($this->selectedEventId(), (int) $this->selectedClassId, $round, $heatIndex, $teamId);

            session()->flash('success', 'Team dikeluarkan dari heat.');
        } catch (ValidationException $e) {
            session()->flash('error', $this->firstValidationMessage($e));
        }
    }

    public function moveTeam(int $round, int $fromHeatIndex, int $teamId): void
    {
        Gate::authorize('manage-events');

        $toHeatIndex = (int) ($this->moveTargets[$teamId] ?? 0);

        if ($toHeatIndex < 1) {
            session()->flash('error', 'Pilih heat tujuan untuk dipindah terlebih dahulu.');

            return;
        }

        try {
            app(CompetitionHeatManagerService::class)
                ->moveTeamBetweenHeats($this->selectedEventId(), (int) $this->selectedClassId, $round, $fromHeatIndex, $toHeatIndex, $teamId);

            unset($this->moveTargets[$teamId]);

            session()->flash('success', 'Team dipindah ke Heat '.str_pad((string) $toHeatIndex, 2, '0', STR_PAD_LEFT).'.');
        } catch (ValidationException $e) {
            session()->flash('error', $this->firstValidationMessage($e));
        }
    }

    public function autoAssignTeams(int $round): void
    {
        Gate::authorize('manage-events');

        try {
            $result = app(CompetitionHeatManagerService::class)
                ->autoAssignRound($this->selectedEventId(), (int) $this->selectedClassId, $round);

            session()->flash('success', 'Distribusi otomatis selesai: '.$result['teams_assigned'].' team dipasang ke '.$result['heat_count'].' heat. Susunan tetap bisa diubah sebelum pertandingan.');
        } catch (ValidationException $e) {
            session()->flash('error', $this->firstValidationMessage($e));
        }
    }

    private function firstValidationMessage(ValidationException $e): string
    {
        $messages = $e->errors();

        return $messages === [] ? $e->getMessage() : (string) reset($messages)[0];
    }

    private function validateFormat(int $participants, int $minParticipants, int $qualifiers): void
    {
        $unit = $this->selectedIsTeamHeat() ? 'tim' : 'peserta';

        if ($minParticipants > $participants) {
            $this->addError('formatMinParticipants', "Minimum {$unit} untuk start tidak boleh melebihi {$unit} per heat.");
        }

        if ($qualifiers > $participants) {
            $this->addError('formatQualifiers', "Jumlah lolos tidak boleh melebihi {$unit} per heat.");
        }
    }

    private function selectedIsTeamHeat(): bool
    {
        $class = CompetitionClass::find((int) $this->selectedClassId);

        return $class !== null && $class->format === \App\Support\CompetitionFormat::TEAM_HEAT;
    }

    private function generateMessage(string $reason, int $round): string
    {
        return match ($reason) {
            'no_format' => 'Belum ada format untuk Round '.$round.'. Buat format terlebih dahulu.',
            'round_exists' => 'Round '.$round.' sudah punya heat. Hapus heat round tersebut dulu bila ingin generate ulang.',
            'no_competitors' => 'Belum ada peserta/team terdaftar di kelas ini.',
            default => 'Generate heat gagal ('.$reason.').',
        };
    }

    private function advanceMessage(string $reason, int $round, int $nextRound): string
    {
        return match ($reason) {
            'no_format' => 'Belum ada format untuk Round '.$round.'.',
            'no_next_format' => 'Belum ada format untuk Round '.$nextRound.' sehingga round berikutnya TIDAK dibuat. Tambahkan format Round '.$nextRound.' bila memang kompetisi berlanjut.',
            'qualified_pool_insufficient' => 'Qualifier belum cukup untuk membangun Round '.$nextRound.'. Tunggu heat lain selesai & di-qualify hingga pool mencapai kapasitas format.',
            'next_round_exists' => 'Round '.$nextRound.' sudah punya heat. Hapus heat round '.$nextRound.' dulu bila ingin generate ulang.',
            'no_qualifiers' => 'Tidak ada qualifier dari Round '.$round.'.',
            default => 'Advancement gagal ('.$reason.').',
        };
    }

    private function removeMessage(string $reason, int $round): string
    {
        return match ($reason) {
            'no_heats' => 'Round '.$round.' tidak punya heat.',
            'round_started' => 'Round '.$round.' sudah dimulai (Playing/Finished) sehingga heat tidak bisa dihapus.',
            default => 'Hapus heat gagal ('.$reason.').',
        };
    }

    private function rebuildMessage(string $reason, int $round): string
    {
        return match ($reason) {
            'no_format' => 'Belum ada format untuk Round '.$round.'.',
            'round_started' => 'Round '.$round.' sudah dimulai (Playing/Finished) sehingga tidak bisa dibangun ulang.',
            'has_results' => 'Round '.$round.' sudah punya hasil yang diinput; hapus hasil heat dulu sebelum membangun ulang.',
            'no_competitors' => 'Belum ada peserta/team terdaftar di kelas ini.',
            default => 'Build ulang gagal ('.$reason.').',
        };
    }

    public function render()
    {
        $service = app(CompetitionHeatManagerService::class);
        $multiRound = app(CompetitionMultiRoundHeatService::class);

        $classes = $this->heatClasses();
        $selected = $classes->firstWhere('id', (int) $this->selectedClassId);

        $formats = collect();
        $rounds = collect();
        $poolCount = 0;

        $isTeamHeat = $selected !== null && $selected->format === \App\Support\CompetitionFormat::TEAM_HEAT;
        $teamRound = 1;
        $availableTeams = collect();
        $teamHeats = collect();
        $assignableHeats = collect();
        $heatCountForRound = null;
        $activeTeamsCount = 0;
        $teamNeedsRebuild = false;

        if ($selected) {
            $poolCount = $service->competitorCount($selected->id);
            $formats = $service->formats($selected->id);

            if ($isTeamHeat) {
                $teamRound = $this->teamRoundFor($formats);
                $schedules = $multiRound->roundSchedules($selected->id, $teamRound);

                if ($this->teamRound !== $teamRound) {
                    $this->teamRound = $teamRound;
                }

                $teamNeedsRebuild = $service->needsRebuild($selected->id, $teamRound);

                $this->teamHeatData($selected, $schedules, $teamRound, $availableTeams, $teamHeats, $assignableHeats, $heatCountForRound, $activeTeamsCount);
            }

            $rounds = $formats->map(function ($format) use ($service, $multiRound, $selected) {
                $schedules = $multiRound->roundSchedules($selected->id, $format->round);

                return [
                    'round' => $format->round,
                    'format' => $format,
                    'estimated_heat_count' => $service->computeHeatCount($selected->id, $format->round),
                    'needs_rebuild' => $schedules->isNotEmpty()
                        && $schedules->contains(fn ($schedule) => (int) $schedule->required_participants !== (int) $format->participants_per_heat),
                    'schedules' => $schedules->map(function ($schedule) use ($selected) {
                        return $this->scheduleCard($schedule, $selected);
                    })->values(),
                ];
            })->values();
        }

        return view('livewire.competition.heat.index', [
            'classes' => $classes,
            'selected' => $selected,
            'formats' => $formats,
            'rounds' => $rounds,
            'poolCount' => $poolCount,
            'isTeamHeat' => $isTeamHeat,
            'teamRound' => $teamRound,
            'availableTeams' => $availableTeams,
            'teamHeats' => $teamHeats,
            'assignableHeats' => $assignableHeats,
            'heatCountForRound' => $heatCountForRound,
            'activeTeamsCount' => $activeTeamsCount,
            'teamNeedsRebuild' => $teamNeedsRebuild,
            'formatLabel' => $selected ? \App\Support\CompetitionFormat::label($selected->format) : '-',
            'resultTypeLabel' => $selected ? CompetitionResultType::label($selected->resultType()) : '-',
            'resultDirection' => $selected ? $this->directionLabel($selected->resultType()) : '-',
            'categories' => \App\Models\CompetitionCategory::where('is_active', true)
                ->whereHas('event', fn ($q) => $q->where('event_type', 'competition')->where('status', 'active'))
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'statusLabels' => ['' => 'Semua Status', 'active' => 'Aktif', 'inactive' => 'Non Aktif'],
        ]);
    }

    private function heatClasses()
    {
        $service = app(CompetitionHeatManagerService::class);

        $query = CompetitionClass::with('competitionCategory')
            ->whereIn('format', $service::SUPPORTED_FORMATS)
            ->whereHas('event', fn ($q) => $q->where('event_type', 'competition')->where('status', 'active'));

        if ($this->categoryId !== null && $this->categoryId !== '') {
            $query->where('competition_category_id', $this->categoryId);
        }

        if ($this->statusFilter === 'active') {
            $query->where('is_active', true);
        } elseif ($this->statusFilter === 'inactive') {
            $query->where('is_active', false);
        } else {
            $query->where('is_active', true);
        }

        return $query->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    private function selectedEventId(): int
    {
        return (int) CompetitionClass::findOrFail((int) $this->selectedClassId)->event_id;
    }

    private function directionLabel(string $resultType): string
    {
        return CompetitionResultType::sortDirection($resultType) === 'asc'
            ? 'Terkecil menang (tercepat ranking terbaik)'
            : 'Terbesar menang (skor tertinggi ranking terbaik)';
    }

    private function teamRoundFor(Collection $formats): int
    {
        $rounds = $formats->pluck('round')->map(fn ($round) => (int) $round)->values();

        if ($rounds->isEmpty()) {
            return 1;
        }

        if ($rounds->contains((int) $this->teamRound)) {
            return (int) $this->teamRound;
        }

        return (int) $rounds->first();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, mixed>  $availableTeams
     * @param  \Illuminate\Support\Collection<int, mixed>  $teamHeats
     * @param  \Illuminate\Support\Collection<int, array{index: int, label: string}>  $assignableHeats
     */
    private function teamHeatData(
        CompetitionClass $class,
        Collection $schedules,
        int $round,
        Collection &$availableTeams,
        Collection &$teamHeats,
        Collection &$assignableHeats,
        ?int &$heatCountForRound,
        int &$activeTeamsCount,
    ): void {
        $service = app(CompetitionHeatManagerService::class);

        $assignableHeats = $schedules->map(fn ($schedule, $index) => [
            'index' => $index + 1,
            'label' => 'Heat '.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
        ])->values();

        $assignedTeamIds = $schedules->flatMap(fn ($schedule) => $schedule->scheduleEntries()->pluck('competition_team_id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $activeTeamsCount = CompetitionTeam::where('competition_class_id', $class->id)
            ->where('is_active', true)
            ->count();

        $availableTeams = CompetitionTeam::with([
            'kelompok',
            'players.competitionRegistration.participation.person',
            'substitutes.competitionRegistration.participation.person',
        ])
            ->where('competition_class_id', $class->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn ($team) => ! $assignedTeamIds->contains((int) $team->id))
            ->map(fn ($team) => [
                'id' => (int) $team->id,
                'name' => $team->name,
                'kelompok' => $team->kelompok?->kelompok_asal,
                'players' => $this->memberNames($team->players),
                'substitutes' => $this->memberNames($team->substitutes),
            ])
            ->values();

        $teamHeats = $schedules->map(fn ($schedule, $index) => $this->teamHeatCard($schedule, $index + 1))->values();

        $format = $service->formatForRound($class->id, $round);
        $heatCountForRound = $schedules->isNotEmpty()
            ? $schedules->count()
            : ($format !== null ? $service->computeHeatCount($class->id, $round) : null);
    }

    private function memberNames(Collection $members): Collection
    {
        return $members
            ->map(fn ($member) => $member->competitionRegistration?->participation?->person?->nama ?? null)
            ->filter()
            ->values();
    }

    private function teamHeatCard(CompetitionSchedule $schedule, int $heatIndex): array
    {
        $heatResults = \App\Models\CompetitionHeatResult::where('competition_schedule_id', $schedule->id)
            ->get()
            ->keyBy('competition_team_id');

        $entries = $schedule->scheduleEntries()
            ->with([
                'team.kelompok',
                'team.players.competitionRegistration.participation.person',
                'team.substitutes.competitionRegistration.participation.person',
            ])
            ->orderBy('order_number')
            ->orderBy('id')
            ->get()
            ->map(function ($entry) use ($heatResults) {
                $team = $entry->team;
                $heatResult = $heatResults->get($entry->competition_team_id);

                return [
                    'team_id' => $entry->competition_team_id,
                    'name' => $team?->name ?? '-',
                    'kelompok' => $team?->kelompok?->kelompok_asal,
                    'players' => $team ? $this->memberNames($team->players) : collect(),
                    'substitutes' => $team ? $this->memberNames($team->substitutes) : collect(),
                    'position' => $heatResult?->position,
                    'status' => $heatResult?->status,
                ];
            })
            ->values();

        return [
            'id' => $schedule->id,
            'heat_index' => $heatIndex,
            'heat_label' => 'Heat '.str_pad((string) $heatIndex, 2, '0', STR_PAD_LEFT),
            'sort_order' => $schedule->sort_order,
            'status' => $schedule->status,
            'required_participants' => $schedule->required_participants,
            'participants_count' => $entries->count(),
            'entries' => $entries,
        ];
    }

    private function scheduleCard($schedule, CompetitionClass $class): array
    {
        $isTeam = $class->isTeamFormat();

        $heatResults = \App\Models\CompetitionHeatResult::where('competition_schedule_id', $schedule->id)
            ->get()
            ->keyBy($isTeam ? 'competition_team_id' : 'competition_registration_id');

        $query = $schedule->scheduleEntries()
            ->with($isTeam ? 'team' : 'competitionRegistration.participation.person')
            ->orderBy('order_number')
            ->orderBy('id');

        $entries = $query->get()->map(function ($entry) use ($isTeam, $heatResults) {
            if ($isTeam) {
                $team = $entry->team;
                $name = $team?->name ?? '-';
                $number = 'Team';
                $heatResult = $heatResults->get($entry->competition_team_id);
            } else {
                $reg = $entry->competitionRegistration;
                $name = $reg?->participation?->person?->nama ?? '-';
                $number = $reg?->participation?->participant_number ?? '-';
                $heatResult = $heatResults->get($entry->competition_registration_id);
            }

            return [
                'name' => $name,
                'number' => $number,
                'position' => $heatResult?->position,
                'status' => $heatResult?->status,
            ];
        })->values();

        return [
            'id' => $schedule->id,
            'sort_order' => $schedule->sort_order,
            'status' => $schedule->status,
            'required_participants' => $schedule->required_participants,
            'participants_count' => $entries->count(),
            'entries' => $entries,
        ];
    }
}
