<?php

use App\Enums\Role;
use App\Livewire\Dashboard\PlatformDashboard;
use App\Livewire\Event\EventSwitcher;
use App\Livewire\Event\Index as EventIndex;
use App\Models\Event;
use App\Models\User;
use App\Support\ActiveEventContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function rc_event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'Routing Event',
        'slug' => 'rc-'.str()->random(8),
        'event_type' => 'cai',
        'status' => 'active',
    ], $overrides));
}

function rc_super_admin(): User
{
    return User::factory()->create(['role' => Role::SuperAdmin]);
}

// ---------------------------------------------------------------------------
// 1. Active-event middleware
// ---------------------------------------------------------------------------

test('the legacy resolve.active-event middleware and events.dashboard route no longer exist', function () {
    $event = rc_event();

    expect(fn () => route('events.dashboard', $event))->toThrow(RouteNotFoundException::class);
});

test('ensure.active-competition middleware works for competition dashboard deep link', function () {
    $event = rc_event(['event_type' => 'competition']);
    $this->actingAs(rc_super_admin());

    $this->get(route('competition.dashboard'))->assertOk();

    expect(app(ActiveEventContext::class)->id())->toBe($event->id);
});

// ---------------------------------------------------------------------------
// 2. Dashboard route resolver
// ---------------------------------------------------------------------------

test('dashboardRoute no longer resolves for removed CAI events dashboard', function () {
    $event = rc_event(['event_type' => 'cai']);

    expect(fn () => $event->dashboardRoute())->toThrow(RouteNotFoundException::class);
});

test('dashboardRoute returns competition.dashboard for competition events', function () {
    $event = rc_event(['event_type' => 'competition']);

    expect($event->dashboardRoute())->toBe(route('competition.dashboard', absolute: false));
});

test('dashboardRoute no longer resolves for removed pengajian events dashboard', function () {
    $event = rc_event(['event_type' => 'pengajian']);

    expect(fn () => $event->dashboardRoute())->toThrow(RouteNotFoundException::class);
});

test('competition routes are consolidated under a clean competition prefix without event ids', function () {
    $content = file_get_contents(base_path('routes/web.php'));

    expect($content)->toContain("Route::prefix('competition')")
        ->and($content)->not->toContain("/competition/{event}")
        ->and($content)->not->toContain("events.switch");
});

test('competition routes generate event-free urls', function () {
    $event = rc_event(['event_type' => 'competition']);

    expect(route('competition.dashboard', absolute: false))
        ->toBe('/competition/dashboard')
        ->and(route('competition.registration', absolute: false))
        ->toBe('/competition/registration')
        ->and(route('competition.participants', absolute: false))
        ->toBe('/competition/participants')
        ->and(route('competition.heat.index', absolute: false))
        ->toBe('/competition/heat')
        ->and(route('competition.match-center', absolute: false))
        ->toBe('/competition/match-center')
        ->and(route('competition.viewer', absolute: false))
        ->toBe('/competition/viewer');
});

test('legacy pengajian and cai module routes no longer exist', function () {
    $event = rc_event();

    foreach ([
        'pengajian.report',
        'pengajian.admin.access',
        'pengajian.enter-token',
        'pengajian.desa',
        'absensi',
        'database',
        'registrasi.peserta',
        'rekap.peserta',
        'qr-label.index',
        'surat-izin',
        'activity-log.index',
    ] as $name) {
        expect(fn () => route($name, ['event' => $event], absolute: false))
            ->toThrow(RouteNotFoundException::class);
    }
});

test('legacy cai root paths are gone — root now serves the competition dashboard', function () {
    $this->get('/absensi')->assertNotFound();
    $this->get('/sesi-absensi')->assertNotFound();
    $this->get('/database')->assertNotFound();
    $this->get('/registrasi')->assertNotFound();
    $this->get('/rekap')->assertNotFound();
    $this->get('/rekap-peserta')->assertNotFound();
    $this->get('/qr-label')->assertNotFound();
    $this->get('/surat-izin')->assertNotFound();
    $this->get('/activity-log')->assertNotFound();
});

// ---------------------------------------------------------------------------
// 3. All redirects use the shared resolver
// ---------------------------------------------------------------------------

test('event switcher redirects via the shared dashboard route resolver', function () {
    $eventA = rc_event(['event_type' => 'cai']);
    $eventB = rc_event(['event_type' => 'competition']);
    $this->actingAs(rc_super_admin());

    app(ActiveEventContext::class)->set($eventA);

    Livewire::test(EventSwitcher::class)
        ->call('switchTo', $eventB->id)
        ->assertRedirect($eventB->dashboardRoute());
});

test('event switcher redirects to pengajian.report via the shared resolver', function () {
    $eventA = rc_event(['event_type' => 'cai']);
    $eventB = rc_event(['event_type' => 'pengajian']);
    $this->actingAs(rc_super_admin());

    app(ActiveEventContext::class)->set($eventA);

    Livewire::test(EventSwitcher::class)
        ->call('switchTo', $eventB->id)
        ->assertRedirect($eventB->dashboardRoute());
});

test('platform dashboard openEvent redirects via the shared resolver', function () {
    $event = rc_event(['event_type' => 'competition']);
    $this->actingAs(rc_super_admin());

    Livewire::test(PlatformDashboard::class)
        ->call('openEvent', $event->id)
        ->assertRedirect($event->dashboardRoute());
});

test('platform dashboard openQuickAccess scan redirects to absensi', function () {
    $event = rc_event();
    $this->actingAs(rc_super_admin());

    Livewire::test(PlatformDashboard::class)
        ->call('openQuickAccess', 'scan', $event->id)
        ->assertRedirect(route('absensi', ['event' => $event], absolute: false));
});

test('platform dashboard openQuickAccess registrasi redirects to registrasi.peserta', function () {
    $event = rc_event();
    $this->actingAs(rc_super_admin());

    Livewire::test(PlatformDashboard::class)
        ->call('openQuickAccess', 'registrasi', $event->id)
        ->assertRedirect(route('registrasi.peserta', ['event' => $event], absolute: false));
});

test('platform dashboard openQuickAccess cari redirects to database', function () {
    $event = rc_event();
    $this->actingAs(rc_super_admin());

    Livewire::test(PlatformDashboard::class)
        ->call('openQuickAccess', 'cari', $event->id)
        ->assertRedirect(route('database', ['event' => $event], absolute: false));
});

test('platform dashboard openQuickAccess rejects unknown target', function () {
    $event = rc_event();
    $this->actingAs(rc_super_admin());

    Livewire::test(PlatformDashboard::class)
        ->call('openQuickAccess', 'unknown', $event->id)
        ->assertStatus(404);
});

test('event creation redirects via the shared resolver for competition events', function () {
    $user = rc_super_admin();
    $this->actingAs($user);

    $slug = 'comp-route-'.substr(md5(uniqid()), 0, 6);

    $test = Livewire::test(EventIndex::class)
        ->set('showCreateForm', true)
        ->set('newName', 'Competition Route Event')
        ->set('newSlug', $slug)
        ->set('newEventType', 'competition')
        ->call('create');

    $event = Event::where('slug', $slug)->firstOrFail();

    $test->assertRedirect($event->dashboardRoute());
});
