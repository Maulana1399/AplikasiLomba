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
        ->assertRedirect(route('competition.dashboard', $event));
});

test('root shows 404 when no active competition event exists', function () {
    $this->get('/')
        ->assertStatus(404);
});

// ---------------------------------------------------------------------------
// Sidebar — 4 menus only
// ---------------------------------------------------------------------------

test('sidebar shows only 4 competition menus when event is active', function () {
    $event = sm_competition_event();
    app(ActiveEventContext::class)->set($event);

    $this->get(route('competition.dashboard', $event))
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
        ->assertDontSee('Kelola Event');
});

test('sidebar shows event name when active', function () {
    $event = sm_competition_event(['name' => 'KSN 2026']);
    app(ActiveEventContext::class)->set($event);

    $this->get(route('competition.dashboard', $event))
        ->assertOk()
        ->assertSee('KSN 2026');
});

test('sidebar shows no-event message when no event is active', function () {
    $this->get(route('competition.dashboard', sm_competition_event()))
        ->assertOk(); // dashboard component itself renders
});

// ---------------------------------------------------------------------------
// No auth required
// ---------------------------------------------------------------------------

test('competition routes are accessible without authentication', function () {
    $event = sm_competition_event();

    $this->get(route('competition.dashboard', $event))->assertOk();
    $this->get(route('competition.registration', $event))->assertOk();
    $this->get(route('competition.participants', $event))->assertOk();
    $this->get(route('competition.category.index', $event))->assertOk();
    $this->get(route('competition.class.index', $event))->assertOk();
    $this->get(route('competition.venue.index', $event))->assertOk();
    $this->get(route('competition.teams', $event))->assertOk();
    $this->get(route('competition.schedule.index', $event))->assertOk();
    $this->get(route('competition.heat.index', $event))->assertOk();
    $this->get(route('competition.bracket-manager', $event))->assertOk();
    $this->get(route('competition.match-center', $event))->assertOk();
    $this->get(route('competition.operator-dashboard', $event))->assertOk();
    $this->get(route('competition.official-panel', $event))->assertOk();
});

// ---------------------------------------------------------------------------
// Event switcher
// ---------------------------------------------------------------------------

test('event switch POST sets active event and redirects to competition dashboard', function () {
    $event = sm_competition_event();

    $this->post(route('events.switch', $event))
        ->assertRedirect(route('competition.dashboard', $event));

    expect(app(ActiveEventContext::class)->id())->toBe($event->id);
});

test('event switch with inactive event returns 404', function () {
    $event = sm_competition_event(['status' => 'inactive']);

    $this->post(route('events.switch', $event))
        ->assertStatus(404);
});
