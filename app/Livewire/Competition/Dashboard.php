<?php

namespace App\Livewire\Competition;

use App\Services\Dashboard\DashboardPresenterFactory;
use App\Support\ActiveEventContext;
use Livewire\Component;

class Dashboard extends Component
{
    public string $eventName = '';

    public function mount(): void
    {
        $event = app(ActiveEventContext::class)->requireCurrent();

        abort_unless($event->isActive(), 404);

        $this->eventName = $event->name;
    }

    public function render(DashboardPresenterFactory $factory)
    {
        $event = app(ActiveEventContext::class)->current();

        $data = $factory->make($event)->present($event);

        return view('livewire.competition.dashboard', array_merge($data, [
            'eventName' => $this->eventName,
        ]));
    }
}
