<?php

namespace App\Services\Competition;

use App\Models\CompetitionClass;
use App\Models\CompetitionHeatFormat;
use App\Models\CompetitionHeatQualifier;
use App\Models\CompetitionHeatResult;
use App\Models\CompetitionOutcome;
use App\Models\CompetitionSchedule;
use App\Models\CompetitionScheduleEntry;
use App\Models\CompetitionTeamOutcome;
use App\Support\CompetitionResultType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Multi-round Heat engine (Sprint R4H-final).
 *
 * Babak penyisihan untuk Individual Heat & Team Heat:
 * - Banyak babak (round), banyak heat per babak, >2 kompetitor per heat.
 * - Round dikodekan lewat `competition_schedules.sort_order` = round*100 + heatIndex.
 *   Legacy heat (sort_order < 100) diperlakukan sebagai round 1.
 * - Setiap heat di-ranking per-heat (`rankHeat`) → `competition_heat_results.position`.
 * - Qualification bersifat PER-HEAT (`qualifyHeat`): satu heat yang selesai bisa langsung
 *   menentukan top-N qualified tanpa menunggu sibling heat. Round berikutnya dibangun
 *   (`advanceRound`/`generateNextRound`) dari POOL qualified (heat yang sudah selesai) —
 *   tidak lagi mensyaratkan SEMUA heat selesai.
 * - `advanceRound` mengisi heat babak berikutnya dari pool qualified (isi sampai
 *   `required_participants`). Heat berikutnya menjadi `Ready` (tidak pernah auto-`Playing`;
 *   R4H ban tetap).
 * - `finalizePodium` meng-agregat hasil round final → Juara 1/2/3.
 *
 * Individual → `competition_outcomes`; Team → `competition_team_outcomes`.
 * Sorting dipakai dari `CompetitionResultType::sortDirection()` (score desc, time/ranking asc) —
 * tidak menduplikasi logika ranking; status excluded dipakai dari
 * `CompetitionResultService::EXCLUDED_STATUSES`.
 */
class CompetitionMultiRoundHeatService
{
    private const EXCLUDED_STATUSES = CompetitionResultService::EXCLUDED_STATUSES;

    /**
     * Round number untuk sebuah sort_order (konvensi round*100 + heatIndex).
     */
    public function roundOf(?int $sortOrder): int
    {
        return max(1, (int) intdiv((int) ($sortOrder ?? 0), 100));
    }

    /**
     * Daftar schedule (heat) sebuah kelas pada round tertentu, urut sort_order.
     *
     * Round 1 juga mencakup legacy heat (sort_order < 100 atau null) — konsisten
     * dengan `roundOf()` yang memperlakukan sort_order kecil sebagai round 1.
     */
    public function roundSchedules(int $classId, int $round): Collection
    {
        $query = CompetitionSchedule::where('competition_class_id', $classId);

        if ($round <= 1) {
            $query->where(function ($q) {
                $q->whereNull('sort_order')->orWhere('sort_order', '<', 200);
            });
        } else {
            $query->where('sort_order', '>=', $round * 100)
                ->where('sort_order', '<', ($round + 1) * 100);
        }

        return $query->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * Semua round yang terpakai kelas (dari sort_order schedules), urut ascending.
     */
    public function rounds(int $classId): Collection
    {
        return CompetitionSchedule::where('competition_class_id', $classId)
            ->whereNotNull('sort_order')
            ->orderBy('sort_order')
            ->pluck('sort_order')
            ->map(fn ($sortOrder) => $this->roundOf($sortOrder))
            ->unique()
            ->values();
    }

    /**
     * Round berikutnya yang punya schedule; null bila sudah final (round terakhir).
     */
    public function nextRound(int $classId, int $round): ?int
    {
        return $this->rounds($classId)
            ->filter(fn ($candidate) => $candidate > $round)
            ->min();
    }

    public function isFinalRound(int $classId, int $round): bool
    {
        return $this->nextRound($classId, $round) === null;
    }

    /**
     * Ranking satu heat (individual ATAU team) dari `competition_heat_results`.
     *
     * @return array{
     *     ranked: bool,
     *     result_type: string,
     *     direction: string,
     *     rows: array<int, array{competitor_id: int, score: float, position: ?int, excluded: bool}>,
     *     reason?: string,
     * }
     */
    public function rankHeat(int $eventId, int $scheduleId): array
    {
        return DB::transaction(function () use ($eventId, $scheduleId) {
            $schedule = $this->scheduleInEvent($eventId, $scheduleId);
            $class = $schedule->competitionClass;
            $resultType = $class->resultType();
            $isTeam = $class->isTeamFormat();

            if (! CompetitionResultType::isRanked($resultType)) {
                return [
                    'ranked' => false,
                    'result_type' => $resultType,
                    'direction' => 'asc',
                    'rows' => [],
                    'reason' => 'win_loss',
                ];
            }

            $direction = CompetitionResultType::sortDirection($resultType);

            $query = CompetitionHeatResult::where('competition_schedule_id', $schedule->id);

            if ($isTeam) {
                $query->whereNotNull('competition_team_id');
            } else {
                $query->whereNotNull('competition_registration_id');
            }

            $heatResults = $query->with($isTeam ? 'competitionTeam' : 'competitionRegistration')->get();

            $scored = collect();

            foreach ($heatResults as $heatResult) {
                $competitor = $isTeam ? $heatResult->competitionTeam : $heatResult->competitionRegistration;

                if ($competitor === null) {
                    continue;
                }

                if ($this->isExcluded($heatResult->status) || $heatResult->score === null) {
                    if ($heatResult->position !== null) {
                        $heatResult->update(['position' => null]);
                    }

                    continue;
                }

                $scored->push([
                    'heat_result' => $heatResult,
                    'competitor_id' => $isTeam ? (int) $heatResult->competition_team_id : (int) $heatResult->competition_registration_id,
                    'score' => (float) $heatResult->score,
                ]);
            }

            $sorted = $direction === 'asc'
                ? $scored->sortBy('score')->values()
                : $scored->sortByDesc('score')->values();

            $rows = $this->assignPositions($sorted);

            return [
                'ranked' => true,
                'result_type' => $resultType,
                'direction' => $direction,
                'rows' => $rows,
            ];
        });
    }

    /**
     * Qualification PER-HEAT: tentukan & simpan top-N sebuah heat yang SUDAH selesai.
     *
     * Tidak menunggu sibling heat, tidak membuat round berikutnya, tidak membuat
     * schedule baru, dan tidak mengisi schedule round berikutnya. Cukup memastikan
     * heat ini lengkap (`isHeatCompleteForAdvancement`), lalu me-rank-nya
     * (`rankHeat`) dan mengambil top-N.
     *
     * Cap ketat: maksimal `topN` kompetitor per heat yang diambil, dalam urutan
     * ranking (position asc). Kompetitor yang seri (position sama) DI ATAS batas
     * slot tidak ikut lolos — status `Lolos` tidak boleh mengabaikan batas Top-N.
     *
     * Idempotent: memanggil dua kali tidak menggandakan data (qualifier adalah
     * derive dari `competition_heat_results.position` + `status`).
     *
     * @return array{
     *     qualified: bool,
     *     reason?: string,  // heat_incomplete | win_loss
     *     schedule_id: int,
     *     round: int,
     *     top_n: int,
     *     qualifiers: list<int>,
     *     qualified_count: int,
     * }
     */
    public function effectiveTopNForHeat(int $classId, int $round, int $heatIndex, int $fallbackTopN): int
    {
        $override = CompetitionHeatQualifier::where('competition_class_id', $classId)
            ->where('round', $round)
            ->where('heat_index', $heatIndex)
            ->first();

        if ($override !== null) {
            return (int) $override->qualifiers_per_heat;
        }

        $formatTopN = CompetitionHeatFormat::where('competition_class_id', $classId)
            ->where('round', $round)
            ->value('qualifiers_per_heat');

        if ($formatTopN !== null) {
            return (int) $formatTopN;
        }

        return $fallbackTopN;
    }

    public function topNForSchedule(CompetitionSchedule $schedule, int $fallbackTopN): int
    {
        $round = $this->roundOf($schedule->sort_order);
        $heatIndex = $this->heatIndexOf($schedule, $round);

        return $this->effectiveTopNForHeat((int) $schedule->competition_class_id, $round, $heatIndex, $fallbackTopN);
    }

    public function heatIndexOf(CompetitionSchedule $schedule, ?int $round = null): int
    {
        $round = $round ?? $this->roundOf($schedule->sort_order);
        $sortOrder = (int) ($schedule->sort_order ?? 0);

        if ($sortOrder >= 100) {
            $idx = $sortOrder - ($round * 100);
            if ($idx >= 1) {
                return $idx;
            }
        }

        $schedules = $this->roundSchedules((int) $schedule->competition_class_id, $round)
            ->values();

        foreach ($schedules as $i => $s) {
            if ((int) $s->id === (int) $schedule->id) {
                return $i + 1;
            }
        }

        return 1;
    }

    public function qualifyHeat(int $eventId, int $scheduleId, int $topN): array
    {
        return DB::transaction(function () use ($eventId, $scheduleId, $topN) {
            $schedule = $this->scheduleInEvent($eventId, $scheduleId);
            $class = $schedule->competitionClass;
            $isTeam = $class->isTeamFormat();
            $round = $this->roundOf($schedule->sort_order);

            if (! CompetitionResultType::isRanked($class->resultType())) {
                return ['qualified' => false, 'reason' => 'win_loss', 'schedule_id' => $scheduleId, 'round' => $round, 'top_n' => $topN, 'qualifiers' => [], 'qualified_count' => 0];
            }

            if (! $this->isHeatCompleteForAdvancement($schedule, $isTeam)) {
                return ['qualified' => false, 'reason' => 'heat_incomplete', 'schedule_id' => $scheduleId, 'round' => $round, 'top_n' => $topN, 'qualifiers' => [], 'qualified_count' => 0];
            }

            $effectiveTopN = $this->topNForSchedule($schedule, $topN);

            $ranked = $this->rankHeat($eventId, $schedule->id);

            $qualifiers = collect($ranked['rows'])
                ->filter(fn ($row) => $row['position'] !== null && $row['position'] > 0 && ! $row['excluded'])
                ->sortBy('position')
                ->take($effectiveTopN)
                ->map(fn ($row) => (int) $row['competitor_id'])
                ->values()
                ->all();

            return [
                'qualified' => true,
                'schedule_id' => $scheduleId,
                'round' => $round,
                'top_n' => $effectiveTopN,
                'qualifiers' => $qualifiers,
                'qualified_count' => count($qualifiers),
            ];
        });
    }

    /**
     * Qualified pool sebuah round = union top-N dari setiap heat yang SUDAH selesai.
     *
     * Heat yang belum selesai di-SKIP (bukan di-reject). Ini adalah pengganti guard
     * lama `not_all_finished` (semua heat selesai) — pool dihitung per-heat.
     *
     * @return array{
     *     qualifiers: list<int>,
     *     qualified_count: int,
     *     completed_heats: int,
     *     total_heats: int,
     *     heats: array<int, array{schedule_id: int, advanced: int, complete: bool}>,
     * }
     */
    public function qualifiedPool(int $eventId, int $classId, int $round, int $topN): array
    {
        return DB::transaction(function () use ($eventId, $classId, $round, $topN) {
            $class = $this->classInEvent($eventId, $classId);
            $isTeam = $class->isTeamFormat();
            $schedules = $this->roundSchedules($class->id, $round);

            $qualifiers = [];
            $completed = 0;
            $heats = [];

            foreach ($schedules as $index => $schedule) {
                $heatIndex = $index + 1;
                $effectiveTopN = $this->effectiveTopNForHeat($class->id, $round, $heatIndex, $topN);
                if (! $this->isHeatCompleteForAdvancement($schedule, $isTeam)) {
                    $heats[] = ['schedule_id' => (int) $schedule->id, 'advanced' => 0, 'complete' => false, 'top_n' => $effectiveTopN];

                    continue;
                }

                $completed++;

                $ranked = $this->rankHeat($eventId, $schedule->id);

                $heatQualifiers = collect($ranked['rows'])
                    ->filter(fn ($row) => $row['position'] !== null && $row['position'] > 0 && ! $row['excluded'])
                    ->sortBy('position')
                    ->take($effectiveTopN)
                    ->map(fn ($row) => (int) $row['competitor_id'])
                    ->values();

                $heats[] = ['schedule_id' => (int) $schedule->id, 'advanced' => $heatQualifiers->count(), 'complete' => true, 'top_n' => $effectiveTopN];

                foreach ($heatQualifiers as $competitorId) {
                    $qualifiers[] = $competitorId;
                }
            }

            return [
                'qualifiers' => $qualifiers,
                'qualified_count' => count($qualifiers),
                'completed_heats' => $completed,
                'total_heats' => $schedules->count(),
                'heats' => $heats,
            ];
        });
    }

    /**
     * Round-level advancement: isi heat babak berikutnya dari pool qualified.
     *
     * Mengkonsumsi `qualifiedPool` (top-N per heat yang SUDAH selesai) — heat yang
     * belum selesai di-skip, TIDAK di-reject. Guard `not_all_finished` (semua heat
     * selesai) dihapus. Guard `no_next_round` / `no_next_heats` dipertahankan untuk
     * kompatibilitas flow Schedule legacy (heat babak berikutnya harus sudah ada).
     *
     * @return array{
     *     advanced: bool,
     *     reason?: string,
     *     round: int,
     *     next_round: ?int,
     *     top_n: int,
     *     qualifiers: int,
     *     assigned: int,
     *     heats: array<int, array{schedule_id: int, advanced: int, complete: bool}>,
     * }
     */
    public function advanceRound(int $eventId, int $classId, int $round, int $topN): array
    {
        return DB::transaction(function () use ($eventId, $classId, $round, $topN) {
            $class = $this->classInEvent($eventId, $classId);
            $isTeam = $class->isTeamFormat();

            $schedules = $this->roundSchedules($class->id, $round);

            if ($schedules->isEmpty()) {
                return ['advanced' => false, 'reason' => 'no_heats', 'round' => $round, 'next_round' => null, 'top_n' => $topN, 'qualifiers' => 0, 'assigned' => 0, 'heats' => []];
            }

            $nextRound = $this->nextRound($class->id, $round);
            if ($nextRound === null) {
                return ['advanced' => false, 'reason' => 'no_next_round', 'round' => $round, 'next_round' => null, 'top_n' => $topN, 'qualifiers' => 0, 'assigned' => 0, 'heats' => []];
            }

            $nextSchedules = $this->roundSchedules($class->id, $nextRound)
                ->sortBy(fn ($schedule) => (int) $schedule->sort_order)
                ->values();

            if ($nextSchedules->isEmpty()) {
                return ['advanced' => false, 'reason' => 'no_next_heats', 'round' => $round, 'next_round' => $nextRound, 'top_n' => $topN, 'qualifiers' => 0, 'assigned' => 0, 'heats' => []];
            }

            $pool = $this->qualifiedPool($eventId, $class->id, $round, $topN);
            $qualifiers = $pool['qualifiers'];

            if ($qualifiers === []) {
                return ['advanced' => false, 'reason' => 'no_qualifiers', 'round' => $round, 'next_round' => $nextRound, 'top_n' => $topN, 'qualifiers' => 0, 'assigned' => 0, 'heats' => $pool['heats']];
            }

            $assigned = 0;
            $positionIndex = 0;

            foreach ($nextSchedules as $nextSchedule) {
                if ($positionIndex >= count($qualifiers)) {
                    break;
                }

                $existing = $nextSchedule->scheduleEntries()
                    ->get()
                    ->pluck($isTeam ? 'competition_team_id' : 'competition_registration_id')
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->toArray();

                $maxOrder = (int) ($nextSchedule->scheduleEntries()->max('order_number') ?? 0);
                $capacity = max(1, (int) ($nextSchedule->required_participants ?? 1));

                while ($positionIndex < count($qualifiers) && count($existing) < $capacity) {
                    $competitorId = $qualifiers[$positionIndex];
                    $positionIndex++;

                    if (in_array((int) $competitorId, $existing, true)) {
                        continue;
                    }

                    $existing[] = (int) $competitorId;
                    $maxOrder++;

                    CompetitionScheduleEntry::create([
                        'competition_schedule_id' => $nextSchedule->id,
                        $isTeam ? 'competition_team_id' : 'competition_registration_id' => $competitorId,
                        'order_number' => $maxOrder,
                    ]);

                    $assigned++;
                }
            }

            // Next round heat menjadi Ready setelah terisi (tidak pernah Playing — R4H ban).
            foreach ($nextSchedules as $nextSchedule) {
                if ($nextSchedule->status === 'Scheduled' && $nextSchedule->canAutoReady()) {
                    $nextSchedule->update(['status' => 'Ready']);
                }
            }

            return [
                'advanced' => $assigned > 0,
                'round' => $round,
                'next_round' => $nextRound,
                'top_n' => $topN,
                'qualifiers' => count($qualifiers),
                'assigned' => $assigned,
                'heats' => $pool['heats'],
            ];
        });
    }

    /**
     * Agregasi hasil SEMUA heat satu babak menjadi ranking persisten.
     *
     * Individual → `competition_outcomes`; Team → `competition_team_outcomes`.
     *
     * @return array{
     *     ranked: bool,
     *     result_type: string,
     *     direction: string,
     *     rows: array<int, array{competitor_id: int, aggregate: float, heats: int, position: int}>,
     *     reason?: string,
     * }
     */
    public function aggregateRoundResults(int $eventId, int $classId, int $round): array
    {
        return DB::transaction(function () use ($eventId, $classId, $round) {
            $class = $this->classInEvent($eventId, $classId);
            $resultType = $class->resultType();
            $isTeam = $class->isTeamFormat();

            if (! CompetitionResultType::isRanked($resultType)) {
                return [
                    'ranked' => false,
                    'result_type' => $resultType,
                    'direction' => 'asc',
                    'rows' => [],
                    'reason' => 'win_loss',
                ];
            }

            $direction = CompetitionResultType::sortDirection($resultType);

            $scheduleIds = $this->roundSchedules($class->id, $round)->pluck('id');

            $heatResults = CompetitionHeatResult::with($isTeam ? 'competitionTeam' : 'competitionRegistration')
                ->whereIn('competition_schedule_id', $scheduleIds)
                ->whereNotNull($isTeam ? 'competition_team_id' : 'competition_registration_id')
                ->get()
                ->filter(function ($heatResult) use ($class, $isTeam) {
                    $competitor = $isTeam ? $heatResult->competitionTeam : $heatResult->competitionRegistration;

                    return $competitor !== null
                        && (int) $competitor->competition_class_id === (int) $class->id
                        && ! $this->isExcluded($heatResult->status)
                        && $heatResult->score !== null;
                });

            $key = $isTeam ? 'competition_team_id' : 'competition_registration_id';
            $aggregates = [];

            foreach ($heatResults->groupBy($key) as $competitorId => $group) {
                $scores = $group->map(fn ($heatResult) => (float) $heatResult->score);
                $aggregates[] = [
                    'competitor_id' => (int) $competitorId,
                    'aggregate' => $direction === 'asc' ? $scores->min() : $scores->max(),
                    'heats' => $group->count(),
                ];
            }

            $sorted = collect($aggregates)
                ->sortBy('aggregate', SORT_REGULAR, $direction === 'desc')
                ->values();

            $rows = $this->assignAggregatePositions($sorted);

            foreach ($rows as $row) {
                if ($isTeam) {
                    CompetitionTeamOutcome::updateOrCreate(
                        ['competition_team_id' => $row['competitor_id']],
                        [
                            'score' => $row['aggregate'],
                            'position' => $row['position'],
                            'remarks' => 'Round '.$round.' ('.$row['heats'].' heat)',
                        ],
                    );
                } else {
                    CompetitionOutcome::updateOrCreate(
                        ['competition_registration_id' => $row['competitor_id']],
                        [
                            'score' => $row['aggregate'],
                            'position' => $row['position'],
                            'remarks' => 'Round '.$round.' ('.$row['heats'].' heat)',
                        ],
                    );
                }
            }

            return [
                'ranked' => true,
                'result_type' => $resultType,
                'direction' => $direction,
                'rows' => $rows,
            ];
        });
    }

    /**
     * Finalisasi round final → agregat + podium Juara 1/2/3.
     *
     * @return array{
     *     finalized: bool,
     *     reason?: string,
     *     round: int,
     *     podium: array<int, array{position: int, name: string, score: ?float}>,
     * }
     */
    public function finalizePodium(int $eventId, int $classId, int $round): array
    {
        $aggregate = $this->aggregateRoundResults($eventId, $classId, $round);

        if (! $aggregate['ranked']) {
            return ['finalized' => false, 'reason' => 'win_loss', 'round' => $round, 'podium' => []];
        }

        $class = $this->classInEvent($eventId, $classId);

        $podium = $this->podiumForClass($eventId, $classId);

        return ['finalized' => true, 'round' => $round, 'podium' => $podium];
    }

    /**
     * @return array<int, array{position: int, name: string, score: ?float}>
     */
    private function podiumForClass(int $eventId, int $classId): array
    {
        $class = CompetitionClass::where('event_id', $eventId)->findOrFail($classId);

        if ($class->isTeamFormat()) {
            return CompetitionTeamOutcome::with('team')
                ->whereHas('team', fn ($query) => $query->where('competition_class_id', $class->id))
                ->whereNotNull('position')
                ->where('position', '>', 0)
                ->orderBy('position')
                ->take(3)
                ->get()
                ->map(fn ($outcome) => [
                    'position' => (int) $outcome->position,
                    'name' => $outcome->team?->name ?? '-',
                    'score' => $outcome->score !== null ? (float) $outcome->score : null,
                ])
                ->values()
                ->all();
        }

        return CompetitionOutcome::with('competitionRegistration.participation.person')
            ->whereHas('competitionRegistration', fn ($query) => $query->where('competition_class_id', $class->id))
            ->whereNotNull('position')
            ->where('position', '>', 0)
            ->orderBy('position')
            ->take(3)
            ->get()
            ->map(fn ($outcome) => [
                'position' => (int) $outcome->position,
                'name' => $outcome->competitionRegistration?->participation?->person?->nama ?? '-',
                'score' => $outcome->score !== null ? (float) $outcome->score : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Apakah SEMUA heat pada sebuah round sudah "selesai untuk advancement"
     * (predicate `isHeatCompleteForAdvancement` untuk tiap heat round).
     *
     * Dipertahankan untuk kompatibilitas backward. Sejak qualification menjadi
     * PER-HEAT (lihat `qualifyHeat` / `qualifiedPool`), round-level generation
     * TIDAK lagi mensyaratkan semua heat selesai; method ini tidak lagi dipakai
     * oleh `advanceRound` / `generateNextRound`.
     */
    public function isRoundCompleteForAdvancement(int $classId, int $round, bool $isTeam): bool
    {
        $schedules = $this->roundSchedules($classId, $round);

        if ($schedules->isEmpty()) {
            return false;
        }

        return $schedules->every(fn ($schedule) => $this->isHeatCompleteForAdvancement($schedule, $isTeam));
    }

    /**
     * Sebuah heat dianggap "selesai untuk advancement" bila:
     *
     * 1. lifecycle schedule sudah `Finished` (jalur `finishMatch`/official
     *    submission — signal lama yang tetap valid), ATAU
     * 2. SEMUA kompetitor yang dijadwalkan sudah punya hasil heat yang lengkap:
     *    `competition_heat_results.status` TERISI (mis. `Lolos`/`Gugur`/
     *    `Tidak Hadir`/`Diskualifikasi`/DNF/DNS/DSQ).
     *
     * Kontrak terdokumentasi: "all competitors in the heat must have a completed
     * result/status before advancement." FIX UAT (2026-08-15): sebelumnya predicate
     * HANYA melihat `status` lifecycle schedule — operator memasukkan hasil
     * per-kompetitor lewat OutcomeManager (`Simpan Hasil Heat` → semua `Lolos`),
     * me-rank, lalu advance, tetapi heat masih berstatus `Ready`/`Playing`/
     * `Waiting Result` (bukan `Finished`) sehingga `advanceRound` menolak dengan
     * `not_all_finished` walau semua kompetitor sudah selesai. Sekarang status
     * hasil per-kompetitor yang terisi juga diakui sebagai "completed".
     *
     * Lifecycle guard (`LEGAL_TRANSITIONS`/`canTransitionTo`) TIDAK diubah;
     * schedule tetap bisa menuju `Finished` via `finishMatch`/official. Predicate
     * ini hanya menentukan readiness ADVANCEMENT, bukan mengganti lifecycle.
     */
    private function isHeatCompleteForAdvancement(CompetitionSchedule $schedule, bool $isTeam): bool
    {
        if ($schedule->status === 'Finished') {
            return true;
        }

        $competitorColumn = $isTeam ? 'competition_team_id' : 'competition_registration_id';

        $competitorIds = $schedule->scheduleEntries()
            ->pluck($competitorColumn)
            ->filter()
            ->values()
            ->all();

        if ($competitorIds === []) {
            return false;
        }

        $completed = CompetitionHeatResult::where('competition_schedule_id', $schedule->id)
            ->whereIn($competitorColumn, $competitorIds)
            ->where('status', '!=', '')
            ->whereNotNull('status')
            ->count();

        return $completed === count($competitorIds);
    }

    /**
     * @return array<int, array{competitor_id: int, score: float, position: ?int, excluded: bool}>
     */
    private function assignPositions(Collection $sorted): array
    {
        $rows = [];
        $previous = null;

        foreach ($sorted as $index => $item) {
            $current = $previous !== null && (float) $item['score'] === (float) $previous['score']
                ? $previous['position']
                : $index + 1;

            $item['heat_result']->update(['position' => $current]);

            $rows[] = [
                'competitor_id' => $item['competitor_id'],
                'score' => $item['score'],
                'position' => $current,
                'excluded' => false,
            ];

            $previous = ['score' => $item['score'], 'position' => $current];
        }

        return $rows;
    }

    /**
     * @return array<int, array{competitor_id: int, aggregate: float, heats: int, position: int}>
     */
    private function assignAggregatePositions(Collection $sorted): array
    {
        $rows = [];
        $previous = null;

        foreach ($sorted as $index => $item) {
            $current = $previous !== null && (float) $item['aggregate'] === (float) $previous['aggregate']
                ? $previous['position']
                : $index + 1;

            $rows[] = [
                'competitor_id' => $item['competitor_id'],
                'aggregate' => $item['aggregate'],
                'heats' => $item['heats'],
                'position' => $current,
            ];

            $previous = ['aggregate' => $item['aggregate'], 'position' => $current];
        }

        return $rows;
    }

    private function isExcluded(?string $status): bool
    {
        return in_array($status, self::EXCLUDED_STATUSES, true);
    }

    private function scheduleInEvent(int $eventId, int $scheduleId): CompetitionSchedule
    {
        return CompetitionSchedule::with('competitionClass')
            ->whereHas('competitionClass', fn ($query) => $query->where('event_id', $eventId))
            ->findOrFail($scheduleId);
    }

    private function classInEvent(int $eventId, int $classId): CompetitionClass
    {
        return CompetitionClass::where('event_id', $eventId)->findOrFail($classId);
    }
}
