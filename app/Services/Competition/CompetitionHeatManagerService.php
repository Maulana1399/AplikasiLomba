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
        $errors = [];

        if ($round < 1) {
            $errors[] = 'Round harus minimal 1.';
        }

        if ($participantsPerHeat < 1) {
            $errors[] = 'Peserta per heat harus lebih dari 0.';
        }

        if ($minParticipantsToStart < 1) {
            $errors[] = 'Minimum peserta untuk start harus lebih dari 0.';
        }

        if ($minParticipantsToStart > $participantsPerHeat) {
            $errors[] = 'Minimum peserta untuk start tidak boleh melebihi peserta per heat.';
        }

        if ($qualifiersPerHeat < 1) {
            $errors[] = 'Jumlah lolos per heat harus lebih dari 0.';
        }

        if ($qualifiersPerHeat > $participantsPerHeat) {
            $errors[] = 'Jumlah lolos tidak boleh melebihi peserta per heat.';
        }

        $class = CompetitionClass::find($classId);

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
     * `participants_per_heat` → `required_participants` tiap heat, kompetitor
     * di-chunk per `participants_per_heat`. Mengabaikan kapasitas/state schedule
     * lama. Null bila tidak ada kompetitor (tidak ada heat dibuat).
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

        $heatCount = (int) ceil($competitors->count() / $perHeat);
        $scheduleIds = [];

        foreach ($competitors->chunk($perHeat) as $chunkIndex => $chunk) {
            $schedule = CompetitionSchedule::create([
                'competition_class_id' => $class->id,
                'status' => 'Scheduled',
                'required_participants' => $perHeat,
                'sort_order' => ($round * 100) + ($chunkIndex + 1),
            ]);

            $order = 1;
            foreach ($chunk as $competitor) {
                CompetitionScheduleEntry::create([
                    'competition_schedule_id' => $schedule->id,
                    $this->competitorColumn($isTeam) => $this->competitorId($competitor),
                    'order_number' => $order++,
                ]);
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
            'competitors_used' => $competitors->count(),
            'schedule_ids' => $scheduleIds,
        ];
    }

    private function competitorColumn(bool $isTeam): string
    {
        return $isTeam ? 'competition_team_id' : 'competition_registration_id';
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
