<?php

use App\Enums\Role;
use App\Livewire\Competition\Execution\Index as ExecutionIndex;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionOutcome;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionSchedule;
use App\Models\CompetitionScheduleEntry;
use App\Models\CompetitionTeam;
use App\Models\Event;
use App\Models\kelompok;
use App\Models\Person;
use App\Models\User;
use App\Services\Competition\CompetitionRegistrationService;
use App\Services\Competition\CompetitionResultService;
use App\Support\ActiveEventContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ex_event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'Ex Event '.str()->random(6),
        'slug' => 'ex-'.str()->random(6),
        'status' => 'active',
        'event_type' => 'competition',
    ], $overrides));
}

function ex_category(Event $event): CompetitionCategory
{
    $category = CompetitionCategory::create(['event_id' => $event->id, 'name' => 'Ex Cat '.str()->random(4)]);
    $category->events()->attach($event);

    return $category;
}

function ex_class(Event $event, CompetitionCategory $category, string $format = 'individual_mass', ?string $resultType = null): CompetitionClass
{
    return CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'Ex Class '.str()->random(4),
        'gender' => 'M',
        'format' => $format,
        'status' => 'registration_open',
        'result_type' => $resultType,
        'winner_count' => 3,
        'is_active' => true,
    ]);
}

function ex_person(string $nama): Person
{
    return Person::create(['nama' => $nama, 'jenis_kelamin' => 'L']);
}

function ex_register(Person $person, Event $event, CompetitionCategory $category, CompetitionClass $class): CompetitionRegistration
{
    return app(CompetitionRegistrationService::class)->registerForPerson(
        person: $person,
        eventId: $event->id,
        competitionCategoryId: $category->id,
        competitionClassId: $class->id,
    )['competition_registration'];
}

function ex_register_many(Event $event, CompetitionCategory $category, CompetitionClass $class, int $count): array
{
    $regs = [];
    for ($i = 1; $i <= $count; $i++) {
        $regs[] = ex_register(ex_person('Afdal P '.$i), $event, $category, $class);
    }

    return $regs;
}

function ex_kelompok(string $name): kelompok
{
    return kelompok::create(['kelompok_asal' => $name]);
}

function ex_team(Event $event, CompetitionClass $class, string $name): CompetitionTeam
{
    return CompetitionTeam::create([
        'event_id' => $event->id,
        'competition_class_id' => $class->id,
        'name' => $name,
        'kelompok_id' => ex_kelompok('K')->id,
        'is_active' => true,
    ]);
}

function ex_admin(): User
{
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    test()->actingAs($admin);

    return $admin;
}

function ex_component()
{
    return \Livewire::test(ExecutionIndex::class);
}

// ---------------------------------------------------------------------------
// Routing + render
// ---------------------------------------------------------------------------

test('execution page route is registered and renders', function () {
    $event = ex_event();
    app(ActiveEventContext::class)->set($event);
    ex_admin();

    ex_component()
        ->assertStatus(200)
        ->assertSee('Eksekusi Lomba');
});

// ---------------------------------------------------------------------------
// Event scoping pada daftar kelas
// ---------------------------------------------------------------------------

test('execution list shows active event classes only', function () {
    $eventA = ex_event();
    $eventB = ex_event();

    $catA = ex_category($eventA);
    $catB = ex_category($eventB);

    $classA = ex_class($eventA, $catA, 'individual_mass', 'ranking');
    $classB = ex_class($eventB, $catB, 'individual_mass', 'ranking');

    app(ActiveEventContext::class)->set($eventA);
    ex_admin();

    $component = ex_component();

    expect(CompetitionClass::where('event_id', $eventA->id)->count())->toBe(1)
        ->and(CompetitionClass::where('event_id', $eventB->id)->count())->toBe(1);
    $component->assertSee($classA->name);
    $component->assertDontSee($classB->name);
});

// ---------------------------------------------------------------------------
// Massal — siapkan jadwal + assign peserta
// ---------------------------------------------------------------------------

test('mass class: select then prepare creates one schedule and assigns all', function () {
    $event = ex_event();
    $category = ex_category($event);
    $class = ex_class($event, $category, 'individual_mass', 'ranking');
    $regs = ex_register_many($event, $category, $class, 3);

    app(ActiveEventContext::class)->set($event);
    ex_admin();

    ex_component()
        ->call('selectClass', $class->id)
        ->assertHasNoErrors()
        ->call('prepareSchedule')
        ->assertHasNoErrors();

    $schedule = CompetitionSchedule::where('competition_class_id', $class->id)->first();

    expect(CompetitionSchedule::where('competition_class_id', $class->id)->count())->toBe(1)
        ->and($schedule)->not->toBeNull()
        ->and($schedule->status)->toBe('Ready')
        ->and(CompetitionScheduleEntry::where('competition_schedule_id', $schedule->id)->count())->toBe(3);

    $assignedRegIds = CompetitionScheduleEntry::where('competition_schedule_id', $schedule->id)
        ->pluck('competition_registration_id')->sort()->values()->all();

    expect($assignedRegIds)->toEqual(collect($regs)->pluck('id')->sort()->values()->all());
});

test('mass prepare is idempotent — no duplicate schedule or entries', function () {
    $event = ex_event();
    $category = ex_category($event);
    $class = ex_class($event, $category, 'individual_mass', 'ranking');
    ex_register_many($event, $category, $class, 3);

    app(ActiveEventContext::class)->set($event);
    ex_admin();

    $component = ex_component();
    $component->call('selectClass', $class->id)->call('prepareSchedule');
    $scheduleId = CompetitionSchedule::where('competition_class_id', $class->id)->first()->id;

    $component->call('prepareSchedule');

    expect(CompetitionSchedule::where('competition_class_id', $class->id)->count())->toBe(1)
        ->and(CompetitionScheduleEntry::where('competition_schedule_id', $scheduleId)->count())->toBe(3);
});

test('scoring flow: input scores then auto-rank via existing engine', function () {
    $event = ex_event();
    $category = ex_category($event);
    $class = ex_class($event, $category, 'individual_mass', 'score');
    $regs = ex_register_many($event, $category, $class, 3);

    app(ActiveEventContext::class)->set($event);
    ex_admin();

    $component = ex_component();
    $component->call('selectClass', $class->id)->call('prepareSchedule');
    $schedule = CompetitionSchedule::where('competition_class_id', $class->id)->first();

    CompetitionOutcome::updateOrCreate(['competition_registration_id' => $regs[0]->id], ['score' => 80]);
    CompetitionOutcome::updateOrCreate(['competition_registration_id' => $regs[1]->id], ['score' => 95]);
    CompetitionOutcome::updateOrCreate(['competition_registration_id' => $regs[2]->id], ['score' => 60]);

    $result = app(CompetitionResultService::class)->rankSchedule($event->id, $schedule->id);

    expect($result['ranked'])->toBeTrue()
        ->and($result['result_type'])->toBe('score')
        ->and($result['rows'][0]['registration_id'])->toBe($regs[1]->id)
        ->and($result['rows'][0]['position'])->toBe(1)
        ->and($result['rows'][1]['registration_id'])->toBe($regs[0]->id)
        ->and($result['rows'][1]['position'])->toBe(2)
        ->and($result['rows'][2]['registration_id'])->toBe($regs[2]->id)
        ->and($result['rows'][2]['position'])->toBe(3);
});

// ---------------------------------------------------------------------------
// Individual Scoring mapping
// ---------------------------------------------------------------------------

test('individual_scoring class is listed with scoring label', function () {
    $event = ex_event();
    $category = ex_category($event);
    $class = ex_class($event, $category, 'individual_mass', 'score');

    app(ActiveEventContext::class)->set($event);
    ex_admin();

    $component = ex_component();
    $component->call('selectClass', $class->id)
        ->assertSee('Individual Scoring');
});

// ---------------------------------------------------------------------------
// Team Mass — assign teams
// ---------------------------------------------------------------------------

test('team mass prepare assigns active teams to one schedule', function () {
    $event = ex_event();
    $category = ex_category($event);
    $class = ex_class($event, $category, 'team_mass', 'ranking');
    $teamA = ex_team($event, $class, 'KM 7');
    $teamB = ex_team($event, $class, 'KM 10');

    app(ActiveEventContext::class)->set($event);
    ex_admin();

    ex_component()
        ->call('selectClass', $class->id)
        ->call('prepareSchedule')
        ->assertHasNoErrors();

    $schedule = CompetitionSchedule::where('competition_class_id', $class->id)->first();

    expect(CompetitionSchedule::where('competition_class_id', $class->id)->count())->toBe(1)
        ->and(CompetitionScheduleEntry::where('competition_schedule_id', $schedule->id)
            ->where('competition_team_id', $teamA->id)->exists())->toBeTrue()
        ->and(CompetitionScheduleEntry::where('competition_schedule_id', $schedule->id)
            ->where('competition_team_id', $teamB->id)->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Proteksi scoping: peserta lintas kelas/lomba tidak bocor
// ---------------------------------------------------------------------------

test('mass prepare only pulls participants of the selected class', function () {
    $event = ex_event();
    $catA = ex_category($event);
    $catB = ex_category($event);

    $classA = ex_class($event, $catA, 'individual_mass', 'ranking');
    $classB = ex_class($event, $catB, 'individual_mass', 'ranking');

    ex_register_many($event, $catA, $classA, 2);
    ex_register_many($event, $catB, $classB, 5);

    app(ActiveEventContext::class)->set($event);
    ex_admin();

    ex_component()
        ->call('selectClass', $classA->id)
        ->call('prepareSchedule');

    $schedule = CompetitionSchedule::where('competition_class_id', $classA->id)->first();
    $entries = CompetitionScheduleEntry::where('competition_schedule_id', $schedule->id);

    expect($entries->count())->toBe(2);

    $entryClassIds = $entries->get()->map(
        fn ($entry) => $entry->competitionRegistration->competition_class_id
    )->unique()->values()->all();

    expect($entryClassIds)->toBe([$classA->id]);
});

test('mass prepare ignores other event participants entirely', function () {
    $eventA = ex_event();
    $eventB = ex_event();
    $catA = ex_category($eventA);
    $catB = ex_category($eventB);

    $classA = ex_class($eventA, $catA, 'individual_mass', 'ranking');
    $classForeign = ex_class($eventB, $catB, 'individual_mass', 'ranking');

    $regA1 = ex_register(ex_person('In Event'), $eventA, $catA, $classA);
    ex_register(ex_person('Foreign'), $eventB, $catB, $classForeign);

    app(ActiveEventContext::class)->set($eventA);
    ex_admin();

    ex_component()
        ->call('selectClass', $classA->id)
        ->call('prepareSchedule');

    $schedule = CompetitionSchedule::where('competition_class_id', $classA->id)->first();

    $assignedIds = CompetitionScheduleEntry::where('competition_schedule_id', $schedule->id)
        ->pluck('competition_registration_id')->all();

    expect($assignedIds)->toBe([$regA1->id]);
});

test('execution allows preparing schedule for class from another active event', function () {
    $eventA = ex_event();
    $eventB = ex_event();
    $catB = ex_category($eventB);
    $foreignClass = ex_class($eventB, $catB, 'individual_mass', 'ranking');

    app(ActiveEventContext::class)->set($eventA);
    ex_admin();

    $component = ex_component();
    $component->set('selectedClassId', (string) $foreignClass->id);

    $component->call('prepareSchedule')->assertHasNoErrors();

    expect(CompetitionSchedule::where('competition_class_id', $foreignClass->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Vs format — dialihkan ke bracket, tidak via jadwal massal
// ---------------------------------------------------------------------------

test('vs format class does not create mass schedule on prepare', function () {
    $event = ex_event();
    $category = ex_category($event);
    $class = ex_class($event, $category, 'individual_vs_individual', 'win_loss');

    app(ActiveEventContext::class)->set($event);
    ex_admin();

    ex_component()
        ->call('selectClass', $class->id)
        ->call('prepareSchedule')
        ->assertHasNoErrors();

    expect(CompetitionSchedule::where('competition_class_id', $class->id)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Reset jadwal massal — aman (Scheduled/Ready saja, tanpa hasil)
// ---------------------------------------------------------------------------

test('mass reset deletes schedule only when not played and without outcomes', function () {
    $event = ex_event();
    $category = ex_category($event);
    $class = ex_class($event, $category, 'individual_mass', 'ranking');
    ex_register_many($event, $category, $class, 3);

    app(ActiveEventContext::class)->set($event);
    ex_admin();

    $component = ex_component();
    $component->call('selectClass', $class->id)->call('prepareSchedule');
    $scheduleId = CompetitionSchedule::where('competition_class_id', $class->id)->first()->id;

    $component->call('resetSchedule');
    expect(CompetitionSchedule::where('competition_class_id', $class->id)->count())->toBe(0);

    // Siapkan ulang lancar setelah reset.
    $component->call('prepareSchedule');
    expect(CompetitionSchedule::where('competition_class_id', $class->id)->count())->toBe(1);
});

test('mass reset is rejected when finished', function () {
    $event = ex_event();
    $category = ex_category($event);
    $class = ex_class($event, $category, 'individual_mass', 'ranking');
    ex_register_many($event, $category, $class, 3);

    app(ActiveEventContext::class)->set($event);
    ex_admin();

    $component = ex_component();
    $component->call('selectClass', $class->id)->call('prepareSchedule');

    CompetitionSchedule::where('competition_class_id', $class->id)->update(['status' => 'Finished']);

    $component->call('resetSchedule');

    expect(CompetitionSchedule::where('competition_class_id', $class->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Multi-event Lomba filter
// ---------------------------------------------------------------------------

test('execution list defaults to active event and shows event name column', function () {
    $event = ex_event();
    $category = ex_category($event);
    $class = ex_class($event, $category, 'individual_mass', 'ranking');

    app(ActiveEventContext::class)->set($event);
    ex_admin();

    $component = ex_component();
    $component->assertSee($class->name)
        ->assertSee($event->name);
});

test('execution lomba filter: switching to semua shows classes from multiple events', function () {
    $eventA = ex_event();
    $eventB = ex_event();
    $catA = ex_category($eventA);
    $catB = ex_category($eventB);

    $classA = ex_class($eventA, $catA, 'individual_mass', 'ranking');
    $classB = ex_class($eventB, $catB, 'team_mass', 'ranking');

    app(ActiveEventContext::class)->set($eventA);
    ex_admin();

    $component = ex_component();

    // Default: only eventA classes visible.
    $component->assertSee($classA->name)
        ->assertDontSee($classB->name);

    // Switch to Semua Lomba.
    $component->set('filterEventId', '');

    $component->assertSee($classA->name)
        ->assertSee($classB->name);
});

test('execution lomba filter: selecting specific event hides other events classes', function () {
    $eventA = ex_event();
    $eventB = ex_event();
    $catA = ex_category($eventA);
    $catB = ex_category($eventB);

    $classA = ex_class($eventA, $catA, 'individual_mass', 'ranking');
    $classB = ex_class($eventB, $catB, 'individual_mass', 'ranking');

    app(ActiveEventContext::class)->set($eventA);
    ex_admin();

    $component = ex_component();

    // Switch to Semua first.
    $component->set('filterEventId', '');
    $component->assertSee($classA->name)->assertSee($classB->name);

    // Now filter to eventB only.
    $component->set('filterEventId', (string) $eventB->id);
    $component->assertDontSee($classA->name)
        ->assertSee($classB->name);
});

test('execution lomba filter resets category and selection', function () {
    $eventA = ex_event();
    $eventB = ex_event();
    $catA = ex_category($eventA);
    $catB = ex_category($eventB);

    $classA = ex_class($eventA, $catA, 'individual_mass', 'ranking');
    $classB = ex_class($eventB, $catB, 'individual_mass', 'ranking');

    app(ActiveEventContext::class)->set($eventA);
    ex_admin();

    $component = ex_component();
    $component->call('selectClass', $classA->id);
    expect($component->get('selectedClassId'))->toBe((string) $classA->id);

    // Switch event — selection and category should reset.
    $component->set('filterEventId', (string) $eventB->id);
    expect($component->get('selectedClassId'))->toBe('')
        ->and($component->get('filterCategoryId'))->toBe('');
});

test('execution category filter is scoped to selected event', function () {
    $eventA = ex_event();
    $eventB = ex_event();
    $catA = ex_category($eventA);
    $catB = ex_category($eventB);

    ex_class($eventA, $catA, 'individual_mass', 'ranking');
    ex_class($eventB, $catB, 'individual_mass', 'ranking');

    app(ActiveEventContext::class)->set($eventA);
    ex_admin();

    $component = ex_component();

    // With eventA selected, only catA should appear in categories.
    $component->assertSee($catA->name)
        ->assertDontSee($catB->name);

    // Switch to eventB — now only catB should appear.
    $component->set('filterEventId', (string) $eventB->id);
    $component->assertDontSee($catA->name)
        ->assertSee($catB->name);
});

test('execution no cross-event participant leak when viewing semua', function () {
    $eventA = ex_event();
    $eventB = ex_event();
    $catA = ex_category($eventA);
    $catB = ex_category($eventB);

    $classA = ex_class($eventA, $catA, 'individual_mass', 'ranking');
    $classB = ex_class($eventB, $catB, 'individual_mass', 'ranking');

    $regA = ex_register(ex_person('Person A'), $eventA, $catA, $classA);
    $regB = ex_register(ex_person('Person B'), $eventB, $catB, $classB);

    app(ActiveEventContext::class)->set($eventA);
    ex_admin();

    // Prepare schedule for classA via Semua Lomba view.
    $component = ex_component();
    $component->set('filterEventId', '');
    $component->call('selectClass', $classA->id)
        ->call('prepareSchedule')
        ->assertHasNoErrors();

    $schedule = CompetitionSchedule::where('competition_class_id', $classA->id)->first();
    $assignedIds = CompetitionScheduleEntry::where('competition_schedule_id', $schedule->id)
        ->pluck('competition_registration_id')->all();

    expect($assignedIds)->toBe([$regA->id]);
});

test('execution lomba filter dropdown lists active competition events only', function () {
    $eventActive = ex_event();
    $eventInactive = ex_event(['status' => 'inactive']);

    app(ActiveEventContext::class)->set($eventActive);
    ex_admin();

    $component = ex_component();
    $component->assertSee($eventActive->name)
        ->assertDontSee($eventInactive->name);
});

// ---------------------------------------------------------------------------
// Kategori filter across events
// ---------------------------------------------------------------------------

test('execution kategori filter when semua: shows all categories deduplicated', function () {
    $eventA = ex_event();
    $eventB = ex_event();
    $catA = ex_category($eventA);
    $catB = ex_category($eventB);

    // Attach catA to both events — must still appear only once in Semua.
    $catA->events()->attach($eventB);

    app(ActiveEventContext::class)->set($eventA);
    ex_admin();

    $component = ex_component();
    $component->set('filterEventId', '');

    $categories = $component->get('filterCategories');

    expect($categories->count())->toBe(2)
        ->and($categories->pluck('id')->all())
        ->toContain($catA->id)
        ->and($categories->pluck('id')->all())
        ->toContain($catB->id);
});

test('execution kategori filter when semua: filters classes by category across events', function () {
    $eventA = ex_event();
    $eventB = ex_event();
    $catA = ex_category($eventA);
    $catB = ex_category($eventB);

    $classA = ex_class($eventA, $catA, 'individual_mass', 'ranking');
    $classB = ex_class($eventA, $catB, 'individual_mass', 'ranking');
    $classC = ex_class($eventB, $catA, 'team_mass', 'ranking');

    app(ActiveEventContext::class)->set($eventA);
    ex_admin();

    $component = ex_component();
    $component->set('filterEventId', '');
    $component->set('filterCategoryId', (string) $catA->id);

    $component->assertSee($classA->name)
        ->assertSee($classC->name)
        ->assertDontSee($classB->name);
});

test('execution kategori filter when specific lomba: only that lomba categories appear', function () {
    $eventA = ex_event();
    $eventB = ex_event();
    $catA = ex_category($eventA);
    $catB = ex_category($eventB);

    ex_class($eventA, $catA, 'individual_mass', 'ranking');
    ex_class($eventB, $catB, 'individual_mass', 'ranking');

    app(ActiveEventContext::class)->set($eventA);
    ex_admin();

    $component = ex_component();

    $categories = $component->get('filterCategories');
    expect($categories->pluck('id')->all())->toContain($catA->id)
        ->and($categories->pluck('id')->all())->not->toContain($catB->id);

    $component->set('filterEventId', (string) $eventB->id);
    $categories = $component->get('filterCategories');
    expect($categories->pluck('id')->all())->not->toContain($catA->id)
        ->and($categories->pluck('id')->all())->toContain($catB->id);
});

test('execution kategori filter when semua: no cross-event leak of mismatched category classes', function () {
    $eventA = ex_event();
    $eventB = ex_event();
    $catA = ex_category($eventA);
    $catB = ex_category($eventB);

    // Both events have a class named identically under different categories.
    $classInA = ex_class($eventA, $catA, 'individual_mass', 'ranking');
    $classInB = ex_class($eventB, $catB, 'individual_mass', 'ranking');

    app(ActiveEventContext::class)->set($eventA);
    ex_admin();

    $component = ex_component();
    $component->set('filterEventId', '');
    $component->set('filterCategoryId', (string) $catA->id);

    // Only the class that truly belongs to catA shows; eventB's class (catB) stays hidden.
    $component->assertSee($classInA->name)
        ->assertDontSee($classInB->name);
});

test('execution kategori filter persists when switching between specific lomba and semua', function () {
    $eventA = ex_event();
    $eventB = ex_event();
    $catA = ex_category($eventA);
    $catB = ex_category($eventB);

    ex_class($eventA, $catA, 'individual_mass', 'ranking');
    ex_class($eventB, $catB, 'individual_mass', 'ranking');

    app(ActiveEventContext::class)->set($eventA);
    ex_admin();

    $component = ex_component();
    $component->set('filterEventId', '');
    $component->set('filterCategoryId', (string) $catA->id);

    // Switching to eventB resets the category (existing behavior).
    $component->set('filterEventId', (string) $eventB->id);
    expect($component->get('filterCategoryId'))->toBe('');

    // Back to Semua — kategori list full again, category still empty.
    $component->set('filterEventId', '');
    expect($component->get('filterCategories')->count())->toBe(2);
});
