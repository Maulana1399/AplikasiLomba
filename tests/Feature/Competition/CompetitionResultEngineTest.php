<?php

use App\Enums\Role;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionOutcome;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionSchedule;
use App\Models\CompetitionScheduleEntry;
use App\Models\Event;
use App\Models\Person;
use App\Models\User;
use App\Services\Competition\CompetitionRegistrationService;
use App\Services\Competition\CompetitionResultService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function cge_event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'CGE Event '.str()->random(6),
        'slug' => 'cge-'.str()->random(6),
        'status' => 'active',
        'event_type' => 'competition',
    ], $overrides));
}

function cge_category(Event $event): CompetitionCategory
{
    $category = CompetitionCategory::create(['event_id' => $event->id, 'name' => 'CGE Cat '.str()->random(4)]);

    $category->events()->attach($event);

    return $category;
}

function cge_class(Event $event, CompetitionCategory $category, string $format, ?string $resultType = null): CompetitionClass
{
    return CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'CGE Class '.str()->random(4),
        'gender' => 'M',
        'format' => $format,
        'status' => 'registration_open',
        'result_type' => $resultType,
        'is_active' => true,
    ]);
}

function cge_person(string $nama): Person
{
    return Person::create(['nama' => $nama, 'jenis_kelamin' => 'L']);
}

function cge_register(Person $person, Event $event, CompetitionCategory $category, CompetitionClass $class): CompetitionRegistration
{
    return app(CompetitionRegistrationService::class)->registerForPerson(
        person: $person,
        eventId: $event->id,
        competitionCategoryId: $category->id,
        competitionClassId: $class->id,
    )['competition_registration'];
}

function cge_schedule(CompetitionClass $class, int $required = 1): CompetitionSchedule
{
    return CompetitionSchedule::create([
        'competition_class_id' => $class->id,
        'status' => 'Scheduled',
        'required_participants' => $required,
    ]);
}

function cge_entry(CompetitionSchedule $schedule, CompetitionRegistration $registration): CompetitionScheduleEntry
{
    return CompetitionScheduleEntry::create([
        'competition_schedule_id' => $schedule->id,
        'competition_registration_id' => $registration->id,
    ]);
}

function cge_outcome(CompetitionRegistration $registration, ?float $score, ?string $status = null): CompetitionOutcome
{
    return CompetitionOutcome::updateOrCreate(
        ['competition_registration_id' => $registration->id],
        ['score' => $score, 'status' => $status, 'position' => null],
    );
}

// ---------------------------------------------------------------------------
// Schema + result type resolution
// ---------------------------------------------------------------------------

test('competition_classes has nullable result_type column', function () {
    expect(Schema::hasColumn('competition_classes', 'result_type'))->toBeTrue();
});

test('result type resolves from format default', function () {
    $event = cge_event();
    $category = cge_category($event);

    expect(cge_class($event, $category, 'individual_mass')->resultType())->toBe('ranking');
    expect(cge_class($event, $category, 'individual_heat')->resultType())->toBe('time');
    expect(cge_class($event, $category, 'individual_vs_individual')->resultType())->toBe('score');
    expect(cge_class($event, $category, 'team_vs_team')->resultType())->toBe('win_loss');
});

test('explicit result_type overrides format default', function () {
    $event = cge_event();
    $category = cge_category($event);
    $class = cge_class($event, $category, 'individual_mass', 'score');

    expect($class->resultType())->toBe('score');
});

// ---------------------------------------------------------------------------
// Auto ranking — Individual Mass (ascending score = finish order / time)
// ---------------------------------------------------------------------------

test('rankSchedule ranks mass participants ascending and persists positions', function () {
    $event = cge_event();
    $category = cge_category($event);
    $class = cge_class($event, $category, 'individual_mass');
    $schedule = cge_schedule($class, 5);

    $scores = [30, 25, 28, 22, 35];
    $registrations = collect($scores)->map(function ($score, $i) use ($event, $category, $class, $schedule) {
        $reg = cge_register(cge_person('Mass '.($i + 1)), $event, $category, $class);
        cge_entry($schedule, $reg);
        cge_outcome($reg, $score);

        return $reg;
    });

    $result = app(CompetitionResultService::class)->rankSchedule($event->id, $schedule->id);

    expect($result['ranked'])->toBeTrue()
        ->and($result['result_type'])->toBe('ranking')
        ->and($result['direction'])->toBe('asc');

    // Ascending: 22→1, 25→2, 28→3, 30→4, 35→5
    $positionByScore = $registrations->mapWithKeys(function ($reg) {
        return [(float) $reg->outcome->score => $reg->outcome->fresh()->position];
    })->sortKeys()->all();

    expect($positionByScore)->toBe([22 => 1, 25 => 2, 28 => 3, 30 => 4, 35 => 5]);
});

test('rankSchedule sorts score-descending when result type is score', function () {
    $event = cge_event();
    $category = cge_category($event);
    $class = cge_class($event, $category, 'individual_vs_individual', 'score');
    $schedule = cge_schedule($class, 3);

    $registrations = collect([30, 10, 25])->map(function ($score) use ($event, $category, $class, $schedule) {
        $reg = cge_register(cge_person('Score '.$score), $event, $category, $class);
        cge_entry($schedule, $reg);
        cge_outcome($reg, $score);

        return $reg;
    });

    app(CompetitionResultService::class)->rankSchedule($event->id, $schedule->id);

    $positionByScore = $registrations->mapWithKeys(function ($reg) {
        return [(float) $reg->outcome->score => $reg->outcome->fresh()->position];
    })->sortKeys()->all();

    // Descending: 30→1, 25→2, 10→3
    expect($positionByScore)->toBe([10 => 3, 25 => 2, 30 => 1]);
});

test('rankSchedule handles ties with competition ranking (1,1,3)', function () {
    $event = cge_event();
    $category = cge_category($event);
    $class = cge_class($event, $category, 'individual_mass');
    $schedule = cge_schedule($class, 3);

    $registrations = collect([25, 25, 30])->map(function ($score) use ($event, $category, $class, $schedule) {
        $reg = cge_register(cge_person('Tie '.$score), $event, $category, $class);
        cge_entry($schedule, $reg);
        cge_outcome($reg, $score);

        return $reg;
    });

    app(CompetitionResultService::class)->rankSchedule($event->id, $schedule->id);

    $positions = $registrations->map(fn ($reg) => $reg->outcome->fresh()->position)->sort()->values()->all();
    expect($positions)->toBe([1, 1, 3]);
});

test('rankSchedule excludes disqualified / absent participants', function () {
    $event = cge_event();
    $category = cge_category($event);
    $class = cge_class($event, $category, 'individual_mass');
    $schedule = cge_schedule($class, 3);

    $a = cge_register(cge_person('A'), $event, $category, $class);
    $b = cge_register(cge_person('B'), $event, $category, $class);
    $c = cge_register(cge_person('C'), $event, $category, $class);
    cge_entry($schedule, $a);
    cge_entry($schedule, $b);
    cge_entry($schedule, $c);

    cge_outcome($a, 10);
    cge_outcome($b, 20);
    cge_outcome($c, 30, 'Diskualifikasi');

    $result = app(CompetitionResultService::class)->rankSchedule($event->id, $schedule->id);

    expect($result['rows'])->toHaveCount(2)
        ->and($a->outcome->fresh()->position)->toBe(1)
        ->and($b->outcome->fresh()->position)->toBe(2)
        ->and($c->outcome->fresh()->position)->toBeNull();
});

test('rankSchedule skips participants without a score', function () {
    $event = cge_event();
    $category = cge_category($event);
    $class = cge_class($event, $category, 'individual_mass');
    $schedule = cge_schedule($class, 2);

    $a = cge_register(cge_person('A'), $event, $category, $class);
    $b = cge_register(cge_person('B'), $event, $category, $class);
    cge_entry($schedule, $a);
    cge_entry($schedule, $b);

    cge_outcome($a, 10);
    cge_outcome($b, null);

    $result = app(CompetitionResultService::class)->rankSchedule($event->id, $schedule->id);

    expect($result['rows'])->toHaveCount(1)
        ->and($a->outcome->fresh()->position)->toBe(1)
        ->and($b->outcome->fresh()->position)->toBeNull();
});

// ---------------------------------------------------------------------------
// win_loss not ranked
// ---------------------------------------------------------------------------

test('rankSchedule is no-op for win_loss format', function () {
    $event = cge_event();
    $category = cge_category($event);
    $class = cge_class($event, $category, 'team_vs_team');
    $schedule = cge_schedule($class, 2);

    $a = cge_register(cge_person('A'), $event, $category, $class);
    cge_entry($schedule, $a);
    cge_outcome($a, 5);

    $result = app(CompetitionResultService::class)->rankSchedule($event->id, $schedule->id);

    expect($result['ranked'])->toBeFalse()
        ->and($result['reason'])->toBe('win_loss')
        ->and($a->outcome->fresh()->position)->toBeNull();
});

// ---------------------------------------------------------------------------
// Event scope
// ---------------------------------------------------------------------------

test('rankSchedule cannot run for schedule of another event', function () {
    $eventA = cge_event();
    $eventB = cge_event();
    $category = cge_category($eventA);
    $class = cge_class($eventA, $category, 'individual_mass');
    $schedule = cge_schedule($class, 1);
    $reg = cge_register(cge_person('A'), $eventA, $category, $class);
    cge_entry($schedule, $reg);
    cge_outcome($reg, 10);

    expect(fn () => app(CompetitionResultService::class)->rankSchedule($eventB->id, $schedule->id))
        ->toThrow(ModelNotFoundException::class);
});

// ---------------------------------------------------------------------------
// Podium
// ---------------------------------------------------------------------------

test('podium returns top 3 after ranking', function () {
    $event = cge_event();
    $category = cge_category($event);
    $class = cge_class($event, $category, 'individual_mass');
    $schedule = cge_schedule($class, 5);

    $registrations = collect([30, 25, 28, 22, 35])->map(function ($score) use ($event, $category, $class, $schedule) {
        $reg = cge_register(cge_person('Podium '.$score), $event, $category, $class);
        cge_entry($schedule, $reg);
        cge_outcome($reg, $score);

        return $reg;
    });

    app(CompetitionResultService::class)->rankSchedule($event->id, $schedule->id);

    $podium = app(CompetitionResultService::class)->podiumForSchedule($event->id, $schedule->id);

    expect($podium)->toHaveCount(3)
        ->and($podium[0]['position'])->toBe(1)
        ->and($podium[1]['position'])->toBe(2)
        ->and($podium[2]['position'])->toBe(3)
        ->and($podium[0]['person_name'])->toBe('Podium 22');
});

// ---------------------------------------------------------------------------
// Component — OutcomeManager autoRank
// ---------------------------------------------------------------------------

test('OutcomeManager autoRank ranks entries and shows podium', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cge_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cge_category($event);
    $class = cge_class($event, $category, 'individual_mass');
    $schedule = cge_schedule($class, 3);

    $a = cge_register(cge_person('R A'), $event, $category, $class);
    $b = cge_register(cge_person('R B'), $event, $category, $class);
    $c = cge_register(cge_person('R C'), $event, $category, $class);
    cge_entry($schedule, $a);
    cge_entry($schedule, $b);
    cge_entry($schedule, $c);
    cge_outcome($a, 30);
    cge_outcome($b, 25);
    cge_outcome($c, 20);

    \Livewire::test(\App\Livewire\Competition\Schedule\OutcomeManager::class, ['schedule' => $schedule])
        ->call('autoRank')
        ->assertHasNoErrors()
        ->assertSee('Ranking otomatis selesai')
        ->assertSee('Juara 1');

    expect($a->outcome->fresh()->position)->toBe(3)
        ->and($b->outcome->fresh()->position)->toBe(2)
        ->and($c->outcome->fresh()->position)->toBe(1);
});
