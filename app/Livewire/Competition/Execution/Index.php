<?php

namespace App\Livewire\Competition\Execution;

use App\Models\CompetitionBracket;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionSchedule;
use App\Models\CompetitionScheduleEntry;
use App\Models\CompetitionTeam;
use App\Models\Event;
use App\Services\Competition\CompetitionResultService;
use App\Services\Competition\CompetitionWorkflowService;
use App\Support\ActiveEventContext;
use App\Support\CompetitionFormat;
use App\Support\CompetitionResultType;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Lomba Execution — operator flow for formats handled outside Heat/Bracket.
 *
 * Format yang ditangani:
 * - Massal (individual_mass / team_mass) → buat SATU schedule per kelas,
 *   assign seluruh peserta/tim, lalu arahkan ke OutcomeManager (input hasil +
 *   auto-rank + podium). Tidak ada logika ranking kedua.
 * - Individual Scoring (= individual_mass + result_type score) → jalur sama
 *   dengan Massal.
 * - Individual vs Individual / Team vs Team → jalur bracket existing
 *   (BracketManager + OfficialPanel), halaman ini hanya menavigasi.
 *
 * Proteksi scoping:
 * - Daftar kelas selalu dibatasi ke event aktif (ActiveEventContext).
 * - Siapkan schedule hanya untuk kelas milik event aktif.
 * - Peserta/tim yang di-assign hanya dari kelas tsb (tidak pernah lintas
 *   kelas/lomba).
 */
class Index extends Component
{
    public string $selectedClassId = '';

    public string $filterEventId = '';

    public string $filterCategoryId = '';

    public string $filterFormat = '';

    public bool $processing = false;

    /** Format eksekusi yang tersedia di halaman ini. */
    public const FORMATS = [
        CompetitionFormat::INDIVIDUAL_MASS => 'Massal',
        'individual_scoring' => 'Individual Scoring',
        CompetitionFormat::INDIVIDUAL_VS_INDIVIDUAL => 'Individual vs Individual',
        CompetitionFormat::TEAM_VS_TEAM => 'Team vs Team',
    ];

    /** Format yang dieksekusi via jalur Massal (single schedule). */
    private const MASS_FORMATS = [
        CompetitionFormat::INDIVIDUAL_MASS,
        CompetitionFormat::TEAM_MASS,
    ];

    public function mount(): void
    {
        $event = app(ActiveEventContext::class)->requireCurrent();
        $this->filterEventId = (string) $event->id;
    }

    public function updatedFilterEventId(): void
    {
        $this->filterCategoryId = '';
        $this->selectedClassId = '';
        $this->resetValidation();
    }

    public function updatedFilterCategoryId(): void
    {
        $this->resetValidation();
    }

    public function updatedFilterFormat(): void
    {
        $this->resetValidation();
    }

    public function getEventsProperty(): \Illuminate\Support\Collection
    {
        return Event::where('event_type', 'competition')
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function getFilterCategoriesProperty(): \Illuminate\Support\Collection
    {
        $query = CompetitionCategory::where('is_active', true);

        if ($this->filterEventId === '' || $this->filterEventId === '0') {
            $query->whereHas('events', fn ($q) => $q->where('event_type', 'competition')->where('status', 'active'));
        } else {
            $query->whereHas('events', fn ($q) => $q->where('events.id', (int) $this->filterEventId));
        }

        return $query->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function selectClass($classId): void
    {
        $this->selectedClassId = (string) $classId;
        $this->resetErrorBag();
    }

    public function backToList(): void
    {
        $this->selectedClassId = '';
        $this->resetErrorBag();
    }

    /**
     * Siapkan schedule eksekusi Massal / Individual Scoring:
     * - Gunakan schedule yang sudah ada (idempotent) atau buat SATU schedule baru.
     * - Assign seluruh peserta (individual) / tim (team) kelas tsb yang belum masuk.
     * - Status schedule di-rekonsiliasi via CompetitionWorkflowService::checkAutoReady.
     *
     * Hanya untuk format massal; menolak format vs (jalurnya bracket).
     */
    public function prepareSchedule(): void
    {
        Gate::authorize('manage-events');

        if ($this->processing) {
            return;
        }
        $this->processing = true;

        try {
            $class = CompetitionClass::whereHas('event', fn ($q) => $q->where('event_type', 'competition')->where('status', 'active'))
                ->findOrFail((int) $this->selectedClassId);

            if (! $this->isMass($class)) {
                session()->flash('error', 'Format ini tidak dieksekusi via jadwal massal. Gunakan Bracket / Panel Official.');

                return;
            }

            $schedule = $this->scheduleForMass($class);

            [$assignedCompetitors, $participants] = $this->assignMassParticipants($class, $schedule);

            app(CompetitionWorkflowService::class)->checkAutoReady($schedule);

            session()->flash(
                'success',
                "Jadwal eksekusi siap: {$assignedCompetitors} peserta/tim dipasang dari {$participants} total."
            );
        } finally {
            $this->processing = false;
        }
    }

    /**
     * Reset jadwal eksekusi massal bila belum ada hasil yang dimainkan.
     * Hanya status Scheduled / Ready & tanpa outcome → hapus schedule (+ entries),
     * operator dapat menyusun ulang.
     */
    public function resetSchedule(): void
    {
        Gate::authorize('manage-events');

        $class = CompetitionClass::whereHas('event', fn ($q) => $q->where('event_type', 'competition')->where('status', 'active'))
            ->findOrFail((int) $this->selectedClassId);

        $schedule = $this->massSchedule($class);

        if ($schedule === null) {
            session()->flash('error', 'Belum ada jadwal eksekusi untuk kelas ini.');

            return;
        }

        if (! in_array($schedule->status, ['Scheduled', 'Ready'], true)) {
            session()->flash('error', 'Jadwal sudah dimulai (Playing/Finished) sehingga tidak bisa di-reset.');

            return;
        }

        if ($this->hasOutcomes($class)) {
            session()->flash('error', 'Kelas sudah punya hasil (outcome); hapus hasil dulu sebelum reset.');

            return;
        }

        CompetitionScheduleEntry::where('competition_schedule_id', $schedule->id)->delete();
        $schedule->delete();

        session()->flash('success', 'Jadwal eksekusi dihapus. Kelas dapat disiapkan ulang.');
    }

    // -------------------------------------------------------------------------
    // Helpers — Massal
    // -------------------------------------------------------------------------

    private function isMass(CompetitionClass $class): bool
    {
        return in_array($class->format, self::MASS_FORMATS, true);
    }

    private function massSchedule(CompetitionClass $class): ?CompetitionSchedule
    {
        return CompetitionSchedule::where('competition_class_id', $class->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    /**
     * Ambil schedule massal yang akan dipakai, atau buat bila belum ada.
     *
     * Idempotent: kelas massal memakai SATU schedule (hasil akhir satu jalur),
     * jadwal existing dipakai ulang, tidak pernah diduplikasi.
     */
    private function scheduleForMass(CompetitionClass $class): CompetitionSchedule
    {
        $existing = CompetitionSchedule::where('competition_class_id', $class->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $competitors = $class->isTeamFormat()
            ? (int) CompetitionTeam::where('competition_class_id', $class->id)->where('is_active', true)->count()
            : (int) CompetitionRegistration::where('competition_class_id', $class->id)->count();

        return CompetitionSchedule::create([
            'competition_class_id' => $class->id,
            'status' => 'Scheduled',
            'required_participants' => max(2, $competitors),
            'sort_order' => 1,
        ]);
    }

    /**
     * Assign seluruh peserta/tim kelas ke schedule massal (event-scoped via kelas).
     *
     * @return array{0: int, 1: int} [jumlah ter-assign baru, total kompetitor]
     */
    private function assignMassParticipants(CompetitionClass $class, CompetitionSchedule $schedule): array
    {
        if ($class->isTeamFormat()) {
            $teams = CompetitionTeam::where('competition_class_id', $class->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get();

            $assignedTeamIds = CompetitionScheduleEntry::where('competition_schedule_id', $schedule->id)
                ->pluck('competition_team_id')
                ->filter()
                ->all();

            $assigned = 0;
            foreach ($teams as $team) {
                if (in_array($team->id, $assignedTeamIds, true)) {
                    continue;
                }

                CompetitionScheduleEntry::create([
                    'competition_schedule_id' => $schedule->id,
                    'competition_team_id' => $team->id,
                    'order_number' => $this->nextOrder($schedule, 'competition_team_id'),
                ]);
                $assigned++;
            }

            return [$assigned, $teams->count()];
        }

        $registrations = CompetitionRegistration::where('competition_class_id', $class->id)
            ->orderBy('id')
            ->get();

        $assignedRegIds = CompetitionScheduleEntry::where('competition_schedule_id', $schedule->id)
            ->pluck('competition_registration_id')
            ->all();

        $assigned = 0;
        foreach ($registrations as $registration) {
            if (in_array($registration->id, $assignedRegIds, true)) {
                continue;
            }

            CompetitionScheduleEntry::create([
                'competition_schedule_id' => $schedule->id,
                'competition_registration_id' => $registration->id,
                'order_number' => $this->nextOrder($schedule, 'competition_registration_id'),
            ]);
            $assigned++;
        }

        return [$assigned, $registrations->count()];
    }

    private function nextOrder(CompetitionSchedule $schedule, string $column): int
    {
        return (int) CompetitionScheduleEntry::where('competition_schedule_id', $schedule->id)
            ->whereNotNull($column)
            ->count() + 1;
    }

    private function hasOutcomes(CompetitionClass $class): bool
    {
        $registrationIds = CompetitionRegistration::where('competition_class_id', $class->id)
            ->pluck('id');

        $hasIndividual = \App\Models\CompetitionOutcome::whereIn('competition_registration_id', $registrationIds)->exists();

        $teamIds = CompetitionTeam::where('competition_class_id', $class->id)->pluck('id');

        $hasTeam = \App\Models\CompetitionTeamOutcome::whereIn('competition_team_id', $teamIds)->exists();

        return $hasIndividual || $hasTeam;
    }

    // -------------------------------------------------------------------------
    // Render helpers
    // -------------------------------------------------------------------------

    public function render()
    {
        $activeEvent = app(ActiveEventContext::class)->current();

        $query = CompetitionClass::with('competitionCategory', 'event')
            ->whereIn('format', $this->executableFormats());

        if ($this->filterEventId !== '' && $this->filterEventId !== '0') {
            $query->where('event_id', (int) $this->filterEventId);
        } else {
            $query->whereHas('event', fn ($q) => $q->where('event_type', 'competition')->where('status', 'active'));
        }

        if ($this->filterCategoryId !== '' && $this->filterCategoryId !== '0') {
            $query->where('competition_category_id', (int) $this->filterCategoryId);
        }

        $this->applyFormatFilter($query);

        $classes = $query->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (CompetitionClass $class) => $this->classRow($class));

        $selected = null;
        $detail = null;

        if ($this->selectedClassId !== '') {
            $class = CompetitionClass::with('competitionCategory')
                ->find((int) $this->selectedClassId);

            if ($class !== null) {
                $selected = $class;
                $detail = $this->detailFor($class);
            } else {
                $this->selectedClassId = '';
            }
        }

        return view('livewire.competition.execution.index', [
            'classes' => $classes,
            'selected' => $selected,
            'detail' => $detail,
            'formats' => self::FORMATS,
            'formatLabel' => $selected ? $this->formatLabel($selected) : '-',
            'resultTypeLabel' => $selected ? CompetitionResultType::label($selected->resultType()) : '-',
            'categories' => $this->filterCategories,
            'events' => $this->events,
        ]);
    }

    private function classRow(CompetitionClass $class): array
    {
        $schedule = $this->massSchedule($class);

        $executionStatus = match ($class->format) {
            CompetitionFormat::INDIVIDUAL_VS_INDIVIDUAL,
            CompetitionFormat::TEAM_VS_TEAM => $this->hasActiveBracket($class) ? 'Bracket' : 'Bracket belum dibuat',
            default => $schedule !== null ? $schedule->status : 'Belum disiapkan',
        };

        return [
            'id' => $class->id,
            'name' => $class->name,
            'event_name' => $class->event?->name ?? '-',
            'category' => $class->competitionCategory?->name ?? '-',
            'format' => $this->formatLabel($class),
            'result_type' => CompetitionResultType::label($class->resultType()),
            'gender' => $class->gender ?? '-',
            'participants' => $this->competitorCount($class),
            'execution_status' => $executionStatus,
        ];
    }

    /** Detail data untuk kelas terpilih. */
    private function detailFor(CompetitionClass $class): array
    {
        $eventId = (int) $class->event_id;
        $isTeam = $class->isTeamFormat();

        if (! $this->isMass($class)) {
            $bracket = CompetitionBracket::with('competitionClass')
                ->where('competition_class_id', $class->id)
                ->whereIn('status', ['draft', 'active'])
                ->orderBy('created_at', 'desc')
                ->first();

            return [
                'kind' => 'vs',
                'is_team' => $isTeam,
                'competitors' => $this->competitorCount($class),
                'winner_count' => $class->winner_count ?? 3,
                'has_bracket' => $bracket !== null,
                'bracket_name' => $bracket?->name ?? null,
                'podium' => $this->podium($eventId, $class, $isTeam),
            ];
        }

        $schedule = $this->massSchedule($class);

        return [
            'kind' => 'mass',
            'is_team' => $isTeam,
            'participants' => $this->competitorCount($class),
            'schedule' => $schedule,
            'entries' => $schedule ? $this->massEntries($class, $schedule) : collect(),
            'has_schedule' => $schedule !== null,
            'locked' => $schedule !== null && $schedule->status === 'Finished',
            'podium' => $this->podium($eventId, $class, $isTeam),
        ];
    }

    private function massEntries(CompetitionClass $class, CompetitionSchedule $schedule): \Illuminate\Support\Collection
    {
        if ($class->isTeamFormat()) {
            return CompetitionScheduleEntry::with('team.kelompok', 'team.outcome')
                ->where('competition_schedule_id', $schedule->id)
                ->whereNotNull('competition_team_id')
                ->orderBy('order_number')
                ->orderBy('id')
                ->get()
                ->map(function (CompetitionScheduleEntry $entry) {
                    $team = $entry->team;

                    return [
                        'name' => $team?->name ?? '-',
                        'detail' => $team?->kelompok?->kelompok_asal ?? '-',
                        'position' => $team?->outcome?->position ?? null,
                        'score' => $team?->outcome?->score ?? null,
                        'status' => $team?->outcome?->status ?? null,
                    ];
                });
        }

        return CompetitionScheduleEntry::with([
            'competitionRegistration.participation.person.desa',
            'competitionRegistration.participation.person.kelompok',
            'competitionRegistration.outcome',
        ])
            ->where('competition_schedule_id', $schedule->id)
            ->whereNotNull('competition_registration_id')
            ->orderBy('order_number')
            ->orderBy('id')
            ->get()
            ->map(function (CompetitionScheduleEntry $entry) {
                $reg = $entry->competitionRegistration;

                return [
                    'name' => $reg?->participation?->person?->nama ?? '-',
                    'detail' => $reg?->participation?->participant_number ?? '-',
                    'position' => $reg?->outcome?->position ?? null,
                    'score' => $reg?->outcome?->score ?? null,
                    'status' => $reg?->outcome?->status ?? null,
                ];
            });
    }

    private function podium(int $eventId, CompetitionClass $class, bool $isTeam): array
    {
        if (! $this->isMass($class)) {
            $service = app(CompetitionResultService::class);

            return $isTeam
                ? $service->podiumForTeams($eventId, $class->id, $class->winner_count ?? 3)
                : $service->podiumForClass($eventId, $class->id, $class->winner_count ?? 3);
        }

        $schedule = $this->massSchedule($class);
        if ($schedule === null) {
            return [];
        }

        $service = app(CompetitionResultService::class);

        return $isTeam
            ? $service->podiumForTeams($eventId, $class->id, $class->winner_count ?? 3)
            : $service->podiumForSchedule($eventId, $schedule->id, $class->winner_count ?? 3);
    }

    public function formatLabel(CompetitionClass $class): string
    {
        if ($class->format === CompetitionFormat::INDIVIDUAL_MASS
            && $class->result_type === CompetitionResultType::SCORE) {
            return 'Individual Scoring';
        }

        if (! in_array($class->format, self::FORMATS, true)) {
            return \App\Support\CompetitionFormat::label($class->format);
        }

        return self::FORMATS[$class->format];
    }

    private function executableFormats(): array
    {
        return [
            CompetitionFormat::INDIVIDUAL_MASS,
            CompetitionFormat::TEAM_MASS,
            CompetitionFormat::INDIVIDUAL_VS_INDIVIDUAL,
            CompetitionFormat::TEAM_VS_TEAM,
        ];
    }

    private function applyFormatFilter($query): void
    {
        if ($this->filterFormat === '' || $this->filterFormat === 'all') {
            return;
        }

        if ($this->filterFormat === 'individual_scoring') {
            $query->where('format', CompetitionFormat::INDIVIDUAL_MASS)
                ->where('result_type', CompetitionResultType::SCORE);

            return;
        }

        $query->where('format', $this->filterFormat);
    }

    private function hasActiveBracket(CompetitionClass $class): bool
    {
        return CompetitionBracket::where('competition_class_id', $class->id)
            ->whereIn('status', ['draft', 'active'])
            ->exists();
    }

    private function competitorCount(CompetitionClass $class): int
    {
        return $class->isTeamFormat()
            ? (int) CompetitionTeam::where('competition_class_id', $class->id)->where('is_active', true)->count()
            : (int) CompetitionRegistration::where('competition_class_id', $class->id)->count();
    }
}
