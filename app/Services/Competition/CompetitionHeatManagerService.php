<?php

namespace App\Services\Competition;

use App\Models\CompetitionClass;
use App\Models\CompetitionHeatFormat;
use App\Models\CompetitionHeatQualifier;
use App\Models\CompetitionHeatResult;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionSchedule;
use App\Models\CompetitionScheduleEntry;
use App\Models\CompetitionTeam;
use App\Support\CompetitionFormat;
use App\Support\CompetitionResultType;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Heat Manager — orchestrator UI/workflow di atas struktur heat existing.
 *
 * Heat Manager BUKAN architecture paralel. Ia meng-orchestrate struktur yang
 * sudah ada:
 *
 * - Format heat (peserta per heat + lolos per heat per round) dipersist ke
 *   `competition_heat_formats` (baru) — satu-satunya state yang sebelumnya tidak
 *   ada tempat simpan (top_n sengaja tidak dipersist di Sprint R4H).
 * - Heat (jadwal per round) tetap `competition_schedules` (sort_order =
 *   round*100 + heatIndex; required_participants = kapasitas).
 * - Peserta/team heat tetap `competition_schedule_entries` (competition_
 *   registration_id / competition_team_id — tidak pernah menukar identity).
 * - Hasil + ranking per-heat tetap `competition_heat_results` + rankHeat &
 *   advanceRound `CompetitionMultiRoundHeatService` (TIDAK diduplikasi).
 * - Input hasil tetap `OutcomeManager` (Livewire) — Heat Manager hanya menautkan.
 * - Bracket TIDAK disentuh; heat (individual_heat / team_heat) bukan format VS,
 *   jadi tidak pernah memaksakan model bracket. Kelas VS tetap di luar scope.
 *
 * Alur yang dipersembahkan ke operator:
 *   Format (7/heat, lolos 3) → Generate Heat Round 1 → Input Hasil/rank per heat
 *   → Generate Round Berikutnya (auto buat heat round berikutnya dari qualifier)
 *   → ... sampai format round berikutnya tidak ada (single-round → tidak boleh
 *   memfabrikasi round).
 */
class CompetitionHeatManagerService
{
    public function __construct(
        private readonly CompetitionMultiRoundHeatService $multiRound,
    ) {}

    /** Format yang didukung Heat Manager (competitor = Registration ATAU Team). */
    public const SUPPORTED_FORMATS = [
        CompetitionFormat::INDIVIDUAL_HEAT,
        CompetitionFormat::TEAM_HEAT,
    ];

    // -------------------------------------------------------------------------
    // Format
    // -------------------------------------------------------------------------

    public function isSupportedClass(CompetitionClass $class): bool
    {
        return $this->isSupportedFormat($class->format)
            && CompetitionResultType::isRanked($class->resultType());
    }

    public function isSupportedFormat(?string $format): bool
    {
        return in_array($format, self::SUPPORTED_FORMATS, true);
    }

    public function formats(int $classId): Collection
    {
        return CompetitionHeatFormat::where('competition_class_id', $classId)
            ->orderBy('round')
            ->get();
    }

    public function formatForRound(int $classId, int $round): ?CompetitionHeatFormat
    {
        return CompetitionHeatFormat::where('competition_class_id', $classId)
            ->where('round', $round)
            ->first();
    }

    /**
     * Validasi format heat.
     *
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function validateFormat(
        int $classId,
        int $round,
        int $participantsPerHeat,
        int $qualifiersPerHeat,
        int $minParticipantsToStart = 2,
    ): array {
        $class = CompetitionClass::find($classId);
        $unit = ($class !== null && $class->isTeamFormat()) ? 'tim' : 'peserta';

        $errors = [];

        if ($round < 1) {
            $errors[] = 'Round harus minimal 1.';
        }

        if ($participantsPerHeat < 1) {
            $errors[] = ucfirst($unit).' per heat harus lebih dari 0.';
        }

        if ($minParticipantsToStart < 1) {
            $errors[] = 'Minimum '.$unit.' untuk start harus lebih dari 0.';
        }

        if ($minParticipantsToStart > $participantsPerHeat) {
            $errors[] = 'Minimum '.$unit.' untuk start tidak boleh melebihi '.$unit.' per heat.';
        }

        if ($qualifiersPerHeat < 1) {
            $errors[] = 'Jumlah lolos per heat harus lebih dari 0.';
        }

        if ($qualifiersPerHeat > $participantsPerHeat) {
            $errors[] = 'Jumlah lolos tidak boleh melebihi '.$unit.' per heat.';
        }

        if ($class === null) {
            $errors[] = 'Kelas tidak ditemukan.';

            return ['valid' => false, 'errors' => $errors];
        }

        if (! $this->isSupportedFormat($class->format)) {
            $errors[] = 'Heat Manager hanya mendukung format '.implode(' / ', self::SUPPORTED_FORMATS).'.';
        }

        $resultType = $class->resultType();

        if (! CompetitionResultType::isValid($resultType)) {
            $errors[] = 'Result type tidak valid.';
        }

        if (! CompetitionResultType::isRanked($resultType)) {
            $errors[] = 'Format heat memerlukan result type yang bisa di-ranking (score/time/ranking).';
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    /**
     * Simpan (upsert) format heat sebuah round.
     *
     * @throws ValidationException bila format tidak valid.
     */
    public function upsertFormat(
        int $eventId,
        int $classId,
        int $round,
        int $participantsPerHeat,
        int $qualifiersPerHeat,
        int $minParticipantsToStart = 2,
    ): CompetitionHeatFormat {
        return DB::transaction(function () use ($eventId, $classId, $round, $participantsPerHeat, $qualifiersPerHeat, $minParticipantsToStart) {
            $this->classInEvent($eventId, $classId);

            $validation = $this->validateFormat($classId, $round, $participantsPerHeat, $qualifiersPerHeat, $minParticipantsToStart);

            if (! $validation['valid']) {
                throw ValidationException::withMessages(['format' => $validation['errors']]);
            }

            return CompetitionHeatFormat::updateOrCreate(
                ['competition_class_id' => $classId, 'round' => $round],
                [
                    'participants_per_heat' => $participantsPerHeat,
                    'min_participants_to_start' => $minParticipantsToStart,
                    'qualifiers_per_heat' => $qualifiersPerHeat,
                ],
            );
        });
    }

    public function deleteFormat(int $eventId, int $formatId): void
    {
        $format = CompetitionHeatFormat::with('competitionClass')->findOrFail($formatId);

        if ((int) $format->competitionClass?->event_id !== (int) $eventId) {
            throw new ModelNotFoundException('Format heat bukan milik event aktif.');
        }

        CompetitionHeatQualifier::where('competition_class_id', $format->competition_class_id)
            ->where('round', $format->round)
            ->delete();

        $format->delete();
    }

    public function heatQualifiers(int $classId, int $round): Collection
    {
        return CompetitionHeatQualifier::where('competition_class_id', $classId)
            ->where('round', $round)
            ->orderBy('heat_index')
            ->get();
    }

    public function effectiveQualifiersForHeat(int $classId, int $round, int $heatIndex): ?int
    {
        $override = CompetitionHeatQualifier::where('competition_class_id', $classId)
            ->where('round', $round)
            ->where('heat_index', $heatIndex)
            ->first();

        if ($override !== null) {
            return (int) $override->qualifiers_per_heat;
        }

        $format = $this->formatForRound($classId, $round);

        return $format ? (int) $format->qualifiers_per_heat : null;
    }

    public function upsertHeatQualifier(int $eventId, int $classId, int $round, int $heatIndex, int $qualifiersPerHeat): CompetitionHeatQualifier
    {
        return DB::transaction(function () use ($eventId, $classId, $round, $heatIndex, $qualifiersPerHeat) {
            $this->classInEvent($eventId, $classId);

            if ($round < 1 || $heatIndex < 1) {
                throw ValidationException::withMessages(['heat_qualifier' => 'Round dan heat index harus >= 1.']);
            }

            if ($qualifiersPerHeat < 1) {
                throw ValidationException::withMessages(['heat_qualifier' => 'Jumlah lolos per heat harus lebih dari 0.']);
            }

            $schedules = $this->multiRound->roundSchedules($classId, $round);
            $target = $schedules->values()->get($heatIndex - 1);

            if ($target !== null) {
                $entryCount = $target->scheduleEntries()->count();
                if ($entryCount > 0 && $qualifiersPerHeat > $entryCount) {
                    throw ValidationException::withMessages(['heat_qualifier' => 'Jumlah lolos tidak boleh melebihi peserta heat.']);
                }
            }

            return CompetitionHeatQualifier::updateOrCreate(
                ['competition_class_id' => $classId, 'round' => $round, 'heat_index' => $heatIndex],
                ['qualifiers_per_heat' => $qualifiersPerHeat],
            );
        });
    }

    public function deleteHeatQualifier(int $eventId, int $qualifierId): void
    {
        $qualifier = CompetitionHeatQualifier::with('competitionClass')->findOrFail($qualifierId);

        if ((int) $qualifier->competitionClass?->event_id !== (int) $eventId) {
            throw new ModelNotFoundException('Heat qualifier bukan milik event aktif.');
        }

        $qualifier->delete();
    }

    // -------------------------------------------------------------------------
    // Compute / pool
    // -------------------------------------------------------------------------

    /** Jumlah kompetitor yang akan di-split ketika generate round 1. */
    public function competitorCount(int $classId): int
    {
        $class = CompetitionClass::findOrFail($classId);

        return $class->isTeamFormat()
            ? CompetitionTeam::where('competition_class_id', $classId)->where('is_active', true)->count()
            : CompetitionRegistration::where('competition_class_id', $classId)->count();
    }

    /**
     * Perkiraan jumlah heat sebuah round dari format.
     *
     * Round 1 memakai jumlah registrasi/team terdaftar; round berikutnya memakai
     * estimasi qualifier round sebelumnya bila formatnya tersedia (jumlah heat
     * akurat baru diketahui saat `generateNextRound` berjalan).
     */
    public function computeHeatCount(int $classId, int $round): ?int
    {
        $format = $this->formatForRound($classId, $round);

        if ($format === null) {
            return null;
        }

        if ($round <= 1) {
            $pool = $this->competitorCount($classId);

            return $pool === 0 ? 0 : (int) ceil($pool / $format->participants_per_heat);
        }

        $prevFormat = $this->formatForRound($classId, $round - 1);

        if ($prevFormat === null) {
            return null;
        }

        $prevHeatCount = $this->computeHeatCount($classId, $round - 1) ?? 0;

        $estimatedQualifiers = ($prevHeatCount ?? 0) * $prevFormat->qualifiers_per_heat;

        return $estimatedQualifiers === 0
            ? null
            : (int) ceil($estimatedQualifiers / $format->participants_per_heat);
    }

    /**
     * Apakah heat round ini perlu dibangun ulang dari format saat ini?
     *
     * `needs_rebuild` = true bila sudah ada heat pada round tsb DAN:
     * - jumlah heat tidak lagi sama dengan perhitungan format
     *   (`ceil(eligible_team_count / teams_per_heat)`), ATAU
     * - kapasitas (`required_participants`) salah satu heat tidak sama dengan
     *   `participants_per_heat` format.
     *
     * Dipakai UI untuk menampilkan aksi "Generate Ulang Babak Ini" — bukan
     * untuk rebuild otomatis. Menghitung ulang dari CompetitionTeam (bukan
     * member/participant); `team_size`, `min_participants_to_start`, dan
     * `qualifiers_per_heat` tidak memengaruhi jumlah heat.
     */
    public function needsRebuild(int $classId, int $round): bool
    {
        $format = $this->formatForRound($classId, $round);

        if ($format === null) {
            return false;
        }

        $schedules = $this->multiRound->roundSchedules($classId, $round);

        if ($schedules->isEmpty()) {
            return false;
        }

        // Round 1: jumlah heat dapat dihitung persis dari CompetitionTeam aktif
        // (ceil(eligible_team_count / teams_per_heat)). Round > 1 bergantung pada
        // pool qualifier yang bersifat dinamis, jadi hanya kapasitas yang dicek.
        if ($round <= 1) {
            $expected = $this->computeHeatCount($classId, $round);

            if ($expected !== null && $schedules->count() !== $expected) {
                return true;
            }
        }

        return $schedules->contains(
            fn ($schedule) => (int) $schedule->required_participants !== (int) $format->participants_per_heat
        );
    }

    // -------------------------------------------------------------------------
    // Generate Heat
    // -------------------------------------------------------------------------

    /**
     * Generate heat sebuah round dari POOL penuh kelas (dipakai untuk round 1).
     *
     * Format heat adalah SATU-SATUNYA sumber kebenaran untuk kapasitas:
     * - `competition_heat_formats.participants_per_heat` → `required_participants`
     *   SETIAP heat dibuat, SELALU (kapasitas lama/legacy diabaikan total).
     * - Kompetitor dibagi berurutan per `participants_per_heat` (chunk) ke
     *   `competition_schedule_entries` — identity tidak pernah diubah/ditukar.
     * - Idempotent: menolak bila round sudah punya heat (`round_exists`).
     *
     * @return array{
     *     generated: bool,
     *     reason?: string,
     *     round: int,
     *     heat_count: int,
     *     competitors_used: int,
     *     schedule_ids: array<int, int>,
     * }
     */
    public function generateRound(int $eventId, int $classId, int $round): array
    {
        return DB::transaction(function () use ($eventId, $classId, $round) {
            $class = $this->classInEvent($eventId, $classId);
            $format = $this->formatForRound($class->id, $round);

            if ($format === null) {
                return ['generated' => false, 'reason' => 'no_format', 'round' => $round, 'heat_count' => 0, 'competitors_used' => 0, 'schedule_ids' => []];
            }

            if ($this->multiRound->roundSchedules($class->id, $round)->isNotEmpty()) {
                return ['generated' => false, 'reason' => 'round_exists', 'round' => $round, 'heat_count' => 0, 'competitors_used' => 0, 'schedule_ids' => []];
            }

            $generated = $this->generateRoundInternal($class, $format, $round);

            if ($generated === null) {
                return ['generated' => false, 'reason' => 'no_competitors', 'round' => $round, 'heat_count' => 0, 'competitors_used' => 0, 'schedule_ids' => []];
            }

            return $generated;
        });
    }

    /**
     * Build ulang heat sebuah round dari format sebagai sumber kebenaran.
     *
     * Diperuntukkan memperbaiki round yang heat-nya dibuat di luar Heat Manager
     * (mis. Jadwal manual / R4H legacy dengan `required_participants` 2 per heat,
     * persis kondisi UAT 2026-08-26). Heat round yang TIDAK dimulai dihapus lalu
     * di-generate ulang dari `participants_per_heat`.
     *
     * Data aktual tidak pernah hilang:
     * - menolak bila ada heat `Playing`/`Waiting Result`/`Finished` (`round_started`), DAN
     * - menolak bila sudah ada `competition_heat_results` pada round tsb (`has_results`).
     *
     * Tidak ada mutation otomatis — aksi operator eksplisit ("Generate Ulang Babak Ini").
     *
     * @return array{
     *     rebuilt: bool,
     *     reason?: string,
     *     round: int,
     *     heat_count: int,
     *     competitors_used: int,
     *     schedule_ids: array<int, int>,
     * }
     */
    public function rebuildRound(int $eventId, int $classId, int $round): array
    {
        return DB::transaction(function () use ($eventId, $classId, $round) {
            $class = $this->classInEvent($eventId, $classId);
            $format = $this->formatForRound($class->id, $round);

            if ($format === null) {
                return ['rebuilt' => false, 'reason' => 'no_format', 'round' => $round, 'heat_count' => 0, 'competitors_used' => 0, 'schedule_ids' => []];
            }

            $existing = $this->multiRound->roundSchedules($class->id, $round);

            $started = $existing->first(fn ($schedule) => in_array($schedule->status, ['Playing', 'Waiting Result', 'Finished'], true));

            if ($started !== null) {
                return ['rebuilt' => false, 'reason' => 'round_started', 'round' => $round, 'heat_count' => 0, 'competitors_used' => 0, 'schedule_ids' => []];
            }

            if (CompetitionHeatResult::whereIn('competition_schedule_id', $existing->pluck('id'))->exists()) {
                return ['rebuilt' => false, 'reason' => 'has_results', 'round' => $round, 'heat_count' => 0, 'competitors_used' => 0, 'schedule_ids' => []];
            }

            foreach ($existing as $schedule) {
                $schedule->delete();
            }

            $generated = $this->generateRoundInternal($class, $format, $round);

            if ($generated === null) {
                return ['rebuilt' => false, 'reason' => 'no_competitors', 'round' => $round, 'heat_count' => 0, 'competitors_used' => 0, 'schedule_ids' => []];
            }

            return ['rebuilt' => true] + $generated;
        });
    }

    // -------------------------------------------------------------------------
    // Generate Round Berikutnya
    // -------------------------------------------------------------------------

    /**
     * Auto create heat round berikutnya dari qualified pool heat round ini.
     *
     * - Pool = top-N per heat yang SUDAH selesai (`CompetitionMultiRoundHeatService`
     *   `qualifiedPool` — tidak diduplikasi). Heat yang belum selesai di-skip; guard
     *   lama "semua heat selesai" (`not_all_finished`) DIGANTI menjadi
     *   `qualified_pool_insufficient` bila pool belum mencapai kapasitas format.
     *   Format `$round` DAN format `$round+1` wajib tersedia; tanpa format
     *   berikutnya → menolak (`no_next_format`) dan TIDAK memfabrikasi round
     *   (persyaratan test H).
     * - Jumlah heat berikutnya = ceil(qualifier / participants_per_heat format
     *   berikutnya); heat dibuat dengan sort_order = (round+1)*100 + i. Round
     *   berikutnya TIDAK dibuat prematur bila pool < capacity.
     * - Assignment qualifier → entries memakai `advanceRound` existing (top-N per
     *   heat, exclude status, fill-kapasitas, idempotent, next heat `Ready`,
     *   tidak pernah `Playing`).
     * - Idempotent: menolak bila round berikutnya sudah punya heat
     *   (`next_round_exists`) supaya tidak menciptakan schedule duplikat.
     *
     * @return array{
     *     advanced: bool,
     *     reason?: string,
     *     round: int,
     *     next_round: int,
     *     heat_count: int,
     *     qualifiers: int,
     *     assigned: int,
     *     schedule_ids: array<int, int>,
     * }
     */
    public function generateNextRound(int $eventId, int $classId, int $round): array
    {
        return DB::transaction(function () use ($eventId, $classId, $round) {
            $class = $this->classInEvent($eventId, $classId);

            $format = $this->formatForRound($class->id, $round);
            if ($format === null) {
                return ['advanced' => false, 'reason' => 'no_format', 'round' => $round, 'next_round' => $round + 1, 'heat_count' => 0, 'qualifiers' => 0, 'assigned' => 0, 'schedule_ids' => []];
            }

            $nextFormat = $this->formatForRound($class->id, $round + 1);
            if ($nextFormat === null) {
                return ['advanced' => false, 'reason' => 'no_next_format', 'round' => $round, 'next_round' => $round + 1, 'heat_count' => 0, 'qualifiers' => 0, 'assigned' => 0, 'schedule_ids' => []];
            }

            if ($this->multiRound->roundSchedules($class->id, $round + 1)->isNotEmpty()) {
                return ['advanced' => false, 'reason' => 'next_round_exists', 'round' => $round, 'next_round' => $round + 1, 'heat_count' => 0, 'qualifiers' => 0, 'assigned' => 0, 'schedule_ids' => []];
            }

            // Qualified pool: top-N per heat yang SUDAH selesai (per-heat), BUKAN
            // "semua heat selesai" (guard not_all_finished dihapus). Heat yang belum
            // selesai di-skip — qualification-nya menunggu sampai heat tsb selesai.
            $pool = $this->multiRound->qualifiedPool($eventId, $class->id, $round, (int) $format->qualifiers_per_heat);
            $qualifierCount = $pool['qualified_count'];

            if ($qualifierCount === 0) {
                return ['advanced' => false, 'reason' => 'no_qualifiers', 'round' => $round, 'next_round' => $round + 1, 'heat_count' => 0, 'qualifiers' => 0, 'assigned' => 0, 'schedule_ids' => []];
            }

            $nextPerHeat = (int) $nextFormat->participants_per_heat;

            // Jangan membuat round berikutnya prematur: pool qualified harus cukup
            // untuk minimal satu heat penuh sesuai kapasitas format.
            if ($qualifierCount < $nextPerHeat) {
                return ['advanced' => false, 'reason' => 'qualified_pool_insufficient', 'round' => $round, 'next_round' => $round + 1, 'heat_count' => 0, 'qualifiers' => $qualifierCount, 'assigned' => 0, 'schedule_ids' => []];
            }

            $isTeam = $class->isTeamFormat();
            $qualifiers = $pool['qualifiers'];
            $heatCount = (int) ceil($qualifierCount / $nextPerHeat);

            // Distribusi seimbang: max(heat_size) - min(heat_size) <= 1.
            // base = floor(total / heatCount); extra heat pertama mendapat +1 peserta.
            $base = intdiv($qualifierCount, $heatCount);
            $extra = $qualifierCount % $heatCount;

            $scheduleIds = [];
            $assigned = 0;
            $qualifierOffset = 0;

            for ($i = 1; $i <= $heatCount; $i++) {
                $heatSize = $base + ($i <= $extra ? 1 : 0);

                $schedule = CompetitionSchedule::create([
                    'competition_class_id' => $class->id,
                    'status' => 'Scheduled',
                    'required_participants' => $nextPerHeat,
                    'sort_order' => (($round + 1) * 100) + $i,
                ]);

                $order = 1;
                for ($j = 0; $j < $heatSize && $qualifierOffset < $qualifierCount; $j++, $qualifierOffset++) {
                    CompetitionScheduleEntry::create([
                        'competition_schedule_id' => $schedule->id,
                        $isTeam ? 'competition_team_id' : 'competition_registration_id' => $qualifiers[$qualifierOffset],
                        'order_number' => $order++,
                    ]);
                    $assigned++;
                }

                if ($schedule->canAutoReady()) {
                    $schedule->update(['status' => 'Ready']);
                }

                $scheduleIds[] = (int) $schedule->id;
            }

            return [
                'advanced' => $assigned > 0,
                'round' => $round,
                'next_round' => $round + 1,
                'heat_count' => $heatCount,
                'qualifiers' => $qualifierCount,
                'assigned' => $assigned,
                'schedule_ids' => $scheduleIds,
            ];
        });
    }

    /**
     * Hapus heat (schedule) sebuah round yang BELUM dimulai — untuk redo.
     *
     * Hanya menghapus schedule berstatus `Scheduled`/`Ready`; menolak bila ada
     * heat yang sudah `Playing`/`Waiting Result`/`Finished` (data aktual tidak
     * boleh hilang tanpa konfirmasi lebih lanjut). Delete cascade menangani
     * entries + heat results + relasi bracket (model booted hook existing).
     *
     * @return array{deleted: bool, reason?: string, round: int, schedule_ids: array<int, int>}
     */
    public function removeRoundSchedules(int $eventId, int $classId, int $round): array
    {
        $class = $this->classInEvent($eventId, $classId);

        $schedules = $this->multiRound->roundSchedules($class->id, $round);

        if ($schedules->isEmpty()) {
            return ['deleted' => false, 'reason' => 'no_heats', 'round' => $round, 'schedule_ids' => []];
        }

        $started = $schedules->first(fn ($schedule) => in_array($schedule->status, ['Playing', 'Waiting Result', 'Finished'], true));

        if ($started !== null) {
            return ['deleted' => false, 'reason' => 'round_started', 'round' => $round, 'schedule_ids' => []];
        }

        $ids = $schedules->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        foreach ($schedules as $schedule) {
            $schedule->delete();
        }

        return ['deleted' => true, 'round' => $round, 'schedule_ids' => $ids];
    }

    // -------------------------------------------------------------------------
    // Team Heat — assignment manual (operator memilih Team ke heat)
    // -------------------------------------------------------------------------

    /**
     * Masukkan satu Team ke sebuah heat pada sebuah round.
     *
     * Heat yang sudah dimulai (`Playing`/`Waiting Result`/`Finished`) atau yang
     * sudah punya hasil TIDAK bisa diubah. Satu team tidak boleh berada di dua
     * heat pada round yang sama; kapasitas heat dihormati (`required_participants`).
     *
     * @return array{assigned: bool, schedule_id: int, heat_index: int, team_id: int}
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function assignTeamToHeat(int $eventId, int $classId, int $round, int $heatIndex, int $teamId): array
    {
        return DB::transaction(function () use ($eventId, $classId, $round, $heatIndex, $teamId) {
            $class = $this->classInEvent($eventId, $classId);
            $this->assertTeamHeatClass($class);

            $team = CompetitionTeam::where('event_id', $eventId)
                ->where('competition_class_id', $class->id)
                ->where('is_active', true)
                ->find($teamId);

            if ($team === null) {
                throw ValidationException::withMessages(['team' => 'Team tidak ditemukan atau tidak aktif pada kelas ini.']);
            }

            $schedules = $this->multiRound->roundSchedules($class->id, $round);
            $target = $this->heatForIndex($schedules, $heatIndex);

            if ($target === null) {
                throw ValidationException::withMessages(['heat' => 'Heat tidak ditemukan pada round ini.']);
            }

            $this->assertHeatEditable($target, 'Heat sudah berjalan/berhasil; team tidak bisa diubah.');

            $duplicate = $schedules
                ->filter(fn ($schedule) => (int) $schedule->id !== (int) $target->id)
                ->first(fn ($schedule) => $schedule->scheduleEntries()->where('competition_team_id', $teamId)->exists());

            if ($duplicate !== null) {
                throw ValidationException::withMessages(['team' => 'Team '.$team->name.' sudah berada di heat lain pada round ini.']);
            }

            if ($target->scheduleEntries()->count() >= (int) $target->required_participants) {
                throw ValidationException::withMessages(['heat' => 'Kapasitas heat sudah penuh ('.$target->required_participants.' slot).']);
            }

            CompetitionScheduleEntry::create([
                'competition_schedule_id' => $target->id,
                'competition_team_id' => $teamId,
                'order_number' => (int) ($target->scheduleEntries()->max('order_number') ?? 0) + 1,
            ]);

            $this->autoReady($target);

            return [
                'assigned' => true,
                'schedule_id' => (int) $target->id,
                'heat_index' => $heatIndex,
                'team_id' => (int) $teamId,
            ];
        });
    }

    /**
     * Keluarkan satu Team dari sebuah heat pada sebuah round (sebelum dimulai).
     *
     * @return array{removed: bool, schedule_id: int, heat_index: int, team_id: int}
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function removeTeamFromHeat(int $eventId, int $classId, int $round, int $heatIndex, int $teamId): array
    {
        return DB::transaction(function () use ($eventId, $classId, $round, $heatIndex, $teamId) {
            $class = $this->classInEvent($eventId, $classId);
            $this->assertTeamHeatClass($class);

            $schedules = $this->multiRound->roundSchedules($class->id, $round);
            $target = $this->heatForIndex($schedules, $heatIndex);

            if ($target === null) {
                throw ValidationException::withMessages(['heat' => 'Heat tidak ditemukan pada round ini.']);
            }

            $this->assertHeatEditable($target, 'Heat sudah berjalan/berhasil; team tidak bisa diubah.');

            $entry = CompetitionScheduleEntry::where('competition_schedule_id', $target->id)
                ->where('competition_team_id', $teamId)
                ->first();

            if ($entry === null) {
                throw ValidationException::withMessages(['team' => 'Team tidak berada di heat ini.']);
            }

            $entry->delete();

            return [
                'removed' => true,
                'schedule_id' => (int) $target->id,
                'heat_index' => $heatIndex,
                'team_id' => (int) $teamId,
            ];
        });
    }

    /**
     * Pindahkan satu Team antar heat pada round yang sama (sebelum dimulai).
     *
     * @return array{moved: bool, schedule_id: ?int, heat_index: int, team_id: int}
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function moveTeamBetweenHeats(int $eventId, int $classId, int $round, int $fromHeatIndex, int $toHeatIndex, int $teamId): array
    {
        return DB::transaction(function () use ($eventId, $classId, $round, $fromHeatIndex, $toHeatIndex, $teamId) {
            $class = $this->classInEvent($eventId, $classId);
            $this->assertTeamHeatClass($class);

            if ($fromHeatIndex === $toHeatIndex) {
                return ['moved' => true, 'schedule_id' => null, 'heat_index' => $fromHeatIndex, 'team_id' => (int) $teamId];
            }

            $schedules = $this->multiRound->roundSchedules($class->id, $round);
            $from = $this->heatForIndex($schedules, $fromHeatIndex);
            $to = $this->heatForIndex($schedules, $toHeatIndex);

            if ($from === null || $to === null) {
                throw ValidationException::withMessages(['heat' => 'Heat tidak ditemukan pada round ini.']);
            }

            $this->assertHeatEditable($from, 'Heat asal sudah berjalan/berhasil; team tidak bisa diubah.');
            $this->assertHeatEditable($to, 'Heat tujuan sudah berjalan/berhasil; team tidak bisa diubah.');

            $entry = CompetitionScheduleEntry::where('competition_schedule_id', $from->id)
                ->where('competition_team_id', $teamId)
                ->first();

            if ($entry === null) {
                throw ValidationException::withMessages(['team' => 'Team tidak berada di heat asal.']);
            }

            $duplicate = $schedules
                ->filter(fn ($schedule) => ! in_array((int) $schedule->id, [(int) $from->id, (int) $to->id], true))
                ->first(fn ($schedule) => $schedule->scheduleEntries()->where('competition_team_id', $teamId)->exists());

            if ($duplicate !== null) {
                throw ValidationException::withMessages(['team' => 'Team sudah berada di heat lain pada round ini.']);
            }

            if ($to->scheduleEntries()->count() >= (int) $to->required_participants) {
                throw ValidationException::withMessages(['heat' => 'Kapasitas heat tujuan sudah penuh ('.$to->required_participants.' slot).']);
            }

            $entry->update([
                'competition_schedule_id' => $to->id,
                'order_number' => (int) ($to->scheduleEntries()->max('order_number') ?? 0) + 1,
            ]);

            $this->autoReady($to);

            return [
                'moved' => true,
                'schedule_id' => (int) $to->id,
                'heat_index' => $toHeatIndex,
                'team_id' => (int) $teamId,
            ];
        });
    }

    /**
     * Distribusi otomatis (helper/preview) — mengacak urutan seluruh Team kelas
     * lalu mengisi heat round ini secara SEIMBANG (least-filled, selisih entry
     * antar heat maksimal 1; mis. 10 team / 3 heat → 4,3,3 bukan 4,4,2).
     *
     * Ini HANYA helper: hasilnya tetap bisa diubah operator lewat
     * `moveTeamBetweenHeats` / `removeTeamFromHeat`. Bukan bersifat aturan
     * berbasis urutan DB. Menolak bila round sudah berjalan atau sudah punya hasil.
     *
     * Komposisi tim TIDAK disentuh: entri tetap menunjuk `competition_team_id`
     * dari Pembagian Tim; Heat tidak pernah membuat/mengacak anggota tim.
     *
     * @return array{assigned: bool, heat_count: int, teams_assigned: int, schedule_ids: array<int, int>}
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function autoAssignRound(int $eventId, int $classId, int $round): array
    {
        return DB::transaction(function () use ($eventId, $classId, $round) {
            $class = $this->classInEvent($eventId, $classId);
            $this->assertTeamHeatClass($class);

            $schedules = $this->multiRound->roundSchedules($class->id, $round);

            if ($schedules->isEmpty()) {
                throw ValidationException::withMessages(['heat' => 'Belum ada heat pada round ini. Buat heat terlebih dahulu.']);
            }

            $started = $schedules->first(fn ($schedule) => in_array($schedule->status, ['Playing', 'Waiting Result', 'Finished'], true));

            if ($started !== null) {
                throw ValidationException::withMessages(['heat' => 'Round sudah dimulai; distribusi otomatis tidak diizinkan.']);
            }

            if (CompetitionHeatResult::whereIn('competition_schedule_id', $schedules->pluck('id'))->exists()) {
                throw ValidationException::withMessages(['heat' => 'Round sudah punya hasil; distribusi otomatis tidak diizinkan.']);
            }

            $teams = CompetitionTeam::where('competition_class_id', $class->id)
                ->where('is_active', true)
                ->orderBy('id')
                ->get();

            if ($teams->isEmpty()) {
                throw ValidationException::withMessages(['team' => 'Belum ada team aktif pada kelas ini.']);
            }

            CompetitionScheduleEntry::whereIn('competition_schedule_id', $schedules->pluck('id'))->delete();

            $scheduleList = $schedules->values();
            $capacities = $scheduleList->map(fn ($schedule) => max(0, (int) $schedule->required_participants))->all();
            $counts = array_fill(0, $scheduleList->count(), 0);

            $shuffled = $teams->shuffle()->values();
            $assigned = 0;
            $scheduleIds = [];

            // Isi heat dengan jumlah paling sedikit lebih dulu (least-filled):
            // selisih entry antar heat maksimal 1 dan kapasitas tetap dihormati.
            foreach ($shuffled as $team) {
                $target = null;

                foreach ($scheduleList as $index => $schedule) {
                    if ($counts[$index] >= $capacities[$index]) {
                        continue;
                    }

                    if ($target === null || $counts[$index] < $counts[$target]) {
                        $target = $index;
                    }
                }

                if ($target === null) {
                    break;
                }

                $counts[$target]++;

                CompetitionScheduleEntry::create([
                    'competition_schedule_id' => $scheduleList[$target]->id,
                    'competition_team_id' => $team->id,
                    'order_number' => $counts[$target],
                ]);
                $assigned++;
            }

            foreach ($scheduleList as $schedule) {
                $this->autoReady($schedule);
                $scheduleIds[] = (int) $schedule->id;
            }

            return [
                'assigned' => $assigned > 0,
                'heat_count' => $scheduleList->count(),
                'teams_assigned' => $assigned,
                'schedule_ids' => $scheduleIds,
            ];
        });
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function roundCompetitors(CompetitionClass $class): Collection
    {
        if ($class->isTeamFormat()) {
            return CompetitionTeam::where('competition_class_id', $class->id)
                ->where('is_active', true)
                ->orderBy('id')
                ->get();
        }

        return CompetitionRegistration::with('participation')
            ->where('competition_class_id', $class->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * Inti generate heat: format adalah sumber kebenaran kapasitas.
     *
     * `participants_per_heat` → `required_participants` tiap heat.
     *
     * - Individual Heat: kompetitor dibagi SECARA SEIMBANG (selisih entry antar
     *   heat maksimal 1, mis. 10/4 → 4,3,3 bukan 4,4,2), urutan identity tetap
     *   berdasarkan pool registrasi.
     * - Team Heat: heat dibuat KOSONG — TIDAK ada auto-assign berbasis urutan
     *   DB. Pemilihan Team ke heat dilakukan operator secara eksplisit melalui
     *   `assignTeamToHeat` / `moveTeamBetweenHeats` / `removeTeamFromHeat`.
     *
     * Mengabaikan kapasitas/state schedule lama. Null bila tidak ada kompetitor
     * (tidak ada heat dibuat).
     *
     * @return array{generated: bool, round: int, heat_count: int, competitors_used: int, schedule_ids: array<int, int>}|null
     */
    private function generateRoundInternal(CompetitionClass $class, CompetitionHeatFormat $format, int $round): ?array
    {
        $competitors = $this->roundCompetitors($class);
        $isTeam = $class->isTeamFormat();
        $perHeat = (int) $format->participants_per_heat;

        if ($competitors->isEmpty()) {
            return null;
        }

        $total = $competitors->count();
        $heatCount = (int) ceil($total / $perHeat);

        // Distribusi seimbang: selisih jumlah entry antar heat maksimal 1.
        // base = floor(total / heatCount); `extra` heat pertama mendapat +1.
        // (Bukan pemotongan per kapasitas penuh yang menghasilkan 4,4,2.)
        $base = intdiv($total, $heatCount);
        $extra = $total % $heatCount;

        $scheduleIds = [];
        $offset = 0;

        for ($heat = 1; $heat <= $heatCount; $heat++) {
            $heatSize = $base + ($heat <= $extra ? 1 : 0);

            $schedule = CompetitionSchedule::create([
                'competition_class_id' => $class->id,
                'status' => 'Scheduled',
                'required_participants' => $perHeat,
                'sort_order' => ($round * 100) + $heat,
            ]);

            if (! $isTeam) {
                $chunk = $competitors->slice($offset, $heatSize)->values();
                $offset += $heatSize;

                $order = 1;
                foreach ($chunk as $competitor) {
                    CompetitionScheduleEntry::create([
                        'competition_schedule_id' => $schedule->id,
                        $this->competitorColumn($isTeam) => $this->competitorId($competitor),
                        'order_number' => $order++,
                    ]);
                }
            }

            if ($schedule->canAutoReady()) {
                $schedule->update(['status' => 'Ready']);
            }

            $scheduleIds[] = (int) $schedule->id;
        }

        return [
            'generated' => true,
            'round' => $round,
            'heat_count' => $heatCount,
            'competitors_used' => $isTeam ? 0 : $total,
            'schedule_ids' => $scheduleIds,
        ];
    }

    private function competitorColumn(bool $isTeam): string
    {
        return $isTeam ? 'competition_team_id' : 'competition_registration_id';
    }

    private function heatForIndex(Collection $schedules, int $heatIndex): ?CompetitionSchedule
    {
        $index = 0;

        foreach ($schedules as $schedule) {
            $index++;

            if ($index === $heatIndex) {
                return $schedule;
            }
        }

        return null;
    }

    private function assertTeamHeatClass(CompetitionClass $class): void
    {
        if ($class->format !== CompetitionFormat::TEAM_HEAT) {
            throw ValidationException::withMessages(['heat' => 'Assignment team hanya untuk format Team Heat.']);
        }
    }

    private function assertHeatEditable(CompetitionSchedule $schedule, string $message): void
    {
        if (in_array($schedule->status, ['Playing', 'Waiting Result', 'Finished'], true)
            || CompetitionHeatResult::where('competition_schedule_id', $schedule->id)->exists()) {
            throw ValidationException::withMessages(['heat' => $message]);
        }
    }

    private function autoReady(CompetitionSchedule $schedule): void
    {
        if ($schedule->status === 'Scheduled' && $schedule->canAutoReady()) {
            $schedule->update(['status' => 'Ready']);
        }
    }

    private function competitorId($competitor): int
    {
        return (int) $competitor->getKey();
    }

    private function classInEvent(int $eventId, int $classId): CompetitionClass
    {
        return CompetitionClass::where('event_id', $eventId)->findOrFail($classId);
    }
}
