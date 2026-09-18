<?php

namespace App\Livewire\Competition\Report;

use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Services\Competition\CompetitionReportService;
use App\Support\ActiveEventContext;
use Livewire\Component;

class Outcome extends Component
{
    public string $filterCategoryId = '';

    public string $filterClassId = '';

    public string $filterVenueId = '';

    public function updatedFilterCategoryId(): void
    {
        $this->filterClassId = '';
    }

    public function render()
    {
        $event = app(ActiveEventContext::class)->current();

        $outcomes = app(CompetitionReportService::class)->outcomeReport($event, [
            'category_id' => $this->filterCategoryId,
            'class_id' => $this->filterClassId,
        ]);

        return view('livewire.competition.report.outcome', [
            'outcomes' => $outcomes,
            'categories' => CompetitionCategory::where('event_id', $event?->id)
                ->where('is_active', true)->orderBy('name')->get(),
            'filterClasses' => $this->filterCategoryId
                ? CompetitionClass::where('competition_category_id', $this->filterCategoryId)
                    ->where('is_active', true)->orderBy('name')->get()
                : collect(),
        ]);
    }
}
