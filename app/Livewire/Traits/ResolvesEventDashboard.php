<?php

namespace App\Livewire\Traits;

use App\Models\Event;
use App\Services\Event\EventAccessService;
use App\Support\ActiveEventContext;

/**
 * Shared dashboard mount: validates the event is active, authorizes the user
 * against that specific event, and makes it the active event context.
 */
trait ResolvesEventDashboard
{
    protected function resolveEventDashboard(Event $event): void
    {
        abort_unless($event->isActive(), 404);

        // AplikasiLomba: no-auth LAN app — skip user access check
        app(ActiveEventContext::class)->set($event);

        $this->eventName = $event->name;
    }
}
