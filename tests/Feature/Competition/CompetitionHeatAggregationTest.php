<?php

use App\Enums\Role;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionHeatResult;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionSchedule;
use App\Models\CompetitionScheduleEntry;
use App\Models\Event;
use App\Models\Person;
use App\Models\User;
use App\Services\Competition\CompetitionRegistrationService;
use App\Services\Competition\CompetitionResultService;
use App\Support\CompetitionTime;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function cha_event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'CHA Event '.str()->random(6),
        'slug' => 'cha-'.str()->random(6),
        'status' => 'active',
        'event_type' => 'competition',
    ], $overrides));
}

function cha_category(Event $event): CompetitionCategory
{
    return CompetitionCategory::create(['event_id' => $event->id, 'name' => 'CHA Cat '.str()->random(4)]);
}

function cha_class(Event $event, CompetitionCategory $category, string $format = 'individual_heat', ?string $resultType = null): CompetitionClass
{
    return CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'CHA Class '.str()->random(4),
        'gender' => 'M',
        'format' => $format,
        'status' => 'registration_open',
        'result_type' => $resultType,
        'is_active' => true,
    ]);
}

function cha_person(string $nama): Person
{
    return Person::create(['nama' => $nama, 'jenis_kelamin' => 'L']);
}

function cha_register(Person $person, Event $event, CompetitionCategory $category, CompetitionClass $class): CompetitionRegistration
{
    return app(CompetitionRegistrationService::class)->registerForPerson(
        person: $person,
        eventId: $event->id,
        competitionCategoryId: $category->id,
        competitionClassId: $class->id,
    )['competition_registration'];
}

function cha_schedule(CompetitionClass $class, int $sortOrder = 1): CompetitionSchedule
{
    return CompetitionSchedule::create([
        'competition_class_id' => $class->id,
        'status' => 'Scheduled',
        'required_participants' => 1,
        'sort_order' => $sortOrder,
    ]);
}

function cha_entry(CompetitionSchedule $schedule, CompetitionRegistration $registration): CompetitionScheduleEntry
{
    return CompetitionScheduleEntry::create([
        'competition_schedule_id' => $schedule->id,
        'competition_registration_id' => $registration->id,
    ]);
}

function cha_heat(CompetitionSchedule $schedule, CompetitionRegistration $registration, ?float $seconds, ?string $status = null): CompetitionHeatResult
{
    return CompetitionHeatResult::updateOrCreate(
        ['competition_schedule_id' => $schedule->id, 'competition_registration_id' => $registration->id],
        ['score' => $seconds, 'status' => $status, 'position' => null],
    );
}

// ---------------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------------

test('competition_heat_results table exists with expected schema', function () {
    expect(Schema::hasTable('competition_heat_results'))->toBeTrue()
        ->and(Schema::hasColumns('competition_heat_results', [
            'competition_schedule_id', 'competition_registration_id', 'score', 'position', 'status', 'notes',
        ]))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Time representation
// ---------------------------------------------------------------------------

test('CompetitionTime parses mm:ss.mmm and plain seconds', function () {
    expect(CompetitionTime::parse('1:32.5'))->toBe(92.5)
        ->and(CompetitionTime::parse('01:02.500'))->toBe(62.5)
        ->and(CompetitionTime::parse('92.5'))->toBe(92.5)
        ->and(CompetitionTime::parse('0:05.25'))->toBe(5.25)
        ->and(CompetitionTime::parse(''))->toBeNull()
        ->and(CompetitionTime::parse('abc'))->toBeNull();
});

test('CompetitionTime formats seconds to M:SS.mmm', function () {
    expect(CompetitionTime::format(92.5))->toBe('1:32.500')
        ->and(CompetitionTime::format(5.25))->toBe('0:05.250')
        ->and(CompetitionTime::format(null))->toBe('');
});

// ---------------------------------------------------------------------------
// Multi-heat aggregation (Individual Heat, result_type = time)
// ---------------------------------------------------------------------------

test('aggregateHeatResults combines heats into final ranking (best time)', function () {
    $event = cha_event();
    $category = cha_category($event);
    $class = cha_class($event, $category, 'individual_heat');
    $heat1 = cha_schedule($class, 1);
    $heat2 = cha_schedule($class, 2);

    $a = cha_register(cha_person('Ath A'), $event, $category, $class);
    $b = cha_register(cha_person('Ath B'), $event, $category, $class);
    $c = cha_register(cha_person('Ath C'), $event, $category, $class);

    foreach (['heat1' => $heat1, 'heat2' => $heat2] as $schedule) {
        cha_entry($schedule, $a);
        cha_entry($schedule, $b);
        cha_entry($schedule, $c);
    }

    // Heat 1: A=100.0s, B=95.0s, C=110.0s
    cha_heat($heat1, $a, 100.0);
    cha_heat($heat1, $b, 95.0);
    cha_heat($heat1, $c, 110.0);
    // Heat 2: A=90.0s, B=98.0s, C=105.0s
    cha_heat($heat2, $a, 90.0);
    cha_heat($heat2, $b, 98.0);
    cha_heat($heat2, $c, 105.0);

    $result = app(CompetitionResultService::class)->aggregateHeatResults($event->id, $class->id);

    expect($result['ranked'])->toBeTrue()
        ->and($result['result_type'])->toBe('time')
        ->and($result['direction'])->toBe('asc')
        ->and($result['rows'])->toHaveCount(3);

    $positionByPerson = [
        $a->outcome->fresh()->position => 'Ath A',
        $b->outcome->fresh()->position => 'Ath B',
        $c->outcome->fresh()->position => 'Ath C',
    ];

    // Best times: A=90.0 → 1, B=95.0 → 2, C=105.0 → 3
    expect($positionByPerson[1])->toBe('Ath A')
        ->and($positionByPerson[2])->toBe('Ath B')
        ->and($positionByPerson[3])->toBe('Ath C')
        ->and((float) $a->outcome->fresh()->score)->toBe(90.0);
});

test('aggregateHeatResults excludes disqualified heat result', function () {
    $event = cha_event();
    $category = cha_category($event);
    $class = cha_class($event, $category, 'individual_heat');
    $heat1 = cha_schedule($class, 1);
    $heat2 = cha_schedule($class, 2);

    $a = cha_register(cha_person('Ath A'), $event, $category, $class);
    $b = cha_register(cha_person('Ath B'), $event, $category, $class);

    cha_entry($heat1, $a);
    cha_entry($heat2, $a);
    cha_entry($heat1, $b);
    cha_entry($heat2, $b);

    cha_heat($heat1, $a, 100.0);
    cha_heat($heat2, $a, 90.0);
    cha_heat($heat1, $b, 95.0);
    cha_heat($heat2, $b, 94.0, 'Diskualifikasi'); // excluded

    app(CompetitionResultService::class)->aggregateHeatResults($event->id, $class->id);

    // B only valid heat = 95.0; A best = 90.0
    expect($a->outcome->fresh()->position)->toBe(1)
        ->and($b->outcome->fresh()->position)->toBe(2)
        ->and((float) $b->outcome->fresh()->score)->toBe(95.0);
});

test('aggregateHeatResults aggregates score-descending when result type is score', function () {
    $event = cha_event();
    $category = cha_category($event);
    $class = cha_class($event, $category, 'individual_heat', 'score');
    $heat1 = cha_schedule($class, 1);
    $heat2 = cha_schedule($class, 2);

    $a = cha_register(cha_person('Sc A'), $event, $category, $class);
    $b = cha_register(cha_person('Sc B'), $event, $category, $class);

    cha_entry($heat1, $a);
    cha_entry($heat2, $a);
    cha_entry($heat1, $b);
    cha_entry($heat2, $b);

    cha_heat($heat1, $a, 7.0);
    cha_heat($heat2, $a, 9.5);
    cha_heat($heat1, $b, 8.0);
    cha_heat($heat2, $b, 6.5);

    app(CompetitionResultService::class)->aggregateHeatResults($event->id, $class->id);

    // Max: A=9.5 → 1, B=8.0 → 2
    expect($a->outcome->fresh()->position)->toBe(1)
        ->and($b->outcome->fresh()->position)->toBe(2);
});

test('aggregateHeatResults handles ties in final ranking', function () {
    $event = cha_event();
    $category = cha_category($event);
    $class = cha_class($event, $category, 'individual_heat');
    $heat1 = cha_schedule($class, 1);

    $a = cha_register(cha_person('Tie A'), $event, $category, $class);
    $b = cha_register(cha_person('Tie B'), $event, $category, $class);
    $c = cha_register(cha_person('Tie C'), $event, $category, $class);

    cha_entry($heat1, $a);
    cha_entry($heat1, $b);
    cha_entry($heat1, $c);

    cha_heat($heat1, $a, 90.0);
    cha_heat($heat1, $b, 90.0);
    cha_heat($heat1, $c, 100.0);

    app(CompetitionResultService::class)->aggregateHeatResults($event->id, $class->id);

    $positions = [$a->outcome->fresh()->position, $b->outcome->fresh()->position, $c->outcome->fresh()->position];
    sort($positions);
    expect($positions)->toBe([1, 1, 3]);
});

// ---------------------------------------------------------------------------
// Event scope / win_loss
// ---------------------------------------------------------------------------

test('aggregateHeatResults cannot run for class of another event', function () {
    $eventA = cha_event();
    $eventB = cha_event();
    $category = cha_category($eventA);
    $class = cha_class($eventA, $category, 'individual_heat');
    $schedule = cha_schedule($class);
    $reg = cha_register(cha_person('Ath'), $eventA, $category, $class);
    cha_entry($schedule, $reg);
    cha_heat($schedule, $reg, 90.0);

    expect(fn () => app(CompetitionResultService::class)->aggregateHeatResults($eventB->id, $class->id))
        ->toThrow(ModelNotFoundException::class);
});

test('aggregateHeatResults is no-op for win_loss', function () {
    $event = cha_event();
    $category = cha_category($event);
    $class = cha_class($event, $category, 'team_vs_team');
    $schedule = cha_schedule($class);
    $reg = cha_register(cha_person('Ath'), $event, $category, $class);
    cha_entry($schedule, $reg);
    cha_heat($schedule, $reg, 90.0);

    $result = app(CompetitionResultService::class)->aggregateHeatResults($event->id, $class->id);

    expect($result['ranked'])->toBeFalse()
        ->and($result['reason'])->toBe('win_loss')
        ->and($reg->outcome)->toBeNull();
});

// ---------------------------------------------------------------------------
// Final podium (class-wide)
// ---------------------------------------------------------------------------

test('podiumForClass returns top 3 after aggregation', function () {
    $event = cha_event();
    $category = cha_category($event);
    $class = cha_class($event, $category, 'individual_heat');
    $heat1 = cha_schedule($class, 1);
    $heat2 = cha_schedule($class, 2);

    $a = cha_register(cha_person('Pod A'), $event, $category, $class);
    $b = cha_register(cha_person('Pod B'), $event, $category, $class);
    $c = cha_register(cha_person('Pod C'), $event, $category, $class);

    foreach ([$heat1, $heat2] as $schedule) {
        cha_entry($schedule, $a);
        cha_entry($schedule, $b);
        cha_entry($schedule, $c);
    }

    cha_heat($heat1, $a, 100.0);
    cha_heat($heat1, $b, 95.0);
    cha_heat($heat1, $c, 110.0);
    cha_heat($heat2, $a, 90.0);
    cha_heat($heat2, $b, 98.0);
    cha_heat($heat2, $c, 105.0);

    app(CompetitionResultService::class)->aggregateHeatResults($event->id, $class->id);

    $podium = app(CompetitionResultService::class)->podiumForClass($event->id, $class->id);

    expect($podium)->toHaveCount(3)
        ->and($podium[0]['position'])->toBe(1)
        ->and($podium[0]['person_name'])->toBe('Pod A')
        ->and($podium[1]['person_name'])->toBe('Pod B')
        ->and($podium[2]['person_name'])->toBe('Pod C');
});

// ---------------------------------------------------------------------------
// Component — OutcomeManager heat flow
// ---------------------------------------------------------------------------

test('OutcomeManager heat flow saves per-heat time, aggregates final and shows podium', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cha_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cha_category($event);
    $class = cha_class($event, $category, 'individual_heat');
    $heat1 = cha_schedule($class, 1);

    $a = cha_register(cha_person('H A'), $event, $category, $class);
    $b = cha_register(cha_person('H B'), $event, $category, $class);
    cha_entry($heat1, $a);
    cha_entry($heat1, $b);

    $component = \Livewire::test(\App\Livewire\Competition\Schedule\OutcomeManager::class, ['schedule' => $heat1]);
    $inst = $component->instance();

    // Simulate operator typing time into the heat form, then saving.
    $heatRows = $inst->heatResults;
    $heatRows[0]['timeText'] = '1:40.0';
    $heatRows[1]['timeText'] = '1:35.0';
    $inst->heatResults = $heatRows;
    $inst->saveOutcomes();

    $saved = CompetitionHeatResult::where('competition_schedule_id', $heat1->id)->orderBy('id')->get();
    expect($saved)->toHaveCount(2)
        ->and((float) $saved[0]->score)->toBe(100.0)
        ->and((float) $saved[1]->score)->toBe(95.0);

    $inst->aggregateFinal();

    expect((float) $a->outcome->fresh()->score)->toBe(100.0)
        ->and((float) $b->outcome->fresh()->score)->toBe(95.0)
        ->and($a->outcome->fresh()->position)->toBe(2)
        ->and($b->outcome->fresh()->position)->toBe(1);

    // Fresh page render shows the final podium.
    \Livewire::test(\App\Livewire\Competition\Schedule\OutcomeManager::class, ['schedule' => $heat1])
        ->assertSet('isHeat', true)
        ->assertSee('Juara 1')
        ->assertSee('H B')
        ->assertSee('Juara 2');
});

// ---------------------------------------------------------------------------
// Component — Waiting Result heat: operator input path (UAT gap fix)
// ---------------------------------------------------------------------------

test('operator can open Waiting Result heat in OutcomeManager and input per-participant times', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cha_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cha_category($event);
    $class = cha_class($event, $category, 'individual_heat');
    $heat = cha_schedule($class, 1);
    $heat->update(['status' => 'Waiting Result']);

    $a = cha_register(cha_person('WR A'), $event, $category, $class);
    $b = cha_register(cha_person('WR B'), $event, $category, $class);
    cha_entry($heat, $a);
    cha_entry($heat, $b);

    $component = \Livewire::test(\App\Livewire\Competition\Schedule\OutcomeManager::class, ['schedule' => $heat]);
    $inst = $component->instance();

    expect(count($inst->heatResults))->toBe(2)
        ->and($component->assertSet('isHeat', true));

    $heatRows = $inst->heatResults;
    $heatRows[0]['timeText'] = '1:50.0';
    $heatRows[1]['timeText'] = '1:45.0';
    $inst->heatResults = $heatRows;
    $inst->saveOutcomes();

    $saved = CompetitionHeatResult::where('competition_schedule_id', $heat->id)->orderBy('id')->get();
    expect($saved)->toHaveCount(2)
        ->and((float) $saved[0]->score)->toBe(110.0)
        ->and((float) $saved[1]->score)->toBe(105.0);

    expect($heat->fresh()->status)->toBe('Waiting Result');
});

test('operator score-type heat input uses numeric score field and aggregates via CompetitionResultService', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cha_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cha_category($event);
    $class = cha_class($event, $category, 'individual_heat', 'score');
    $heat = cha_schedule($class, 1);
    $heat->update(['status' => 'Waiting Result']);

    $a = cha_register(cha_person('SC A'), $event, $category, $class);
    $b = cha_register(cha_person('SC B'), $event, $category, $class);
    cha_entry($heat, $a);
    cha_entry($heat, $b);

    $component = \Livewire::test(\App\Livewire\Competition\Schedule\OutcomeManager::class, ['schedule' => $heat]);
    $inst = $component->instance();

    $heatRows = $inst->heatResults;
    $heatRows[0]['scoreValue'] = '7.5';
    $heatRows[1]['scoreValue'] = '9.0';
    $inst->heatResults = $heatRows;
    $inst->saveOutcomes();

    $saved = CompetitionHeatResult::where('competition_schedule_id', $heat->id)->orderBy('id')->get();
    expect($saved)->toHaveCount(2)
        ->and((float) $saved[0]->score)->toBe(7.5)
        ->and((float) $saved[1]->score)->toBe(9.0);

    app(CompetitionResultService::class)->aggregateHeatResults($event->id, $class->id);

    expect((float) $b->outcome->fresh()->score)->toBe(9.0)
        ->and($b->outcome->fresh()->position)->toBe(1)
        ->and($a->outcome->fresh()->position)->toBe(2);
});

test('official submission still finishes a Waiting Result heat after operator input', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cha_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cha_category($event);
    $class = cha_class($event, $category, 'individual_heat');
    $heat = cha_schedule($class, 1);
    $heat->update(['status' => 'Waiting Result', 'required_participants' => 1]);

    $a = cha_register(cha_person('FIN A'), $event, $category, $class);
    cha_entry($heat, $a);

    $component = \Livewire::test(\App\Livewire\Competition\Schedule\OutcomeManager::class, ['schedule' => $heat]);
    $inst = $component->instance();
    $heatRows = $inst->heatResults;
    $heatRows[0]['timeText'] = '1:30.0';
    $inst->heatResults = $heatRows;
    $inst->saveOutcomes();

    expect(CompetitionHeatResult::where('competition_schedule_id', $heat->id)->count())->toBe(1);

    $official = \Livewire::test(\App\Livewire\Competition\OfficialPanel::class);
    $official->call('openSubmitDialog', $heat->id);
    $official->set('selectedWinnerId', $a->id);
    $official->set('finishReason', 'Normal');
    $official->call('submitResult');

    expect($heat->fresh()->status)->toBe('Finished')
        ->and($heat->fresh()->winner_registration_id)->toBe($a->id);
});

test('Match Center Waiting Result heat shows Input Hasil link to OutcomeManager', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cha_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cha_category($event);
    $class = cha_class($event, $category, 'individual_heat');
    $heat = cha_schedule($class, 1);
    $heat->update(['status' => 'Waiting Result']);

    $a = cha_register(cha_person('WR A'), $event, $category, $class);
    cha_entry($heat, $a);

    \Livewire::test(\App\Livewire\Competition\MatchCenter::class)
        ->assertSee('Input Hasil')
        ->assertSee(route('competition.schedule.outcomes', ['schedule' => $heat->id], false));

    $response = $this->get(route('competition.schedule.outcomes', ['schedule' => $heat->id]));
    $response->assertOk();
});
