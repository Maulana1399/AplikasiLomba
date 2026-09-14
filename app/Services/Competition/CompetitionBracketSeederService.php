<?php

namespace App\Services\Competition;

use App\Models\CompetitionBracket;
use App\Models\CompetitionBracketMatch;
use App\Models\CompetitionClass;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionScheduleEntry;
use App\Models\CompetitionTeam;

/**
 * Auto-seed initial round of a single-elimination bracket (Sprint R4E + R4G).
 *
 * Fills `competition_schedule_entries` of the FIRST-round matches (round =
 * totalRounds) from the class competitors deterministically:
 *
 *   Individual vs Individual:  [A,B,C,D] → M1 = A+B, M2 = C+D
 *   Team vs Team:              [A,B,C,D] → M1 = A+B, M2 = C+D
 *
 * Contract:
 * - Event-scoped (class wajib milik event).
 * - Class-scoped (hanya competitor class bracket).
 * - Idempotent (tidak membuat duplicate; tidak overwrite entry yang ada).
 * - Hanya initial round; round berikutnya tetap TBD sampai winner advancement.
 * - Match yang sudah punya entry (manual) TIDAK di-overwrite.
 * - Setelah seeding, match round pertama yang entry-nya sudah lengkap di-
 *   promote ke `Ready` via `CompetitionWorkflowService::checkAutoReady()`
 *   sehingga muncul di Match Center (Sprint R4G).
 */
class CompetitionBracketSeederService
{
    public function __construct(
        private readonly CompetitionWorkflowService $workflow,
    ) {}

    /**
     * @return array{
     *     seeded: int,
     *     initial_matches: int,
     *     competitor_type: string,
     *     byes_advanced: int,
     * }
     */
    public function seedInitialRound(int $eventId, int $bracketId): array
    {
        $bracket = CompetitionBracket::findOrFail($bracketId);

        $class = CompetitionClass::where('event_id', $eventId)
            ->findOrFail($bracket->competition_class_id);

        $totalRounds = (int) log($bracket->participant_count, 2);

        $initialMatches = CompetitionBracketMatch::where('competition_bracket_id', $bracket->id)
            ->where('round', $totalRounds)
            ->orderBy('position')
            ->get();

        if ($class->isTeamFormat()) {
            $competitors = CompetitionTeam::where('event_id', $eventId)
                ->where('competition_class_id', $class->id)
                ->orderBy('id')
                ->get();
            $column = 'competition_team_id';
            $competitorType = 'team';
        } else {
            $competitors = CompetitionRegistration::where('competition_class_id', $class->id)
                ->orderBy('id')
                ->get();
            $column = 'competition_registration_id';
            $competitorType = 'registration';
        }

        $competitorCount = $competitors->count();
        $bracketSize = (int) $bracket->participant_count;
        $matchCount = $initialMatches->count();
        $byeCount = max(0, $bracketSize - $competitorCount);

        $perMatchCounts = $this->perMatchCounts($matchCount, $competitorCount, $byeCount);

        $seeded = 0;
        $cursor = 0;

        foreach ($initialMatches as $index => $match) {
            if ($match->schedule === null || $match->schedule->scheduleEntries()->exists()) {
                $cursor += $perMatchCounts[$index] ?? 0;

                continue;
            }

            $take = $perMatchCounts[$index] ?? 0;

            for ($slot = 0; $slot < $take; $slot++) {
                $competitor = $competitors->get($cursor + $slot);

                if ($competitor === null) {
                    break;
                }

                CompetitionScheduleEntry::create([
                    'competition_schedule_id' => $match->schedule->id,
                    $column => $competitor->id,
                    'order_number' => $slot + 1,
                ]);

                $seeded++;
            }

            $cursor += $take;

            $this->workflow->checkAutoReady($match->schedule);
        }

        $byesAdvanced = $this->advanceByeMatches($initialMatches, $class->isTeamFormat());

        return [
            'seeded' => $seeded,
            'initial_matches' => $initialMatches->count(),
            'competitor_type' => $competitorType,
            'byes_advanced' => $byesAdvanced,
        ];
    }

    /**
     * Hitung jumlah peserta per initial match dengan penyebaran bye merata.
     *
     * @return array<int,int>
     */
    private function perMatchCounts(int $matchCount, int $competitorCount, int $byeCount): array
    {
        if ($matchCount === 0) {
            return [];
        }

        if ($competitorCount >= $matchCount) {
            $singles = $byeCount;
            $counts = array_fill(0, $matchCount, 2);

            for ($k = 0; $k < $singles; $k++) {
                $idx = (int) floor($k * $matchCount / $singles);
                $counts[$idx] = 1;
            }

            return $counts;
        }

        $counts = array_fill(0, $matchCount, 0);

        for ($k = 0; $k < $competitorCount; $k++) {
            $idx = (int) floor($k * $matchCount / $competitorCount);
            $counts[$idx] = 1;
        }

        return $counts;
    }

    private function advanceByeMatches($initialMatches, bool $isTeam): int
    {
        $advanced = 0;

        foreach ($initialMatches as $match) {
            $schedule = $match->schedule;

            if ($schedule === null || $schedule->status === 'Finished') {
                continue;
            }

            $count = $schedule->scheduleEntries()->count();

            if ($count !== 1) {
                continue;
            }

            $entry = $schedule->scheduleEntries()->first();
            $winnerId = $isTeam ? $entry->competition_team_id : $entry->competition_registration_id;

            if ($winnerId === null) {
                continue;
            }

            $update = [
                'status' => 'Finished',
                'finished_at' => now(),
                'finished_by' => auth()->id(),
            ];

            if ($isTeam) {
                $update['winner_team_id'] = $winnerId;
            } else {
                $update['winner_registration_id'] = $winnerId;
            }

            $schedule->update($update);

            if ($isTeam) {
                $this->workflow->advanceWinnerTeam($schedule->fresh());
            } else {
                $this->workflow->advanceWinner($schedule->fresh());
            }

            $advanced++;
        }

        return $advanced;
    }
}
