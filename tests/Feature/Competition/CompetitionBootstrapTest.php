<?php

use App\Models\Event;
use App\Support\CompetitionBootstrap;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function cb_event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'Lomba Test',
        'slug' => 'lomba-'.substr(md5(uniqid()), 0, 8),
        'event_type' => 'competition',
        'status' => 'active',
    ], $overrides));
}

// ---------------------------------------------------------------------------
// Bootstrap service
// ---------------------------------------------------------------------------

test('bootstrap creates a single active competition event when none exists', function () {
    $bootstrap = app(CompetitionBootstrap::class);

    $event = $bootstrap->ensureActiveCompetitionEvent();

    expect($event->isActive())->toBeTrue()
        ->and($event->isCompetition())->toBeTrue()
        ->and(Event::active()->where('event_type', 'competition')->count())->toBe(1);
});

test('bootstrap is idempotent and never creates duplicates', function () {
    $bootstrap = app(CompetitionBootstrap::class);

    $first = $bootstrap->ensureActiveCompetitionEvent();
    $second = $bootstrap->ensureActiveCompetitionEvent();

    expect($first->is($second))->toBeTrue()
        ->and(Event::count())->toBe(1);
});

test('bootstrap reuses an existing active competition event', function () {
    $event = cb_event(['slug' => 'existing-lomba']);

    $resolved = app(CompetitionBootstrap::class)->ensureActiveCompetitionEvent();

    expect($resolved->is($event))->toBeTrue()
        ->and(Event::count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Regression: legacy "Buat event" fallback must never appear
// ---------------------------------------------------------------------------

test('root never shows the legacy create-event instruction when no event exists', function () {
    $response = $this->get('/');

    $response
        ->assertRedirect()
        ->assertDontSee('Buat event');

    $event = Event::active()->where('event_type', 'competition')->firstOrFail();

    $this->get(route('competition.dashboard'))
        ->assertOk()
        ->assertSee($event->name)
        ->assertDontSee('Buat event')
        ->assertDontSee('Tidak ada event lomba aktif');
});

test('sidebar no-event fallback does not show the legacy create-event instruction', function () {
    cb_event();

    $this->get(route('competition.dashboard'))
        ->assertOk()
        ->assertDontSee('Buat event')
        ->assertDontSee('Tidak ada event lomba aktif');
});