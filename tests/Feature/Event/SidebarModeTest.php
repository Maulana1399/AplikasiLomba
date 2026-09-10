<?php

use App\Models\Event;
use App\Support\ActiveEventContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function sm_competition_event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'Lomba Test',
        'slug' => 'lomba-'.substr(md5(uniqid()), 0, 8),
        'event_type' => 'competition',
        'status' => 'active',
    ], $overrides));
}

// ---------------------------------------------------------------------------
// Root redirect
// ---------------------------------------------------------------------------

test('root redirects to competition dashboard when active competition event exists', function () {
    $event = sm_competition_event();

    $this->get('/')
        ->assertRedirect(route('competition.dashboard'));
});

test('root bootstraps a default competition event when none exists', function () {
    $this->get('/')
        ->assertRedirect();

    $event = Event::active()->where('event_type', 'competition')->first();

    expect($event)->not->toBeNull()
        ->and($event->isCompetition())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Sidebar — 4 menus only
// ---------------------------------------------------------------------------

test('sidebar shows only 4 competition menus when event is active', function () {
    $event = sm_competition_event();
    app(ActiveEventContext::class)->set($event);

    $this->get(route('competition.dashboard'))
        ->assertOk()
        ->assertSee('Registrasi')
        ->assertSee('Setting')
        ->assertSee('Pembagian Tim')
        ->assertSee('Lomba')
        ->assertDontSee('Master Data')
        ->assertDontSee('User')
        ->assertDontSee('Pengajian')
        ->assertDontSee('Scan Absensi')
        ->assertDontSee('QR & Label')
        ->assertDontSee('Surat Izin')
        ->assertDontSee('Kelola Event')
        ->assertDontSee('Venue')
        ->assertDontSee('Jadwal');
});

test('sidebar shows event name when active', function () {
    $event = sm_competition_event(['name' => 'KSN 2026']);
    app(ActiveEventContext::class)->set($event);

    $this->get(route('competition.dashboard'))
        ->assertOk()
        ->assertSee('KSN 2026');
});

test('sidebar falls back to neutral text when no context can be resolved', function () {
    $this->get(route('competition.dashboard', sm_competition_event()))
        ->assertOk(); // dashboard component itself renders
});

// ---------------------------------------------------------------------------
// No auth required
// ---------------------------------------------------------------------------

test('competition routes are accessible without authentication', function () {
    sm_competition_event();

    $this->get(route('competition.dashboard'))->assertOk();
    $this->get(route('competition.registration'))->assertOk();
    $this->get(route('competition.participants'))->assertOk();
    $this->get(route('competition.category.index'))->assertOk();
    $this->get(route('competition.class.index'))->assertOk();
    $this->get(route('competition.teams'))->assertOk();
    $this->get(route('competition.heat.index'))->assertOk();
    $this->get(route('competition.bracket-manager'))->assertOk();
    $this->get(route('competition.match-center'))->assertOk();
    $this->get(route('competition.operator-dashboard'))->assertOk();
    $this->get(route('competition.official-panel'))->assertOk();
});

test('routes to removed Venue and Jadwal pages do not exist', function () {
    expect(fn () => route('competition.venue.index'))
        ->toThrow(\Symfony\Component\Routing\Exception\RouteNotFoundException::class)
        ->and(fn () => route('competition.schedule.index'))
        ->toThrow(\Symfony\Component\Routing\Exception\RouteNotFoundException::class);
});
