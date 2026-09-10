<?php

namespace App\Services\Competition;

use App\Models\CompetitionClass;
use App\Models\CompetitionTeam;
use App\Models\CompetitionTeamMember;
use App\Support\CompetitionFormat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Auto team formation for team-based competitions.
 *
 * Two formation modes:
 *
 * 1. Based on Group (formForClass / previewForClass)
 *    - Only team formats (team_vs_team / team_mass) can be auto-formed.
 *    - One Kelompok = one Team per CompetitionClass.
 *    - Team size = `CompetitionClass.team_size` bila diset, default kelompok terkecil.
 *    - Remaining participants of each Kelompok become substitutes (excess/cadangan).
 *    - Participants are never moved between Kelompoks.
 *    - Regu / PlacementService are NOT used for Competition Teams.
 *
 * 2. Random & Balanced (formBalancedForClass / previewBalancedForClass)
 *    - Mixes participants freely across Kelompoks within the class.
 *    - Team count = ceil(eligible / team_size), teams named "Tim 1..N".
 *    - Randomised but balanced: kelas (Person.kelas) spread round-robin,
 *      gender balanced via best-effort swap pass, team sizes balanced (±1).
 *    - No participant is duplicated or lost.
 */
class CompetitionTeamFormationService
{
    /**
     * Based on Group — persist one team per Kelompok.
     *
     * @return array{
     *     class: CompetitionClass,
     *     team_size: int,
     *     teams: array<int, array{team: CompetitionTeam, kelompok: string, players: int, substitutes: int}>
     * }
     *
     * @throws \Illuminate\Validation\ValidationException bila team sudah dibentuk
     *                                                    dan `$force` (rebuild eksplisit) false.
     */
    public function formForClass(int $eventId, int $competitionClassId, ?int $forcedTeamSize = null, bool $force = false): array
    {
        return DB::transaction(function () use ($eventId, $competitionClassId, $forcedTeamSize, $force) {
            $class = $this->resolveClass($eventId, $competitionClassId);
            $this->guardTeamFormat($class);

            $registrations = $class->competitionRegistrations()
                ->with('participation.person.kelompok')
                ->get()
                ->filter(fn ($registration) => $registration->participation?->person?->kelompok_id !== null)
                ->values();

            $byKelompok = $registrations->groupBy(fn ($registration) => $registration->participation->person->kelompok_id);

            $teamSize = $forcedTeamSize
                ?? $class->team_size
                ?? $byKelompok->map(fn ($group) => $group->count())->min();

            if ($teamSize === null || $teamSize < 1) {
                throw ValidationException::withMessages([
                    'class' => 'Tidak ada peserta berkelompok untuk membentuk team.',
                ]);
            }

            $this->assertCanForm($class, $force);

            // Regenerate (transactional). Members cascade on team delete.
            $this->deleteTeamsForClass($class);

            $teams = [];

            foreach ($byKelompok as $kelompokId => $group) {
                $kelompok = $group->first()->participation->person->kelompok;

                $team = CompetitionTeam::create([
                    'event_id' => $eventId,
                    'competition_class_id' => $class->id,
                    'name' => $kelompok->kelompok_asal,
                    'kelompok_id' => $kelompok->id,
                    'is_active' => true,
                ]);

                $sorted = $group->sortBy('id')->values();
                $playerCount = min($sorted->count(), $teamSize);

                foreach ($sorted as $index => $registration) {
                    CompetitionTeamMember::create([
                        'competition_team_id' => $team->id,
                        'competition_registration_id' => $registration->id,
                        'is_substitute' => $index >= $playerCount,
                        'sort_order' => $index + 1,
                    ]);
                }

                $teams[] = [
                    'team' => $team,
                    'kelompok' => $kelompok->kelompok_asal,
                    'players' => $playerCount,
                    'substitutes' => $sorted->count() - $playerCount,
                ];
            }

            return [
                'class' => $class,
                'team_size' => $teamSize,
                'teams' => $teams,
            ];
        });
    }

    /**
     * Based on Group — preview tanpa menyentuh DB.
     *
     * @return array{
     *     class: CompetitionClass,
     *     team_size: int,
     *     teams: array<int, array{kelompok: string, participants: int, players: int, substitutes: int}>
     * }
     */
    public function previewForClass(int $eventId, int $competitionClassId, ?int $forcedTeamSize = null): array
    {
        $class = $this->resolveClass($eventId, $competitionClassId);
        $this->guardTeamFormat($class);

        $registrations = $class->competitionRegistrations()
            ->with('participation.person.kelompok')
            ->get()
            ->filter(fn ($registration) => $registration->participation?->person?->kelompok_id !== null)
            ->values();

        $byKelompok = $registrations->groupBy(fn ($registration) => $registration->participation->person->kelompok_id);

        $teamSize = $forcedTeamSize
            ?? $class->team_size
            ?? $byKelompok->map(fn ($group) => $group->count())->min();

        if ($teamSize === null || $teamSize < 1) {
            throw ValidationException::withMessages([
                'class' => 'Tidak dapat preview: tidak ada peserta berkelompok.',
            ]);
        }

        $teams = $byKelompok
            ->sortBy(fn ($group) => $group->first()->id)
            ->map(function ($group) use ($teamSize) {
                $count = $group->count();

                return [
                    'kelompok' => $group->first()->participation->person->kelompok->kelompok_asal,
                    'participants' => $count,
                    'players' => min($count, $teamSize),
                    'substitutes' => max(0, $count - $teamSize),
                ];
            })
            ->values()
            ->all();

        return [
            'class' => $class,
            'team_size' => $teamSize,
            'teams' => $teams,
        ];
    }

    /**
     * Random & Balanced — persist teams from a mixed, balanced distribution.
     *
     * @return array{
     *     class: CompetitionClass,
     *     team_size: int,
     *     team_count: int,
     *     teams: array<int, array{team: CompetitionTeam, name: string, players: int, substitutes: int}>
     * }
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function formBalancedForClass(int $eventId, int $competitionClassId, ?int $forcedTeamSize = null, bool $force = false): array
    {
        return DB::transaction(function () use ($eventId, $competitionClassId, $forcedTeamSize, $force) {
            $class = $this->resolveClass($eventId, $competitionClassId);
            $this->guardTeamFormat($class);
            $this->assertCanForm($class, $force);

            $preview = $this->computeBalancedForClass($class, $forcedTeamSize);

            $this->deleteTeamsForClass($class);

            $teams = [];
            foreach ($preview['teams'] as $slot) {
                $team = CompetitionTeam::create([
                    'event_id' => $class->event_id,
                    'competition_class_id' => $class->id,
                    'name' => $slot['name'],
                    'kelompok_id' => null,
                    'is_active' => true,
                ]);

                $playerCount = 0;

                foreach ($slot['members'] as $index => $registrationId) {
                    $asSubstitute = $index >= $preview['team_size'];

                    if (! $asSubstitute) {
                        $playerCount++;
                    }

                    CompetitionTeamMember::create([
                        'competition_team_id' => $team->id,
                        'competition_registration_id' => $registrationId,
                        'is_substitute' => $asSubstitute,
                        'sort_order' => $index + 1,
                    ]);
                }

                $teams[] = [
                    'team' => $team,
                    'name' => $slot['name'],
                    'players' => $playerCount,
                    'substitutes' => count($slot['members']) - $playerCount,
                ];
            }

            return [
                'class' => $class,
                'team_size' => $preview['team_size'],
                'team_count' => $preview['team_count'],
                'teams' => $teams,
            ];
        });
    }

    /**
     * Random & Balanced — preview tanpa menyentuh DB.
     *
     * @return array{
     *     class: CompetitionClass,
     *     team_size: int,
     *     team_count: int,
     *     teams: array<int, array{name: string, members: list<int>, players: int, substitutes: int}>
     * }
     */
    public function previewBalancedForClass(int $eventId, int $competitionClassId, ?int $forcedTeamSize = null): array
    {
        $class = $this->resolveClass($eventId, $competitionClassId);
        $this->guardTeamFormat($class);

        return $this->computeBalancedForClass($class, $forcedTeamSize);
    }

    /**
     * Pure computation for Random & Balanced (share preview + persist).
     *
     * @return array{
     *     class: CompetitionClass,
     *     team_size: int,
     *     team_count: int,
     *     teams: array<int, array{name: string, members: list<int>, players: int, substitutes: int}>
     * }
     */
    private function computeBalancedForClass(CompetitionClass $class, ?int $forcedTeamSize): array
    {
        $registrations = $class->competitionRegistrations()
            ->with('participation.person')
            ->get()
            ->filter(fn ($registration) => $registration->participation?->person !== null)
            ->values();

        if ($registrations->isEmpty()) {
            throw ValidationException::withMessages([
                'class' => 'Tidak ada peserta eligible untuk membentuk team.',
            ]);
        }

        $teamSize = $forcedTeamSize ?? $class->team_size;

        if ($teamSize === null || $teamSize < 1) {
            throw ValidationException::withMessages([
                'class' => 'Ukuran tim belum ditentukan. Atur ukuran tim di Settings atau isi pada form Pembagian Tim.',
            ]);
        }

        $total = $registrations->count();
        $teamCount = max(1, (int) ceil($total / $teamSize));

        $teams = array_fill(0, $teamCount, []);

        // Primary pass — sebarkan kelas (Person.kelas) merata via round-robin per kelas.
        $byKelas = $registrations
            ->filter(fn ($registration) => $registration->participation->person->kelas !== null)
            ->groupBy(fn ($registration) => $registration->participation->person->kelas);

        foreach ($byKelas->shuffle() as $group) {
            $members = $group->shuffle();

            $pointer = random_int(0, $teamCount - 1);

            foreach ($members as $member) {
                $teams[$pointer % $teamCount][] = $member->id;
                $pointer++;
            }
        }

        // Peserta tanpa kelas — isi tim terkecil (ujung best-effort, tetap seimbang ukuran).
        $noKelas = $registrations
            ->filter(fn ($registration) => $registration->participation->person->kelas === null)
            ->shuffle();

        foreach ($noKelas as $member) {
            $teams[$this->smallestTeamIndex($teams)][] = $member->id;
        }

        $this->balanceGenders($teams, $registrations);

        $slots = [];
        foreach ($teams as $index => $memberIds) {
            $playerCount = min(count($memberIds), $teamSize);

            $slots[] = [
                'name' => 'Tim '.($index + 1),
                'members' => $memberIds,
                'players' => $playerCount,
                'substitutes' => max(0, count($memberIds) - $playerCount),
            ];
        }

        return [
            'class' => $class,
            'team_size' => $teamSize,
            'team_count' => $teamCount,
            'teams' => $slots,
        ];
    }

    /**
     * Best-effort gender balancing via limited swap pass.
     *
     * Swaps are accepted only when they strictly reduce the absolute
     * Laki-Laki vs Perempuan gap and do not worsen the kelas spread
     * (same-kelas swaps are naturally preferred for the latter).
     *
     * @param array<int, list<int>> $teams
     */
    private function balanceGenders(array &$teams, Collection $registrations): void
    {
        $byInk = [];
        foreach ($registrations as $index => $registration) {
            $byInk[$registration->id] = $registration;
        }

        $genderOf = fn (int $id): string => $byInk[$id]->participation->person->jenis_kelamin ?? 'L';
        $kelasOf = fn (int $id): ?string => $byInk[$id]->participation->person->kelas ?? null;

        $imbalance = static function (array $team) use ($genderOf): int {
            $laki = 0;
            $perempuan = 0;

            foreach ($team as $id) {
                $genderOf($id) === 'P' ? $perempuan++ : $laki++;
            }

            return abs($laki - $perempuan);
        };

        $kelasDeviation = function (array $teams) use ($kelasOf): int {
            $teamCount = count($teams);
            $cells = [];
            $totals = [];

            foreach ($teams as $idx => $team) {
                foreach ($team as $id) {
                    $kelas = $kelasOf($id) ?? '(tanpa kelas)';
                    $cells[$idx][$kelas] = ($cells[$idx][$kelas] ?? 0) + 1;
                    $totals[$kelas] = ($totals[$kelas] ?? 0) + 1;
                }
            }

            $deviation = 0;

            foreach ($totals as $kelas => $total) {
                $ideal = (int) ceil($total / $teamCount);

                for ($idx = 0; $idx < $teamCount; $idx++) {
                    $count = $cells[$idx][$kelas] ?? 0;
                    $deviation += max(0, $count - $ideal);
                }
            }

            return $deviation;
        };

        $limit = count($teams) * 2;

        for ($attempt = 0; $attempt < $limit; $attempt++) {
            $best = null;
            $deviationBefore = $kelasDeviation($teams);

            for ($i = 0; $i < count($teams); $i++) {
                for ($j = $i + 1; $j < count($teams); $j++) {
                    $before = $imbalance($teams[$i]) + $imbalance($teams[$j]);

                    foreach ($teams[$i] as $posI => $idI) {
                        foreach ($teams[$j] as $posJ => $idJ) {
                            // Simulate swap.
                            $teams[$i][$posI] = $idJ;
                            $teams[$j][$posJ] = $idI;

                            $after = $imbalance($teams[$i]) + $imbalance($teams[$j]);
                            $deviationAfter = $kelasDeviation($teams);

                            // Revert.
                            $teams[$i][$posI] = $idI;
                            $teams[$j][$posJ] = $idJ;

                            if ($after >= $before || $deviationAfter > $deviationBefore) {
                                continue;
                            }

                            if ($best === null
                                || $after < $best['after']
                                || ($after === $best['after'] && $deviationAfter < $best['deviationAfter'])) {
                                $best = [
                                    'i' => $i,
                                    'j' => $j,
                                    'posI' => $posI,
                                    'posJ' => $posJ,
                                    'after' => $after,
                                    'deviationAfter' => $deviationAfter,
                                ];
                            }
                        }
                    }
                }
            }

            if ($best === null) {
                break;
            }

            $tmp = $teams[$best['i']][$best['posI']];
            $teams[$best['i']][$best['posI']] = $teams[$best['j']][$best['posJ']];
            $teams[$best['j']][$best['posJ']] = $tmp;
        }
    }

    /**
     * Index tim dengan jumlah anggota paling sedikit (tie-break acak).
     *
     * @param array<int, list<int>> $teams
     */
    private function smallestTeamIndex(array $teams): int
    {
        $sizes = array_map('count', $teams);
        $min = min($sizes);

        $candidates = [];
        foreach ($sizes as $index => $size) {
            if ($size === $min) {
                $candidates[] = $index;
            }
        }

        return $candidates[array_rand($candidates)];
    }

    private function resolveClass(int $eventId, int $competitionClassId): CompetitionClass
    {
        return CompetitionClass::where('event_id', $eventId)
            ->findOrFail($competitionClassId);
    }

    private function guardTeamFormat(CompetitionClass $class): void
    {
        if (! CompetitionFormat::isTeamFormat($class->format)) {
            throw ValidationException::withMessages([
                'class' => 'Pembagian tim hanya untuk format team (team_vs_team / team_mass).',
            ]);
        }
    }

    private function assertCanForm(CompetitionClass $class, bool $force): void
    {
        // Guard: jangan regenerate bila team sudah dipakai di jadwal/hasil.
        $inUse = CompetitionTeam::where('competition_class_id', $class->id)
            ->get()
            ->contains(fn ($team) => $team->scheduleEntries()->exists() || $team->outcome()->exists());

        if ($inUse) {
            throw ValidationException::withMessages([
                'class' => 'Tidak dapat membentuk ulang team: team sudah dipakai di jadwal/hasil.',
            ]);
        }

        // Manual protection: jangan diam-diam menimpa pembagian team yang sudah
        // ada (otomatis maupun manual). Rebuild hanya lewat aksi eksplisit ($force).
        $hasExisting = CompetitionTeam::where('competition_class_id', $class->id)
            ->whereHas('members')
            ->exists();

        if ($hasExisting && ! $force) {
            throw ValidationException::withMessages([
                'class' => 'Tim sudah dibentuk. Membentuk ulang akan mengganti pembagian — gunakan rebuild eksplisit.',
            ]);
        }
    }

    private function deleteTeamsForClass(CompetitionClass $class): void
    {
        CompetitionTeam::where('competition_class_id', $class->id)->get()->each->delete();
    }
}