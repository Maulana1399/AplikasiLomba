<?php

use App\Enums\Role;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionHeatResult;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionSchedule;
use App\Models\CompetitionScheduleEntry;
use App\Models\CompetitionTeam;
use App\Models\CompetitionTeamOutcome;
use App\Models\Event;
use App\Models\kelompok;
use App\Models\Person;
use App\Models\User;
use App\Services\Competition\CompetitionMultiRoundHeatService;
use App\Services\Competition\CompetitionRegistrationService;
use App\Services\Competition\CompetitionResultService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function mrh_event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'MRH Event '.str()->random(6),
        'slug' => 'mrh-'.str()->random(6),
        'status' => 'active',
        'event_type' => 'competition',
    ], $overrides));
}

function mrh_category(Event $event): CompetitionCategory
{
    $category = CompetitionCategory::create(['event_id' => $event->id, 'name' => 'MRH Cat '.str()->random(4)]);

    $category->events()->attach($event);

    return $category;
}

function mrh_class(Event $event, CompetitionCategory $category, string $format = 'individual_heat', ?string $resultType = null): CompetitionClass
{
    return CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'MRH Class '.str()->random(4),
        'gender' => 'M',
        'format' => $format,
        'status' => 'registration_open',
        'result_type' => $resultType,
        'is_active' => true,
    ]);
}

function mrh_person(string $nama, ?kelompok $kelompok = null): Person
{
    return Person::create(['nama' => $nama, 'jenis_kelamin' => 'L', 'kelompok_id' => $kelompok?->id]);
}

function mrh_register(Person $person, Event $event, CompetitionCategory $category, CompetitionClass $class): CompetitionRegistration
{
    return app(CompetitionRegistrationService::class)->registerForPerson(
        person: $person,
        eventId: $event->id,
        competitionCategoryId: $category->id,
        competitionClassId: $class->id,
    )['competition_registration'];
}

function mrh_kelompok(string $name): kelompok
{
    return kelompok::create(['kelompok_asal' => $name]);
}

function mrh_team(Event $event, CompetitionClass $class, ?kelompok $kelompok, string $name): CompetitionTeam
{
    return CompetitionTeam::create([
        'event_id' => $event->id,
        'competition_class_id' => $class->id,
        'name' => $name,
        'kelompok_id' => $kelompok?->id,
        'is_active' => true,
    ]);
}

/**
 * Heat schedule. sortOrder mengikuti konvensi round*100 + heatIndex
 * (101 = round 1 heat 1, 201 = round 2 heat 1, 301 = final).
 */
function mrh_heat(CompetitionClass $class, int $sortOrder, int $capacity = 4): CompetitionSchedule
{
    return CompetitionSchedule::create([
        'competition_class_id' => $class->id,
        'status' => 'Scheduled',
        'required_participants' => $capacity,
        'sort_order' => $sortOrder,
    ]);
}

function mrh_entry(CompetitionSchedule $schedule, CompetitionRegistration $registration): CompetitionScheduleEntry
{
    return CompetitionScheduleEntry::create([
        'competition_schedule_id' => $schedule->id,
        'competition_registration_id' => $registration->id,
    ]);
}

function mrh_team_entry(CompetitionSchedule $schedule, CompetitionTeam $team): CompetitionScheduleEntry
{
    return CompetitionScheduleEntry::create([
        'competition_schedule_id' => $schedule->id,
        'competition_team_id' => $team->id,
    ]);
}

function mrh_heat_result(CompetitionSchedule $schedule, CompetitionRegistration $registration, ?float $seconds, ?string $status = null): CompetitionHeatResult
{
    return CompetitionHeatResult::updateOrCreate(
        ['competition_schedule_id' => $schedule->id, 'competition_registration_id' => $registration->id],
        ['score' => $seconds, 'status' => $status, 'position' => null],
    );
}

function mrh_team_heat_result(CompetitionSchedule $schedule, CompetitionTeam $team, ?float $seconds, ?string $status = null): CompetitionHeatResult
{
    return CompetitionHeatResult::updateOrCreate(
        ['competition_schedule_id' => $schedule->id, 'competition_team_id' => $team->id],
        ['score' => $seconds, 'status' => $status, 'position' => null],
    );
}

function mrh_finish(CompetitionSchedule $schedule): void
{
    $schedule->update(['status' => 'Finished']);
}

// ---------------------------------------------------------------------------
// A. Schema — team heat result column
// ---------------------------------------------------------------------------

test('A. competition_heat_results supports team_id with dual unique', function () {
    expect(Schema::hasColumn('competition_heat_results', 'competition_team_id'))->toBeTrue()
        ->and(Schema::hasIndex('competition_heat_results', 'uniq_heat_schedule_registration'))->toBeTrue()
        ->and(Schema::hasIndex('competition_heat_results', 'uniq_heat_schedule_team'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// B. Round derivation (sort_order convention)
// ---------------------------------------------------------------------------

test('B. roundOf derives round from sort_order convention', function () {
    $service = app(CompetitionMultiRoundHeatService::class);

    expect($service->roundOf(101))->toBe(1)
        ->and($service->roundOf(102))->toBe(1)
        ->and($service->roundOf(201))->toBe(2)
        ->and($service->roundOf(301))->toBe(3)
        ->and($service->roundOf(1))->toBe(1)   // legacy heat
        ->and($service->roundOf(2))->toBe(1)   // legacy heat
        ->and($service->roundOf(null))->toBe(1);
});

test('C. roundSchedules groups heats by round and nextRound walks forward', function () {
    $event = mrh_event();
    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'individual_heat');

    $h1 = mrh_heat($class, 101);
    $h2 = mrh_heat($class, 102);
    $r2 = mrh_heat($class, 201);
    $final = mrh_heat($class, 301);

    $service = app(CompetitionMultiRoundHeatService::class);

    expect($service->roundSchedules($class->id, 1)->pluck('id')->toArray())
        ->toContain($h1->id)->toContain($h2->id)
        ->not->toContain($r2->id)
        ->and($service->roundSchedules($class->id, 2)->pluck('id')->toArray())->toContain($r2->id)
        ->and($service->roundSchedules($class->id, 3)->pluck('id')->toArray())->toContain($final->id)
        ->and($service->nextRound($class->id, 1))->toBe(2)
        ->and($service->nextRound($class->id, 2))->toBe(3)
        ->and($service->nextRound($class->id, 3))->toBeNull()
        ->and($service->isFinalRound($class->id, 3))->toBeTrue()
        ->and($service->isFinalRound($class->id, 1))->toBeFalse();
});

// ---------------------------------------------------------------------------
// D–G. Per-heat ranking (individual)
// ---------------------------------------------------------------------------

test('D. rankHeat ranks a single heat ascending by time', function () {
    $event = mrh_event();
    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'individual_heat');
    $heat = mrh_heat($class, 101, 3);

    $a = mrh_register(mrh_person('R A'), $event, $category, $class);
    $b = mrh_register(mrh_person('R B'), $event, $category, $class);
    $c = mrh_register(mrh_person('R C'), $event, $category, $class);

    mrh_entry($heat, $a);
    mrh_entry($heat, $b);
    mrh_entry($heat, $c);

    mrh_heat_result($heat, $a, 100.0);
    mrh_heat_result($heat, $b, 95.0);
    mrh_heat_result($heat, $c, 110.0);

    $result = app(CompetitionMultiRoundHeatService::class)->rankHeat($event->id, $heat->id);

    expect($result['ranked'])->toBeTrue()
        ->and($result['direction'])->toBe('asc')
        ->and($result['rows'])->toHaveCount(3)
        ->and($heat->heatResults()->where('competition_registration_id', $a->id)->value('position'))->toBe(2)
        ->and($heat->heatResults()->where('competition_registration_id', $b->id)->value('position'))->toBe(1)
        ->and($heat->heatResults()->where('competition_registration_id', $c->id)->value('position'))->toBe(3);
});

test('E. rankHeat ranks descending when result type is score', function () {
    $event = mrh_event();
    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'individual_heat', 'score');
    $heat = mrh_heat($class, 101, 3);

    $a = mrh_register(mrh_person('S A'), $event, $category, $class);
    $b = mrh_register(mrh_person('S B'), $event, $category, $class);

    mrh_entry($heat, $a);
    mrh_entry($heat, $b);
    mrh_heat_result($heat, $a, 7.0);
    mrh_heat_result($heat, $b, 9.5);

    $result = app(CompetitionMultiRoundHeatService::class)->rankHeat($event->id, $heat->id);

    expect($result['direction'])->toBe('desc')
        ->and($heat->heatResults()->where('competition_registration_id', $b->id)->value('position'))->toBe(1)
        ->and($heat->heatResults()->where('competition_registration_id', $a->id)->value('position'))->toBe(2);
});

test('F. rankHeat handles ties with competition ranking 1,1,3', function () {
    $event = mrh_event();
    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'individual_heat');
    $heat = mrh_heat($class, 101, 3);

    $a = mrh_register(mrh_person('T A'), $event, $category, $class);
    $b = mrh_register(mrh_person('T B'), $event, $category, $class);
    $c = mrh_register(mrh_person('T C'), $event, $category, $class);

    mrh_entry($heat, $a);
    mrh_entry($heat, $b);
    mrh_entry($heat, $c);
    mrh_heat_result($heat, $a, 90.0);
    mrh_heat_result($heat, $b, 90.0);
    mrh_heat_result($heat, $c, 100.0);

    app(CompetitionMultiRoundHeatService::class)->rankHeat($event->id, $heat->id);

    $positions = $heat->heatResults()->pluck('position')->sort()->values();
    expect($positions->toArray())->toBe([1, 1, 3]);
});

test('G. rankHeat excludes disqualified and no-score rows', function () {
    $event = mrh_event();
    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'individual_heat');
    $heat = mrh_heat($class, 101, 4);

    $a = mrh_register(mrh_person('X A'), $event, $category, $class);
    $b = mrh_register(mrh_person('X B'), $event, $category, $class);
    $c = mrh_register(mrh_person('X C'), $event, $category, $class);
    $d = mrh_register(mrh_person('X D'), $event, $category, $class);

    mrh_entry($heat, $a);
    mrh_entry($heat, $b);
    mrh_entry($heat, $c);
    mrh_entry($heat, $d);

    mrh_heat_result($heat, $a, 100.0);
    mrh_heat_result($heat, $b, 95.0, 'Diskualifikasi');
    mrh_heat_result($heat, $c, null);
    mrh_heat_result($heat, $d, 110.0);

    $result = app(CompetitionMultiRoundHeatService::class)->rankHeat($event->id, $heat->id);

    expect($result['rows'])->toHaveCount(2)
        ->and($heat->heatResults()->where('competition_registration_id', $b->id)->value('position'))->toBeNull()
        ->and($heat->heatResults()->where('competition_registration_id', $a->id)->value('position'))->toBe(1)
        ->and($heat->heatResults()->where('competition_registration_id', $d->id)->value('position'))->toBe(2);
});

// ---------------------------------------------------------------------------
// H–L. Advancement (individual)
// ---------------------------------------------------------------------------

test('H. advanceRound advances finished heats only — skips unfinished sibling (per-heat)', function () {
    $event = mrh_event();
    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'individual_heat');

    $h1 = mrh_heat($class, 101, 2);
    $h2 = mrh_heat($class, 102, 2);
    $r2 = mrh_heat($class, 201, 4);

    $a = mrh_register(mrh_person('Skip A'), $event, $category, $class);
    $b = mrh_register(mrh_person('Skip B'), $event, $category, $class);

    mrh_entry($h1, $a);
    mrh_entry($h1, $b);
    mrh_heat_result($h1, $a, 100.0, 'Lolos');
    mrh_heat_result($h1, $b, 95.0, 'Lolos');
    mrh_finish($h1);

    // h2 belum selesai (tidak ada hasil/status) — harus di-skip, BUKAN di-reject.

    $result = app(CompetitionMultiRoundHeatService::class)->advanceRound($event->id, $class->id, 1, 2);

    expect($result['advanced'])->toBeTrue()
        ->and($result['qualifiers'])->toBe(2);

    $nextIds = $r2->scheduleEntries()->pluck('competition_registration_id')->map(fn ($id) => (int) $id)->sort()->values()->all();

    expect($nextIds)->toBe([(int) $a->id, (int) $b->id]);
});

test('I. advanceRound advances top-N per heat into next round heats by capacity', function () {
    $event = mrh_event();
    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'individual_heat');

    $h1 = mrh_heat($class, 101, 4);
    $h2 = mrh_heat($class, 102, 4);
    $r2 = mrh_heat($class, 201, 4);

    $regs = [];
    foreach (['A', 'B', 'C', 'D'] as $name) {
        $regs[$name] = mrh_register(mrh_person('Ath '.$name), $event, $category, $class);
    }

    mrh_entry($h1, $regs['A']);
    mrh_entry($h1, $regs['B']);
    mrh_entry($h1, $regs['C']);
    mrh_entry($h2, $regs['C']);
    mrh_entry($h2, $regs['D']);

    mrh_heat_result($h1, $regs['A'], 100.0);
    mrh_heat_result($h1, $regs['B'], 95.0);
    mrh_heat_result($h1, $regs['C'], 110.0);
    mrh_heat_result($h2, $regs['C'], 120.0);
    mrh_heat_result($h2, $regs['D'], 90.0);

    mrh_finish($h1);
    mrh_finish($h2);

    $result = app(CompetitionMultiRoundHeatService::class)->advanceRound($event->id, $class->id, 1, 2);

    expect($result['advanced'])->toBeTrue()
        ->and($result['next_round'])->toBe(2)
        ->and($result['qualifiers'])->toBe(4);

    // H1: A(2), B(1) → top2 = B, A. H2: C(2), D(1) → top2 = D, C. → 4 qualifiers.
    $nextIds = $r2->scheduleEntries()->pluck('competition_registration_id')->map(fn ($id) => (int) $id)->sort()->values();
    expect($nextIds->toArray())->toBe([
        (int) $regs['A']->id, (int) $regs['B']->id, (int) $regs['C']->id, (int) $regs['D']->id,
    ]);
});

test('J. advanceRound sets next heat Ready, never Playing (R4H ban)', function () {
    $event = mrh_event();
    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'individual_heat');

    $h1 = mrh_heat($class, 101, 2);
    $r2 = mrh_heat($class, 201, 2);

    $a = mrh_register(mrh_person('Ready A'), $event, $category, $class);
    $b = mrh_register(mrh_person('Ready B'), $event, $category, $class);

    mrh_entry($h1, $a);
    mrh_entry($h1, $b);
    mrh_heat_result($h1, $a, 90.0);
    mrh_heat_result($h1, $b, 95.0);
    mrh_finish($h1);

    app(CompetitionMultiRoundHeatService::class)->advanceRound($event->id, $class->id, 1, 2);

    expect($r2->fresh()->status)->toBe('Ready');
});

test('K. advanceRound is idempotent — does not duplicate entries on repeat', function () {
    $event = mrh_event();
    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'individual_heat');

    $h1 = mrh_heat($class, 101, 2);
    $r2 = mrh_heat($class, 201, 2);

    $a = mrh_register(mrh_person('Idem A'), $event, $category, $class);
    $b = mrh_register(mrh_person('Idem B'), $event, $category, $class);

    mrh_entry($h1, $a);
    mrh_entry($h1, $b);
    mrh_heat_result($h1, $a, 90.0);
    mrh_heat_result($h1, $b, 95.0);
    mrh_finish($h1);

    $service = app(CompetitionMultiRoundHeatService::class);
    $service->advanceRound($event->id, $class->id, 1, 2);
    $service->advanceRound($event->id, $class->id, 1, 2);

    expect($r2->scheduleEntries()->count())->toBe(2);
});

test('L. advanceRound skips excluded qualifiers', function () {
    $event = mrh_event();
    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'individual_heat');

    $h1 = mrh_heat($class, 101, 3);
    $r2 = mrh_heat($class, 201, 2);

    $a = mrh_register(mrh_person('Leg A'), $event, $category, $class);
    $b = mrh_register(mrh_person('Leg B'), $event, $category, $class);
    $c = mrh_register(mrh_person('Leg C'), $event, $category, $class);

    mrh_entry($h1, $a);
    mrh_entry($h1, $b);
    mrh_entry($h1, $c);
    mrh_heat_result($h1, $a, 90.0);
    mrh_heat_result($h1, $b, 95.0, 'Tidak Hadir');
    mrh_heat_result($h1, $c, 100.0);
    mrh_finish($h1);

    $result = app(CompetitionMultiRoundHeatService::class)->advanceRound($event->id, $class->id, 1, 2);

    $nextIds = $r2->scheduleEntries()->pluck('competition_registration_id')->map(fn ($id) => (int) $id)->sort()->values();
    expect($nextIds->toArray())->toBe([(int) $a->id, (int) $c->id]);
});

// ---------------------------------------------------------------------------
// M–P. Final round aggregation + podium (individual)
// ---------------------------------------------------------------------------

test('M. aggregateRoundResults aggregates only the final round heats', function () {
    $event = mrh_event();
    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'individual_heat');

    $round1 = mrh_heat($class, 101, 2);
    $final = mrh_heat($class, 301, 2);

    $a = mrh_register(mrh_person('Fin A'), $event, $category, $class);
    $b = mrh_register(mrh_person('Fin B'), $event, $category, $class);

    // Round 1 result harus TIDAK ikut di-agregat round final.
    mrh_entry($round1, $a);
    mrh_heat_result($round1, $a, 40.0);

    mrh_entry($final, $a);
    mrh_entry($final, $b);
    mrh_heat_result($final, $a, 92.0);
    mrh_heat_result($final, $b, 88.0);

    $result = app(CompetitionMultiRoundHeatService::class)->aggregateRoundResults($event->id, $class->id, 3);

    expect($result['ranked'])->toBeTrue()
        ->and($result['rows'])->toHaveCount(2)
        ->and((float) $a->outcome->fresh()->score)->toBe(92.0)
        ->and((float) $b->outcome->fresh()->score)->toBe(88.0)
        ->and($a->outcome->fresh()->position)->toBe(2)
        ->and($b->outcome->fresh()->position)->toBe(1);
});

test('N. finalizePodium writes Juara 1/2/3 from final round', function () {
    $event = mrh_event();
    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'individual_heat');
    $final = mrh_heat($class, 301, 3);

    $a = mrh_register(mrh_person('Pod A'), $event, $category, $class);
    $b = mrh_register(mrh_person('Pod B'), $event, $category, $class);
    $c = mrh_register(mrh_person('Pod C'), $event, $category, $class);

    mrh_entry($final, $a);
    mrh_entry($final, $b);
    mrh_entry($final, $c);
    mrh_heat_result($final, $a, 95.0);
    mrh_heat_result($final, $b, 100.0);
    mrh_heat_result($final, $c, 90.0);

    $result = app(CompetitionMultiRoundHeatService::class)->finalizePodium($event->id, $class->id, 3);

    expect($result['finalized'])->toBeTrue()
        ->and($result['podium'])->toHaveCount(3)
        ->and($result['podium'][0]['name'])->toBe('Pod C')
        ->and($result['podium'][1]['name'])->toBe('Pod A')
        ->and($result['podium'][2]['name'])->toBe('Pod B');
});

test('O. end-to-end individual multi-round heat produces final podium', function () {
    $event = mrh_event();
    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'individual_heat');

    $h1 = mrh_heat($class, 101, 4);
    $h2 = mrh_heat($class, 102, 4);
    $r2 = mrh_heat($class, 201, 3);
    $final = mrh_heat($class, 301, 2);

    $regs = [];
    foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $name) {
        $regs[$name] = mrh_register(mrh_person('E2E '.$name), $event, $category, $class);
    }

    foreach ([$h1, $h2] as $heat) {
        mrh_entry($heat, $regs['A']);
        mrh_entry($heat, $regs['B']);
    }
    mrh_entry($h2, $regs['C']);
    mrh_entry($h2, $regs['D']);

    // H1: A(100), B(95) → B, A
    mrh_heat_result($h1, $regs['A'], 100.0);
    mrh_heat_result($h1, $regs['B'], 95.0);
    // H2: A(98), B(97), C(96), D(99) → C, B, A, D → top2 = C, B
    mrh_heat_result($h2, $regs['A'], 98.0);
    mrh_heat_result($h2, $regs['B'], 97.0);
    mrh_heat_result($h2, $regs['C'], 96.0);
    mrh_heat_result($h2, $regs['D'], 99.0);

    mrh_finish($h1);
    mrh_finish($h2);

    $service = app(CompetitionMultiRoundHeatService::class);

    $advance1 = $service->advanceRound($event->id, $class->id, 1, 2);
    expect($advance1['advanced'])->toBeTrue()
        ->and($r2->fresh()->status)->toBe('Ready');

    // R2 sekarang berisi: B, A (dari H1) + C, B? B sudah ada → C saja dari H2 → B,A,C
    // fill R2 (cap 4): qualifiers urut = [B,A] dari H1 lalu [C,B] dari H2 → B,A,C (B dup).
    expect($r2->scheduleEntries()->count())->toBe(3);

    // Tentukan heat result R2 agar final: C terbaik, lalu B, lalu A.
    $r2entries = $r2->scheduleEntries()->pluck('competition_registration_id')->map(fn ($id) => (int) $id)->toArray();
    $best = $regs['C']->id;
    $second = $regs['B']->id;
    $third = $regs['A']->id;

    foreach ($r2entries as $regId) {
        $time = match ((int) $regId) {
            (int) $best => 80.0,
            (int) $second => 85.0,
            default => 90.0,
        };
        mrh_heat_result($r2, CompetitionRegistration::find($regId), $time);
    }
    mrh_finish($r2);

    $advance2 = $service->advanceRound($event->id, $class->id, 2, 2);
    expect($advance2['advanced'])->toBeTrue()
        ->and($final->fresh()->status)->toBe('Ready')
        ->and($final->scheduleEntries()->count())->toBe(2);

    // Final: C vs B → C 82.0, B 84.0 → C Juara 1, B Juara 2.
    $finalEntries = $final->scheduleEntries()->pluck('competition_registration_id')->map(fn ($id) => (int) $id)->toArray();
    foreach ($finalEntries as $regId) {
        mrh_heat_result($final, CompetitionRegistration::find($regId), (int) $regId === (int) $best ? 82.0 : 84.0);
    }
    mrh_finish($final);

    $finalize = $service->finalizePodium($event->id, $class->id, 3);

    expect($finalize['finalized'])->toBeTrue()
        ->and($finalize['podium'][0]['name'])->toBe('E2E C')
        ->and($finalize['podium'][1]['name'])->toBe('E2E B')
        ->and((float) $regs['C']->outcome->fresh()->score)->toBe(82.0);
});

test('P. rankHeat/advanceRound are event-scoped — other event rejected', function () {
    $eventA = mrh_event();
    $eventB = mrh_event();
    $category = mrh_category($eventA);
    $class = mrh_class($eventA, $category, 'individual_heat');
    $heat = mrh_heat($class, 101, 2);

    expect(fn () => app(CompetitionMultiRoundHeatService::class)->rankHeat($eventB->id, $heat->id))
        ->toThrow(ModelNotFoundException::class);
});

// ---------------------------------------------------------------------------
// Q–W. Team Heat
// ---------------------------------------------------------------------------

test('Q. team heat result stored per (schedule, team)', function () {
    $event = mrh_event();
    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'team_heat');
    $heat = mrh_heat($class, 101, 3);

    $k1 = mrh_kelompok('T1');
    $k2 = mrh_kelompok('T2');
    $teamA = mrh_team($event, $class, $k1, 'Tim Satu');
    $teamB = mrh_team($event, $class, $k2, 'Tim Dua');

    mrh_team_entry($heat, $teamA);
    mrh_team_entry($heat, $teamB);
    mrh_team_heat_result($heat, $teamA, 100.0);
    mrh_team_heat_result($heat, $teamB, 95.0);

    expect($heat->heatResults()->count())->toBe(2)
        ->and($heat->heatResults()->where('competition_team_id', $teamA->id)->value('score'))->toBe('100.00');
});

test('R. rankHeat ranks teams by time', function () {
    $event = mrh_event();
    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'team_heat');
    $heat = mrh_heat($class, 101, 3);

    $teamA = mrh_team($event, $class, mrh_kelompok('RA'), 'Tim R A');
    $teamB = mrh_team($event, $class, mrh_kelompok('RB'), 'Tim R B');

    mrh_team_entry($heat, $teamA);
    mrh_team_entry($heat, $teamB);
    mrh_team_heat_result($heat, $teamA, 100.0);
    mrh_team_heat_result($heat, $teamB, 95.0);

    $result = app(CompetitionMultiRoundHeatService::class)->rankHeat($event->id, $heat->id);

    expect($result['ranked'])->toBeTrue()
        ->and($heat->heatResults()->where('competition_team_id', $teamB->id)->value('position'))->toBe(1)
        ->and($heat->heatResults()->where('competition_team_id', $teamA->id)->value('position'))->toBe(2);
});

test('S. advanceRound advances top-N teams to next round', function () {
    $event = mrh_event();
    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'team_heat');

    $h1 = mrh_heat($class, 101, 4);
    $r2 = mrh_heat($class, 201, 2);

    $teamA = mrh_team($event, $class, mrh_kelompok('SA'), 'Tim S A');
    $teamB = mrh_team($event, $class, mrh_kelompok('SB'), 'Tim S B');
    $teamC = mrh_team($event, $class, mrh_kelompok('SC'), 'Tim S C');

    mrh_team_entry($h1, $teamA);
    mrh_team_entry($h1, $teamB);
    mrh_team_entry($h1, $teamC);
    mrh_team_heat_result($h1, $teamA, 90.0);
    mrh_team_heat_result($h1, $teamB, 95.0);
    mrh_team_heat_result($h1, $teamC, 100.0);
    mrh_finish($h1);

    $result = app(CompetitionMultiRoundHeatService::class)->advanceRound($event->id, $class->id, 1, 2);

    $nextTeams = $r2->scheduleEntries()->pluck('competition_team_id')->map(fn ($id) => (int) $id)->sort()->values();
    expect($result['advanced'])->toBeTrue()
        ->and($nextTeams->toArray())->toBe([(int) $teamA->id, (int) $teamB->id]);
});

test('T. advanceRound sets next team heat Ready, never Playing', function () {
    $event = mrh_event();
    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'team_heat');

    $h1 = mrh_heat($class, 101, 2);
    $r2 = mrh_heat($class, 201, 2);

    $teamA = mrh_team($event, $class, mrh_kelompok('TA'), 'Tim T A');
    $teamB = mrh_team($event, $class, mrh_kelompok('TB'), 'Tim T B');

    mrh_team_entry($h1, $teamA);
    mrh_team_entry($h1, $teamB);
    mrh_team_heat_result($h1, $teamA, 90.0);
    mrh_team_heat_result($h1, $teamB, 95.0);
    mrh_finish($h1);

    app(CompetitionMultiRoundHeatService::class)->advanceRound($event->id, $class->id, 1, 2);

    expect($r2->fresh()->status)->toBe('Ready');
});

test('U. aggregateRoundResults writes team outcomes for final round', function () {
    $event = mrh_event();
    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'team_heat');
    $final = mrh_heat($class, 301, 3);

    $teamA = mrh_team($event, $class, mrh_kelompok('UA'), 'Tim U A');
    $teamB = mrh_team($event, $class, mrh_kelompok('UB'), 'Tim U B');

    mrh_team_entry($final, $teamA);
    mrh_team_entry($final, $teamB);
    mrh_team_heat_result($final, $teamA, 100.0);
    mrh_team_heat_result($final, $teamB, 95.0);

    $result = app(CompetitionMultiRoundHeatService::class)->aggregateRoundResults($event->id, $class->id, 3);

    expect($result['ranked'])->toBeTrue()
        ->and((float) $teamB->outcome->fresh()->score)->toBe(95.0)
        ->and($teamB->outcome->fresh()->position)->toBe(1)
        ->and((float) $teamA->outcome->fresh()->score)->toBe(100.0)
        ->and($teamA->outcome->fresh()->position)->toBe(2);
});

test('V. finalizePodium ranks teams into Juara 1/2/3', function () {
    $event = mrh_event();
    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'team_heat');
    $final = mrh_heat($class, 301, 3);

    $teamA = mrh_team($event, $class, mrh_kelompok('VA'), 'Tim V A');
    $teamB = mrh_team($event, $class, mrh_kelompok('VB'), 'Tim V B');
    $teamC = mrh_team($event, $class, mrh_kelompok('VC'), 'Tim V C');

    mrh_team_entry($final, $teamA);
    mrh_team_entry($final, $teamB);
    mrh_team_entry($final, $teamC);
    mrh_team_heat_result($final, $teamA, 100.0);
    mrh_team_heat_result($final, $teamB, 95.0);
    mrh_team_heat_result($final, $teamC, 90.0);

    $result = app(CompetitionMultiRoundHeatService::class)->finalizePodium($event->id, $class->id, 3);

    expect($result['finalized'])->toBeTrue()
        ->and($result['podium'][0]['name'])->toBe('Tim V C')
        ->and($result['podium'][1]['name'])->toBe('Tim V B')
        ->and($result['podium'][2]['name'])->toBe('Tim V A');
});

test('W. team heat result with duplicate (schedule, team) is rejected by unique index', function () {
    $event = mrh_event();
    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'team_heat');
    $heat = mrh_heat($class, 101, 2);
    $teamA = mrh_team($event, $class, mrh_kelompok('WA'), 'Tim W A');

    mrh_team_entry($heat, $teamA);
    mrh_team_heat_result($heat, $teamA, 100.0);

    expect(fn () => CompetitionHeatResult::create([
        'competition_schedule_id' => $heat->id,
        'competition_team_id' => $teamA->id,
        'score' => 99.0,
    ]))->toThrow(QueryException::class);
});

// ---------------------------------------------------------------------------
// X–AD. Regressions
// ---------------------------------------------------------------------------

test('X. regression: existing aggregateHeatResults still works (all-heat best)', function () {
    $event = mrh_event();
    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'individual_heat');
    $heat1 = mrh_heat($class, 1);
    $heat2 = mrh_heat($class, 2);

    $a = mrh_register(mrh_person('Agg A'), $event, $category, $class);
    $b = mrh_register(mrh_person('Agg B'), $event, $category, $class);

    mrh_entry($heat1, $a);
    mrh_entry($heat2, $a);
    mrh_entry($heat1, $b);
    mrh_entry($heat2, $b);
    mrh_heat_result($heat1, $a, 100.0);
    mrh_heat_result($heat2, $a, 90.0);
    mrh_heat_result($heat1, $b, 95.0);
    mrh_heat_result($heat2, $b, 94.0);

    $result = app(CompetitionResultService::class)->aggregateHeatResults($event->id, $class->id);

    expect($result['ranked'])->toBeTrue()
        ->and((float) $a->outcome->fresh()->score)->toBe(90.0)
        ->and((float) $b->outcome->fresh()->score)->toBe(94.0);
});

test('Y. regression: Team Mass rankTeams unaffected by team_heat addition', function () {
    $event = mrh_event();
    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'team_mass');

    $teamA = mrh_team($event, $class, mrh_kelompok('YA'), 'Tim Y A');
    $teamB = mrh_team($event, $class, mrh_kelompok('YB'), 'Tim Y B');

    CompetitionTeamOutcome::updateOrCreate(
        ['competition_team_id' => $teamA->id],
        ['score' => 7.0, 'status' => null, 'position' => null],
    );
    CompetitionTeamOutcome::updateOrCreate(
        ['competition_team_id' => $teamB->id],
        ['score' => 9.0, 'status' => null, 'position' => null],
    );

    $result = app(CompetitionResultService::class)->rankTeams($event->id, $class->id);

    expect($result['ranked'])->toBeTrue()
        ->and($teamA->outcome->fresh()->position)->toBe(1)
        ->and($teamB->outcome->fresh()->position)->toBe(2);
});

test('Z. regression: finishMatch still finishes heat/mass directly (non-official)', function () {
    $event = mrh_event();
    $category = mrh_category($event);
    $workflow = app(\App\Services\Competition\CompetitionWorkflowService::class);

    foreach (['individual_heat', 'team_heat', 'individual_mass'] as $format) {
        $class = mrh_class($event, $category, $format);
        $schedule = mrh_heat($class, 101, 2);
        $schedule->update(['status' => 'Playing']);

        expect($workflow->finishMatch($schedule))->toBeTrue()
            ->and($schedule->fresh()->status)->toBe('Finished');
    }
});

test('AA. regression: individual_heat isHeat flow still works in OutcomeManager', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = mrh_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'individual_heat');
    $heat = mrh_heat($class, 101, 2);

    $a = mrh_register(mrh_person('OM A'), $event, $category, $class);
    $b = mrh_register(mrh_person('OM B'), $event, $category, $class);
    mrh_entry($heat, $a);
    mrh_entry($heat, $b);

    $component = \Livewire::test(\App\Livewire\Competition\Schedule\OutcomeManager::class, ['schedule' => $heat]);
    $inst = $component->instance();

    $heatRows = $inst->heatResults;
    $heatRows[0]['timeText'] = '1:40.0';
    $heatRows[1]['timeText'] = '1:35.0';
    $inst->heatResults = $heatRows;
    $inst->saveOutcomes();

    $saved = CompetitionHeatResult::where('competition_schedule_id', $heat->id)->orderBy('id')->get();
    expect($saved)->toHaveCount(2)
        ->and((float) $saved[0]->score)->toBe(100.0)
        ->and((float) $saved[1]->score)->toBe(95.0);

    $inst->rankHeat();
    $inst->finalizeHeatFinal();

    expect((float) $b->outcome->fresh()->score)->toBe(95.0)
        ->and($b->outcome->fresh()->position)->toBe(1);
});

test('AB. regression: team_heat OutcomeManager saves per-team heat times', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = mrh_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'team_heat');
    $heat = mrh_heat($class, 101, 2);

    $teamA = mrh_team($event, $class, mrh_kelompok('ABA'), 'Tim AB A');
    $teamB = mrh_team($event, $class, mrh_kelompok('ABB'), 'Tim AB B');
    mrh_team_entry($heat, $teamA);
    mrh_team_entry($heat, $teamB);

    $component = \Livewire::test(\App\Livewire\Competition\Schedule\OutcomeManager::class, ['schedule' => $heat]);
    $inst = $component->instance();

    expect($inst->isTeamHeat)->toBeTrue()
        ->and(count($inst->heatResults))->toBe(2);

    $heatRows = $inst->heatResults;
    $heatRows[0]['timeText'] = '1:40.0';
    $heatRows[1]['timeText'] = '1:35.0';
    $inst->heatResults = $heatRows;
    $inst->saveOutcomes();

    $saved = CompetitionHeatResult::where('competition_schedule_id', $heat->id)->orderBy('id')->get();
    expect($saved)->toHaveCount(2)
        ->and($saved->every(fn ($row) => $row->competition_team_id !== null))->toBeTrue();
});

test('AC. regression: schedule delete cascades heat results', function () {
    $event = mrh_event();
    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'individual_heat');
    $heat = mrh_heat($class, 101, 2);

    $a = mrh_register(mrh_person('Del A'), $event, $category, $class);
    mrh_entry($heat, $a);
    mrh_heat_result($heat, $a, 100.0);

    $heat->delete();

    expect(CompetitionHeatResult::count())->toBe(0);
});

test('AD. regression: existing schedule entries flow unaffected for heat rounds', function () {
    $event = mrh_event();
    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'individual_heat');

    $h1 = mrh_heat($class, 101, 4);
    $h2 = mrh_heat($class, 102, 4);

    $a = mrh_register(mrh_person('Entry A'), $event, $category, $class);
    $b = mrh_register(mrh_person('Entry B'), $event, $category, $class);

    mrh_entry($h1, $a);
    mrh_entry($h2, $b);

    expect($h1->scheduleEntries()->count())->toBe(1)
        ->and($h2->scheduleEntries()->count())->toBe(1)
        ->and(CompetitionScheduleEntry::where('competition_registration_id', $a->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// AE. UAT regression — Match Center "Finish Match" heat: Playing → Waiting Result
// ---------------------------------------------------------------------------

test('AE. UAT: Match Center Finish Match moves Playing heat to Waiting Result, NOT Finished', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = mrh_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'individual_heat');
    $heat = mrh_heat($class, 101, 2);
    $heat->update(['status' => 'Ready']);

    $a = mrh_register(mrh_person('UAT A'), $event, $category, $class);
    $b = mrh_register(mrh_person('UAT B'), $event, $category, $class);
    mrh_entry($heat, $a);
    mrh_entry($heat, $b);

    $component = \Livewire::test(\App\Livewire\Competition\MatchCenter::class);

    $component->call('startMatch', $heat->id);
    expect($heat->fresh()->status)->toBe('Playing');

    $component->call('moveToWaitingResult', $heat->id);
    $heat->refresh();
    expect($heat->status)->toBe('Waiting Result')
        ->and($heat->status)->not->toBe('Finished')
        ->and(\App\Models\CompetitionSchedule::where('competition_class_id', $class->id)
            ->where('status', 'Finished')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// AF–AG. UAT regression — Individual Heat advancement:
//        all competitors status-filled (Lolos) although heat NOT lifecycle-Finished
// ---------------------------------------------------------------------------

test('AF. UAT: Advance Top 2 succeeds when all 4 competitors have status Lolos and times, heat not Finished', function () {
    $event = mrh_event();
    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'individual_heat', 'time');
    $heat = mrh_heat($class, 101, 4);
    $next = mrh_heat($class, 201, 4);

    $a = mrh_register(mrh_person('AF A'), $event, $category, $class);
    $b = mrh_register(mrh_person('AF B'), $event, $category, $class);
    $c = mrh_register(mrh_person('AF C'), $event, $category, $class);
    $d = mrh_register(mrh_person('AF D'), $event, $category, $class);

    mrh_entry($heat, $a);
    mrh_entry($heat, $b);
    mrh_entry($heat, $c);
    mrh_entry($heat, $d);

    // UAT flow: heat sudah Playing → Waiting Result (Match Center), hasil di-seed
    // oleh Operator/OutcomeManager dengan status Lolos, BELUM official submit.
    $heat->update(['status' => 'Waiting Result']);

    mrh_heat_result($heat, $a, 100.0, 'Lolos');
    mrh_heat_result($heat, $b, 95.0, 'Lolos');
    mrh_heat_result($heat, $c, 110.0, 'Lolos');
    mrh_heat_result($heat, $d, 90.0, 'Lolos');

    app(CompetitionMultiRoundHeatService::class)->rankHeat($event->id, $heat->id);

    $result = app(CompetitionMultiRoundHeatService::class)->advanceRound($event->id, $class->id, 1, 2);

    // FIX: advancement berhasil meskipun heat belum lifecycle-Finished
    // (predicate = semua kompetitor punya status hasil terisi).
    expect($result['advanced'])->toBeTrue()
        ->and($result['reason'] ?? '')->not->toBe('not_all_finished')
        ->and($result['qualifiers'])->toBe(2);

    // Tepat 2 tercepat (90.0 = D pos 1, 95.0 = B pos 2) lolos; A & C tidak.
    $advancedIds = $next->scheduleEntries()->pluck('competition_registration_id')
        ->map(fn ($id) => (int) $id)->sort()->values()->all();

    expect($advancedIds)->toBe([(int) $b->id, (int) $d->id])
        ->and($heat->heatResults()->where('competition_registration_id', $d->id)->value('position'))->toBe(1)
        ->and($heat->heatResults()->where('competition_registration_id', $b->id)->value('position'))->toBe(2)
        ->and($next->scheduleEntries()->where('competition_registration_id', $a->id)->exists())->toBeFalse()
        ->and($next->scheduleEntries()->where('competition_registration_id', $c->id)->exists())->toBeFalse();
});

test('AG. UAT: qualifyHeat rejects heat_incomplete when one competitor is unfinished; sibling heat qualifies independently', function () {
    $event = mrh_event();
    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'individual_heat', 'time');

    $h1 = mrh_heat($class, 101, 4);
    $h2 = mrh_heat($class, 102, 4);
    $next = mrh_heat($class, 201, 4);

    $a = mrh_register(mrh_person('AG A'), $event, $category, $class);
    $b = mrh_register(mrh_person('AG B'), $event, $category, $class);
    $c = mrh_register(mrh_person('AG C'), $event, $category, $class);
    $d = mrh_register(mrh_person('AG D'), $event, $category, $class);

    mrh_entry($h1, $a);
    mrh_entry($h1, $b);
    mrh_entry($h1, $c);
    mrh_entry($h1, $d);
    mrh_entry($h2, $a);
    mrh_entry($h2, $b);
    mrh_entry($h2, $c);
    mrh_entry($h2, $d);

    // Kompetitor D di heat 1 belum diisi status hasilnya (masih kosong → belum selesai).
    mrh_heat_result($h1, $a, 100.0, 'Lolos');
    mrh_heat_result($h1, $b, 95.0, 'Lolos');
    mrh_heat_result($h1, $c, 90.0, 'Lolos');
    mrh_heat_result($h1, $d, 85.0, null);

    mrh_heat_result($h2, $a, 100.0, 'Lolos');
    mrh_heat_result($h2, $b, 95.0, 'Lolos');
    mrh_heat_result($h2, $c, 90.0, 'Lolos');
    mrh_heat_result($h2, $d, 85.0, 'Lolos');

    $service = app(CompetitionMultiRoundHeatService::class);

    // Heat 1 belum lengkap (D tanpa status) → heat_incomplete (bukan not_all_finished).
    $resultH1 = $service->qualifyHeat($event->id, $h1->id, 2);
    expect($resultH1['qualified'])->toBeFalse()
        ->and($resultH1['reason'])->toBe('heat_incomplete')
        ->and($resultH1['qualified_count'])->toBe(0);

    // Heat 2 lengkap → qualification per-heat sukses tanpa menunggu heat 1.
    $resultH2 = $service->qualifyHeat($event->id, $h2->id, 2);
    expect($resultH2['qualified'])->toBeTrue()
        ->and($resultH2['qualified_count'])->toBe(2);

    // Tidak ada round berikutnya yang disentuh (belum diisi oleh qualification).
    expect($next->scheduleEntries()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// AH. UAT determination — no_next_round is CORRECT for a single-round class
//     (no Round 2 schedule pre-created). The multi-round heat service does NOT
//     fabricate a next-round schedule; only the Schedule/Index UI creates
//     schedules (sort_order = round*100 + heatIndex).
// ---------------------------------------------------------------------------

test('AH. UAT: single-round heat returns no_next_round without creating a fake Round 2', function () {
    $event = mrh_event();
    $category = mrh_category($event);
    $class = mrh_class($event, $category, 'individual_heat', 'time');
    $heat = mrh_heat($class, 101, 4);

    $a = mrh_register(mrh_person('AH A'), $event, $category, $class);
    $b = mrh_register(mrh_person('AH B'), $event, $category, $class);
    $c = mrh_register(mrh_person('AH C'), $event, $category, $class);
    $d = mrh_register(mrh_person('AH D'), $event, $category, $class);

    mrh_entry($heat, $a);
    mrh_entry($heat, $b);
    mrh_entry($heat, $c);
    mrh_entry($heat, $d);

    $heat->update(['status' => 'Waiting Result']);

    mrh_heat_result($heat, $a, 100.0, 'Lolos');
    mrh_heat_result($heat, $b, 95.0, 'Lolos');
    mrh_heat_result($heat, $c, 110.0, 'Lolos');
    mrh_heat_result($heat, $d, 90.0, 'Lolos');

    $service = app(CompetitionMultiRoundHeatService::class);
    $service->rankHeat($event->id, $heat->id);

    $schedulesBefore = CompetitionSchedule::count();
    $entriesBefore = CompetitionScheduleEntry::count();

    $result = $service->advanceRound($event->id, $class->id, 1, 2);

    // Heat ini lengkap (semua hasil terisi), TAPI kelas ini memang single-round:
    // hanya ada schedule round 1, tidak ada schedule round 2 (sort_order 200-299).
    // no_next_round adalah behavior BENAR (advanceRound tidak memfabrikasi round).
    expect($result['advanced'])->toBeFalse()
        ->and($result['reason'])->toBe('no_next_round')
        ->and($result['next_round'])->toBeNull()
        ->and($service->isFinalRound($class->id, 1))->toBeTrue();

    // Tidak ada fake Round 2 yang dibuat; tidak ada entry advancement baru.
    expect(CompetitionSchedule::count())->toBe($schedulesBefore)
        ->and(CompetitionScheduleEntry::count())->toBe($entriesBefore);

    // Per-heat ranking tetap tersedia (aspek multi-round tetap jalan).
    $ranked = $service->rankHeat($event->id, $heat->id);
    expect($ranked['ranked'])->toBeTrue()
        ->and($heat->heatResults()->where('competition_registration_id', $d->id)->value('position'))->toBe(1);
});
