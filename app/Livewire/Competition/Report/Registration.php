<?php

namespace App\Livewire\Competition\Report;

use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Services\Competition\CompetitionReportService;
use App\Support\ActiveEventContext;
use Livewire\Component;
use Livewire\WithPagination;

class Registration extends Component
{
    use WithPagination;

    public string $filterCategoryId = '';

    public string $filterClassId = '';

    public string $filterGender = '';

    public string $search = '';

    public function updatedFilterCategoryId(): void
    {
        $this->filterClassId = '';
    }

    public function render()
    {
        $event = app(ActiveEventContext::class)->current();

        $registrations = app(CompetitionReportService::class)->registrationReport($event, [
            'category_id' => $this->filterCategoryId,
            'class_id' => $this->filterClassId,
            'gender' => $this->filterGender,
            'search' => $this->search,
        ]);

        return view('livewire.competition.report.registration', [
            'registrations' => $registrations,
            'categories' => CompetitionCategory::where('event_id', $event?->id)
                ->where('is_active', true)->orderBy('name')->get(),
            'filterClasses' => $this->filterCategoryId
                ? CompetitionClass::where('competition_category_id', $this->filterCategoryId)
                    ->where('is_active', true)->orderBy('name')->get()
                : collect(),
        ]);
    }
}
