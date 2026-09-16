<?php

namespace App\Services\Competition;

use App\Models\CompetitionBracket;
use App\Models\CompetitionBracketMatch;
use App\Models\CompetitionOutcome;
use App\Models\CompetitionSchedule;
use App\Models\CompetitionScheduleEntry;
use App\Models\CompetitionTeamOutcome;
use App\Support\CompetitionFormat;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CompetitionWorkflowService
{
    public const array LEGAL_TRANSITIONS = [
        'Scheduled' => ['Ready'],
        'Ready' => ['Playing'],
        'Playing' => ['Waiting Result', 'Finished'],
        'Waiting Result' => ['Finished'],
        'Finished' => ['Scheduled'],
    ];

    public function canTransitionTo(CompetitionSchedule $schedule, string $newStatus): bool
    {
        return in_array($newStatus, self::LEGAL_TRANSITIONS[$schedule->status] ?? [], true);
    }

    public function isBracketMatch(CompetitionSchedule $schedule): bool
    {
        return $schedule->bracketMatch()->exists();
    }

    public function requiresOfficial(CompetitionSchedule $schedule): bool
    {
        if ($this->isBracketMatch($schedule)) {
            return true;
        }

        // Non-bracket vs-format (team_vs_team / individual_vs_individual) juga
        // memakai official flow: Playing → Waiting Result → official submit.
        return CompetitionFormat::isVsFormat($schedule->competitionClass?->format);
    }

    /** Match dengan competitor TEAM (team_vs_team / team_mass class). */
    public function isTeamMatch(CompetitionSchedule $schedule): bool
    {
        return $schedule->competitionClass?->isTeamFormat() ?? false;
    }

    public function prepareMatch(CompetitionSchedule $schedule): bool
    {
        if (! $this->canTransitionTo($schedule, 'Ready')) {
            return false;
        }

        if (! $schedule->canAutoReady()) {
            return false;
        }

        $schedule->update(['status' => 'Ready']);

        return true;
    }

    public function startMatch(CompetitionSchedule $schedule): bool
    {
        if (! $this->canTransitionTo($schedule, 'Playing')) {
            return false;
        }

        if (! $schedule->isReadyForStart()) {
            return false;
        }

        $schedule->update(['status' => 'Playing']);

        return true;
    }

    public function completeMatch(CompetitionSchedule $schedule): string
    {
        if ($this->requiresOfficial($schedule)) {
            if (! $this->canTransitionTo($schedule, 'Waiting Result')) {
                return $schedule->status;
            }
            $schedule->update(['status' => 'Waiting Result']);

            return 'Waiting Result';
        }

        if (! $this->canTransitionTo($schedule, 'Finished')) {
            return $schedule->status;
        }

        $schedule->update(['status' => 'Finished']);

        return 'Finished';
    }

    public function finishMatch(CompetitionSchedule $schedule): bool
    {
        if ($this->requiresOfficial($schedule)) {
            return false;
        }

        if (! $this->canTransitionTo($schedule, 'Finished')) {
            return false;
        }

        $schedule->update(['status' => 'Finished']);

        $this->finalizePodium($schedule);

        return true;
    }

    public function moveToWaitingResult(CompetitionSchedule $schedule): bool
    {
        if (! $this->canTransitionTo($schedule, 'Waiting Result')) {
            return false;
        }

        $schedule->update(['status' => 'Waiting Result']);

        return true;
    }

    public function submitResult(
        CompetitionSchedule $schedule,
        int $winnerRegistrationId,
        string $finishReason,
        ?string $finishNotes
    ): void {
        if (! $this->canTransitionTo($schedule, 'Finished')) {
            throw new \RuntimeException('Cannot submit result: match status does not allow transition to Finished.');
        }

        $schedule->update([
            'winner_registration_id' => $winnerRegistrationId,
            'finish_reason' => $finishReason,
            'finish_notes' => $finishNotes ?: null,
            'finished_at' => Carbon::now(),
            'finished_by' => auth()->id(),
            'status' => 'Finished',
        ]);

        $this->advanceWinner($schedule);

        $this->advanceLoser($schedule);

        $this->finalizePodium($schedule);
    }

    public function advanceWinner(CompetitionSchedule $schedule): void
    {
        $bracketMatch = $schedule->bracketMatch;
        if (! $bracketMatch || $bracketMatch->is_third_place) {
            // Bronze Match tidak pernah memajukan pemenangnya ke match lain.
            return;
        }

        $winnerRegId = $schedule->winner_registration_id;
        if (! $winnerRegId) {
            return;
        }

        // Tempat tujuan normal adalah Final (dan turunannya) — Bronze Match
        // (is_third_place) bukan tujuan normal pemenang, jadi di-exclude.
        $nextMatch = CompetitionBracketMatch::where(function ($q) use ($bracketMatch) {
            $q->where('source_match_a_id', $bracketMatch->id)
                ->orWhere('source_match_b_id', $bracketMatch->id);
        })
            ->where('is_third_place', false)
            ->with('schedule')
            ->first();

        if (! $nextMatch || ! $nextMatch->schedule) {
            return;
        }

        $existingEntry = CompetitionScheduleEntry::where('competition_schedule_id', $nextMatch->schedule->id)
            ->where('competition_registration_id', $winnerRegId)
            ->exists();

        if ($existingEntry) {
            return;
        }

        $isSourceA = $nextMatch->source_match_a_id === $bracketMatch->id;

        $maxOrder = CompetitionScheduleEntry::where('competition_schedule_id', $nextMatch->schedule->id)
            ->max('order_number') ?? 0;

        $assignedCount = CompetitionScheduleEntry::where('competition_schedule_id', $nextMatch->schedule->id)
            ->count();

        if ($assignedCount >= 2) {
            return;
        }

        CompetitionScheduleEntry::create([
            'competition_schedule_id' => $nextMatch->schedule->id,
            'competition_registration_id' => $winnerRegId,
            'order_number' => $maxOrder + 1,
            'corner' => $isSourceA ? 'Merah' : 'Biru',
            'position' => $isSourceA ? 1 : 2,
        ]);

        $nextSchedule = $nextMatch->schedule;
        if ($nextSchedule->status === 'Scheduled' && $nextSchedule->canAutoReady()) {
            $nextSchedule->update(['status' => 'Ready']);
        }
    }

    /**
     * Submit hasil pertandingan dengan pemenang TEAM (Team vs Team).
     *
     * - `$eventId` (opsional): bila diberikan, schedule wajib milik event tsb.
     * - Winner team wajib merupakan salah satu entry (competition_team_id) schedule.
     * - Bracket advancement hanya berjalan bila schedule memang bracket.
     */
    public function submitTeamResult(
        CompetitionSchedule $schedule,
        int $winnerTeamId,
        string $finishReason,
        ?string $finishNotes,
        ?int $eventId = null,
    ): void {
        if ($eventId !== null) {
            $classEventId = $schedule->competitionClass?->event_id;
            if ($classEventId === null || (int) $classEventId !== (int) $eventId) {
                throw new \RuntimeException('Match does not belong to the active event.');
            }
        }

        $isEntry = $schedule->scheduleEntries()
            ->where('competition_team_id', $winnerTeamId)
            ->exists();

        if (! $isEntry) {
            throw new \RuntimeException('Winner team must be an entry of this match.');
        }

        if (! $this->canTransitionTo($schedule, 'Finished')) {
            throw new \RuntimeException('Cannot submit result: match status does not allow transition to Finished.');
        }

        $schedule->update([
            'winner_team_id' => $winnerTeamId,
            'finish_reason' => $finishReason,
            'finish_notes' => $finishNotes ?: null,
            'finished_at' => Carbon::now(),
            'finished_by' => auth()->id(),
            'status' => 'Finished',
        ]);

        $this->advanceWinnerTeam($schedule);

        $this->advanceLoserTeam($schedule);

        $this->finalizePodium($schedule);
    }

    /**
     * Salin pemenang TEAM ke match berikutnya (bracket).
     */
    public function advanceWinnerTeam(CompetitionSchedule $schedule): void
    {
        $bracketMatch = $schedule->bracketMatch;
        if (! $bracketMatch || $bracketMatch->is_third_place) {
            return;
        }

        $winnerTeamId = $schedule->winner_team_id;
        if (! $winnerTeamId) {
            return;
        }

        $nextMatch = CompetitionBracketMatch::where(function ($q) use ($bracketMatch) {
            $q->where('source_match_a_id', $bracketMatch->id)
                ->orWhere('source_match_b_id', $bracketMatch->id);
        })
            ->where('is_third_place', false)
            ->with('schedule')
            ->first();

        if (! $nextMatch || ! $nextMatch->schedule) {
            return;
        }

        $existingEntry = CompetitionScheduleEntry::where('competition_schedule_id', $nextMatch->schedule->id)
            ->where('competition_team_id', $winnerTeamId)
            ->exists();

        if ($existingEntry) {
            return;
        }

        $isSourceA = $nextMatch->source_match_a_id === $bracketMatch->id;

        $maxOrder = CompetitionScheduleEntry::where('competition_schedule_id', $nextMatch->schedule->id)
            ->max('order_number') ?? 0;

        $assignedCount = CompetitionScheduleEntry::where('competition_schedule_id', $nextMatch->schedule->id)
            ->count();

        if ($assignedCount >= 2) {
            return;
        }

        CompetitionScheduleEntry::create([
            'competition_schedule_id' => $nextMatch->schedule->id,
            'competition_team_id' => $winnerTeamId,
            'order_number' => $maxOrder + 1,
            'corner' => $isSourceA ? 'Merah' : 'Biru',
            'position' => $isSourceA ? 1 : 2,
        ]);

        $nextSchedule = $nextMatch->schedule;
        if ($nextSchedule->status === 'Scheduled' && $nextSchedule->canAutoReady()) {
            $nextSchedule->update(['status' => 'Ready']);
        }
    }

    /**
     * Perebutan Juara 3 (Bronze Match) — Individual vs Individual.
     *
     * Hanya aktif bila bracket di-generate dengan third_place_match = true.
     * Loser semifinal (yang MENUNJUK match ini sebagai source) disalin ke
     * Bronze. Bronze OFF → tidak melakukan apa-apa (perilaku tied-3rd lama
     * dipertahankan). Idempotent: entry duplikat dicegah.
     */
    public function advanceLoser(CompetitionSchedule $schedule): void
    {
        $bracketMatch = $schedule->bracketMatch;
        if (! $bracketMatch || $bracketMatch->is_third_place) {
            return;
        }

        $bracket = $bracketMatch->bracket;
        if (! $bracket || ! $bracket->third_place_match) {
            return;
        }

        // Bronze menunjuk source berupa dua semifinal (round 2). QF/Final tidak
        // pernah dirujuk sebagai source Bronze, jadi lookup ini otomatis hanya
        // mencocokkan semifinal.
        $bronze = CompetitionBracketMatch::where('competition_bracket_id', $bracket->id)
            ->where('is_third_place', true)
            ->where(function ($q) use ($bracketMatch) {
                $q->where('source_match_a_id', $bracketMatch->id)
                    ->orWhere('source_match_b_id', $bracketMatch->id);
            })
            ->with('schedule')
            ->first();

        if (! $bronze || ! $bronze->schedule) {
            return;
        }

        $loserRegId = $this->loserRegistrationId($schedule);
        if ($loserRegId === null) {
            return;
        }

        $bronzeSchedule = $bronze->schedule;

        $existing = CompetitionScheduleEntry::where('competition_schedule_id', $bronzeSchedule->id)
            ->where('competition_registration_id', $loserRegId)
            ->exists();

        if ($existing) {
            return;
        }

        $assignedCount = CompetitionScheduleEntry::where('competition_schedule_id', $bronzeSchedule->id)->count();

        if ($assignedCount >= 2) {
            return;
        }

        $isSourceA = $bronze->source_match_a_id === $bracketMatch->id;

        CompetitionScheduleEntry::create([
            'competition_schedule_id' => $bronzeSchedule->id,
            'competition_registration_id' => $loserRegId,
            'order_number' => $assignedCount + 1,
            'corner' => $isSourceA ? 'Merah' : 'Biru',
            'position' => $isSourceA ? 1 : 2,
        ]);

        if ($bronzeSchedule->status === 'Scheduled' && $bronzeSchedule->canAutoReady()) {
            $bronzeSchedule->update(['status' => 'Ready']);
        }
    }

    /**
     * Perebutan Juara 3 (Bronze Match) — Team vs Team.
     *
     * Mirip {@see advanceLoser()} untuk identitas team.
     */
    public function advanceLoserTeam(CompetitionSchedule $schedule): void
    {
        $bracketMatch = $schedule->bracketMatch;
        if (! $bracketMatch || $bracketMatch->is_third_place) {
            return;
        }

        $bracket = $bracketMatch->bracket;
        if (! $bracket || ! $bracket->third_place_match) {
            return;
        }

        $bronze = CompetitionBracketMatch::where('competition_bracket_id', $bracket->id)
            ->where('is_third_place', true)
            ->where(function ($q) use ($bracketMatch) {
                $q->where('source_match_a_id', $bracketMatch->id)
                    ->orWhere('source_match_b_id', $bracketMatch->id);
            })
            ->with('schedule')
            ->first();

        if (! $bronze || ! $bronze->schedule) {
            return;
        }

        $loserTeamId = $this->loserTeamId($schedule);
        if ($loserTeamId === null) {
            return;
        }

        $bronzeSchedule = $bronze->schedule;

        $existing = CompetitionScheduleEntry::where('competition_schedule_id', $bronzeSchedule->id)
            ->where('competition_team_id', $loserTeamId)
            ->exists();

        if ($existing) {
            return;
        }

        $assignedCount = CompetitionScheduleEntry::where('competition_schedule_id', $bronzeSchedule->id)->count();

        if ($assignedCount >= 2) {
            return;
        }

        $isSourceA = $bronze->source_match_a_id === $bracketMatch->id;

        CompetitionScheduleEntry::create([
            'competition_schedule_id' => $bronzeSchedule->id,
            'competition_team_id' => $loserTeamId,
            'order_number' => $assignedCount + 1,
            'corner' => $isSourceA ? 'Merah' : 'Biru',
            'position' => $isSourceA ? 1 : 2,
        ]);

        if ($bronzeSchedule->status === 'Scheduled' && $bronzeSchedule->canAutoReady()) {
            $bronzeSchedule->update(['status' => 'Ready']);
        }
    }

    /** Loser Individual = entry yang bukan pemenang (competition_registration_id). */
    private function loserRegistrationId(CompetitionSchedule $schedule): ?int
    {
        $winnerRegId = $schedule->winner_registration_id;
        if ($winnerRegId === null) {
            return null;
        }

        return $schedule->scheduleEntries()
            ->whereNotNull('competition_registration_id')
            ->where('competition_registration_id', '!=', $winnerRegId)
            ->value('competition_registration_id');
    }

    /** Loser Team = entry yang bukan pemenang (competition_team_id). */
    private function loserTeamId(CompetitionSchedule $schedule): ?int
    {
        $winnerTeamId = $schedule->winner_team_id;
        if ($winnerTeamId === null) {
            return null;
        }

        return $schedule->scheduleEntries()
            ->whereNotNull('competition_team_id')
            ->where('competition_team_id', '!=', $winnerTeamId)
            ->value('competition_team_id');
    }

    /**
     * Batalkan penyalinan loser ke Bronze Match (Individual).
     *
     * Hanya menghapus entry loser bila Bronze belum dimainkan (status Scheduled
     * / Ready). Jika Bronze sudah Playing/Finished, tidak ada perubahan
     * destruktif — melindungi hasil yang sudah berjalan/selesai.
     */
    public function rollbackLoserAdvancement(CompetitionSchedule $schedule): void
    {
        $bracketMatch = $schedule->bracketMatch;
        if (! $bracketMatch || $bracketMatch->is_third_place) {
            return;
        }

        $bracket = $bracketMatch->bracket;
        if (! $bracket || ! $bracket->third_place_match) {
            return;
        }

        $bronze = CompetitionBracketMatch::where('competition_bracket_id', $bracket->id)
            ->where('is_third_place', true)
            ->where(function ($q) use ($bracketMatch) {
                $q->where('source_match_a_id', $bracketMatch->id)
                    ->orWhere('source_match_b_id', $bracketMatch->id);
            })
            ->with('schedule')
            ->first();

        if (! $bronze || ! $bronze->schedule) {
            return;
        }

        $bronzeSchedule = $bronze->schedule;

        // Bronze sudah dimainkan → jangan destruktif.
        if (! in_array($bronzeSchedule->status, ['Scheduled', 'Ready'], true)) {
            return;
        }

        $loserRegId = $this->loserRegistrationId($schedule);
        if ($loserRegId === null) {
            return;
        }

        CompetitionScheduleEntry::where('competition_schedule_id', $bronzeSchedule->id)
            ->where('competition_registration_id', $loserRegId)
            ->delete();

        $this->checkAutoReady($bronzeSchedule);
    }

    /** Batalkan penyalinan loser ke Bronze Match (Team). Lihat {@see rollbackLoserAdvancement()}. */
    public function rollbackLoserTeamAdvancement(CompetitionSchedule $schedule): void
    {
        $bracketMatch = $schedule->bracketMatch;
        if (! $bracketMatch || $bracketMatch->is_third_place) {
            return;
        }

        $bracket = $bracketMatch->bracket;
        if (! $bracket || ! $bracket->third_place_match) {
            return;
        }

        $bronze = CompetitionBracketMatch::where('competition_bracket_id', $bracket->id)
            ->where('is_third_place', true)
            ->where(function ($q) use ($bracketMatch) {
                $q->where('source_match_a_id', $bracketMatch->id)
                    ->orWhere('source_match_b_id', $bracketMatch->id);
            })
            ->with('schedule')
            ->first();

        if (! $bronze || ! $bronze->schedule) {
            return;
        }

        $bronzeSchedule = $bronze->schedule;

        if (! in_array($bronzeSchedule->status, ['Scheduled', 'Ready'], true)) {
            return;
        }

        $loserTeamId = $this->loserTeamId($schedule);
        if ($loserTeamId === null) {
            return;
        }

        CompetitionScheduleEntry::where('competition_schedule_id', $bronzeSchedule->id)
            ->where('competition_team_id', $loserTeamId)
            ->delete();

        $this->checkAutoReady($bronzeSchedule);
    }

    /**
     * Entry yang berada di Bronze Match yang SUDAH dimainkan (Playing,
     * Waiting Result, Finished). Saat reset semifinal, entry & outcome Bronze
     * tersebut dilindungi dari penghapusan agar hasil Bronze yang sudah
     * berjalan/selesai tidak dirusak. Bronze yang belum dimainkan (Scheduled /
     * Ready) TIDAK dilindungi.
     */
    private function playedBronzeEntries(CompetitionSchedule $schedule): Collection
    {
        $bracketMatch = $schedule->bracketMatch;
        if (! $bracketMatch?->bracket) {
            return collect();
        }

        $bronze = CompetitionBracketMatch::where('competition_bracket_id', $bracketMatch->bracket->id)
            ->where('is_third_place', true)
            ->with('schedule.scheduleEntries')
            ->first();

        if (! $bronze?->schedule || in_array($bronze->schedule->status, ['Scheduled', 'Ready'], true)) {
            return collect();
        }

        return $bronze->schedule->scheduleEntries;
    }

    /**
     * Reset sebuah match ke Scheduled (dan otomatis Ready bila peserta masih
     * lengkap sesuai required_participants).
     *
     * Reset bersifat cascade-invalidation: seluruh match di hilir
     * (via source_match_a_id / source_match_b_id, scoped ke bracket yang sama)
     * yang bergantung pada match ini ikut dibatalkan — entry turunan dari
     * branch ini dihapus, metadata winner/finish dibersihkan, dan outcome
     * podium (CompetitionOutcome / CompetitionTeamOutcome) dihapus sehingga
     * Juara 1/2 (Final) maupun tied-3rd (bronze OFF) tidak tersisa stale.
     *
     * - Downstream Finished (bukan bronze) → full invalidation.
     * - Downstream Scheduled/Ready → entry turunan dihapus, status dikembalikan
     *   ke Scheduled, lalu direkonsiliasi via checkAutoReady (Ready bila peserta
     *   masih lengkap).
     * - Downstream Playing/Waiting Result → reset ditolak (tidak ada mutasi)
     *   agar hasil yang sedang berjalan tidak terkorupsi.
     * - Bronze Match yang sudah dimainkan (Playing/Waiting Result/Finished)
     *   tetap dilindungi dan tidak disentuh.
     *
     * Match yang di-reset (bracket) yang masih memenuhi required_participants
     * langsung dipromosikan kembali ke Ready via checkAutoReady() — tidak ada
     * readiness logic duplikat.
     *
     * @return array{reset: bool, reason?: string, invalidated_downstream?: bool, blocked_schedule_id?: int, status?: string}
     */
    public function resetMatch(CompetitionSchedule $schedule): array
    {
        if (! in_array($schedule->status, ['Finished', 'Playing'], true)) {
            return ['reset' => false, 'reason' => 'not_allowed'];
        }

        $isTeam = $this->isTeamMatch($schedule);

        $cascade = $this->invalidateDownstreamBranch($schedule, $isTeam);

        if (! $cascade['ok']) {
            return [
                'reset' => false,
                'reason' => 'downstream_active',
                'blocked_schedule_id' => $cascade['blocked_schedule_id'],
            ];
        }

        if ($isTeam) {
            $this->rollbackLoserTeamAdvancement($schedule);

            $this->deleteOutcomesForMatch($schedule, true);
        } else {
            $this->rollbackLoserAdvancement($schedule);

            $this->deleteOutcomesForMatch($schedule, false);
        }

        $schedule->update([
            'winner_registration_id' => null,
            'winner_team_id' => null,
            'finish_reason' => null,
            'finish_notes' => null,
            'finished_at' => null,
            'finished_by' => null,
            'status' => 'Scheduled',
        ]);

        $schedule->refresh();

        // Final status reconciliation: bracket match yang masih 2/2 (peserta
        // lengkap) otomatis kembali ke Ready; Playing/Ready di-hilir tidak
        // pernah disentuh karena checkAutoReady hanya mengevaluasi
        // Scheduled/Ready. Match non-bracket tidak diubah perilakunya.
        if ($schedule->bracketMatch()->exists()) {
            $this->checkAutoReady($schedule);
        }

        return [
            'reset' => true,
            'invalidated_downstream' => $cascade['invalidated'],
            'status' => $schedule->status,
        ];
    }

    /**
     * Cascade-invalidasi match di hilir (jalur winner) dari match yang
     * direset. Membangun rantai downstream dulu (tanpa mutasi) sehingga luas
     * gangguan diketahui, lalu menerapkannya deepest-first agar penggunaan
     * `first()` pada lookup source id deterministik (bronze di-exclude).
     *
     * @return array{ok: bool, invalidated?: bool, blocked_schedule_id?: int}
     */
    private function invalidateDownstreamBranch(CompetitionSchedule $schedule, bool $isTeam): array
    {
        $steps = [];
        $cursor = $schedule->fresh();

        for ($guard = 0; $guard < 64; $guard++) {
            $bracketMatch = $cursor->bracketMatch;
            if (! $bracketMatch || $bracketMatch->is_third_place) {
                break;
            }

            $winner = $isTeam ? $cursor->winner_team_id : $cursor->winner_registration_id;
            if ($winner === null) {
                break;
            }

            $next = CompetitionBracketMatch::where(function ($q) use ($bracketMatch) {
                $q->where('source_match_a_id', $bracketMatch->id)
                    ->orWhere('source_match_b_id', $bracketMatch->id);
            })
                ->where('is_third_place', false)
                ->with('schedule')
                ->first();

            if (! $next || ! $next->schedule) {
                break;
            }

            $nextSchedule = $next->schedule->fresh();

            // Proteksi: downstream yang sedang berjalan tidak boleh direset
            // dari hulu — akan merusak state Playing/Waiting Result.
            if (in_array($nextSchedule->status, ['Playing', 'Waiting Result'], true)) {
                return ['ok' => false, 'blocked_schedule_id' => $nextSchedule->id];
            }

            $steps[] = [$next, $winner];

            $cursor = $nextSchedule;
        }

        foreach (array_reverse($steps) as [$match, $winner]) {
            $this->applyInvStep($match, $winner, $isTeam);
        }

        return ['ok' => true, 'invalidated' => $steps !== []];
    }

    /**
     * Terapkan satu langkah cascade: hapus entry winner dari match hilir,
     * lalu invalidasi penuh bila Finished, atau rollback status bila belum.
     */
    private function applyInvStep(CompetitionBracketMatch $match, int $winner, bool $isTeam): void
    {
        $childSchedule = $match->schedule;
        $wasFinished = $childSchedule->status === 'Finished';

        // Hapus outcome lebih dulu (berdasarkan entry yang masih ada di DB)
        // baru entry-nya — supaya identitas Juara 1/2/3 tidak terlewat.
        if ($wasFinished) {
            $this->invalidateFinishedMatch($childSchedule, $isTeam);
        }

        CompetitionScheduleEntry::where('competition_schedule_id', $childSchedule->id)
            ->where($isTeam ? 'competition_team_id' : 'competition_registration_id', $winner)
            ->delete();

        $childSchedule->refresh();

        if (! $wasFinished) {
            $remainingCount = $childSchedule->scheduleEntries()->count();
            if ($remainingCount < ($childSchedule->required_participants ?? 2)) {
                $childSchedule->update(['status' => 'Scheduled']);
            }
        }

        $this->checkAutoReady($childSchedule);
    }

    /**
     * Invalidasi penuh match hilir yang statusnya Finished (bukan bronze):
     * bersihkan metadata winner/finish, hapus outcome podium, dan kembalikan
     * status ke Scheduled (evaluasi ulang via checkAutoReady).
     */
    private function invalidateFinishedMatch(CompetitionSchedule $schedule, bool $isTeam): void
    {
        $this->deleteOutcomesForMatch($schedule, $isTeam);

        $schedule->update([
            'winner_registration_id' => null,
            'winner_team_id' => null,
            'finish_reason' => null,
            'finish_notes' => null,
            'finished_at' => null,
            'finished_by' => null,
            'status' => 'Scheduled',
        ]);
    }

    /**
     * Hapus outcome podium terkait match:
     * - Final + bronze OFF → seluruh outcome bracket (Juara 1/2/3) dihapus
     *   agar tied-3rd yang ditulis finalisasi Final ikut dibersihkan/dihitung
     *   ulang; bronze dicegah merusak bracket lain (scoped ke bracket).
     * - Selain itu → outcome entry match sendiri, melindungi Bronze yang
     *   sudah dimainkan (played/bronze protection dipertahankan).
     */
    private function deleteOutcomesForMatch(CompetitionSchedule $schedule, bool $isTeam): void
    {
        $bracketMatch = $schedule->bracketMatch;
        $bracket = $bracketMatch?->bracket;
        $isFinal = $bracketMatch !== null
            && ! $bracketMatch->is_third_place
            && (int) $bracketMatch->round === 1;

        if ($isFinal && $bracket && ! $bracket->third_place_match) {
            $this->deleteAllBracketOutcomes($bracket, $isTeam);

            return;
        }

        if ($isTeam) {
            $ids = $schedule->scheduleEntries()->pluck('competition_team_id');
            $protectedIds = $this->playedBronzeEntries($schedule)->pluck('competition_team_id');

            CompetitionTeamOutcome::whereIn('competition_team_id', $ids->all())
                ->when($protectedIds->isNotEmpty(), fn ($query) => $query->whereNotIn('competition_team_id', $protectedIds->all()))
                ->delete();

            return;
        }

        $ids = $schedule->scheduleEntries()->pluck('competition_registration_id');
        $protectedIds = $this->playedBronzeEntries($schedule)->pluck('competition_registration_id');

        CompetitionOutcome::whereIn('competition_registration_id', $ids->all())
            ->when($protectedIds->isNotEmpty(), fn ($query) => $query->whereNotIn('competition_registration_id', $protectedIds->all()))
            ->delete();
    }

    /** Hapus seluruh outcome podium dari satu bracket (Juara 1/2/3/4). */
    private function deleteAllBracketOutcomes(CompetitionBracket $bracket, bool $isTeam): void
    {
        $scheduleIds = $bracket->bracketMatches()->pluck('competition_schedule_id');

        $ids = CompetitionScheduleEntry::whereIn('competition_schedule_id', $scheduleIds)
            ->pluck($isTeam ? 'competition_team_id' : 'competition_registration_id')
            ->filter()
            ->unique();

        if ($ids->isEmpty()) {
            return;
        }

        if ($isTeam) {
            CompetitionTeamOutcome::whereIn('competition_team_id', $ids->all())->delete();
        } else {
            CompetitionOutcome::whereIn('competition_registration_id', $ids->all())->delete();
        }
    }

    public function assignParticipant(CompetitionSchedule $schedule, int $registrationId): void
    {
        CompetitionScheduleEntry::create([
            'competition_schedule_id' => $schedule->id,
            'competition_registration_id' => $registrationId,
        ]);

        $this->checkAutoReady($schedule);
    }

    public function unassignParticipant(CompetitionSchedule $schedule, int $registrationId): void
    {
        CompetitionScheduleEntry::where('competition_schedule_id', $schedule->id)
            ->where('competition_registration_id', $registrationId)
            ->delete();

        $this->checkAutoReady($schedule);
    }

    public function checkAutoReady(CompetitionSchedule $schedule): void
    {
        $schedule->refresh();

        if ($schedule->status === 'Scheduled' && $schedule->canAutoReady()) {
            $schedule->update(['status' => 'Ready']);
        } elseif (in_array($schedule->status, ['Ready', 'Scheduled'], true)
            && $schedule->scheduleEntries()->count() < $schedule->minParticipantsToStart()) {
            $schedule->update(['status' => 'Scheduled']);
        }

        $schedule->refresh();
    }

    /**
     * After a match is Finished, finalize Juara 1/2/3 when it is the final of a
     * single-elimination bracket — registration (Individual vs Individual) or
     * team (Team vs Team).
     */
    private function finalizePodium(CompetitionSchedule $schedule): void
    {
        $service = app(CompetitionBracketPodiumService::class);

        if ($this->isTeamMatch($schedule)) {
            $service->finalizeTeamPodiumForSchedule($schedule);

            return;
        }

        $service->finalizePodiumForSchedule($schedule);
    }
}
