<?php

use App\Enums\Role;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionHeatResult;
use App\Models\CompetitionScheduleEntry;
use App\Models\Event;
use App\Models\kelompok;
use App\Models\Person;
use App\Models\User;
use App\Services\Competition\CompetitionMultiRoundHeatService;
use App\Services\Competition\CompetitionRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function r_event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'Repro '.str()->random(6),
        'slug' => 'repro-'.str()->random(6),
        'status' => 'active',
        'event_type' => 'competition',
    ], $overrides));
}

function r_category(Event $event): CompetitionCategory
{
    $category = CompetitionCategory::create(['event_id' => $event->id, 'name' => 'Cat '.str()->random(4)]);


    return $category;
}

function r_class(Event $event, CompetitionCategory $category): CompetitionClass
{
    return CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'Class '.str()->random(4),
        'gender' => 'M',
        'format' => 'individual_heat',
        'status' => 'registration_open',
        'result_type' => 'time',
        'is_active' => true,
    ]);
}

function r_person(string $nama, ?kelompok $kelompok = null): Person
{
    return Person::create(['nama' => $nama, 'jenis_kelamin' => 'L', 'kelompok_id' => $kelompok?->id]);
}

function r_reg(Person $person, Event $event, CompetitionCategory $category, CompetitionClass $class): \App\Models\CompetitionRegistration
{
    return app(CompetitionRegistrationService::class)->registerForPerson(
        person: $person,
        eventId: $event->id,
        competitionCategoryId: $category->id,
        competitionClassId: $class->id,
    )['competition_registration'];
}

test('UAT regression: exact UAT scenario (C01=1:30 C02=1:40 C03=1:20 C04=1:10, top 2) advances C04 then C03, never C01/C02', function () {
    $event = r_event();
    $category = r_category($event);
    $class = r_class($event, $category);

    $r1 = mrh_heat($class, 101, 4);
    $r2 = mrh_heat($class, 201, 2);

    $C01 = r_reg(r_person('Competition 01'), $event, $category, $class);
    $C02 = r_reg(r_person('Competition 02'), $event, $category, $class);
    $C03 = r_reg(r_person('Competition 03'), $event, $category, $class);
    $C04 = r_reg(r_person('Competition 04'), $event, $category, $class);
    r_reg(r_person('Competition 05'), $event, $category, $class); // 5th competitor, NOT scheduled in R1

    foreach ([$C01, $C02, $C03, $C04] as $reg) {
        mrh_entry($r1, $reg);
    }

    $times = [
        $C01->id => 90.0,
        $C02->id => 100.0,
        $C03->id => 80.0,
        $C04->id => 70.0,
    ];
    foreach ([$C01, $C02, $C03, $C04] as $reg) {
        mrh_heat_result($r1, $reg, $times[$reg->id], 'Lolos');
    }

    $service = app(CompetitionMultiRoundHeatService::class);
    $result = $service->advanceRound($event->id, $class->id, 1, 2);

    $r2ids = CompetitionScheduleEntry::where('competition_schedule_id', $r2->id)
        ->orderBy('id')
        ->pluck('competition_registration_id')
        ->map(fn ($id) => (int) $id)
        ->values()
        ->all();

    expect($result['advanced'])->toBeTrue()
        ->and($result['qualifiers'])->toBe(2)
        ->and($result['assigned'])->toBe(2)
        ->and($r2ids)->toBe([(int) $C04->id, (int) $C03->id])
        ->and($r2ids)->not->toContain((int) $C01->id)
        ->and($r2ids)->not->toContain((int) $C02->id);
});

test('UAT regression: typing heat times via the Livewire boundary survives re-render and saves to the CORRECT registration (no identity swap)', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = r_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = r_category($event);
    $class = r_class($event, $category);
    $heat = mrh_heat($class, 101, 4);

    $C01 = r_reg(r_person('Heat 01'), $event, $category, $class);
    $C02 = r_reg(r_person('Heat 02'), $event, $category, $class);
    $C03 = r_reg(r_person('Heat 03'), $event, $category, $class);
    $C04 = r_reg(r_person('Heat 04'), $event, $category, $class);

    foreach ([$C01, $C02, $C03, $C04] as $reg) {
        mrh_entry($heat, $reg);
    }

    $component = \Livewire::test(\App\Livewire\Competition\Schedule\OutcomeManager::class, ['schedule' => $heat]);
    $rows = $component->get('heatResults');
    expect($rows)->toHaveCount(4)
        ->and($rows[0]['registration_id'])->toBe((int) $C01->id)
        ->and($rows[3]['registration_id'])->toBe((int) $C04->id);

    $component->set('heatResults.0.timeText', '1:30.000')
        ->set('heatResults.1.timeText', '1:40.000')
        ->set('heatResults.2.timeText', '1:20.000')
        ->set('heatResults.3.timeText', '1:10.000')
        ->assertSet('heatResults.0.timeText', '1:30.000')
        ->assertSet('heatResults.3.timeText', '1:10.000')
        ->call('saveOutcomes');

    $saved = CompetitionHeatResult::where('competition_schedule_id', $heat->id)->get()->keyBy('competition_registration_id');

    expect($saved)->toHaveCount(4)
        ->and((float) $saved[(int) $C01->id]->score)->toBe(90.0)
        ->and((float) $saved[(int) $C02->id]->score)->toBe(100.0)
        ->and((float) $saved[(int) $C03->id]->score)->toBe(80.0)
        ->and((float) $saved[(int) $C04->id]->score)->toBe(70.0);
});
