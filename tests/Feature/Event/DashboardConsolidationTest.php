<?php

use App\Enums\Role;
use App\Livewire\Competition\Dashboard as CompetitionDashboard;
use App\Livewire\Event\Dashboard as EventDashboard;
use App\Models\Event;
use App\Models\Participation;
use App\Models\Person;
use App\Models\User;
use App\Services\Dashboard\CaiDashboardPresenter;
use App\Services\Dashboard\CompetitionDashboardPresenter;
use App\Services\Dashboard\DashboardPresenterFactory;
use App\Services\Dashboard\PengajianDashboardPresenter;
use App\Support\ActiveEventContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function dch_event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'DCH Event '.str()->random(6),
        'slug' => 'dch-'.str()->random(8),
        'status' => 'active',
        'event_type' => 'cai',
    ], $overrides));
}

function dc_admin(): User
{
    return User::factory()->create(['role' => Role::Admin]);
}

// ---------------------------------------------------------------------------
// 1. DashboardPresenterFactory memilih presenter yang benar
// ---------------------------------------------------------------------------

test('factory returns CaiDashboardPresenter for cai event', function () {
    $event = dch_event(['event_type' => 'cai']);
    $factory = app(DashboardPresenterFactory::class);

    expect($factory->make($event))->toBeInstanceOf(CaiDashboardPresenter::class);
});

test('factory returns CompetitionDashboardPresenter for competition event', function () {
    $event = dch_event(['event_type' => 'competition']);
    $factory = app(DashboardPresenterFactory::class);

    expect($factory->make($event))->toBeInstanceOf(CompetitionDashboardPresenter::class);
});

test('factory returns PengajianDashboardPresenter for pengajian event', function () {
    $event = dch_event(['event_type' => 'pengajian']);
    $factory = app(DashboardPresenterFactory::class);

    expect($factory->make($event))->toBeInstanceOf(PengajianDashboardPresenter::class);
});

// ---------------------------------------------------------------------------
// 2. Presenter memilih view yang benar
// ---------------------------------------------------------------------------

test('CaiDashboardPresenter returns cai partial view', function () {
    $presenter = app(CaiDashboardPresenter::class);

    expect($presenter->view())->toBe('livewire.event.dashboard.cai');
});

test('CompetitionDashboardPresenter returns competition partial view', function () {
    $presenter = app(CompetitionDashboardPresenter::class);

    expect($presenter->view())->toBe('livewire.event.dashboard.competition');
});

test('PengajianDashboardPresenter returns pengajian partial view', function () {
    $presenter = app(PengajianDashboardPresenter::class);

    expect($presenter->view())->toBe('livewire.event.dashboard.pengajian');
});

// ---------------------------------------------------------------------------
// 3. CAI Dashboard tidak memiliki widget Competition
// ---------------------------------------------------------------------------

test('CAI dashboard does not render competition widgets', function () {
    $event = dch_event(['event_type' => 'cai']);
    $user = dc_admin();
    app(ActiveEventContext::class)->set($event);

    Livewire::actingAs($user)
        ->test(EventDashboard::class, ['event' => $event])
        ->assertDontSee('Match Center')
        ->assertDontSee('Live Pertandingan')
        ->assertDontSee('Jadwal Hari Ini')
        ->assertDontSee('Registrasi Terbaru')
        ->assertDontSee('Belum Dijadwalkan');
});

test('CAI dashboard does not render Quick Action competition shortcuts', function () {
    $event = dch_event(['event_type' => 'cai']);
    $user = dc_admin();
    app(ActiveEventContext::class)->set($event);

    Livewire::actingAs($user)
        ->test(EventDashboard::class, ['event' => $event])
        ->assertDontSee('Aksi Cepat')
        ->assertDontSee('competition.registration')
        ->assertDontSee('competition.match-center')
        ->assertDontSee('competition.bracket-manager');
});

// ---------------------------------------------------------------------------
// 4. Competition Dashboard tidak memiliki widget CAI
// ---------------------------------------------------------------------------

test('competition dashboard does not render CAI attendance widgets', function () {
    $event = dch_event(['event_type' => 'competition']);
    $user = dc_admin();
    app(ActiveEventContext::class)->set($event);

    Livewire::actingAs($user)
        ->test(EventDashboard::class, ['event' => $event])
        ->assertDontSee('Belum Absen')
        ->assertDontSee('Ganti Sesi')
        ->assertDontSee('Sesi Aktif');
});

// ---------------------------------------------------------------------------
// 5. Pengajian Dashboard tidak memiliki widget Competition maupun CAI
// ---------------------------------------------------------------------------

test('pengajian dashboard does not render competition widgets', function () {
    $event = dch_event(['event_type' => 'pengajian']);
    $user = dc_admin();
    app(ActiveEventContext::class)->set($event);

    Livewire::actingAs($user)
        ->test(EventDashboard::class, ['event' => $event])
        ->assertDontSee('Match Center')
        ->assertDontSee('Live Pertandingan')
        ->assertDontSee('Belum Dijadwalkan');
});

test('pengajian dashboard does not render CAI attendance widgets', function () {
    $event = dch_event(['event_type' => 'pengajian']);
    $user = dc_admin();
    app(ActiveEventContext::class)->set($event);

    Livewire::actingAs($user)
        ->test(EventDashboard::class, ['event' => $event])
        ->assertDontSee('Ganti Sesi')
        ->assertDontSee('Belum Absen');
});

// ---------------------------------------------------------------------------
// 6. Semua event type membuka /events/{event}/dashboard (200 OK)
// ---------------------------------------------------------------------------

test('cai event dashboard route returns 200', function () {
    $event = dch_event(['event_type' => 'cai']);
    $this->actingAs(dc_admin());

    $this->get(route('events.dashboard', $event))->assertOk();
});

test('competition event dashboard route returns 200', function () {
    $event = dch_event(['event_type' => 'competition']);
    $this->actingAs(dc_admin());

    $this->get(route('events.dashboard', $event))->assertOk();
});

test('pengajian event dashboard route returns 200', function () {
    $event = dch_event(['event_type' => 'pengajian']);
    $this->actingAs(dc_admin());

    $this->get(route('events.dashboard', $event))->assertOk();
});

// ---------------------------------------------------------------------------
// 7. CompetitionDashboard lama tetap berfungsi sebagai compatibility layer
// ---------------------------------------------------------------------------

test('competition.dashboard legacy route still returns 200', function () {
    $event = dch_event(['event_type' => 'competition']);
    $this->actingAs(dc_admin());

    $this->get(route('competition.dashboard'))->assertOk();
});

test('Competition\\Dashboard component renders without CAI data', function () {
    $event = dch_event(['event_type' => 'competition']);
    $user = dc_admin();
    app(ActiveEventContext::class)->set($event);

    Livewire::actingAs($user)
        ->test(CompetitionDashboard::class)
        ->assertDontSee('Belum Absen')
        ->assertDontSee('Ganti Sesi')
        ->assertDontSee('Sesi Aktif');
});

// ---------------------------------------------------------------------------
// 8. Presenter CAI tidak menjalankan query Competition (query isolation)
// ---------------------------------------------------------------------------

test('CaiDashboardPresenter present() returns cai keys only', function () {
    $event = dch_event(['event_type' => 'cai']);

    $presenter = app(CaiDashboardPresenter::class);
    $data = $presenter->present($event);

    expect($data)->toHaveKeys(['totalPeserta', 'sesiAktif', 'sudahAbsenCount', 'izinCount', 'belumAbsenCount', 'pesertaBelumAbsen', 'daftarSesi'])
        ->not->toHaveKey('overview')
        ->not->toHaveKey('liveMatches')
        ->not->toHaveKey('todaySchedules');
});

// ---------------------------------------------------------------------------
// 9. Presenter Competition tidak menjalankan query CAI (query isolation)
// ---------------------------------------------------------------------------

test('CompetitionDashboardPresenter present() returns competition keys only', function () {
    $event = dch_event(['event_type' => 'competition']);

    $presenter = app(CompetitionDashboardPresenter::class);
    $data = $presenter->present($event);

    expect($data)->toHaveKeys(['overview', 'liveMatches', 'todaySchedules', 'recentRegistrations', 'recentResults'])
        ->not->toHaveKey('sesiAktif')
        ->not->toHaveKey('sudahAbsenCount')
        ->not->toHaveKey('belumAbsenCount');
});

// ---------------------------------------------------------------------------
// 10. Presenter Pengajian tidak menjalankan query Competition maupun CAI
// ---------------------------------------------------------------------------

test('PengajianDashboardPresenter present() returns pengajian keys only', function () {
    $event = dch_event(['event_type' => 'pengajian']);

    $presenter = app(PengajianDashboardPresenter::class);
    $data = $presenter->present($event);

    expect($data)->toHaveKeys(['summary', 'desaBreakdown'])
        ->not->toHaveKey('sesiAktif')
        ->not->toHaveKey('overview')
        ->not->toHaveKey('liveMatches');
});

// ---------------------------------------------------------------------------
// 11. EventDashboard presenterData scoped ke event yang benar
// ---------------------------------------------------------------------------

test('EventDashboard presenterData contains totalPeserta for event with one participant', function () {
    $event = dch_event(['event_type' => 'cai', 'slug' => 'dc-cai-scope']);

    $person = Person::create(['nama' => 'DC Person']);
    Participation::create([
        'person_id' => $person->id,
        'event_id' => $event->id,
        'participant_number' => 'KL001',
        'attendance_code' => 'KJA-DC001',
        'jenis_peserta' => 'Wajib',
    ]);

    $user = dc_admin();
    app(ActiveEventContext::class)->set($event);

    $result = Livewire::actingAs($user)
        ->test(EventDashboard::class, ['event' => $event]);

    $presenterData = $result->viewData('presenterData');

    expect($presenterData['totalPeserta'])->toBe(1);
});

test('EventDashboard presenterData excludes participants from other events', function () {
    $eventA = dch_event(['event_type' => 'cai', 'slug' => 'dc-cai-a2']);
    $eventB = dch_event(['event_type' => 'cai', 'slug' => 'dc-cai-b2']);

    $person = Person::create(['nama' => 'DC Shared']);
    Participation::create([
        'person_id' => $person->id,
        'event_id' => $eventA->id,
        'participant_number' => 'KL002',
        'attendance_code' => 'KJA-DC002',
        'jenis_peserta' => 'Wajib',
    ]);

    $user = dc_admin();
    app(ActiveEventContext::class)->set($eventB);

    $result = Livewire::actingAs($user)->test(EventDashboard::class, ['event' => $eventB]);
    $presenterData = $result->viewData('presenterData');

    expect($presenterData['totalPeserta'])->toBe(0);
});
