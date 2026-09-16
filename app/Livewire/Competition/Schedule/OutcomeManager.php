<?php

namespace App\Livewire\Competition\Schedule;

use App\Models\CompetitionHeatFormat;
use App\Models\CompetitionHeatResult;
use App\Models\CompetitionOutcome;
use App\Models\CompetitionSchedule;
use App\Models\CompetitionScheduleEntry;
use App\Models\CompetitionTeamOutcome;
use App\Services\Competition\CompetitionMultiRoundHeatService;
use App\Services\Competition\CompetitionResultService;
use App\Support\ActiveEventContext;
use App\Support\CompetitionFormat;
use App\Support\CompetitionResultType;
use App\Support\CompetitionTime;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class OutcomeManager extends Component
{
    public CompetitionSchedule $schedule;

    public array $outcomes = [];

    public array $heatResults = [];

    public array $teamOutcomes = [];

    public bool $isHeat = false;

    public bool $isTeam = false;

    public bool $isTeamHeat = false;

    public int $round = 1;

    /** Top-N per heat dari format (source-of-truth) untuk round heat ini. */
    public ?int $formatTopN = null;

    /**
     * Event milik kelas schedule ini — sumber konteks yang benar.
     *
     * Route dapat memuat schedule dari event mana pun (route model binding tidak
     * di-scope ke active event), sementara query result-service bersifat
     * event-scoped (`findOrFail`). Memakai active event session membuat halaman
     * 404 ketika schedule berasal dari event yang berbeda dari active event.
     */
    public ?int $eventId = null;

    public function mount(CompetitionSchedule $schedule): void
    {
        $context = app(ActiveEventContext::class);
        $context->requireCurrent();

        $this->schedule = $schedule->load(['competitionClass.competitionCategory', 'venue']);

        $this->eventId = $schedule->competitionClass?->event_id !== null
            ? (int) $schedule->competitionClass->event_id
            : $context->id();

        $format = $schedule->competitionClass?->format;
        $this->isTeamHeat = $format === CompetitionFormat::TEAM_HEAT;
        $this->isHeat = in_array($format, [CompetitionFormat::INDIVIDUAL_HEAT, CompetitionFormat::TEAM_HEAT], true);
        $this->isTeam = $schedule->competitionClass?->isTeamFormat() ?? false;
        $this->round = app(CompetitionMultiRoundHeatService::class)->roundOf($schedule->sort_order);
        $this->formatTopN = $this->formatTopNForRound();

        $this->loadParticipants();
    }

    private function formatTopNForRound(): ?int
    {
        if (! $this->isHeat) {
            return null;
        }

        $heatFormat = CompetitionHeatFormat::where('competition_class_id', $this->schedule->competition_class_id)
            ->where('round', $this->round)
            ->first();

        return $heatFormat?->qualifiers_per_heat !== null
            ? (int) $heatFormat->qualifiers_per_heat
            : null;
    }

    public function loadParticipants(): void
    {
        if ($this->isTeamHeat) {
            $this->loadTeamHeatParticipants();

            return;
        }

        $with = [
            'competitionRegistration.participation.person.desa',
            'competitionRegistration.participation.person.kelompok',
        ];

        if ($this->isTeam) {
            $with[] = 'team.outcome';
        } else {
            $with[] = 'competitionRegistration.outcome';

            if ($this->isHeat) {
                $with[] = 'competitionRegistration.heatResults';
            }
        }

        $entries = CompetitionScheduleEntry::with($with)
            ->where('competition_schedule_id', $this->schedule->id)
            ->orderBy('order_number')
            ->orderBy('id')
            ->get();

        if ($this->isTeam) {
            $this->teamOutcomes = $entries->map(function ($entry) {
                $team = $entry->team;
                if (! $team) {
                    return null;
                }

                $outcome = $team->outcome;

                return [
                    'team_id' => $team->id,
                    'team_name' => $team->name,
                    'position' => $outcome?->position ?? '',
                    'status' => $outcome?->status ?? '',
                    'score' => $outcome?->score ?? '',
                    'remarks' => $outcome?->remarks ?? '',
                ];
            })->filter()->values()->toArray();

            return;
        }

        if ($this->isHeat) {
            $this->heatResults = $entries->map(function ($entry) {
                $reg = $entry->competitionRegistration;
                if (! $reg) {
                    return null;
                }

                $heatResult = $reg->heatResults
                    ->firstWhere('competition_schedule_id', $this->schedule->id);

                return [
                    'heat_result_id' => $heatResult?->id,
                    'registration_id' => $reg->id,
                    'person_name' => $reg->participation?->person?->nama ?? '-',
                    'participant_number' => $reg->participation?->participant_number ?? '-',
                    'desa' => $reg->participation?->person?->desa?->desa_asal ?? '-',
                    'kelompok' => $reg->participation?->person?->kelompok?->kelompok_asal ?? '-',
                    'timeText' => CompetitionTime::format($heatResult?->score !== null ? (float) $heatResult->score : null),
                    'scoreValue' => $heatResult?->score !== null ? (string) (float) $heatResult->score : '',
                    'status' => $heatResult?->status ?? '',
                    'notes' => $heatResult?->notes ?? '',
                    'final_position' => $reg->outcome?->position ?? '',
                ];
            })->filter()->values()->toArray();

            return;
        }

        $this->outcomes = $entries->map(function ($entry) {
            $reg = $entry->competitionRegistration;
            if (! $reg) {
                return null;
            }

            return [
                'registration_id' => $reg->id,
                'person_name' => $reg->participation?->person?->nama ?? '-',
                'participant_number' => $reg->participation?->participant_number ?? '-',
                'desa' => $reg->participation?->person?->desa?->desa_asal ?? '-',
                'kelompok' => $reg->participation?->person?->kelompok?->kelompok_asal ?? '-',
                'position' => $reg->outcome?->position ?? '',
                'status' => $reg->outcome?->status ?? '',
                'score' => $reg->outcome?->score ?? '',
                'remarks' => $reg->outcome?->remarks ?? '',
            ];
        })->filter()->values()->toArray();
    }

    private function loadTeamHeatParticipants(): void
    {
        $entries = CompetitionScheduleEntry::with([
            'team.outcome',
            'team.heatResults',
        ])
            ->where('competition_schedule_id', $this->schedule->id)
            ->orderBy('order_number')
            ->orderBy('id')
            ->get();

        $this->heatResults = $entries->map(function ($entry) {
            $team = $entry->team;
            if (! $team) {
                return null;
            }

            $heatResult = $team->heatResults
                ->firstWhere('competition_schedule_id', $this->schedule->id);

            return [
                'heat_result_id' => $heatResult?->id,
                'team_id' => $team->id,
                'team_name' => $team->name,
                'timeText' => CompetitionTime::format($heatResult?->score !== null ? (float) $heatResult->score : null),
                'scoreValue' => $heatResult?->score !== null ? (string) (float) $heatResult->score : '',
                'status' => $heatResult?->status ?? '',
                'notes' => $heatResult?->notes ?? '',
                'final_position' => $team->outcome?->position ?? '',
            ];
        })->filter()->values()->toArray();
    }

    public function saveOutcomes(): void
    {
        Gate::authorize('manage-events');

        if ($this->isTeamHeat) {
            $this->saveHeatResults();

            return;
        }

        if ($this->isTeam) {
            $this->saveTeamOutcomes();

            return;
        }

        if ($this->isHeat) {
            $this->saveHeatResults();

            return;
        }

        $this->validate([
            'outcomes.*.position' => 'nullable|integer|min:0',
            'outcomes.*.status' => 'nullable|string|max:50',
            'outcomes.*.score' => 'nullable|numeric|min:0',
            'outcomes.*.remarks' => 'nullable|string|max:1000',
        ]);

        foreach ($this->outcomes as $data) {
            CompetitionOutcome::updateOrCreate(
                ['competition_registration_id' => $data['registration_id']],
                [
                    'position' => $data['position'] !== '' ? (int) $data['position'] : null,
                    'status' => $data['status'] ?: null,
                    'score' => $data['score'] !== '' ? (float) $data['score'] : null,
                    'remarks' => $data['remarks'] ?: null,
                ]
            );
        }

        session()->flash('success', 'Outcome berhasil disimpan.');
        $this->loadParticipants();
    }

    private function saveHeatResults(): void
    {
        $this->validate([
            'heatResults.*.timeText' => 'nullable|string|max:20',
            'heatResults.*.scoreValue' => 'nullable|numeric|min:0',
            'heatResults.*.status' => 'nullable|string|max:50',
            'heatResults.*.notes' => 'nullable|string|max:1000',
        ]);

        foreach ($this->heatResults as $data) {
            $score = $this->parseHeatScore($data);

            CompetitionHeatResult::updateOrCreate(
                [
                    'competition_schedule_id' => $this->schedule->id,
                    'competition_registration_id' => $data['registration_id'] ?? null,
                    'competition_team_id' => $data['team_id'] ?? null,
                ],
                [
                    'score' => $score,
                    'status' => $data['status'] ?: null,
                    'notes' => $data['notes'] ?: null,
                ]
            );
        }

        $this->reconcileHeatStatus();

        session()->flash('success', 'Hasil heat berhasil disimpan.');
        $this->loadParticipants();
    }

    /**
     * Parse nilai heat sesuai result_type kelas:
     * - time → CompetitionTime::parse (M:SS.mmm / detik)
     * - score / ranking → nilai numerik langsung
     * - win_loss → bukan heat (tidak dipakai).
     */
    private function parseHeatScore(array $data): ?float
    {
        if ($this->resultType === CompetitionResultType::TIME) {
            return CompetitionTime::parse($data['timeText'] ?? null);
        }

        $value = trim((string) ($data['scoreValue'] ?? ''));

        return $value === '' || ! is_numeric($value)
            ? null
            : round((float) $value, 2);
    }

    private function saveTeamOutcomes(): void
    {
        $this->validate([
            'teamOutcomes.*.position' => 'nullable|integer|min:0',
            'teamOutcomes.*.status' => 'nullable|string|max:50',
            'teamOutcomes.*.score' => 'nullable|numeric|min:0',
            'teamOutcomes.*.remarks' => 'nullable|string|max:1000',
        ]);

        foreach ($this->teamOutcomes as $data) {
            CompetitionTeamOutcome::updateOrCreate(
                ['competition_team_id' => $data['team_id']],
                [
                    'position' => $data['position'] !== '' ? (int) $data['position'] : null,
                    'status' => $data['status'] ?: null,
                    'score' => $data['score'] !== '' ? (float) $data['score'] : null,
                    'remarks' => $data['remarks'] ?: null,
                ]
            );
        }

        session()->flash('success', 'Hasil team berhasil disimpan.');
        $this->loadParticipants();
    }

    private function reconcileHeatStatus(): void
    {
        $this->schedule->refresh();

        if (! $this->isHeat) {
            return;
        }

        if ($this->schedule->status === 'Finished') {
            return;
        }

        if ($this->schedule->status === 'Scheduled' && $this->schedule->canAutoReady()) {
            $this->schedule->update(['status' => 'Ready']);
            $this->schedule->refresh();
        }

        if ($this->schedule->status === 'Ready' && $this->schedule->isReadyForStart()) {
            return;
        }

        if ($this->schedule->status === 'Playing' || $this->schedule->status === 'Waiting Result') {
            $competitorColumn = $this->isTeamHeat ? 'competition_team_id' : 'competition_registration_id';
            $competitorIds = $this->schedule->scheduleEntries()->pluck($competitorColumn)->filter()->values();

            if ($competitorIds->isNotEmpty()) {
                $completed = CompetitionHeatResult::where('competition_schedule_id', $this->schedule->id)
                    ->whereIn($competitorColumn, $competitorIds->all())
                    ->whereNotNull('status')
                    ->where('status', '!=', '')
                    ->count();

                if ($completed >= $competitorIds->count()) {
                    $this->schedule->update(['status' => 'Finished']);
                }
            }
        }
    }

    /**
     * Rank the schedule's participants automatically from their score (mass),
     * or rank the class's TEAMS (Team Mass).
     */
    public function autoRank(): void
    {
        Gate::authorize('manage-events');

        $eventId = $this->eventId;
        $service = app(CompetitionResultService::class);

        if ($this->isTeam) {
            $result = $service->rankTeams($eventId, $this->schedule->competition_class_id);

            if (! $result['ranked']) {
                session()->flash('error', 'Auto-ranking team tidak tersedia untuk format win/loss.');

                return;
            }

            session()->flash('success', 'Ranking team selesai: '.count($result['rows']).' team di-ranking ('.CompetitionResultType::label($result['result_type']).').');
            $this->loadParticipants();

            return;
        }

        $result = $service->rankSchedule($eventId, $this->schedule->id);

        if (! $result['ranked']) {
            session()->flash('error', 'Auto-ranking tidak tersedia untuk format win/loss.');

            return;
        }

        session()->flash('success', 'Ranking otomatis selesai: '.count($result['rows']).' peserta di-ranking ('.CompetitionResultType::label($result['result_type']).').');
        $this->loadParticipants();
    }

    /**
     * Aggregate all heats of the class into the final ranking + podium.
     */
    public function aggregateFinal(): void
    {
        Gate::authorize('manage-events');

        $result = app(CompetitionResultService::class)->aggregateHeatResults($this->eventId, $this->schedule->competition_class_id);

        if (! $result['ranked']) {
            session()->flash('error', 'Aggregasi final tidak tersedia untuk format win/loss.');

            return;
        }

        session()->flash('success', 'Final ranking berhasil dibuat: '.count($result['rows']).' peserta ('.CompetitionResultType::label($result['result_type']).').');
        $this->loadParticipants();
    }

    /**
     * Ranking per-heat (individual ATAU team) — multi-round heat.
     */
    public function rankHeat(): void
    {
        Gate::authorize('manage-events');

        $result = app(CompetitionMultiRoundHeatService::class)->rankHeat($this->eventId, $this->schedule->id);

        if (! $result['ranked']) {
            session()->flash('error', 'Ranking heat tidak tersedia untuk format win/loss.');

            return;
        }

        $this->reconcileHeatStatus();

        session()->flash('success', 'Ranking heat selesai: '.count($result['rows']).' kompetitor di-ranking ('.CompetitionResultType::label($result['result_type']).').');
        $this->loadParticipants();
    }

    /**
     * Qualification PER-HEAT dengan Top-N dari konfigurasi format (source-of-truth).
     *
     * Menggantikan tombol advance hardcoded sebelumnya: operator TIDAK memilih
     * Top-N manual — nilai diambil dari `competition_heat_formats.qualifiers_per_heat`
     * untuk round heat ini, sehingga UI tidak bisa mengubah semantic qualification.
     */
    public function advanceHeat(): void
    {
        Gate::authorize('manage-events');

        if ($this->formatTopN === null) {
            session()->flash('error', 'Belum ada format heat (Top-N) untuk round ini. Buat format di Heat Manager terlebih dahulu.');

            return;
        }

        $this->advanceHeatRound($this->formatTopN);
    }

    public function advanceHeatRound(int $topN): void
    {
        Gate::authorize('manage-events');

        $result = app(CompetitionMultiRoundHeatService::class)
            ->qualifyHeat($this->eventId, $this->schedule->id, $topN);

        if (! $result['qualified']) {
            $message = match ($result['reason'] ?? null) {
                'heat_incomplete' => 'Heat ini belum selesai: masih ada kompetitor yang belum punya hasil/status. Lengkapi semua hasil & status heat ini terlebih dahulu.',
                'win_loss' => 'Qualification tidak tersedia untuk format win/loss.',
                default => 'Qualification gagal ('.($result['reason'] ?? 'unknown').').',
            };
            session()->flash('error', $message);

            return;
        }

        session()->flash('success', "{$result['qualified_count']} peserta berhasil lolos dari heat ini (top {$topN}).");
        $this->loadParticipants();
    }

    /**
     * Finalisasi podium Juara 1/2/3 dari hasil round final (multi-round heat).
     */
    public function finalizeHeatFinal(): void
    {
        Gate::authorize('manage-events');

        $service = app(CompetitionMultiRoundHeatService::class);
        $finalRound = $this->round;

        while (! $service->isFinalRound($this->schedule->competition_class_id, $finalRound)) {
            $finalRound = $service->nextRound($this->schedule->competition_class_id, $finalRound);
        }

        $result = $service->finalizePodium($this->eventId, $this->schedule->competition_class_id, $finalRound);

        if (! $result['finalized']) {
            session()->flash('error', 'Finalisasi podium tidak tersedia untuk format win/loss.');

            return;
        }

        session()->flash('success', 'Podium final selesai: '.count($result['podium']).' Juara (round '.$result['round'].').');
        $this->loadParticipants();
    }

    public function getResultTypeProperty(): string
    {
        return $this->schedule->competitionClass?->resultType() ?? CompetitionResultType::RANKING;
    }

    public function getResultDirectionProperty(): string
    {
        return CompetitionResultType::sortDirection($this->resultType);
    }

    public function getCanAutoRankProperty(): bool
    {
        $format = $this->schedule->competitionClass?->format;

        return CompetitionResultType::isRanked($this->resultType)
            && ! $this->isHeat
            && ! in_array($format, [CompetitionFormat::INDIVIDUAL_VS_INDIVIDUAL, CompetitionFormat::TEAM_VS_TEAM], true);
    }

    public function getPodiumProperty(): array
    {
        $eventId = $this->eventId;
        $service = app(CompetitionResultService::class);
        $format = $this->schedule->competitionClass?->format;
        $winnerCount = $this->schedule->competitionClass?->winner_count ?? 3;

        if ($this->isTeam) {
            return $service->podiumForTeams($eventId, $this->schedule->competition_class_id, $winnerCount);
        }

        // Heat & vs-format (bracket) memakai podium final kelas; mass memakai podium schedule.
        if ($this->isHeat || in_array($format, [CompetitionFormat::INDIVIDUAL_VS_INDIVIDUAL, CompetitionFormat::TEAM_VS_TEAM], true)) {
            return $service->podiumForClass($eventId, $this->schedule->competition_class_id, $winnerCount);
        }

        return $service->podiumForSchedule($eventId, $this->schedule->id, $winnerCount);
    }

    public function render()
    {
        return view('livewire.competition.schedule.outcome-manager', [
            'className' => $this->schedule->competitionClass?->name ?? '-',
            'categoryName' => $this->schedule->competitionClass?->competitionCategory?->name ?? '-',
            'venueName' => $this->schedule->venue?->name ?? '-',
            'resultType' => $this->resultType,
            'resultDirection' => $this->resultDirection,
            'canAutoRank' => $this->canAutoRank,
            'podium' => $this->podium,
            'isHeat' => $this->isHeat,
            'isTeamHeat' => $this->isTeamHeat,
            'heatResults' => $this->heatResults,
            'round' => $this->round,
            'isTeam' => $this->isTeam,
            'teamOutcomes' => $this->teamOutcomes,
        ]);
    }
}
