<?php

namespace App\Services\Dashboard;

use App\Models\Event;

class DashboardPresenterFactory
{
    public function __construct(
        private CompetitionDashboardPresenter $competition,
    ) {}

    public function make(Event $event): DashboardPresenterContract
    {
        return $this->competition;
    }
}
