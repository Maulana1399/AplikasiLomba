<?php

namespace App\Livewire\Competition;

use App\Models\CompetitionBracket;
use App\Models\CompetitionBracketMatch;
use App\Models\CompetitionClass;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionSchedule;
use App\Support\ActiveEventContext;
use App\Support\CompetitionFormat;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class BracketManager extends Component
{
    public string $filterClassId = '';

    public ?int $selectedBracketId = null;

    public string $newParticipantCount = '8';

    public bool $thirdPlaceMatch = false;

    public function mount(): void
    {
        app(ActiveEventContext::class)->requireCurrent();
    }

    public function updatedFilterClassId(): void
    {
        if (! $this->filterClassId) {
            return;
        }

        $class = CompetitionClass::find($this->filterClassId);
        if (! $class) {
            return;
        }

        $this->newParticipantCount = (string) $this->suggestBracketSize(
            CompetitionRegistration::where('competition_class_id', $class->id)->count()
        );
    }

    public function selectBracket(int $bracketId): void
    {
        $this->selectedBracketId = $bracketId;
    }

    public function generate(int $classId): void
    {
        Gate::authorize('manage-events');

        $class = CompetitionClass::findOrFail($classId);
        $count = (int) $this->newParticipantCount;

        if (! in_array($count, [4, 8, 16, 32])) {
            session()->flash('error', 'Participant count must be 4, 8, 16, or 32.');

            return;
        }

        $existing = CompetitionBracket::where('competition_class_id', $classId)
            ->whereIn('status', ['draft', 'active'])
            ->exists();

        if ($existing) {
            session()->flash('error', 'An active bracket already exists for this class.');

            return;
        }

        $registrationCount = CompetitionRegistration::where('competition_class_id', $classId)->count();
        $suggested = $this->suggestBracketSize($registrationCount);

        if ($registrationCount > 0) {
            if ($count < $suggested) {
                session()->flash('error', "Bracket size {$count} is too small for {$registrationCount} participants. Minimum suggested size: {$suggested}.");

                return;
            }
            if ($count > $suggested * 2) {
                session()->flash('error', "Bracket size {$count} is too large for {$registrationCount} participants. Suggested size: {$suggested}.");

                return;
            }
        }

        $bracket = CompetitionBracket::create([
            'competition_class_id' => $classId,
            'name' => $class->name.' Bracket',
            'participant_count' => $count,
            'status' => 'active',
            'third_place_match' => $this->thirdPlaceMatch,
        ]);

        $totalRounds = (int) log($count, 2);

        for ($round = $totalRounds; $round >= 1; $round--) {
            $matchesInRound = (int) pow(2, $round - 1);

            for ($pos = 1; $pos <= $matchesInRound; $pos++) {
                $schedule = CompetitionSchedule::create([
                    'competition_class_id' => $classId,
                    'status' => 'Scheduled',
                    'required_participants' => 2,
                    'sort_order' => ($totalRounds - $round) * 100 + $pos,
                ]);

                $bracketMatch = new CompetitionBracketMatch([
                    'competition_bracket_id' => $bracket->id,
                    'competition_schedule_id' => $schedule->id,
                    'round' => $round,
                    'position' => $pos,
                    'is_third_place' => false,
                ]);

                if ($round < $totalRounds) {
                    $prevMatchesCount = (int) pow(2, $round);
                    $bracketMatch->source_match_a_id = $this->findBracketMatch($bracket->id, $round + 1, $pos * 2 - 1);
                    $bracketMatch->source_match_b_id = $this->findBracketMatch($bracket->id, $round + 1, $pos * 2);
                }

                $bracketMatch->save();
            }
        }

        // Perebutan Juara 3 (Bronze Match): round=1, position=2, is_third_place.
        // Sumber = dua semifinal (round 2). Loser SF masuk ke sini (via
        // CompetitionWorkflowService::advanceLoser*). Independen dari Final.
        if ($this->thirdPlaceMatch && $totalRounds >= 2) {
            $bronzeSchedule = CompetitionSchedule::create([
                'competition_class_id' => $classId,
                'status' => 'Scheduled',
                'required_participants' => 2,
                'sort_order' => (($totalRounds - 1) * 100) + 2,
            ]);

            $bronze = new CompetitionBracketMatch([
                'competition_bracket_id' => $bracket->id,
                'competition_schedule_id' => $bronzeSchedule->id,
                'round' => 1,
                'position' => 2,
                'is_third_place' => true,
                'source_match_a_id' => $this->findBracketMatch($bracket->id, 2, 1),
                'source_match_b_id' => $this->findBracketMatch($bracket->id, 2, 2),
            ]);

            $bronze->save();
        }

        $this->selectedBracketId = $bracket->id;

        $eventId = app(ActiveEventContext::class)->requireCurrent()->id;
        $seedResult = app(\App\Services\Competition\CompetitionBracketSeederService::class)
            ->seedInitialRound($eventId, $bracket->id);

        session()->flash('success', "Bracket generated with {$count} participants (".$seedResult['seeded'].' competitor seeded to initial round).');
    }

    public function deleteBracket(int $bracketId): void
    {
        Gate::authorize('manage-events');

        $bracket = CompetitionBracket::findOrFail($bracketId);

        if ($this->bracketHasPlayedMatches($bracket)) {
            session()->flash('error', 'Bracket tidak dapat dihapus karena sudah ada pertandingan yang dimainkan.');

            return;
        }

        $bracket->delete();

        $this->selectedBracketId = null;
        session()->flash('success', 'Bracket berhasil dihapus.');
    }

    public function regenerateBracket(int $bracketId): void
    {
        Gate::authorize('manage-events');

        $bracket = CompetitionBracket::findOrFail($bracketId);

        if ($this->bracketHasPlayedMatches($bracket)) {
            session()->flash('error', 'Bracket tidak dapat dibuat ulang karena sudah ada pertandingan yang dimainkan.');

            return;
        }

        $classId = $bracket->competition_class_id;

        $bracket->delete();

        $this->filterClassId = (string) $classId;
        $this->newParticipantCount = (string) $this->suggestBracketSize(
            CompetitionRegistration::where('competition_class_id', $classId)->count()
        );
        $this->selectedBracketId = null;

        session()->flash('success', 'Bracket lama dihapus. Silakan generate bracket baru.');
    }

    private function bracketHasPlayedMatches(CompetitionBracket $bracket): bool
    {
        $scheduleIds = $bracket->bracketMatches()->pluck('competition_schedule_id');

        return CompetitionSchedule::whereIn('id', $scheduleIds)
            ->where('status', '!=', 'Scheduled')
            ->exists();
    }

    private function suggestBracketSize(int $participantCount): int
    {
        if ($participantCount <= 4) {
            return 4;
        }
        if ($participantCount <= 8) {
            return 8;
        }
        if ($participantCount <= 16) {
            return 16;
        }

        return 32;
    }

    private function findBracketMatch(int $bracketId, int $round, int $position): ?int
    {
        $match = CompetitionBracketMatch::where('competition_bracket_id', $bracketId)
            ->where('round', $round)
            ->where('position', $position)
            ->first();

        return $match?->id;
    }

    private function getRoundLabel(int $round, int $totalRounds): string
    {
        if ($round === 1) {
            return 'Final';
        }
        if ($round === 2) {
            return 'Semi Final';
        }
        if ($round === 3) {
            return 'Quarter Final';
        }
        $roundNum = $totalRounds - $round + 1;

        return 'Round of '.pow(2, $totalRounds - $round + 1);
    }

    public function render()
    {
        $event = app(ActiveEventContext::class)->current();

        $classes = CompetitionClass::where('event_id', $event?->id)
            ->where('is_active', true)
            ->whereIn('format', [CompetitionFormat::INDIVIDUAL_VS_INDIVIDUAL, CompetitionFormat::TEAM_VS_TEAM])
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        $classIdsWithBrackets = CompetitionBracket::whereIn('status', ['draft', 'active'])
            ->whereIn('competition_class_id', $classes->pluck('id'))
            ->pluck('competition_class_id')
            ->unique()
            ->toArray();

        $brackets = CompetitionBracket::with('competitionClass')
            ->whereIn('competition_class_id', $classes->pluck('id'))
            ->orderBy('created_at', 'desc')
            ->get();

        $selectedBracket = null;
        $bracketRounds = [];

        if ($this->selectedBracketId) {
            $selectedBracket = CompetitionBracket::with([
                'bracketMatches.schedule.scheduleEntries.competitionRegistration.participation.person',
                'bracketMatches.schedule.scheduleEntries.team',
                'bracketMatches.schedule.winner.participation.person',
                'bracketMatches.schedule.winnerTeam',
                'bracketMatches.sourceMatchA',
                'bracketMatches.sourceMatchB',
            ])->find($this->selectedBracketId);

            if ($selectedBracket) {
                $totalRounds = (int) log($selectedBracket->participant_count, 2);
                $matches = $selectedBracket->bracketMatches
                    ->where('is_third_place', false)
                    ->groupBy('round')
                    ->sortKeysDesc();

                foreach ($matches as $round => $roundMatches) {
                    $bracketRounds[] = [
                        'label' => $this->getRoundLabel($round, $totalRounds),
                        'round' => $round,
                        'matches' => $roundMatches->sortBy('position')->values(),
                    ];
                }

                // Perebutan Juara 3 (Bronze Match) sebagai section terpisah.
                $bronzeMatch = $selectedBracket->bracketMatches->firstWhere('is_third_place', true);

                if ($bronzeMatch) {
                    $bracketRounds[] = [
                        'label' => 'Perebutan Juara 3',
                        'round' => 0,
                        'matches' => collect([$bronzeMatch]),
                    ];
                }
            }
        }

        return view('livewire.competition.bracket-manager', [
            'classes' => $classes,
            'classIdsWithBrackets' => $classIdsWithBrackets,
            'brackets' => $brackets,
            'selectedBracket' => $selectedBracket,
            'bracketRounds' => $bracketRounds,
            'podium' => $this->podiumForSelected($selectedBracket, $event?->id),
        ]);
    }

    /**
     * Final podium untuk bracket terpilih (dari competition_outcomes / team outcomes).
     *
     * Bronze OFF → Juara 1/2/3 (3 slot); Bronze ON → Juara 1/2/3/4 (4 slot).
     *
     * @return array<int, array{position: int, person_name?: string, team_name?: string, participant_number?: string, score: ?float}>
     */
    private function podiumForSelected(?CompetitionBracket $bracket, ?int $eventId): array
    {
        if ($bracket === null || $eventId === null) {
            return [];
        }

        $limit = max($bracket->competitionClass?->winner_count ?? 3, $bracket->third_place_match ? 4 : 1);

        $service = app(\App\Services\Competition\CompetitionResultService::class);

        if ($bracket->competitionClass?->isTeamFormat()) {
            return $service->podiumForTeams($eventId, $bracket->competition_class_id, $limit);
        }

        return $service->podiumForClass($eventId, $bracket->competition_class_id, $limit);
    }
}
