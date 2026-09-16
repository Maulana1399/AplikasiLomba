<?php

use App\Enums\Role;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
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
use App\Services\Competition\CompetitionTeamFormationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function ctc_event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'CTC Event '.str()->random(6),
        'slug' => 'ctc-'.str()->random(6),
        'status' => 'active',
        'event_type' => 'competition',
    ], $overrides));
}

function ctc_category(Event $event): CompetitionCategory
{
    $category = CompetitionCategory::create(['event_id' => $event->id, 'name' => 'CTC Cat '.str()->random(4)]);

    $category->events()->attach($event);

    return $category;
}

function ctc_class(Event $event, CompetitionCategory $category, string $format = 'team_mass'): CompetitionClass
{
    return CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'CTC Class '.str()->random(4),
        'gender' => 'M',
        'format' => $format,
        'status' => 'registration_open',
        'is_active' => true,
    ]);
}

function ctc_kelompok(string $name): kelompok
{
    return kelompok::create(['kelompok_asal' => $name]);
}

function ctc_person(string $nama, ?kelompok $kelompok = null): Person
{
    return Person::create(['nama' => $nama, 'jenis_kelamin' => 'L', 'kelompok_id' => $kelompok?->id]);
}

function ctc_register(Person $person, Event $event, CompetitionCategory $category, CompetitionClass $class): CompetitionRegistration
{
    return app(CompetitionRegistrationService::class)->registerForPerson(
        person: $person,
        eventId: $event->id,
        competitionCategoryId: $category->id,
        competitionClassId: $class->id,
    )['competition_registration'];
}

function ctc_team(Event $event, CompetitionClass $class, ?kelompok $kelompok, string $name): CompetitionTeam
{
    return CompetitionTeam::create([
        'event_id' => $event->id,
        'competition_class_id' => $class->id,
        'name' => $name,
        'kelompok_id' => $kelompok?->id,
        'is_active' => true,
    ]);
}

function ctc_team_outcome(CompetitionTeam $team, ?float $score, ?string $status = null): CompetitionTeamOutcome
{
    return CompetitionTeamOutcome::updateOrCreate(
        ['competition_team_id' => $team->id],
        ['score' => $score, 'status' => $status, 'position' => null],
    );
}

function ctc_schedule(CompetitionClass $class): CompetitionSchedule
{
    return CompetitionSchedule::create(['competition_class_id' => $class->id, 'status' => 'Scheduled', 'required_participants' => 1]);
}

// ---------------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------------

test('team competitor foundation schema exists', function () {
    expect(Schema::hasColumns('competition_schedule_entries', ['competition_team_id', 'competition_registration_id']))->toBeTrue()
        ->and(Schema::hasColumn('competition_schedules', 'winner_team_id'))->toBeTrue()
        ->and(Schema::hasTable('competition_team_outcomes'))->toBeTrue()
        ->and(Schema::hasColumns('competition_team_outcomes', ['competition_team_id', 'position', 'status', 'score']))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Team as schedule entry
// ---------------------------------------------------------------------------

test('EntryManager team mode lists teams and assigns them to a schedule', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = ctc_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = ctc_category($event);
    $class = ctc_class($event, $category, 'team_mass');
    $schedule = ctc_schedule($class);
    $teamA = ctc_team($event, $class, ctc_kelompok('KM 7'), 'KM 7');
    $teamB = ctc_team($event, $class, ctc_kelompok('KM 10'), 'KM 10');

    $component = \Livewire::test(\App\Livewire\Competition\Schedule\EntryManager::class, ['schedule' => $schedule]);

    expect($component->get('isTeam'))->toBeTrue();

    $component->call('assign', $teamA->id);

    expect(CompetitionScheduleEntry::where('competition_schedule_id', $schedule->id)
        ->where('competition_team_id', $teamA->id)->exists())->toBeTrue()
        ->and(CompetitionScheduleEntry::where('competition_schedule_id', $schedule->id)
            ->whereNotNull('competition_registration_id')->count())->toBe(0);
});

test('duplicate team assignment to the same schedule is blocked by unique constraint', function () {
    $event = ctc_event();
    $category = ctc_category($event);
    $class = ctc_class($event, $category, 'team_mass');
    $schedule = ctc_schedule($class);
    $team = ctc_team($event, $class, ctc_kelompok('KM 7'), 'KM 7');

    CompetitionScheduleEntry::create(['competition_schedule_id' => $schedule->id, 'competition_team_id' => $team->id]);

    expect(fn () => CompetitionScheduleEntry::create(['competition_schedule_id' => $schedule->id, 'competition_team_id' => $team->id]))
        ->toThrow(QueryException::class);
});

test('EntryManager rejects team that does not belong to the class', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = ctc_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = ctc_category($event);
    $classA = ctc_class($event, $category, 'team_mass');
    $classB = ctc_class($event, $category, 'team_mass');
    $schedule = ctc_schedule($classA);
    $teamFromB = ctc_team($event, $classB, ctc_kelompok('KM 99'), 'KM 99');

    \Livewire::test(\App\Livewire\Competition\Schedule\EntryManager::class, ['schedule' => $schedule])
        ->call('assign', $teamFromB->id);

    expect(CompetitionScheduleEntry::where('competition_schedule_id', $schedule->id)->count())->toBe(0);
});

test('registration and team entries coexist in schedule_entries', function () {
    $event = ctc_event();
    $category = ctc_category($event);
    $class = ctc_class($event, $category, 'individual_mass');
    $schedule = ctc_schedule($class);

    $person = ctc_person('Orang');
    $reg = ctc_register($person, $event, $category, $class);
    $team = ctc_team($event, $class, ctc_kelompok('KM 1'), 'KM 1');

    CompetitionScheduleEntry::create(['competition_schedule_id' => $schedule->id, 'competition_registration_id' => $reg->id]);
    CompetitionScheduleEntry::create(['competition_schedule_id' => $schedule->id, 'competition_team_id' => $team->id]);

    expect(CompetitionScheduleEntry::where('competition_schedule_id', $schedule->id)->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// Team Mass ranking + podium
// ---------------------------------------------------------------------------

test('rankTeams ranks class teams by score and writes positions', function () {
    $event = ctc_event();
    $category = ctc_category($event);
    $class = ctc_class($event, $category, 'team_mass');
    $t1 = ctc_team($event, $class, ctc_kelompok('KM 7'), 'KM 7');
    $t2 = ctc_team($event, $class, ctc_kelompok('KM 10'), 'KM 10');
    $t3 = ctc_team($event, $class, ctc_kelompok('KM 12'), 'KM 12');

    ctc_team_outcome($t1, 30);
    ctc_team_outcome($t2, 25);
    ctc_team_outcome($t3, 20);

    $result = app(CompetitionResultService::class)->rankTeams($event->id, $class->id);

    expect($result['ranked'])->toBeTrue()
        ->and($result['rows'])->toHaveCount(3)
        ->and($t3->outcome->fresh()->position)->toBe(1)
        ->and($t2->outcome->fresh()->position)->toBe(2)
        ->and($t1->outcome->fresh()->position)->toBe(3);
});

test('rankTeams handles ties and excluded teams', function () {
    $event = ctc_event();
    $category = ctc_category($event);
    $class = ctc_class($event, $category, 'team_mass');
    $t1 = ctc_team($event, $class, ctc_kelompok('A'), 'A');
    $t2 = ctc_team($event, $class, ctc_kelompok('B'), 'B');
    $t3 = ctc_team($event, $class, ctc_kelompok('C'), 'C');
    $t4 = ctc_team($event, $class, ctc_kelompok('D'), 'D');

    ctc_team_outcome($t1, 25);
    ctc_team_outcome($t2, 25);
    ctc_team_outcome($t3, 30);
    ctc_team_outcome($t4, 10, 'Diskualifikasi');

    $result = app(CompetitionResultService::class)->rankTeams($event->id, $class->id);

    expect($result['rows'])->toHaveCount(3)
        ->and([$t1->outcome->fresh()->position, $t2->outcome->fresh()->position])->toContain(1, 1)
        ->and($t3->outcome->fresh()->position)->toBe(3)
        ->and($t4->outcome->fresh()->position)->toBeNull();
});

test('podiumForTeams returns top 3 teams', function () {
    $event = ctc_event();
    $category = ctc_category($event);
    $class = ctc_class($event, $category, 'team_mass');
    $t1 = ctc_team($event, $class, ctc_kelompok('KM 7'), 'KM 7');
    $t2 = ctc_team($event, $class, ctc_kelompok('KM 10'), 'KM 10');
    $t3 = ctc_team($event, $class, ctc_kelompok('KM 12'), 'KM 12');
    ctc_team_outcome($t1, 30);
    ctc_team_outcome($t2, 25);
    ctc_team_outcome($t3, 20);

    app(CompetitionResultService::class)->rankTeams($event->id, $class->id);

    $podium = app(CompetitionResultService::class)->podiumForTeams($event->id, $class->id);

    expect($podium)->toHaveCount(3)
        ->and($podium[0]['team_name'])->toBe('KM 12')
        ->and($podium[1]['team_name'])->toBe('KM 10')
        ->and($podium[2]['team_name'])->toBe('KM 7');
});

test('rankTeams cannot run for class of another event', function () {
    $eventA = ctc_event();
    $eventB = ctc_event();
    $category = ctc_category($eventA);
    $class = ctc_class($eventA, $category, 'team_mass');
    $team = ctc_team($eventA, $class, ctc_kelompok('KM 1'), 'KM 1');
    ctc_team_outcome($team, 10);

    expect(fn () => app(CompetitionResultService::class)->rankTeams($eventB->id, $class->id))
        ->toThrow(ModelNotFoundException::class);
});

// ---------------------------------------------------------------------------
// Formation guard
// ---------------------------------------------------------------------------

test('auto formation refuses to regenerate teams already scheduled', function () {
    $event = ctc_event();
    $category = ctc_category($event);
    $class = ctc_class($event, $category, 'team_vs_team');
    $km = ctc_kelompok('KM 7');

    foreach (range(1, 8) as $i) {
        ctc_register(ctc_person("P{$i}", $km), $event, $category, $class);
    }

    app(CompetitionTeamFormationService::class)->formForClass($event->id, $class->id);

    $team = CompetitionTeam::where('competition_class_id', $class->id)->first();
    $schedule = ctc_schedule($class);
    CompetitionScheduleEntry::create(['competition_schedule_id' => $schedule->id, 'competition_team_id' => $team->id]);

    expect(fn () => app(CompetitionTeamFormationService::class)->formForClass($event->id, $class->id))
        ->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// OutcomeManager team path
// ---------------------------------------------------------------------------

test('OutcomeManager team path saves team results and shows podium', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = ctc_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = ctc_category($event);
    $class = ctc_class($event, $category, 'team_mass');
    $schedule = ctc_schedule($class);
    $t1 = ctc_team($event, $class, ctc_kelompok('KM 7'), 'KM 7');
    $t2 = ctc_team($event, $class, ctc_kelompok('KM 10'), 'KM 10');
    CompetitionScheduleEntry::create(['competition_schedule_id' => $schedule->id, 'competition_team_id' => $t1->id]);
    CompetitionScheduleEntry::create(['competition_schedule_id' => $schedule->id, 'competition_team_id' => $t2->id]);

    $component = \Livewire::test(\App\Livewire\Competition\Schedule\OutcomeManager::class, ['schedule' => $schedule]);
    $inst = $component->instance();

    expect($inst->isTeam)->toBeTrue();

    $rows = $inst->teamOutcomes;
    $rows[0]['score'] = '30';
    $rows[1]['score'] = '25';
    $inst->teamOutcomes = $rows;
    $inst->saveOutcomes();
    $inst->autoRank();

    $t1->refresh();
    $t2->refresh();

    expect(CompetitionTeamOutcome::count())->toBe(2)
        ->and((float) $t1->outcome->score)->toBe(30.0)
        ->and((float) $t2->outcome->score)->toBe(25.0)
        ->and($t1->outcome->position)->toBe(2)
        ->and($t2->outcome->position)->toBe(1);

    // Fresh page shows the team podium.
    \Livewire::test(\App\Livewire\Competition\Schedule\OutcomeManager::class, ['schedule' => $schedule])
        ->assertSet('isTeam', true)
        ->assertSee('Juara 1')
        ->assertSee('KM 10');
});
