<?php

namespace App\Livewire\Competition\Result;

use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\Event;
use App\Services\Competition\CompetitionResultService;
use App\Support\ActiveEventContext;
use App\Support\CompetitionResultType;
use App\Support\CompetitionTime;
use Livewire\Component;

/**
 * Hasil Lomba — centralized overview of final rankings for all classes.
 *
 * Membaca hasil FINAL dari engine existing (CompetitionResultService):
 * - Individual (massal / heat / scoring / vs) → CompetitionOutcome
 *   via podiumForClass($eventId, $classId, limit).
 * - Team (team mass / team heat / team vs) → CompetitionTeamOutcome
 *   via podiumForTeams($eventId, $classId, limit).
 *
 * Tidak membuat logika ranking kedua, tidak menyimpan hasil baru, dan selalu
 * di-scope per event (CompetitionClass.event_id) sehingga tidak ada
 * cross-event leak.
 */
class Index extends Component
{
    /** Lomba (filter). Default = active event. '' = Semua lomba. */
    public string $filterEventId = '';

    /** Kategori (filter). Mengikuti lomba. */
    public string $filterCategoryId = '';

    /** Kelas Lomba (filter). Mengikuti lomba + kategori. */
    public string $filterClassId = '';

    /** Status: '' (Semua) | 'selesai' | 'belum'. */
    public string $filterStatus = 'selesai';

    public function mount(): void
    {
        $event = app(ActiveEventContext::class)->requireCurrent();
        $this->filterEventId = (string) $event->id;
    }

    public function updatedFilterEventId(): void
    {
        $this->filterCategoryId = '';
        $this->filterClassId = '';
        $this->resetValidation();
    }

    public function updatedFilterCategoryId(): void
    {
        $this->filterClassId = '';
        $this->resetValidation();
    }

    public function updatedFilterStatus(): void
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
        if ($this->filterEventId === '' || $this->filterEventId === '0') {
            return collect();
        }

        return CompetitionCategory::whereHas('events', fn ($q) => $q->where('events.id', (int) $this->filterEventId))
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function getFilterClassesProperty(): \Illuminate\Support\Collection
    {
        if ($this->filterEventId === '' || $this->filterEventId === '0') {
            return collect();
        }

        $query = CompetitionClass::with('competitionCategory')
            ->where('event_id', (int) $this->filterEventId);

        if ($this->filterCategoryId !== '' && $this->filterCategoryId !== '0') {
            $query->where('competition_category_id', (int) $this->filterCategoryId);
        }

        return $query->orderBy('sort_order')->orderBy('name')->get();
    }

    public function render()
    {
        $event = app(ActiveEventContext::class)->current();
        $service = app(CompetitionResultService::class);

        $classes = $this->queryClasses($event);

        $rows = [];
        foreach ($classes as $class) {
            $this->appendClassRows($rows, $class, $service);
        }

        return view('livewire.competition.result.index', [
            'rows' => $rows,
            'events' => $this->events,
            'filterCategories' => $this->filterCategories,
            'filterClasses' => $this->filterClasses,
        ]);
    }

    private function queryClasses(?\App\Models\Event $event): \Illuminate\Support\Collection
    {
        if ($event === null) {
            return collect();
        }

        $query = CompetitionClass::with('competitionCategory', 'event')
            ->where('event_id', $event->id);

        if ($this->filterEventId !== '' && $this->filterEventId !== '0') {
            $query->where('event_id', (int) $this->filterEventId);
        }

        if ($this->filterCategoryId !== '' && $this->filterCategoryId !== '0') {
            $query->where('competition_category_id', (int) $this->filterCategoryId);
        }

        if ($this->filterClassId !== '' && $this->filterClassId !== '0') {
            $query->where('id', (int) $this->filterClassId);
        }

        if ($this->filterStatus === 'selesai') {
            $query->where(fn ($q) => $q
                ->whereHas('competitionRegistrations.outcome', fn ($o) => $o->whereNotNull('position')->where('position', '>', 0))
                ->orWhereHas('competitionTeams.outcome', fn ($o) => $o->whereNotNull('position')->where('position', '>', 0)));
        } elseif ($this->filterStatus === 'belum') {
            $query->where(fn ($q) => $q
                ->whereDoesntHave('competitionRegistrations.outcome', fn ($o) => $o->whereNotNull('position')->where('position', '>', 0))
                ->whereDoesntHave('competitionTeams.outcome', fn ($o) => $o->whereNotNull('position')->where('position', '>', 0)));
        }

        return $query->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function appendClassRows(array &$rows, CompetitionClass $class, CompetitionResultService $service): void
    {
        $winnerCount = (int) ($class->winner_count ?? 3);
        $honorableCount = (int) ($class->honorable_mention_count ?? 0);
        $limit = max(1, $winnerCount + $honorableCount);

        $eventId = (int) $class->event_id;
        $isTeam = $class->isTeamFormat();

        $podium = $isTeam
            ? $service->podiumForTeams($eventId, $class->id, $limit)
            : $service->podiumForClass($eventId, $class->id, $limit);

        if (empty($podium)) {
            $rows[] = $this->classPlaceholderRow($class);

            return;
        }

        foreach ($podium as $entry) {
            $rows[] = $this->podiumRow($class, $entry, $winnerCount);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function classPlaceholderRow(CompetitionClass $class): array
    {
        return [
            'position' => null,
            'name' => '-',
            'is_honorable' => false,
            'event_name' => $class->event?->name ?? '-',
            'category_name' => $class->competitionCategory?->name ?? '-',
            'class_name' => $class->name,
            'class_id' => (int) $class->id,
            'format' => \App\Support\CompetitionFormat::label($class->format),
            'result_type' => CompetitionResultType::label($class->resultType()),
            'score_text' => '-',
            'status' => 'Belum Selesai',
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function podiumRow(CompetitionClass $class, array $entry, int $winnerCount): array
    {
        $name = $entry['person_name'] ?? $entry['team_name'] ?? '-';
        $score = $entry['score'] ?? null;

        return [
            'position' => (int) $entry['position'],
            'name' => $name,
            'is_honorable' => (int) $entry['position'] > $winnerCount,
            'event_name' => $class->event?->name ?? '-',
            'category_name' => $class->competitionCategory?->name ?? '-',
            'class_name' => $class->name,
            'class_id' => (int) $class->id,
            'format' => \App\Support\CompetitionFormat::label($class->format),
            'result_type' => CompetitionResultType::label($class->resultType()),
            'score_text' => $this->scoreText($class, $score),
            'status' => 'Selesai',
        ];
    }

    private function scoreText(CompetitionClass $class, ?float $score): string
    {
        if ($score === null) {
            return '-';
        }

        if ($class->resultType() === CompetitionResultType::TIME) {
            return CompetitionTime::format($score);
        }

        return rtrim(rtrim(number_format($score, 2, '.', ''), '0'), '.');
    }
}
