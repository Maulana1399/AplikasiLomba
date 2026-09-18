<?php

namespace App\Livewire\Competition\TeamList;

use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Services\Competition\CompetitionTeamService;
use App\Support\ActiveEventContext;
use App\Support\CompetitionFormat;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Read-only daftar tim yang sudah terbentuk untuk satu kelas.
 *
 * Tidak membuat/mengubah tim apa pun — untuk membentuk tim gunakan
 * halaman Pembentukan Tim (competition.teams).
 */
class Index extends Component
{
    public string $competitionCategoryId = '';

    public string $competitionClassId = '';

    public function mount(): void
    {
        app(ActiveEventContext::class)->requireCurrent();
        Gate::authorize('manage-registration');
    }

    public function updatedCompetitionCategoryId(): void
    {
        $this->competitionClassId = '';
    }

    public function getCategoriesProperty()
    {
        $event = app(ActiveEventContext::class)->current();

        return CompetitionCategory::where('event_id', $event?->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function getClassesProperty()
    {
        if (blank($this->competitionCategoryId)) {
            return collect();
        }

        return CompetitionClass::where('competition_category_id', $this->competitionCategoryId)
            ->where('format', CompetitionFormat::TEAM_VS_TEAM)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function getSelectedClassProperty(): ?CompetitionClass
    {
        if (blank($this->competitionClassId) || blank($this->competitionCategoryId)) {
            return null;
        }

        return CompetitionClass::where('id', $this->competitionClassId)
            ->where('competition_category_id', $this->competitionCategoryId)
            ->where('format', CompetitionFormat::TEAM_VS_TEAM)
            ->where('is_active', true)
            ->first();
    }

    public function getFormatLabelProperty(): string
    {
        return $this->selectedClass !== null
            ? CompetitionFormat::label($this->selectedClass->format)
            : '';
    }

    public function getGenderLabelProperty(): string
    {
        return match ($this->selectedClass?->gender) {
            'L' => 'Putra',
            'P' => 'Putri',
            'M' => 'Campuran',
            default => '-',
        };
    }

    /**
     * Team milik kelas terpilih. event_id diambil dari CompetitionClass
     * (lomba sebenarnya), bukan ActiveEventContext global — konsisten dengan
     * perbaikan pemilihan kelas lintas-Lomba.
     */
    public function getTeamsProperty()
    {
        $class = $this->selectedClass;

        if ($class === null) {
            return collect();
        }

        return app(CompetitionTeamService::class)->listForClass($class->event_id, $class->id);
    }

    public function getSummaryProperty(): array
    {
        $teams = $this->teams;

        return [
            'total_teams' => $teams->count(),
            'players' => $teams->sum(fn ($team) => $team->players->count()),
            'substitutes' => $teams->sum(fn ($team) => $team->substitutes->count()),
        ];
    }

    public function render()
    {
        return view('livewire.competition.team-list.index', [
            'categories' => $this->categories,
            'classes' => $this->classes,
            'teams' => $this->teams,
            'selectedClass' => $this->selectedClass,
            'formatLabel' => $this->formatLabel,
            'genderLabel' => $this->genderLabel,
            'summary' => $this->summary,
        ]);
    }
}