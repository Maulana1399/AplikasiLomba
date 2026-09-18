<?php

use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionHeatResult;
use App\Models\CompetitionSchedule;
use App\Models\CompetitionScheduleEntry;
use App\Models\Event;
use App\Models\Person;
use App\Services\Competition\CompetitionHeatManagerService;
use App\Services\Competition\CompetitionMultiRoundHeatService;
use App\Services\Competition\CompetitionRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers (prefix tcns_ = consistent)
// ---------------------------------------------------------------------------

function tcns_event(): Event
{
    return Event::create([
        'name' => 'TCNS Event '.str()->random(5),
        'slug' => 'tcns-'.str()->random(5),
        'status' => 'active',
        'event_type' => 'competition',
    ]);
}

function tcns_category(Event $event): CompetitionCategory
{
    $category = CompetitionCategory::create(['event_id' => $event->id, 'name' => 'TCNS Cat']);


    return $category;
}

function tcns_class(Event $event, CompetitionCategory $cat): CompetitionClass
{
    return CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $cat->id,
        'name' => 'TCNS Class '.str()->random(4),
        'gender' => 'M',
        'format' => 'individual_heat',
        'status' => 'registration_open',
        'result_type' => 'time',
        'is_active' => true,
    ]);
}

function tcns_register_n(Event $event, CompetitionCategory $cat, CompetitionClass $class, int $n): array
{
    $regs = [];
    for ($i = 1; $i <= $n; $i++) {
        $person = Person::create(['nama' => "TCNS P{$i}", 'jenis_kelamin' => 'L']);
        $regs[] = app(CompetitionRegistrationService::class)->registerForPerson(
            person: $person,
            eventId: $event->id,
            competitionCategoryId: $cat->id,
            competitionClassId: $class->id,
        )['competition_registration'];
    }

    return $regs;
}

function tcns_format(Event $event, CompetitionClass $class, int $round, int $perHeat, int $qualifiers, int $min = 2): void
{
    app(CompetitionHeatManagerService::class)->upsertFormat(
        $event->id, $class->id, $round, $perHeat, $qualifiers, $min,
    );
}

function tcns_generate(Event $event, CompetitionClass $class, int $round): array
{
    return app(CompetitionHeatManagerService::class)->generateRound($event->id, $class->id, $round);
}

function tcns_generate_next(Event $event, CompetitionClass $class, int $round): array
{
    return app(CompetitionHeatManagerService::class)->generateNextRound($event->id, $class->id, $round);
}

function tcns_enter_results(Event $event, CompetitionClass $class, int $round, string $status = 'Lolos', bool $finishSchedule = false): void
{
    $heats = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, $round);
    $time = 60.0 + ($round * 100);
    foreach ($heats as $heat) {
        foreach ($heat->scheduleEntries()->pluck('competition_registration_id') as $regId) {
            CompetitionHeatResult::updateOrCreate(
                ['competition_schedule_id' => $heat->id, 'competition_registration_id' => $regId],
                ['score' => $time++, 'status' => $status, 'position' => null],
            );
        }
        app(CompetitionMultiRoundHeatService::class)->rankHeat($event->id, $heat->id);
        if ($finishSchedule) {
            $heat->update(['status' => 'Finished']);
        }
    }
}

function tcns_enter_results_partial(Event $event, CompetitionClass $class, int $round, int $heatsToComplete): void
{
    $heats = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, $round);
    $time = 60.0 + ($round * 100);
    $done = 0;
    foreach ($heats as $heat) {
        if ($done >= $heatsToComplete) {
            break;
        }
        foreach ($heat->scheduleEntries()->pluck('competition_registration_id') as $regId) {
            CompetitionHeatResult::updateOrCreate(
                ['competition_schedule_id' => $heat->id, 'competition_registration_id' => $regId],
                ['score' => $time++, 'status' => 'Lolos', 'position' => null],
            );
        }
        app(CompetitionMultiRoundHeatService::class)->rankHeat($event->id, $heat->id);
        $done++;
    }
}

function tcns_heat_sizes(CompetitionClass $class, int $round): array
{
    return app(CompetitionMultiRoundHeatService::class)
        ->roundSchedules($class->id, $round)
        ->map(fn ($h) => $h->scheduleEntries()->count())
        ->values()
        ->all();
}

// ---------------------------------------------------------------------------
// Contract definition (proved by all tests below):
//
// `generateNextRound(round)` uses `qualifiedPool(round, topN)` which uses
// `isHeatCompleteForAdvancement()`. A heat is "complete" when:
//   (a) schedule.status === 'Finished', OR
//   (b) ALL entries in that heat have a competition_heat_result row with
//       status != '' && status != null.
//
// This contract is IDENTICAL for Round 1→2 and Round 2→3. No special-casing.
// `generateRound(round)` is a different method: it reads ALL registrations
// and does NOT require any results — used only when no heat schedules exist.
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// 1. R1→R2: results entered (Waiting Result status) → succeeds
// ---------------------------------------------------------------------------

test('contract R1→R2: results entered, schedule still Waiting Result → advances successfully', function () {
    $event = tcns_event();
    $cat = tcns_category($event);
    $class = tcns_class($event, $cat);
    tcns_register_n($event, $cat, $class, 8);

    tcns_format($event, $class, 1, 4, 3);
    tcns_format($event, $class, 2, 4, 3);

    tcns_generate($event, $class, 1);

    // Enter results but keep schedule in Waiting Result (not Finished).
    tcns_enter_results($event, $class, 1, 'Lolos', finishSchedule: false);

    $result = tcns_generate_next($event, $class, 1);

    expect($result['advanced'])->toBeTrue()
        ->and($result['qualifiers'])->toBe(6)
        ->and(tcns_heat_sizes($class, 2))->toBe([3, 3]);
});

// ---------------------------------------------------------------------------
// 2. R2→R3: results entered (Waiting Result status) → succeeds identically
// ---------------------------------------------------------------------------

test('contract R2→R3: results entered, schedule still Waiting Result → advances successfully (same contract as R1→R2)', function () {
    $event = tcns_event();
    $cat = tcns_category($event);
    $class = tcns_class($event, $cat);
    tcns_register_n($event, $cat, $class, 8);

    tcns_format($event, $class, 1, 4, 3);
    tcns_format($event, $class, 2, 3, 1);
    tcns_format($event, $class, 3, 2, 1);

    tcns_generate($event, $class, 1);
    tcns_enter_results($event, $class, 1, 'Lolos', finishSchedule: false);
    $adv1 = tcns_generate_next($event, $class, 1);

    expect($adv1['advanced'])->toBeTrue();

    // R2 has 6 qualifiers → 2 heats of 3. Enter results (Waiting Result).
    tcns_enter_results($event, $class, 2, 'Lolos', finishSchedule: false);

    $adv2 = tcns_generate_next($event, $class, 2);

    // R2: 2 heats × top 1 = 2 qualifiers. R3 cap=2 → 1 heat [2].
    expect($adv2['advanced'])->toBeTrue()
        ->and($adv2['qualifiers'])->toBe(2)
        ->and(tcns_heat_sizes($class, 3))->toBe([2]);
});

// ---------------------------------------------------------------------------
// 3. R1→R2: results entered, schedule Finished → also succeeds
// ---------------------------------------------------------------------------

test('contract R1→R2: schedule Finished → also advances (status=Finished shortcut)', function () {
    $event = tcns_event();
    $cat = tcns_category($event);
    $class = tcns_class($event, $cat);
    tcns_register_n($event, $cat, $class, 8);

    tcns_format($event, $class, 1, 4, 3);
    tcns_format($event, $class, 2, 4, 3);

    tcns_generate($event, $class, 1);
    tcns_enter_results($event, $class, 1, 'Lolos', finishSchedule: true);

    $result = tcns_generate_next($event, $class, 1);

    expect($result['advanced'])->toBeTrue()
        ->and($result['qualifiers'])->toBe(6);
});

// ---------------------------------------------------------------------------
// 4. R2→R3: schedule Finished → also succeeds
// ---------------------------------------------------------------------------

test('contract R2→R3: schedule Finished → also advances (same contract as R1→R2)', function () {
    $event = tcns_event();
    $cat = tcns_category($event);
    $class = tcns_class($event, $cat);
    tcns_register_n($event, $cat, $class, 8);

    tcns_format($event, $class, 1, 4, 3);
    tcns_format($event, $class, 2, 3, 1);
    tcns_format($event, $class, 3, 2, 1);

    tcns_generate($event, $class, 1);
    tcns_enter_results($event, $class, 1, 'Lolos', finishSchedule: true);
    tcns_generate_next($event, $class, 1);

    tcns_enter_results($event, $class, 2, 'Lolos', finishSchedule: true);
    $result = tcns_generate_next($event, $class, 2);

    expect($result['advanced'])->toBeTrue()
        ->and($result['qualifiers'])->toBe(2);
});

// ---------------------------------------------------------------------------
// 5. R1→R2 with NO results: no_qualifiers (heats not complete)
// ---------------------------------------------------------------------------

test('contract R1→R2: NO results entered → no_qualifiers (incomplete heats skipped)', function () {
    $event = tcns_event();
    $cat = tcns_category($event);
    $class = tcns_class($event, $cat);
    tcns_register_n($event, $cat, $class, 8);

    tcns_format($event, $class, 1, 4, 3);
    tcns_format($event, $class, 2, 4, 3);

    tcns_generate($event, $class, 1);
    // No results entered.

    $result = tcns_generate_next($event, $class, 1);

    expect($result['advanced'])->toBeFalse()
        ->and($result['reason'])->toBe('no_qualifiers');
});

// ---------------------------------------------------------------------------
// 6. R2→R3 with NO results: no_qualifiers (same contract as R1→R2)
// ---------------------------------------------------------------------------

test('contract R2→R3: NO results entered → no_qualifiers (same contract as R1→R2)', function () {
    $event = tcns_event();
    $cat = tcns_category($event);
    $class = tcns_class($event, $cat);
    tcns_register_n($event, $cat, $class, 8);

    tcns_format($event, $class, 1, 4, 3);
    tcns_format($event, $class, 2, 3, 1);
    tcns_format($event, $class, 3, 2, 1);

    tcns_generate($event, $class, 1);
    tcns_enter_results($event, $class, 1, 'Lolos', finishSchedule: true);
    tcns_generate_next($event, $class, 1);
    // R2 heats exist but NO results entered.

    $result = tcns_generate_next($event, $class, 2);

    expect($result['advanced'])->toBeFalse()
        ->and($result['reason'])->toBe('no_qualifiers');
});

// ---------------------------------------------------------------------------
// 7a. qualified_pool_insufficient for R1→R2: only 1-of-2 heats complete
//    and partial pool < nextPerHeat.
// ---------------------------------------------------------------------------

test('7a. qualified_pool_insufficient R1→R2: 1 of 2 heats done, pool < nextPerHeat', function () {
    $event = tcns_event();
    $cat = tcns_category($event);
    $class = tcns_class($event, $cat);
    tcns_register_n($event, $cat, $class, 8);

    // R1: 2 heats × 4, top 1.  R2: 3 per heat — needs pool ≥ 3.
    tcns_format($event, $class, 1, 4, 1);
    tcns_format($event, $class, 2, 3, 1);

    tcns_generate($event, $class, 1);

    // Complete ONLY heat 1 → pool = 1. nextPerHeat = 3. 1 < 3 → insufficient.
    tcns_enter_results_partial($event, $class, 1, heatsToComplete: 1);

    $result = tcns_generate_next($event, $class, 1);

    expect($result['advanced'])->toBeFalse()
        ->and($result['reason'])->toBe('qualified_pool_insufficient')
        ->and($result['qualifiers'])->toBe(1);
});

// ---------------------------------------------------------------------------
// 7b. qualified_pool_insufficient for R2→R3: same contract
// ---------------------------------------------------------------------------

test('7b. qualified_pool_insufficient R2→R3: 1 of 2 heats done, pool < nextPerHeat', function () {
    $event = tcns_event();
    $cat = tcns_category($event);
    $class = tcns_class($event, $cat);
    tcns_register_n($event, $cat, $class, 8);

    // R1: 2 × 4, top 2 → pool=4 ≥ R2 cap=4. R2: 2 × 4... wait, balanced: 4 qualifiers
    // → 1 heat of 4. Use top 2 each → pool=4, R2 cap=4 → 1 heat. Then R2→R3: 1 heat,
    // top 3, R3 cap=4 → needs pool=3. Only complete 0 R2 heats → no_qualifiers.
    // Instead: R1 top 3 per heat × 2 heats = 6 → R2: 2 heats of 3. R2→R3:
    // only complete 1 R2 heat (top 1 → pool=1), R3 cap=3. 1<3 → insufficient.
    tcns_format($event, $class, 1, 4, 3);
    tcns_format($event, $class, 2, 3, 1);
    tcns_format($event, $class, 3, 3, 1);

    tcns_generate($event, $class, 1);
    tcns_enter_results($event, $class, 1, 'Lolos', finishSchedule: true);
    $adv1 = tcns_generate_next($event, $class, 1);
    expect($adv1['advanced'])->toBeTrue();

    // R2: 2 heats of 3. Complete only 1 → pool=1. R3 cap=3. 1 < 3 → insufficient.
    tcns_enter_results_partial($event, $class, 2, heatsToComplete: 1);

    $result = tcns_generate_next($event, $class, 2);

    expect($result['advanced'])->toBeFalse()
        ->and($result['reason'])->toBe('qualified_pool_insufficient')
        ->and($result['qualifiers'])->toBe(1);
});

// ---------------------------------------------------------------------------
// 8. qualified_pool_sufficient when pool == nextPerHeat (boundary)
// ---------------------------------------------------------------------------

test('qualified_pool_sufficient: pool == nextPerHeat (boundary, not blocked)', function () {
    $event = tcns_event();
    $cat = tcns_category($event);
    $class = tcns_class($event, $cat);
    tcns_register_n($event, $cat, $class, 8);

    // R1: 2 heats × 4, top 2 → pool = 4. R2: cap = 4. 4 < 4 = false → OK.
    tcns_format($event, $class, 1, 4, 2);
    tcns_format($event, $class, 2, 4, 2);

    tcns_generate($event, $class, 1);
    tcns_enter_results($event, $class, 1, 'Lolos', finishSchedule: false);

    $result = tcns_generate_next($event, $class, 1);

    expect($result['advanced'])->toBeTrue()
        ->and($result['qualifiers'])->toBe(4);
});

// ---------------------------------------------------------------------------
// 9. UAT exact repro: R2 (3+3, top 1 each) → R3 (cap=2) → succeeds
//    with results entered (Waiting Result). This is the exact scenario
//    the UAT operator described — and it works once results are entered.
// ---------------------------------------------------------------------------

test('UAT exact R2→R3: 2 heats 3+3, top 1 each, cap 2 next → advances when results entered', function () {
    $event = tcns_event();
    $cat = tcns_category($event);
    $class = tcns_class($event, $cat);
    tcns_register_n($event, $cat, $class, 8);

    tcns_format($event, $class, 1, 4, 3);
    tcns_format($event, $class, 2, 3, 1);
    tcns_format($event, $class, 3, 2, 1, 2);

    tcns_generate($event, $class, 1);
    tcns_enter_results($event, $class, 1, 'Lolos', finishSchedule: false);
    $adv1 = tcns_generate_next($event, $class, 1);
    expect($adv1['advanced'])->toBeTrue();

    // R2: 2 heats × 3 entries. Enter results (Waiting Result).
    $r2Sizes = tcns_heat_sizes($class, 2);
    expect($r2Sizes)->toBe([3, 3]);

    tcns_enter_results($event, $class, 2, 'Lolos', finishSchedule: false);

    // Pool: 2 heats × top 1 = 2. R3 cap = 2. 2 < 2 = false → proceeds.
    $adv2 = tcns_generate_next($event, $class, 2);

    expect($adv2['advanced'])->toBeTrue()
        ->and($adv2['qualifiers'])->toBe(2)
        ->and($adv2['heat_count'])->toBe(1)
        ->and(tcns_heat_sizes($class, 3))->toBe([2]);
});

// ---------------------------------------------------------------------------
// 10. generateRound(1) does NOT require results — reads registrations directly.
//     This is NOT the same as generateNextRound and has NEVER required results.
// ---------------------------------------------------------------------------

test('generateRound(1) does not need results: reads registrations, used only for initial heat creation', function () {
    $event = tcns_event();
    $cat = tcns_category($event);
    $class = tcns_class($event, $cat);
    tcns_register_n($event, $cat, $class, 8);

    tcns_format($event, $class, 1, 4, 3);

    // No results, no heat status — just registrations.
    $result = tcns_generate($event, $class, 1);

    expect($result['generated'])->toBeTrue()
        ->and($result['heat_count'])->toBe(2)
        ->and($result['competitors_used'])->toBe(8);
});

// ---------------------------------------------------------------------------
// 11. Prove both rounds share identical isHeatCompleteForAdvancement path.
//     Heat "complete" = ALL entries have result with non-empty status.
//     Schedule status (Scheduled/Ready/Playing/Waiting Result/Finished)
//     is NOT required to be Finished — only results matter (except shortcut).
// ---------------------------------------------------------------------------

test('isHeatCompleteForAdvancement: results with Lolos status sufficient, Finished not required', function () {
    $event = tcns_event();
    $cat = tcns_category($event);
    $class = tcns_class($event, $cat);
    $regs = tcns_register_n($event, $cat, $class, 4);

    // Manually create a heat in Waiting Result status.
    $heat = CompetitionSchedule::create([
        'competition_class_id' => $class->id,
        'status' => 'Waiting Result',
        'required_participants' => 4,
        'sort_order' => 101,
    ]);

    $i = 1;
    foreach ($regs as $reg) {
        CompetitionScheduleEntry::create([
            'competition_schedule_id' => $heat->id,
            'competition_registration_id' => $reg->id,
            'order_number' => $i++,
        ]);
        CompetitionHeatResult::create([
            'competition_schedule_id' => $heat->id,
            'competition_registration_id' => $reg->id,
            'score' => 60.0 + $i,
            'status' => 'Lolos',
        ]);
    }

    tcns_format($event, $class, 1, 4, 3);
    tcns_format($event, $class, 2, 4, 3);

    // qualifiedPool considers this heat "complete" despite Waiting Result status.
    $pool = app(CompetitionMultiRoundHeatService::class)
        ->qualifiedPool($event->id, $class->id, 1, 3);

    expect($pool['completed_heats'])->toBe(1)
        ->and($pool['qualified_count'])->toBe(3);
});
