<?php

namespace App\Livewire\Competition;

use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionRegistration;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class ParticipantList extends Component
{
    public string $competitionId = '';

    public string $competitionCategoryId = '';

    public string $competitionClassId = '';

    public function mount(): void
    {
        Gate::authorize('view-dashboard');
    }

    public function updatedCompetitionId(): void
    {
        $this->competitionCategoryId = '';
        $this->competitionClassId = '';
    }

    public function updatedCompetitionCategoryId(): void
    {
        $this->competitionClassId = '';
    }

    public function isShowingAllCompetition(): bool
    {
        return $this->competitionId === 'all';
    }

    public function isShowingAllCategory(): bool
    {
        return $this->competitionCategoryId === 'all';
    }

    public function getCompetitionsProperty()
    {
        return \App\Models\Event::where('event_type', 'competition')
            ->where('status', 'active')
            ->whereHas('competitionClasses', fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function getCategoriesProperty()
    {
        if (blank($this->competitionId) || $this->competitionId === 'all') {
            return collect();
        }

        return CompetitionCategory::whereHas('events', fn ($q) => $q->where('events.id', $this->competitionId))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function getClassesProperty()
    {
        if (blank($this->competitionId) || $this->competitionId === 'all' || blank($this->competitionCategoryId) || $this->competitionCategoryId === 'all') {
            return collect();
        }

        return CompetitionClass::where('event_id', $this->competitionId)
            ->where('competition_category_id', $this->competitionCategoryId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function render()
    {
        $registrations = collect();

        if ($this->competitionId === 'all') {
            $competitionIds = $this->competitions->pluck('id')->all();

            if (! empty($competitionIds)) {
                $registrations = CompetitionRegistration::with([
                    'participation.person.desa',
                    'participation.person.kelompok',
                    'competitionCategory',
                    'competitionClass',
                ])
                    ->whereHas('competitionClass', fn ($q) => $q->whereIn('event_id', $competitionIds))
                    ->orderBy('id')
                    ->get();
            }
        } elseif ($this->competitionId && $this->competitionCategoryId === 'all') {
            $registrations = CompetitionRegistration::with([
                'participation.person.desa',
                'participation.person.kelompok',
                'competitionCategory',
                'competitionClass',
            ])
                ->whereHas('competitionClass', fn ($q) => $q->where('event_id', $this->competitionId))
                ->orderBy('id')
                ->get();
        } elseif ($this->competitionClassId) {
            $registrations = CompetitionRegistration::with([
                'participation.person.desa',
                'participation.person.kelompok',
                'competitionCategory',
                'competitionClass',
            ])
                ->where('competition_category_id', $this->competitionCategoryId)
                ->where('competition_class_id', $this->competitionClassId)
                ->whereHas('competitionClass', fn ($q) => $q->where('event_id', $this->competitionId))
                ->orderBy('id')
                ->get();
        }

        return view('livewire.competition.participant-list', [
            'registrations' => $registrations,
            'competitions' => $this->competitions,
            'categories' => $this->categories,
            'classes' => $this->classes,
            'showingAllCompetition' => $this->isShowingAllCompetition(),
            'showingAllCategory' => $this->isShowingAllCategory(),
        ]);
    }
}
