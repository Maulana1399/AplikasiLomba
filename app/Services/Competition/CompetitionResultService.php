<?php

namespace App\Services\Competition;

use App\Models\CompetitionClass;
use App\Models\CompetitionHeatResult;
use App\Models\CompetitionOutcome;
use App\Models\CompetitionSchedule;
use App\Models\CompetitionTeamOutcome;
use App\Support\CompetitionResultType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Competition Result Engine (Sprint R1).
 *
 * Auto-ranking for score-based results (Individual Mass / Individual Heat and
 * any class whose result_type is score/time/ranking):
 *
 *   Registration → Mass Schedule → Participants → Input Result (score)
 *   → sort by result_type direction → assign position (competition ranking
 *     with ties: 1,1,3) → podium 1st/2nd/3rd.
 *
 * Excluded statuses (Diskualifikasi / Tidak Hadir / Gugur / DNF/DNS/DSQ) and
 * participants without a score are NOT ranked. Event-scoped: the schedule must
 * belong to the caller's event.
 */
class CompetitionResultService
{
    public const EXCLUDED_STATUSES = [
        'Diskualifikasi',
        'Tidak Hadir',
        'Gugur',
        'DNF',
        'DNS',
        'DSQ',
    ];

    /**
     * Rank a schedule's participants by score and persist positions.
     *
     * @return array{
     *     ranked: bool,
     *     result_type: string,
     *     direction: string,
     *     rows: array<int, array{registration_id: int, score: float, position: ?int, excluded: bool}>,
     *     reason?: string,
     * }
     */
    public function rankSchedule(int $eventId, int $scheduleId): array
    {
        return DB::transaction(function () use ($eventId, $scheduleId) {
            $schedule = $this->scheduleInEvent($eventId, $scheduleId);
            $class = $schedule->competitionClass;
            $resultType = $class->resultType();

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

            $scored = collect();
            foreach ($schedule->scheduleEntries as $entry) {
                $registration = $entry->competitionRegistration;
                $outcome = $registration?->outcome;

                if ($outcome === null) {
                    continue;
                }

                if ($this->isExcluded($outcome) || $outcome->score === null) {
                    if ($outcome->position !== null) {
                        $outcome->update(['position' => null]);
                    }

                    continue;
                }

                $scored->push([
                    'outcome' => $outcome,
                    'registration_id' => $registration->id,
                    'score' => (float) $outcome->score,
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
     * Top-N (podium) for a schedule, ordered by stored position.
     *
     * @return array<int, array{position: int, person_name: string, participant_number: string, score: ?float}>
     */
    public function podiumForSchedule(int $eventId, int $scheduleId, int $limit = 3): array
    {
        $schedule = $this->scheduleInEvent($eventId, $scheduleId);

        $registrationIds = $schedule->scheduleEntries()->pluck('competition_registration_id');

        return CompetitionOutcome::with('competitionRegistration.participation.person')
            ->whereIn('competition_registration_id', $registrationIds)
            ->whereNotNull('position')
            ->where('position', '>', 0)
            ->orderBy('position')
            ->take($limit)
            ->get()
            ->map(fn ($outcome) => [
                'position' => (int) $outcome->position,
                'person_name' => $outcome->competitionRegistration?->participation?->person?->nama ?? '-',
                'participant_number' => $outcome->competitionRegistration?->participation?->participant_number ?? '-',
                'score' => $outcome->score !== null ? (float) $outcome->score : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Aggregate per-heat results across ALL heats (schedules) of a class into a
     * final ranking, persisted to `competition_outcomes` (unique per registration).
     *
     * Aggregate rule by result type:
     * - time / ranking → best (minimum) across heats.
     * - score → best (maximum) across heats.
     *
     * Final position uses competition ranking (ties: 1,1,3).
     *
     * @return array{
     *     ranked: bool,
     *     result_type: string,
     *     direction: string,
     *     rows: array<int, array{registration_id: int, aggregate: float, heats: int, position: int}>,
     *     reason?: string,
     * }
     */
    public function aggregateHeatResults(int $eventId, int $classId): array
    {
        return DB::transaction(function () use ($eventId, $classId) {
            $class = CompetitionClass::where('event_id', $eventId)->findOrFail($classId);
            $resultType = $class->resultType();

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

            $scheduleIds = CompetitionSchedule::where('competition_class_id', $class->id)->pluck('id');

            $heatResults = CompetitionHeatResult::with('competitionRegistration')
                ->whereIn('competition_schedule_id', $scheduleIds)
                ->get()
                ->filter(fn ($heatResult) => $heatResult->competitionRegistration !== null
                    && $heatResult->competitionRegistration->competition_class_id === $class->id
                    && ! $this->isExcludedStatus($heatResult->status)
                    && $heatResult->score !== null);

            $aggregates = [];

            foreach ($heatResults->groupBy('competition_registration_id') as $registrationId => $group) {
                $scores = $group->map(fn ($heatResult) => (float) $heatResult->score);
                $aggregates[] = [
                    'registration_id' => (int) $registrationId,
                    'aggregate' => $direction === 'asc' ? $scores->min() : $scores->max(),
                    'heats' => $group->count(),
                ];
            }

            $sorted = collect($aggregates)
                ->sortBy('aggregate', SORT_REGULAR, $direction === 'desc')
                ->values();

            $rows = $this->assignAggregatePositions($sorted);

            foreach ($rows as $row) {
                CompetitionOutcome::updateOrCreate(
                    ['competition_registration_id' => $row['registration_id']],
                    [
                        'score' => $row['aggregate'],
                        'position' => $row['position'],
                        'remarks' => $row['heats'].' heat',
                    ],
                );
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
     * Final podium (top-N) for a whole class, ordered by final position.
     *
     * @return array<int, array{position: int, person_name: string, participant_number: string, score: ?float}>
     */
    public function podiumForClass(int $eventId, int $classId, int $limit = 3): array
    {
        $class = CompetitionClass::where('event_id', $eventId)->findOrFail($classId);

        return CompetitionOutcome::with('competitionRegistration.participation.person')
            ->whereHas('competitionRegistration', fn ($query) => $query->where('competition_class_id', $class->id))
            ->whereNotNull('position')
            ->where('position', '>', 0)
            ->orderBy('position')
            ->take($limit)
            ->get()
            ->map(fn ($outcome) => [
                'position' => (int) $outcome->position,
                'person_name' => $outcome->competitionRegistration?->participation?->person?->nama ?? '-',
                'participant_number' => $outcome->competitionRegistration?->participation?->participant_number ?? '-',
                'score' => $outcome->score !== null ? (float) $outcome->score : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Rank the class's TEAMS (Team Mass) by their score in competition_team_outcomes.
     * Event-scoped; competition ranking with ties (1,1,3).
     *
     * @return array{
     *     ranked: bool,
     *     result_type: string,
     *     direction: string,
     *     rows: array<int, array{team_id: int, score: float, position: int}>,
     *     reason?: string,
     * }
     */
    public function rankTeams(int $eventId, int $classId): array
    {
        return DB::transaction(function () use ($eventId, $classId) {
            $class = CompetitionClass::where('event_id', $eventId)->findOrFail($classId);
            $resultType = $class->resultType();

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

            $outcomes = CompetitionTeamOutcome::with('team')
                ->whereHas('team', fn ($query) => $query->where('competition_class_id', $class->id))
                ->get()
                ->filter(fn ($outcome) => $outcome->team !== null
                    && ! $this->isExcludedStatus($outcome->status)
                    && $outcome->score !== null);

            $scored = $outcomes->map(fn ($outcome) => [
                'outcome' => $outcome,
                'team_id' => $outcome->team_id,
                'score' => (float) $outcome->score,
            ]);

            $sorted = $direction === 'asc'
                ? $scored->sortBy('score')->values()
                : $scored->sortByDesc('score')->values();

            $rows = $this->assignTeamPositions($sorted);

            return [
                'ranked' => true,
                'result_type' => $resultType,
                'direction' => $direction,
                'rows' => $rows,
            ];
        });
    }

    /**
     * Final podium (top-N TEAMS) for a class.
     *
     * @return array<int, array{position: int, team_name: string, score: ?float}>
     */
    public function podiumForTeams(int $eventId, int $classId, int $limit = 3): array
    {
        $class = CompetitionClass::where('event_id', $eventId)->findOrFail($classId);

        return CompetitionTeamOutcome::with('team')
            ->whereHas('team', fn ($query) => $query->where('competition_class_id', $class->id))
            ->whereNotNull('position')
            ->where('position', '>', 0)
            ->orderBy('position')
            ->take($limit)
            ->get()
            ->map(fn ($outcome) => [
                'position' => (int) $outcome->position,
                'team_name' => $outcome->team?->name ?? '-',
                'score' => $outcome->score !== null ? (float) $outcome->score : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{team_id: int, score: float, position: int, excluded: bool}>
     */
    private function assignTeamPositions(Collection $sorted): array
    {
        $rows = [];
        $previous = null;

        foreach ($sorted as $index => $item) {
            $current = $previous !== null && (float) $item['score'] === (float) $previous['score']
                ? $previous['position']
                : $index + 1;

            $item['outcome']->update(['position' => $current]);

            $rows[] = [
                'team_id' => $item['team_id'],
                'score' => $item['score'],
                'position' => $current,
                'excluded' => false,
            ];

            $previous = ['score' => $item['score'], 'position' => $current];
        }

        return $rows;
    }

    /**
     * @return array<int, array{registration_id: int, score: float, position: ?int, excluded: bool}>
     */
    private function assignPositions(Collection $sorted): array
    {
        $rows = [];
        $previous = null;

        foreach ($sorted as $index => $item) {
            $current = $previous !== null && (float) $item['score'] === (float) $previous['score']
                ? $previous['position']
                : $index + 1;

            $item['outcome']->update(['position' => $current]);

            $rows[] = [
                'registration_id' => $item['registration_id'],
                'score' => $item['score'],
                'position' => $current,
                'excluded' => false,
            ];

            $previous = ['score' => $item['score'], 'position' => $current];
        }

        return $rows;
    }

    /**
     * @return array<int, array{registration_id: int, aggregate: float, heats: int, position: int}>
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
                'registration_id' => $item['registration_id'],
                'aggregate' => $item['aggregate'],
                'heats' => $item['heats'],
                'position' => $current,
            ];

            $previous = ['aggregate' => $item['aggregate'], 'position' => $current];
        }

        return $rows;
    }

    private function isExcluded(CompetitionOutcome $outcome): bool
    {
        return $this->isExcludedStatus($outcome->status);
    }

    private function isExcludedStatus(?string $status): bool
    {
        return in_array($status, self::EXCLUDED_STATUSES, true);
    }

    private function scheduleInEvent(int $eventId, int $scheduleId): CompetitionSchedule
    {
        return CompetitionSchedule::with('competitionClass')
            ->whereHas('competitionClass', fn ($query) => $query->where('event_id', $eventId))
            ->findOrFail($scheduleId);
    }
}
