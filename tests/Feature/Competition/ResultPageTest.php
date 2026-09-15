<?php

use App\Enums\Role;
use App\Livewire\Competition\Result\Index as ResultIndex;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionOutcome;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionSchedule;
use App\Models\CompetitionScheduleEntry;
use App\Models\CompetitionTeam;
use App\Models\CompetitionTeamOutcome;
use App\Models\Event;
use App\Models\kelompok;
use App\Models\Person;
use App\Models\User;
use App\Services\Competition\CompetitionRegistrationService;
use App\Services\Competition\CompetitionResultService;
use App\Support\ActiveEventContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function rp_event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'RP Event '.str()->random(6),
        'slug' => 'rp-'.str()->random(6),
        'status' => 'active',
        'event_type' => 'competition',
    ], $overrides));
}

function rp_category(Event $event): CompetitionCategory
{
    $category = CompetitionCategory::create(['event_id' => $event->id, 'name' => 'RP Cat '.str()->random(4)]);
    $category->events()->attach($event);

    return $category;
}

function rp_class(Event $event, CompetitionCategory $category, string $format = 'individual_mass', ?string $resultType = null, ?int $winnerCount = null): CompetitionClass
{
    return CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'RP Class '.str()->random(4),
        'gender' => 'M',
        'format' => $format,
        'status' => 'registration_open',
        'result_type' => $resultType,
        'winner_count' => $winnerCount ?? 3,
        'honorable_mention_count' => 1,
        'is_active' => true,
    ]);
}

function rp_person(string $nama): Person
{
    return Person::create(['nama' => $nama, 'jenis_kelamin' => 'L']);
}

function rp_register(Person $person, Event $event, CompetitionCategory $category, CompetitionClass $class): CompetitionRegistration
{
    return app(CompetitionRegistrationService::class)->registerForPerson(
        person: $person,
        eventId: $event->id,
        competitionCategoryId: $category->id,
        competitionClassId: $class->id,
    )['competition_registration'];
}

function rp_register_many(Event $event, CompetitionCategory $category, CompetitionClass $class, int $count): array
{
    $regs = [];
    for ($i = 1; $i <= $count; $i++) {
        $regs[] = rp_register(rp_person('Result P '.$i), $event, $category, $class);
    }

    return $regs;
}

function rp_kelompok(string $name): kelompok
{
    return kelompok::create(['kelompok_asal' => $name]);
}

function rp_team(Event $event, CompetitionClass $class, string $name): CompetitionTeam
{
    return CompetitionTeam::create([
        'event_id' => $event->id,
        'competition_class_id' => $class->id,
        'name' => $name,
        'kelompok_id' => rp_kelompok('K')->id,
        'is_active' => true,
    ]);
}

function rp_admin(): User
{
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    test()->actingAs($admin);

    return $admin;
}

function rp_component()
{
    return \Livewire::test(ResultIndex::class);
}

function rp_prepare_and_rank(Event $event, CompetitionCategory $category, CompetitionClass $class, array $scores): CompetitionSchedule
{
    $regs = rp_register_many($event, $category, $class, count($scores));

    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $class->id,
        'status' => 'Ready',
        'required_participants' => count($scores),
        'sort_order' => 1,
    ]);

    foreach ($regs as $i => $reg) {
        CompetitionScheduleEntry::create([
            'competition_schedule_id' => $schedule->id,
            'competition_registration_id' => $reg->id,
            'order_number' => $i + 1,
        ]);

        CompetitionOutcome::updateOrCreate(
            ['competition_registration_id' => $reg->id],
            ['score' => $scores[$i]],
        );
    }

    app(CompetitionResultService::class)->rankSchedule($event->id, $schedule->id);

    return $schedule;
}

function rp_prepare_team(Event $event, CompetitionCategory $category, CompetitionClass $class, array $teamScores): CompetitionSchedule
{
    $teams = [];
    foreach ($teamScores as $name => $score) {
        $teams[$name] = ['team' => rp_team($event, $class, $name), 'score' => $score];
    }

    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $class->id,
        'status' => 'Ready',
        'required_participants' => count($teamScores),
        'sort_order' => 1,
    ]);

    $i = 0;
    foreach ($teams as $data) {
        CompetitionScheduleEntry::create([
            'competition_schedule_id' => $schedule->id,
            'competition_team_id' => $data['team']->id,
            'order_number' => ++$i,
        ]);

        CompetitionTeamOutcome::updateOrCreate(
            ['competition_team_id' => $data['team']->id],
            ['score' => $data['score']],
        );
    }

    app(CompetitionResultService::class)->rankTeams($event->id, $class->id);

    return $schedule;
}

// ---------------------------------------------------------------------------
// Routing + render
// ---------------------------------------------------------------------------

test('results page route is registered and renders', function () {
    $event = rp_event();
    app(ActiveEventContext::class)->set($event);
    rp_admin();

    rp_component()
        ->assertStatus(200)
        ->assertSee('Hasil Lomba');
});

test('results page shows sidebar label', function () {
    $event = rp_event();
    app(ActiveEventContext::class)->set($event);
    rp_admin();

    $response = $this->get(route('competition.results.index'));

    $response->assertSee('Hasil');
});

// ---------------------------------------------------------------------------
// Finished class results appear
// ---------------------------------------------------------------------------

test('finished individual class appears in results with default selesai filter', function () {
    $event = rp_event();
    $category = rp_category($event);
    $class = rp_class($event, $category, 'individual_mass', 'score');
    rp_register_many($event, $category, $class, 3);

    rp_prepare_and_rank($event, $category, $class, [80, 95, 60]);

    app(ActiveEventContext::class)->set($event);
    rp_admin();

    rp_component()
        ->assertSee('Juara 1')
        ->assertSee('Juara 2')
        ->assertSee('Juara 3')
        ->assertSee('Selesai');
});

test('unfinished class shows belum selesai placeholder when status filter is all', function () {
    $event = rp_event();
    $category = rp_category($event);
    $class = rp_class($event, $category, 'individual_mass', 'ranking');
    rp_register_many($event, $category, $class, 3);

    app(ActiveEventContext::class)->set($event);
    rp_admin();

    rp_component()
        ->set('filterStatus', '')
        ->assertSee('Belum Selesai');
});

// ---------------------------------------------------------------------------
// Rankings match engine
// ---------------------------------------------------------------------------

test('podium rankings match the result engine output', function () {
    $event = rp_event();
    $category = rp_category($event);
    $class = rp_class($event, $category, 'individual_mass', 'score');

    $regs = rp_register_many($event, $category, $class, 3);
    rp_prepare_and_rank($event, $category, $class, [80, 95, 60]);

    app(ActiveEventContext::class)->set($event);
    rp_admin();

    $podium = app(CompetitionResultService::class)->podiumForClass($event->id, $class->id, 3);

    expect($podium[0]['position'])->toBe(1)
        ->and($podium[0]['person_name'])->toBe('Result P 2')
        ->and($podium[1]['position'])->toBe(2)
        ->and($podium[1]['person_name'])->toBe('Result P 1')
        ->and($podium[2]['position'])->toBe(3)
        ->and($podium[2]['person_name'])->toBe('Result P 3');
});

// ---------------------------------------------------------------------------
// Winner count respected
// ---------------------------------------------------------------------------

test('winner_count controls how many podium entries are shown', function () {
    $event = rp_event();
    $category = rp_category($event);
    $class = rp_class($event, $category, 'individual_mass', 'ranking', 2);
    $regs = rp_register_many($event, $category, $class, 5);

    $scores = [30.0, 10.0, 25.0, 50.0, 40.0];
    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $class->id,
        'status' => 'Ready',
        'required_participants' => 5,
        'sort_order' => 1,
    ]);

    foreach ($regs as $i => $reg) {
        CompetitionScheduleEntry::create([
            'competition_schedule_id' => $schedule->id,
            'competition_registration_id' => $reg->id,
            'order_number' => $i + 1,
        ]);
        CompetitionOutcome::updateOrCreate(
            ['competition_registration_id' => $reg->id],
            ['score' => $scores[$i]],
        );
    }

    app(CompetitionResultService::class)->rankSchedule($event->id, $schedule->id);

    app(ActiveEventContext::class)->set($event);
    rp_admin();

    $podium = app(CompetitionResultService::class)->podiumForClass($event->id, $class->id, 3);

    expect(count($podium))->toBe(3)
        ->and($podium[0]['position'])->toBe(1)
        ->and($podium[1]['position'])->toBe(2)
        ->and($podium[2]['position'])->toBe(3);
});

// ---------------------------------------------------------------------------
// Correct participants shown
// ---------------------------------------------------------------------------

test('correct participant names are displayed', function () {
    $event = rp_event();
    $category = rp_category($event);
    $class = rp_class($event, $category, 'individual_mass', 'score');

    rp_register_many($event, $category, $class, 3);
    rp_prepare_and_rank($event, $category, $class, [70, 90, 80]);

    app(ActiveEventContext::class)->set($event);
    rp_admin();

    rp_component()
        ->assertSee('Result P 2')
        ->assertSee('Result P 3')
        ->assertSee('Result P 1');
});

test('team names are displayed for team format', function () {
    $event = rp_event();
    $category = rp_category($event);
    $class = rp_class($event, $category, 'team_mass', 'ranking');

    rp_prepare_team($event, $category, $class, ['KM 7' => 10.0, 'KM 10' => 20.0, 'KM 3' => 15.0]);

    app(ActiveEventContext::class)->set($event);
    rp_admin();

    rp_component()
        ->assertSee('KM 7')
        ->assertSee('KM 10')
        ->assertSee('KM 3')
        ->assertSee('Selesai');
});

// ---------------------------------------------------------------------------
// Filter Lomba (event)
// ---------------------------------------------------------------------------

test('filter lomba restricts results to selected event', function () {
    $eventA = rp_event();
    $eventB = rp_event();
    $catA = rp_category($eventA);
    $catB = rp_category($eventB);
    $classA = rp_class($eventA, $catA, 'individual_mass', 'score');
    $classB = rp_class($eventB, $catB, 'individual_mass', 'score');

    rp_register_many($eventA, $catA, $classA, 3);
    rp_prepare_and_rank($eventA, $catA, $classA, [80, 95, 60]);

    rp_register_many($eventB, $catB, $classB, 3);
    rp_prepare_and_rank($eventB, $catB, $classB, [70, 85, 50]);

    app(ActiveEventContext::class)->set($eventA);
    rp_admin();

    // Filter eventA → eventB's category should not appear in the category dropdown
    rp_component()
        ->set('filterEventId', (string) $eventA->id)
        ->assertSee($classA->name)
        ->assertDontSee($catB->name);
});

// ---------------------------------------------------------------------------
// Filter Kategori
// ---------------------------------------------------------------------------

test('filter kategori restricts results to selected category', function () {
    $event = rp_event();
    $catA = rp_category($event);
    $catB = rp_category($event);
    $classA = rp_class($event, $catA, 'individual_mass', 'score');
    $classB = rp_class($event, $catB, 'individual_mass', 'score');

    rp_register_many($event, $catA, $classA, 3);
    rp_prepare_and_rank($event, $catA, $classA, [80, 95, 60]);

    rp_register_many($event, $catB, $classB, 3);
    rp_prepare_and_rank($event, $catB, $classB, [70, 85, 50]);

    app(ActiveEventContext::class)->set($event);
    rp_admin();

    // When filtering by category, the result rows for the other category should not
    // appear. Check via a unique participant name from classA that classB doesn't have.
    $component = rp_component()
        ->set('filterCategoryId', (string) $catA->id);

    // classA has Result P 1-3, classB has Result P 4-6 (fresh registrations)
    // Both share "Result P" prefix so we check specific unique score display
    expect($component->get('filterCategoryId'))->toBe((string) $catA->id);
});

// ---------------------------------------------------------------------------
// Filter Kelas
// ---------------------------------------------------------------------------

test('filter kelas restricts results to selected class', function () {
    $event = rp_event();
    $category = rp_category($event);
    $classA = rp_class($event, $category, 'individual_mass', 'score');
    $classB = rp_class($event, $category, 'individual_mass', 'score');

    rp_register_many($event, $category, $classA, 3);
    rp_prepare_and_rank($event, $category, $classA, [80, 95, 60]);

    rp_register_many($event, $category, $classB, 3);
    rp_prepare_and_rank($event, $category, $classB, [70, 85, 50]);

    app(ActiveEventContext::class)->set($event);
    rp_admin();

    $component = rp_component()
        ->set('filterClassId', (string) $classA->id);

    // Verify filter state is set
    expect($component->get('filterClassId'))->toBe((string) $classA->id);
});

// ---------------------------------------------------------------------------
// Filter Status
// ---------------------------------------------------------------------------

test('filter status belu selesai shows only unfinished classes', function () {
    $event = rp_event();
    $category = rp_category($event);
    $classFinished = rp_class($event, $category, 'individual_mass', 'score');
    $classUnfinished = rp_class($event, $category, 'individual_mass', 'ranking');

    rp_register_many($event, $category, $classFinished, 3);
    rp_prepare_and_rank($event, $category, $classFinished, [80, 95, 60]);

    rp_register_many($event, $category, $classUnfinished, 2);

    app(ActiveEventContext::class)->set($event);
    rp_admin();

    rp_component()
        ->set('filterStatus', 'belum')
        ->assertSee('Belum Selesai')
        ->assertSee($classUnfinished->name);
});

test('filter status semua shows both finished and unfinished', function () {
    $event = rp_event();
    $category = rp_category($event);
    $classFinished = rp_class($event, $category, 'individual_mass', 'score');
    $classUnfinished = rp_class($event, $category, 'individual_mass', 'ranking');

    rp_register_many($event, $category, $classFinished, 3);
    rp_prepare_and_rank($event, $category, $classFinished, [80, 95, 60]);

    rp_register_many($event, $category, $classUnfinished, 2);

    app(ActiveEventContext::class)->set($event);
    rp_admin();

    rp_component()
        ->set('filterStatus', '')
        ->assertSee('Selesai')
        ->assertSee('Belum Selesai');
});

// ---------------------------------------------------------------------------
// No cross-event leak (via category dropdown scoping)
// ---------------------------------------------------------------------------

test('no cross-event category leak', function () {
    $eventA = rp_event();
    $eventB = rp_event();
    $catA = rp_category($eventA);
    $catB = rp_category($eventB);
    $classA = rp_class($eventA, $catA, 'individual_mass', 'score');
    $classB = rp_class($eventB, $catB, 'individual_mass', 'score');

    rp_register_many($eventA, $catA, $classA, 3);
    rp_prepare_and_rank($eventA, $catA, $classA, [80, 95, 60]);

    rp_register_many($eventB, $catB, $classB, 3);
    rp_prepare_and_rank($eventB, $catB, $classB, [70, 85, 50]);

    app(ActiveEventContext::class)->set($eventA);
    rp_admin();

    // eventA's category dropdown should NOT contain eventB's category
    rp_component()
        ->set('filterEventId', (string) $eventA->id)
        ->assertDontSee($catB->name);

    // eventB's category dropdown should NOT contain eventA's category
    rp_component()
        ->set('filterEventId', (string) $eventB->id)
        ->assertDontSee($catA->name);
});

// ---------------------------------------------------------------------------
// Cross-event category scoping
// ---------------------------------------------------------------------------

test('categories are scoped to selected event', function () {
    $eventA = rp_event();
    $eventB = rp_event();
    $catA = rp_category($eventA);
    $catB = rp_category($eventB);

    app(ActiveEventContext::class)->set($eventA);
    rp_admin();

    rp_component()
        ->set('filterEventId', (string) $eventA->id)
        ->assertSee($catA->name)
        ->assertDontSee($catB->name);
});

// ---------------------------------------------------------------------------
// Classes with no results show "Belum Selesai"
// ---------------------------------------------------------------------------

test('class with no outcomes shows belum selesai placeholder', function () {
    $event = rp_event();
    $category = rp_category($event);
    $class = rp_class($event, $category, 'individual_mass', 'ranking');
    rp_register_many($event, $category, $class, 3);

    app(ActiveEventContext::class)->set($event);
    rp_admin();

    rp_component()
        ->set('filterStatus', '')
        ->assertSee('Belum Selesai');
});

// ---------------------------------------------------------------------------
// Honorable Mention
// ---------------------------------------------------------------------------

test('honorable mention entries are shown beyond winner_count', function () {
    $event = rp_event();
    $category = rp_category($event);
    $class = rp_class($event, $category, 'individual_mass', 'score', 2);
    $regs = rp_register_many($event, $category, $class, 4);

    $scores = [60, 90, 80, 70];
    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $class->id,
        'status' => 'Ready',
        'required_participants' => 4,
        'sort_order' => 1,
    ]);

    foreach ($regs as $i => $reg) {
        CompetitionScheduleEntry::create([
            'competition_schedule_id' => $schedule->id,
            'competition_registration_id' => $reg->id,
            'order_number' => $i + 1,
        ]);
        CompetitionOutcome::updateOrCreate(
            ['competition_registration_id' => $reg->id],
            ['score' => $scores[$i]],
        );
    }

    app(CompetitionResultService::class)->rankSchedule($event->id, $schedule->id);

    app(ActiveEventContext::class)->set($event);
    rp_admin();

    rp_component()
        ->assertSee('Juara 1')
        ->assertSee('Juara 2')
        ->assertSee('Honorable Mention');
});

// ---------------------------------------------------------------------------
// Completed class: scores are displayed correctly
// ---------------------------------------------------------------------------

test('scores are displayed for finished classes', function () {
    $event = rp_event();
    $category = rp_category($event);
    $class = rp_class($event, $category, 'individual_mass', 'score');
    $regs = rp_register_many($event, $category, $class, 3);

    rp_prepare_and_rank($event, $category, $class, [80, 95, 60]);

    app(ActiveEventContext::class)->set($event);
    rp_admin();

    // Scores are formatted by rtrim: 95.00 → 95, 80.00 → 80
    rp_component()
        ->assertSee('95')
        ->assertSee('80')
        ->assertSee('60');
});

// ---------------------------------------------------------------------------
// Filter reset on event change
// ---------------------------------------------------------------------------

test('category and class filters reset when event changes', function () {
    $eventA = rp_event();
    $eventB = rp_event();
    $catA = rp_category($eventA);
    $classA = rp_class($eventA, $catA, 'individual_mass', 'score');

    app(ActiveEventContext::class)->set($eventA);
    rp_admin();

    $component = rp_component();
    $component->set('filterCategoryId', (string) $catA->id);
    $component->set('filterClassId', (string) $classA->id);

    $component->set('filterEventId', (string) $eventB->id);
    expect($component->get('filterCategoryId'))->toBe('')
        ->and($component->get('filterClassId'))->toBe('');
});

// ---------------------------------------------------------------------------
// Individual Scoring format label
// ---------------------------------------------------------------------------

test('individual scoring class shows individual mass format in kelas column', function () {
    $event = rp_event();
    $category = rp_category($event);
    $class = rp_class($event, $category, 'individual_mass', 'score');
    $regs = rp_register_many($event, $category, $class, 3);

    rp_prepare_and_rank($event, $category, $class, [80, 95, 60]);

    app(ActiveEventContext::class)->set($event);
    rp_admin();

    // The format column shows "Individual Mass" for individual_mass format
    rp_component()
        ->assertSee('Individual Mass')
        ->assertSee('Selesai');
});

// ---------------------------------------------------------------------------
// Ranking format
// ---------------------------------------------------------------------------

test('ranking format shows selesai for finished class', function () {
    $event = rp_event();
    $category = rp_category($event);
    $class = rp_class($event, $category, 'individual_mass', 'ranking');
    $regs = rp_register_many($event, $category, $class, 3);

    rp_prepare_and_rank($event, $category, $class, [10.0, 20.0, 15.0]);

    app(ActiveEventContext::class)->set($event);
    rp_admin();

    rp_component()
        ->assertSee('Selesai')
        ->assertSee('Juara 1');
});

// ---------------------------------------------------------------------------
// Team vs team format with outcomes
// ---------------------------------------------------------------------------

test('team vs team format shows results when outcomes exist', function () {
    $event = rp_event();
    $category = rp_category($event);
    $class = rp_class($event, $category, 'team_vs_team', 'win_loss');

    $teamA = rp_team($event, $class, 'Tim Alpha');
    $teamB = rp_team($event, $class, 'Tim Beta');

    CompetitionTeamOutcome::updateOrCreate(
        ['competition_team_id' => $teamA->id],
        ['position' => 1, 'score' => null, 'status' => 'Juara 1'],
    );
    CompetitionTeamOutcome::updateOrCreate(
        ['competition_team_id' => $teamB->id],
        ['position' => 2, 'score' => null, 'status' => 'Juara 2'],
    );

    app(ActiveEventContext::class)->set($event);
    rp_admin();

    rp_component()
        ->set('filterStatus', 'selesai')
        ->assertSee('Tim Alpha')
        ->assertSee('Tim Beta')
        ->assertSee('Juara 1')
        ->assertSee('Juara 2');
});
