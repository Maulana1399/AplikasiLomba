<?php

use App\Enums\Role;
use App\Livewire\Competition\Class\Index as ClassIndex;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\Event;
use App\Models\User;
use App\Support\ActiveEventContext;
use App\Support\CompetitionFormat;
use App\Support\CompetitionResultType;
use Livewire\Livewire;

function filter_event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'Event Filter '.str()->random(6),
        'slug' => 'flt-'.str()->random(6),
        'event_type' => 'competition',
        'status' => 'active',
    ], $overrides));
}

function filter_category(Event $event, array $overrides = []): CompetitionCategory
{
    $category = CompetitionCategory::create(array_merge([
        'event_id' => $event->id,
        'name' => 'Kategori '.str()->random(8),
        'code' => 'KAT-'.strtoupper(str()->random(6)),
        'is_active' => true,
    ], $overrides));

    $category->events()->syncWithoutDetaching([$event->id]);

    return $category;
}

function filter_class(Event $event, CompetitionCategory $category, array $overrides = []): CompetitionClass
{
    return CompetitionClass::create(array_merge([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'Kelas '.str()->random(8),
        'gender' => 'L',
        'format' => CompetitionFormat::INDIVIDUAL_MASS,
        'sort_order' => 1,
        'is_active' => true,
        'code' => 'CLS-'.strtoupper(str()->random(6)),
    ], $overrides));
}

function filter_admin(): User
{
    return User::factory()->create(['role' => Role::Admin]);
}

// ---------------------------------------------------------------------------
// Kontrol dasar
// ---------------------------------------------------------------------------

test('filter state defaults to Semua (tidak membatasi)', function () {
    $event = filter_event();
    app(ActiveEventContext::class)->set($event);

    Livewire::actingAs(filter_admin())
        ->test(ClassIndex::class)
        ->assertSet('filterEventId', '')
        ->assertSet('filterCategoryId', '')
        ->assertSet('filterGender', '')
        ->assertSet('filterFormat', '')
        ->assertSet('filterStatus', '');
});

test('ternpa filter Lomba menampilkan semua kelas kompetisi aktif', function () {
    $event = filter_event();
    app(ActiveEventContext::class)->set($event);
    $cat = filter_category($event);
    $klsA = filter_class($event, $cat, ['name' => 'Kelas Semua A']);
    $klsB = filter_class($event, $cat, ['name' => 'Kelas Semua B']);

    Livewire::actingAs(filter_admin())
        ->test(ClassIndex::class)
        ->assertSee($klsA->name)
        ->assertSee($klsB->name);
});

// ---------------------------------------------------------------------------
// Filter Lomba
// ---------------------------------------------------------------------------

test('filter Lomba hanya menampilkan kelas milik lomba tersebut', function () {
    $eventA = filter_event();
    $eventB = filter_event();
    app(ActiveEventContext::class)->set($eventA);
    $catA = filter_category($eventA);
    $catB = filter_category($eventB);
    $kelasA = filter_class($eventA, $catA, ['name' => 'Kelas Khusus A']);
    $kelasB = filter_class($eventB, $catB, ['name' => 'Kelas Khusus B']);

    Livewire::actingAs(filter_admin())
        ->test(ClassIndex::class)
        ->set('filterEventId', (string) $eventA->id)
        ->assertSee($kelasA->name)
        ->assertDontSee($kelasB->name);
});

test('kelas dari lomba lain tidak bocor saat kategori di-reuse antar lomba', function () {
    $eventA = filter_event();
    $eventB = filter_event();
    app(ActiveEventContext::class)->set($eventA);

    // Satu kategori GLOBAL yang sama dipakai oleh dua lomba (via pivot).
    $shared = CompetitionCategory::create([
        'name' => 'Kategori Global '.str()->random(8),
        'code' => 'SHARED-'.strtoupper(str()->random(6)),
        'is_active' => true,
    ]);
    $shared->events()->syncWithoutDetaching([$eventA->id, $eventB->id]);

    $kelasA = filter_class($eventA, $shared, ['name' => 'Kelas Shared A']);
    $kelasB = filter_class($eventB, $shared, ['name' => 'Kelas Shared B']);

    // Kedua kelas menunjuk competition_category_id yang sama.
    expect($kelasA->competition_category_id)->toBe($shared->id)
        ->and($kelasB->competition_category_id)->toBe($shared->id);

    Livewire::actingAs(filter_admin())
        ->test(ClassIndex::class)
        ->set('filterEventId', (string) $eventA->id)
        ->assertSee($kelasA->name)
        ->assertDontSee($kelasB->name);

    Livewire::actingAs(filter_admin())
        ->test(ClassIndex::class)
        ->set('filterEventId', (string) $eventB->id)
        ->assertSee($kelasB->name)
        ->assertDontSee($kelasA->name);
});

test('kategori untuk hasil filter Lomba hanya milik lomba yang dipilih', function () {
    $eventA = filter_event();
    $eventB = filter_event();
    app(ActiveEventContext::class)->set($eventA);
    $catA = filter_category($eventA, ['name' => 'Kategori Hanya A '.str()->random(4)]);
    $catB = filter_category($eventB, ['name' => 'Kategori Hanya B '.str()->random(4)]);
    filter_class($eventA, $catA, ['name' => 'Kelas Filter A']);
    filter_class($eventB, $catB, ['name' => 'Kelas Filter B']);

    Livewire::actingAs(filter_admin())
        ->test(ClassIndex::class)
        ->set('filterEventId', (string) $eventA->id)
        ->assertSee($catA->name)
        ->assertDontSee($catB->name);
});

test('memilih Lomba mereset filter Kategori', function () {
    $eventA = filter_event();
    $eventB = filter_event();
    app(ActiveEventContext::class)->set($eventA);
    $catA = filter_category($eventA);
    $catB = filter_category($eventB);
    filter_class($eventA, $catA, ['name' => 'Kelas Reset A']);
    filter_class($eventB, $catB, ['name' => 'Kelas Reset B']);

    Livewire::actingAs(filter_admin())
        ->test(ClassIndex::class)
        ->set('filterEventId', (string) $eventA->id)
        ->set('filterCategoryId', (string) $catA->id)
        ->set('filterEventId', (string) $eventB->id)
        ->assertSet('filterCategoryId', '');
});

test('tanpa Lomba terpilih, opsi Kategori kosong (hanya Semua)', function () {
    $event = filter_event();
    app(ActiveEventContext::class)->set($event);
    $cat = filter_category($event);
    filter_class($event, $cat, ['name' => 'Kelas Tanpa Lomba']);

    Livewire::actingAs(filter_admin())
        ->test(ClassIndex::class)
        ->assertSet('filterCategories', collect());
});

// ---------------------------------------------------------------------------
// Filter Kategori
// ---------------------------------------------------------------------------

test('filter Kategori hanya menampilkan kelas untuk Lomba + Kategori tsb', function () {
    $event = filter_event();
    app(ActiveEventContext::class)->set($event);
    $catX = filter_category($event, ['name' => 'Kategori X '.str()->random(4)]);
    $catY = filter_category($event, ['name' => 'Kategori Y '.str()->random(4)]);
    $kelasX = filter_class($event, $catX, ['name' => 'Kelas Cat X']);
    $kelasY = filter_class($event, $catY, ['name' => 'Kelas Cat Y']);

    // Tanpa filter kategori: kedua kelas dari lomba yang sama terlihat.
    Livewire::actingAs(filter_admin())
        ->test(ClassIndex::class)
        ->set('filterEventId', (string) $event->id)
        ->assertSee($kelasX->name)
        ->assertSee($kelasY->name);

    // Dengan filter kategori X: hanya kelas X yang terlihat.
    Livewire::actingAs(filter_admin())
        ->test(ClassIndex::class)
        ->set('filterEventId', (string) $event->id)
        ->set('filterCategoryId', (string) $catX->id)
        ->assertSee($kelasX->name)
        ->assertDontSee($kelasY->name);
});

test('filter Kategori di-scope oleh event_id dan competition_category_id', function () {
    $eventA = filter_event();
    $eventB = filter_event();
    app(ActiveEventContext::class)->set($eventA);
    $catA = filter_category($eventA);
    $catB = filter_category($eventB);
    filter_class($eventA, $catA, ['name' => 'Kelas Scope A']);
    filter_class($eventB, $catB, ['name' => 'Kelas Scope B']);

    // Kategori milik lomba B TIDAK boleh memunculkan kelas lomba B
    // ketika lomba A yang dipilih.
    Livewire::actingAs(filter_admin())
        ->test(ClassIndex::class)
        ->set('filterEventId', (string) $eventA->id)
        ->set('filterCategoryId', (string) $catB->id)
        ->assertDontSee('Kelas Scope B');
});

// ---------------------------------------------------------------------------
// Filter tambahan: Gender, Format, Status
// ---------------------------------------------------------------------------

test('filter Gender hanya menampilkan kelas dengan gender terpilih', function () {
    $event = filter_event();
    app(ActiveEventContext::class)->set($event);
    $cat = filter_category($event);
    $kelasL = filter_class($event, $cat, ['name' => 'Kelas Gender L', 'gender' => 'L']);
    $kelasP = filter_class($event, $cat, ['name' => 'Kelas Gender P', 'gender' => 'P']);

    Livewire::actingAs(filter_admin())
        ->test(ClassIndex::class)
        ->set('filterGender', 'L')
        ->assertSee($kelasL->name)
        ->assertDontSee($kelasP->name);
});

test('filter Format hanya menampilkan kelas dengan format terpilih', function () {
    $event = filter_event();
    app(ActiveEventContext::class)->set($event);
    $cat = filter_category($event);
    $mass = filter_class($event, $cat, ['name' => 'Kelas Format Mass', 'format' => CompetitionFormat::INDIVIDUAL_MASS]);
    $heat = filter_class($event, $cat, ['name' => 'Kelas Format Heat', 'format' => CompetitionFormat::INDIVIDUAL_HEAT]);

    Livewire::actingAs(filter_admin())
        ->test(ClassIndex::class)
        ->set('filterFormat', CompetitionFormat::INDIVIDUAL_MASS)
        ->assertSee($mass->name)
        ->assertDontSee($heat->name);
});

test('filter Format Individual Scoring memakai mapping individual_mass + result_type score', function () {
    $event = filter_event();
    app(ActiveEventContext::class)->set($event);
    $cat = filter_category($event);
    $scoring = filter_class($event, $cat, [
        'name' => 'Kelas Scoring',
        'format' => CompetitionFormat::INDIVIDUAL_MASS,
        'result_type' => CompetitionResultType::SCORE,
    ]);
    $bering = filter_class($event, $cat, [
        'name' => 'Kelas Mass Biasa',
        'format' => CompetitionFormat::INDIVIDUAL_MASS,
        'result_type' => null,
    ]);

    Livewire::actingAs(filter_admin())
        ->test(ClassIndex::class)
        ->set('filterFormat', 'individual_scoring')
        ->assertSee($scoring->name)
        ->assertDontSee($bering->name);
});

test('filter Status hanya menampilkan kelas aktif / nonaktif', function () {
    $event = filter_event();
    app(ActiveEventContext::class)->set($event);
    $cat = filter_category($event);
    $aktif = filter_class($event, $cat, ['name' => 'Kelas Status Aktif', 'is_active' => true]);
    $nonaktif = filter_class($event, $cat, ['name' => 'Kelas Status Nonaktif', 'is_active' => false]);

    Livewire::actingAs(filter_admin())
        ->test(ClassIndex::class)
        ->set('filterStatus', 'inactive')
        ->assertSee($nonaktif->name)
        ->assertDontSee($aktif->name);

    Livewire::actingAs(filter_admin())
        ->test(ClassIndex::class)
        ->set('filterStatus', 'active')
        ->assertSee($aktif->name)
        ->assertDontSee($nonaktif->name);
});

// ---------------------------------------------------------------------------
// Kombinasi filter
// ---------------------------------------------------------------------------

test('kombinasi filter Lomba + Kategori + Gender + Status', function () {
    $event = filter_event();
    app(ActiveEventContext::class)->set($event);
    $cat = filter_category($event);

    $target = filter_class($event, $cat, [
        'name' => 'Kelas Target',
        'gender' => 'L',
        'format' => CompetitionFormat::INDIVIDUAL_MASS,
        'is_active' => true,
    ]);
    filter_class($event, $cat, ['name' => 'Kelas Salah Gender', 'gender' => 'P', 'is_active' => true]);
    filter_class($event, $cat, ['name' => 'Kelas Salah Status', 'gender' => 'L', 'is_active' => false]);

    Livewire::actingAs(filter_admin())
        ->test(ClassIndex::class)
        ->set('filterEventId', (string) $event->id)
        ->set('filterCategoryId', (string) $cat->id)
        ->set('filterGender', 'L')
        ->set('filterFormat', CompetitionFormat::INDIVIDUAL_MASS)
        ->set('filterStatus', 'active')
        ->assertSee($target->name)
        ->assertDontSee('Kelas Salah Gender')
        ->assertDontSee('Kelas Salah Status');
});