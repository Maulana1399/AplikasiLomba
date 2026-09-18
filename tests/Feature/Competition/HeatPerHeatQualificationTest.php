<?php

use App\Models\CompetitionHeatResult;
use App\Services\Competition\CompetitionHeatManagerService;
use App\Services\Competition\CompetitionMultiRoundHeatService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function hph_event(array $overrides = []): \App\Models\Event
{
    return \App\Models\Event::create(array_merge([
        'name' => 'HPH Event '.str()->random(6),
        'slug' => 'hph-'.str()->random(6),
        'status' => 'active',
        'event_type' => 'competition',
    ], $overrides));
}

function hph_category(\App\Models\Event $event): \App\Models\CompetitionCategory
{
    $category = \App\Models\CompetitionCategory::create(['event_id' => $event->id, 'name' => 'HPH Cat '.str()->random(4)]);


    return $category;
}

function hph_class(\App\Models\Event $event, \App\Models\CompetitionCategory $category, string $format = 'individual_heat'): \App\Models\CompetitionClass
{
    return \App\Models\CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'HPH Class '.str()->random(4),
        'gender' => 'M',
        'format' => $format,
        'status' => 'registration_open',
        'result_type' => 'time',
        'is_active' => true,
    ]);
}

function hph_register(\App\Models\Event $event, \App\Models\CompetitionCategory $category, \App\Models\CompetitionClass $class, string $nama): \App\Models\CompetitionRegistration
{
    $person = \App\Models\Person::create(['nama' => $nama, 'jenis_kelamin' => 'L']);

    return app(\App\Services\Competition\CompetitionRegistrationService::class)->registerForPerson(
        person: $person,
        eventId: $event->id,
        competitionCategoryId: $category->id,
        competitionClassId: $class->id,
    )['competition_registration'];
}

test('3 heats round 1 dengan 2/3/2 menghasilkan 7 qualified dan next round hanya qualified', function () {
    $event = hph_event();
    $category = hph_category($event);
    $class = hph_class($event, $category);

    $regs = [];
    for ($i = 1; $i <= 12; $i++) {
        $regs[] = hph_register($event, $category, $class, 'HPH P'.$i);
    }

    $mgr = app(CompetitionHeatManagerService::class);
    $multi = app(CompetitionMultiRoundHeatService::class);

    $mgr->upsertFormat($event->id, $class->id, 1, 4, 2);
    $mgr->upsertFormat($event->id, $class->id, 2, 7, 2);
    $mgr->generateRound($event->id, $class->id, 1);

    $heats = $multi->roundSchedules($class->id, 1)->sortBy('sort_order')->values();
    expect($heats)->toHaveCount(3);

    $mgr->upsertHeatQualifier($event->id, $class->id, 1, 1, 2);
    $mgr->upsertHeatQualifier($event->id, $class->id, 1, 2, 3);
    $mgr->upsertHeatQualifier($event->id, $class->id, 1, 3, 2);

    $scores = [
        1 => [10, 20, 30, 40],
        2 => [11, 21, 31, 41],
        3 => [12, 22, 32, 42],
    ];

    foreach ($heats as $idx => $heat) {
        $heatIdx = $idx + 1;
        $entries = $heat->scheduleEntries()->orderBy('id')->get();

        foreach ($entries as $eIdx => $entry) {
            CompetitionHeatResult::updateOrCreate(
                ['competition_schedule_id' => $heat->id, 'competition_registration_id' => $entry->competition_registration_id],
                ['score' => $scores[$heatIdx][$eIdx], 'status' => 'Lolos', 'position' => null],
            );
        }
    }

    $pool = $multi->qualifiedPool($event->id, $class->id, 1, 999);
    expect($pool['qualified_count'])->toBe(7)
        ->and(collect($pool['heats'])->pluck('advanced')->all())->toBe([2, 3, 2]);

    $allQuali = $pool['qualifiers'];
    expect(count($allQuali))->toBe(7)
        ->and(count(array_unique($allQuali)))->toBe(7);

    $adv = $mgr->generateNextRound($event->id, $class->id, 1);
    expect($adv['qualifiers'])->toBe(7)
        ->and($adv['assigned'])->toBe(7);

    $r2 = $multi->roundSchedules($class->id, 2);
    $r2Ids = $r2->flatMap(fn ($h) => $h->scheduleEntries()->pluck('competition_registration_id'))->filter()->all();
    sort($r2Ids);
    sort($allQuali);
    expect($r2Ids)->toBe($allQuali);
});

test('DNF/DSQ excluded dan incomplete heat tidak ikut qualified', function () {
    $event = hph_event();
    $category = hph_category($event);
    $class = hph_class($event, $category);

    $regs = [];
    for ($i = 1; $i <= 12; $i++) {
        $regs[] = hph_register($event, $category, $class, 'HPH2 P'.$i);
    }

    $mgr = app(CompetitionHeatManagerService::class);
    $multi = app(CompetitionMultiRoundHeatService::class);
    $mgr->upsertFormat($event->id, $class->id, 1, 4, 2);
    $mgr->upsertFormat($event->id, $class->id, 2, 7, 2);
    $mgr->generateRound($event->id, $class->id, 1);

    $heats = $multi->roundSchedules($class->id, 1)->sortBy('sort_order')->values();

    $mgr->upsertHeatQualifier($event->id, $class->id, 1, 1, 2);
    $mgr->upsertHeatQualifier($event->id, $class->id, 1, 2, 3);
    $mgr->upsertHeatQualifier($event->id, $class->id, 1, 3, 2);

    foreach ($heats[0]->scheduleEntries()->orderBy('id')->get() as $idx => $entry) {
        CompetitionHeatResult::updateOrCreate(
            ['competition_schedule_id' => $heats[0]->id, 'competition_registration_id' => $entry->competition_registration_id],
            ['score' => 10 + $idx, 'status' => 'Lolos', 'position' => null],
        );
    }

    $h2Entries = $heats[1]->scheduleEntries()->orderBy('id')->get();
    CompetitionHeatResult::updateOrCreate(['competition_schedule_id' => $heats[1]->id, 'competition_registration_id' => $h2Entries[0]->competition_registration_id], ['score' => 11, 'status' => 'Lolos', 'position' => null]);
    CompetitionHeatResult::updateOrCreate(['competition_schedule_id' => $heats[1]->id, 'competition_registration_id' => $h2Entries[1]->competition_registration_id], ['score' => 12, 'status' => 'DNF', 'position' => null]);
    CompetitionHeatResult::updateOrCreate(['competition_schedule_id' => $heats[1]->id, 'competition_registration_id' => $h2Entries[2]->competition_registration_id], ['score' => 13, 'status' => 'DSQ', 'position' => null]);
    CompetitionHeatResult::updateOrCreate(['competition_schedule_id' => $heats[1]->id, 'competition_registration_id' => $h2Entries[3]->competition_registration_id], ['score' => 14, 'status' => 'Lolos', 'position' => null]);

    $h3Entries = $heats[2]->scheduleEntries()->orderBy('id')->get();
    CompetitionHeatResult::updateOrCreate(['competition_schedule_id' => $heats[2]->id, 'competition_registration_id' => $h3Entries[0]->competition_registration_id], ['score' => 20, 'status' => 'Lolos', 'position' => null]);

    $pool = $multi->qualifiedPool($event->id, $class->id, 1, 999);
    expect($pool['qualified_count'])->toBe(4)
        ->and($pool['heats'][1]['complete'])->toBeTrue()
        ->and($pool['heats'][2]['complete'])->toBeFalse();

    $dnfIds = [$h2Entries[1]->competition_registration_id, $h2Entries[2]->competition_registration_id];
    foreach ($dnfIds as $did) {
        expect($pool['qualifiers'])->not->toContain((int) $did);
    }
});

test('fallback round-level tetap bekerja tanpa per-heat override', function () {
    $event = hph_event();
    $category = hph_category($event);
    $class = hph_class($event, $category);

    for ($i = 1; $i <= 8; $i++) {
        hph_register($event, $category, $class, 'HPH3 P'.$i);
    }

    $mgr = app(CompetitionHeatManagerService::class);
    $multi = app(CompetitionMultiRoundHeatService::class);
    $mgr->upsertFormat($event->id, $class->id, 1, 4, 3);
    $mgr->upsertFormat($event->id, $class->id, 2, 6, 3);
    $mgr->generateRound($event->id, $class->id, 1);

    $heats = $multi->roundSchedules($class->id, 1)->sortBy('sort_order')->values();

    foreach ($heats as $heat) {
        foreach ($heat->scheduleEntries()->orderBy('id')->get() as $idx => $entry) {
            CompetitionHeatResult::updateOrCreate(
                ['competition_schedule_id' => $heat->id, 'competition_registration_id' => $entry->competition_registration_id],
                ['score' => 10 + $idx, 'status' => 'Lolos', 'position' => null],
            );
        }
    }

    $pool = $multi->qualifiedPool($event->id, $class->id, 1, 3);
    expect($pool['qualified_count'])->toBe(6)
        ->and(collect($pool['heats'])->pluck('advanced')->all())->toBe([3, 3]);
});

test('UI saveHeatQualifier validasi dan override tidak duplicate', function () {
    $event = hph_event();
    $category = hph_category($event);
    $class = hph_class($event, $category);

    for ($i = 1; $i <= 8; $i++) {
        hph_register($event, $category, $class, 'HPH4 P'.$i);
    }

    $mgr = app(CompetitionHeatManagerService::class);
    $mgr->upsertFormat($event->id, $class->id, 1, 4, 2);
    $mgr->generateRound($event->id, $class->id, 1);

    $q1 = $mgr->upsertHeatQualifier($event->id, $class->id, 1, 2, 3);
    expect((int) $q1->qualifiers_per_heat)->toBe(3);

    $q2 = $mgr->upsertHeatQualifier($event->id, $class->id, 1, 2, 1);
    expect((int) $q2->qualifiers_per_heat)->toBe(1)
        ->and((int) $q2->id)->toBe((int) $q1->id);

    expect($mgr->effectiveQualifiersForHeat($class->id, 1, 2))->toBe(1)
        ->and($mgr->effectiveQualifiersForHeat($class->id, 1, 1))->toBe(2);
});
