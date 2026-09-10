<?php

namespace App\Support;

use App\Models\Event;

/**
 * AplikasiLomba has no Event CRUD UI, but the competition engine still requires
 * an internal event context (event_id). This idempotent bootstrap guarantees a
 * single active competition event exists so operators never see the legacy
 * "Buat event" flow that was removed.
 */
class CompetitionBootstrap
{
    public const DEFAULT_SLUG = 'lomba-operasional';

    public const DEFAULT_NAME = 'Lomba';

    public const DEFAULT_DESCRIPTION = 'Event competition otomatis untuk AplikasiLomba.';

    public function hasActiveCompetitionEvent(): bool
    {
        return Event::active()->where('event_type', 'competition')->exists();
    }

    /**
     * Reuses any existing active competition event, otherwise creates/repairs
     * the canonical internal event. Never deletes or touches other events.
     */
    public function ensureActiveCompetitionEvent(): Event
    {
        $existing = Event::active()
            ->where('event_type', 'competition')
            ->orderBy('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Event::query()->updateOrCreate(
            ['slug' => self::DEFAULT_SLUG],
            [
                'name' => self::DEFAULT_NAME,
                'description' => self::DEFAULT_DESCRIPTION,
                'event_type' => 'competition',
                'status' => 'active',
                'start_date' => now()->toDateString(),
                'end_date' => now()->toDateString(),
            ],
        );
    }
}