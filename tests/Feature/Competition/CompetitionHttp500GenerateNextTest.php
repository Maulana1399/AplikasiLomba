<?php

use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionHeatFormat;
use App\Models\CompetitionHeatResult;
use App\Models\CompetitionSchedule;
use App\Models\CompetitionScheduleEntry;
use App\Models\CompetitionTeam;
use App\Models\Event;
use App\Models\kelompok;
use App\Models\Person;
use App\Services\Competition\CompetitionHeatManagerService;
use App\Services\Competition\CompetitionMultiRoundHeatService;
use App\Services\Competition\CompetitionRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers (prefix t500_ to avoid conflicts with other test files)
// ---------------------------------------------------------------------------

function t500_event(): Event
{
    return Event::create([
        'name' => 'T500 Event '.str()->random(5),
        'slug' => 't500-'.str()->random(5),
        'status' => 'active',
        'event_type' => 'competition',
    ]);
}

function t500_category(Event $event): CompetitionCategory
{
    $category = CompetitionCategory::create(['event_id' => $event->id, 'name' => 'T500 Cat']);


    return $category;
}

function t500_class(Event $event, CompetitionCategory $cat, string $format = 'individual_heat', string $resultType = 'time'): CompetitionClass
{
    return CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $cat->id,
        'name' => 'T500 Class '.str()->random(4),
        'gender' => 'M',
        'format' => $format,
        'status' => 'registration_open',
        'result_type' => $resultType,
        'is_active' => true,
    ]);
}

function t500_register_n(Event $event, CompetitionCategory $cat, CompetitionClass $class, int $n): array
{
    $regs = [];
    for ($i = 1; $i <= $n; $i++) {
        $person = Person::create(['nama' => "T500 P{$i}", 'jenis_kelamin' => 'L']);
        $regs[] = app(CompetitionRegistrationService::class)->registerForPerson(
            person: $person,
            eventId: $event->id,
            competitionCategoryId: $cat->id,
            competitionClassId: $class->id,
        )['competition_registration'];
    }

    return $regs;
}

function t500_format(Event $event, CompetitionClass $class, int $round, int $perHeat, int $qualifiers, int $min = 2): CompetitionHeatFormat
{
    return app(CompetitionHeatManagerService::class)->upsertFormat(
        $event->id, $class->id, $round, $perHeat, $qualifiers, $min,
    );
}

function t500_generate(Event $event, CompetitionClass $class, int $round): array
{
    return app(CompetitionHeatManagerService::class)->generateRound($event->id, $class->id, $round);
}

function t500_generate_next(Event $event, CompetitionClass $class, int $round): array
{
    return app(CompetitionHeatManagerService::class)->generateNextRound($event->id, $class->id, $round);
}

function t500_finish_round(Event $event, CompetitionClass $class, int $round, string $status = 'Lolos'): void
{
    $multiRound = app(CompetitionMultiRoundHeatService::class);
    $heats = $multiRound->roundSchedules($class->id, $round);
    $time = 60.0 + ($round * 100);

    foreach ($heats as $heat) {
        foreach ($heat->scheduleEntries()->pluck('competition_registration_id') as $regId) {
            CompetitionHeatResult::updateOrCreate(
                ['competition_schedule_id' => $heat->id, 'competition_registration_id' => $regId],
                ['score' => $time++, 'status' => $status, 'position' => null],
            );
        }
        $multiRound->rankHeat($event->id, $heat->id);
        $heat->update(['status' => 'Finished']);
    }
}

function t500_heat_sizes(CompetitionClass $class, int $round): array
{
    return app(CompetitionMultiRoundHeatService::class)
        ->roundSchedules($class->id, $round)
        ->map(fn ($h) => $h->scheduleEntries()->count())
        ->values()
        ->all();
}

// ---------------------------------------------------------------------------
// 1. UAT exact: R2 = 3+3, Generate berikutnya tidak 500
//
// Root cause: `min_participants_to_start` column must exist in
// `competition_heat_formats`. Migration
// `2026_08_27_000003_add_min_participants_to_start_to_competition_heat_formats_table`
// was not run on production — upsertFormat INSERT failed with
// SQLSTATE 42S22 "Unknown column 'min_participants_to_start'".
// ---------------------------------------------------------------------------

test('1. UAT: upsertFormat tidak 500 (min_participants_to_start column wajib ada)', function () {
    $event = t500_event();
    $cat = t500_category($event);
    $class = t500_class($event, $cat);

    // Operator menyimpan format Round 1 — ini yang 500 di production.
    $fmt1 = t500_format($event, $class, 1, 4, 3, 2);
    expect($fmt1->participants_per_heat)->toBe(4)
        ->and($fmt1->qualifiers_per_heat)->toBe(3)
        ->and($fmt1->min_participants_to_start)->toBe(2);

    // Operator menyimpan format Round 2.
    $fmt2 = t500_format($event, $class, 2, 4, 3, 2);
    expect($fmt2->participants_per_heat)->toBe(4)
        ->and($fmt2->qualifiers_per_heat)->toBe(3)
        ->and($fmt2->min_participants_to_start)->toBe(2);

    // Both persisted to DB correctly.
    expect(CompetitionHeatFormat::where('competition_class_id', $class->id)->count())->toBe(2);
});

test('1b. UAT exact: R2 = 3+3 → Generate Round berikutnya tidak 500, berhasil', function () {
    $event = t500_event();
    $cat = t500_category($event);
    $class = t500_class($event, $cat);
    t500_register_n($event, $cat, $class, 8);

    t500_format($event, $class, 1, 4, 3, 2);
    t500_format($event, $class, 2, 4, 3, 2);
    t500_format($event, $class, 3, 4, 3, 2);

    t500_generate($event, $class, 1);
    t500_finish_round($event, $class, 1);

    // R1 → R2: 6 qualifiers (top 3 per heat x 2 heat), balanced 3+3.
    $adv1 = t500_generate_next($event, $class, 1);

    expect($adv1['advanced'])->toBeTrue()
        ->and($adv1['qualifiers'])->toBe(6)
        ->and($adv1['heat_count'])->toBe(2)
        ->and($adv1['assigned'])->toBe(6);

    expect(t500_heat_sizes($class, 2))->toBe([3, 3]);

    // Sekarang R2 → R3: selesaikan R2, generate R3.
    t500_finish_round($event, $class, 2);

    $adv2 = t500_generate_next($event, $class, 2);

    expect($adv2['advanced'])->toBeTrue()
        ->and($adv2['qualifiers'])->toBe(6);

    // R3 capacity=4; 6 qualifiers → ceil(6/4)=2 heats; base=3, extra=0 → [3,3].
    expect(t500_heat_sizes($class, 3))->toBe([3, 3]);
});

// ---------------------------------------------------------------------------
// 2. Round 1 → Round 2 → Round 3 tanpa error
// ---------------------------------------------------------------------------

test('2. R1 → R2 → R3 multi-round tanpa 500', function () {
    $event = t500_event();
    $cat = t500_category($event);
    $class = t500_class($event, $cat);
    t500_register_n($event, $cat, $class, 12);

    t500_format($event, $class, 1, 4, 3, 2);
    t500_format($event, $class, 2, 3, 2, 2);
    t500_format($event, $class, 3, 4, 2, 2);

    t500_generate($event, $class, 1);

    expect(app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1))->toHaveCount(3);

    t500_finish_round($event, $class, 1);
    $adv1 = t500_generate_next($event, $class, 1);

    // 3 heats x top3 = 9 qualifiers → R2 cap=3 → ceil(9/3)=3 heats [3,3,3].
    expect($adv1['advanced'])->toBeTrue()
        ->and($adv1['qualifiers'])->toBe(9)
        ->and(t500_heat_sizes($class, 2))->toBe([3, 3, 3]);

    t500_finish_round($event, $class, 2);
    $adv2 = t500_generate_next($event, $class, 2);

    // 3 heats x top2 = 6 qualifiers → R3 cap=4 → ceil(6/4)=2 heats [3,3].
    expect($adv2['advanced'])->toBeTrue()
        ->and($adv2['qualifiers'])->toBe(6)
        ->and(t500_heat_sizes($class, 3))->toBe([3, 3]);
});

// ---------------------------------------------------------------------------
// 3. Generate round berikutnya ketika round itu adalah final (no next format)
// ---------------------------------------------------------------------------

test('3. Generate dari final round tanpa format berikutnya → graceful no_next_format', function () {
    $event = t500_event();
    $cat = t500_category($event);
    $class = t500_class($event, $cat);
    t500_register_n($event, $cat, $class, 8);

    t500_format($event, $class, 1, 4, 3, 2);
    // Tidak ada format Round 2 — ini adalah final.

    t500_generate($event, $class, 1);
    t500_finish_round($event, $class, 1);

    $result = t500_generate_next($event, $class, 1);

    // Tidak ada format Round 2 → harus return advanced=false, reason=no_next_format.
    expect($result['advanced'])->toBeFalse()
        ->and($result['reason'])->toBe('no_next_format');
});

// ---------------------------------------------------------------------------
// 4. Generate ulang tidak membuat duplicate
// ---------------------------------------------------------------------------

test('4. Generate ulang (round exists) tidak membuat duplicate heat → next_round_exists', function () {
    $event = t500_event();
    $cat = t500_category($event);
    $class = t500_class($event, $cat);
    t500_register_n($event, $cat, $class, 8);

    t500_format($event, $class, 1, 4, 3, 2);
    t500_format($event, $class, 2, 4, 3, 2);

    t500_generate($event, $class, 1);
    t500_finish_round($event, $class, 1);

    $adv1 = t500_generate_next($event, $class, 1);
    expect($adv1['advanced'])->toBeTrue();

    $heatCountAfterFirst = CompetitionSchedule::where('competition_class_id', $class->id)->count();

    // Panggil generateNextRound lagi — round 2 sudah ada.
    $adv2 = t500_generate_next($event, $class, 1);
    expect($adv2['advanced'])->toBeFalse()
        ->and($adv2['reason'])->toBe('next_round_exists');

    // Jumlah heat tidak bertambah.
    expect(CompetitionSchedule::where('competition_class_id', $class->id)->count())
        ->toBe($heatCountAfterFirst);

    // Entry di R2 tidak diduplikasi.
    $r2Entries = app(CompetitionMultiRoundHeatService::class)
        ->roundSchedules($class->id, 2)
        ->sum(fn ($h) => $h->scheduleEntries()->count());

    expect($r2Entries)->toBe(6);
});

// ---------------------------------------------------------------------------
// 5. Top-N per heat tetap benar setelah fix 500
// ---------------------------------------------------------------------------

test('5. Top-N per heat tetap ketat: 4 peserta, Top 3, semua Lolos → tepat 3 advance', function () {
    $event = t500_event();
    $cat = t500_category($event);
    $class = t500_class($event, $cat);
    $regs = t500_register_n($event, $cat, $class, 4);

    $heat = CompetitionSchedule::create([
        'competition_class_id' => $class->id,
        'status' => 'Waiting Result',
        'required_participants' => 4,
        'sort_order' => 101,
    ]);

    $time = 60.0;
    foreach ($regs as $reg) {
        CompetitionScheduleEntry::create([
            'competition_schedule_id' => $heat->id,
            'competition_registration_id' => $reg->id,
            'order_number' => (int) ($time - 59),
        ]);
        CompetitionHeatResult::create([
            'competition_schedule_id' => $heat->id,
            'competition_registration_id' => $reg->id,
            'score' => $time++,
            'status' => 'Lolos',
        ]);
    }

    $service = app(CompetitionMultiRoundHeatService::class);
    $service->rankHeat($event->id, $heat->id);
    $q = $service->qualifyHeat($event->id, $heat->id, 3);

    expect($q['qualified'])->toBeTrue()
        ->and($q['qualified_count'])->toBe(3)
        ->and($q['qualifiers'])->toHaveCount(3);
});

// ---------------------------------------------------------------------------
// 6. Time/score ranking tidak berubah
// ---------------------------------------------------------------------------

test('6a. Time result_type: tercepat menang, Top 2 benar', function () {
    $event = t500_event();
    $cat = t500_category($event);
    $class = t500_class($event, $cat, 'individual_heat', 'time');
    [$a, $b, $c, $d] = t500_register_n($event, $cat, $class, 4);

    $heat = CompetitionSchedule::create([
        'competition_class_id' => $class->id,
        'status' => 'Waiting Result',
        'required_participants' => 4,
        'sort_order' => 101,
    ]);

    $scores = [$a->id => 90.0, $b->id => 100.0, $c->id => 80.0, $d->id => 70.0];
    $i = 1;
    foreach ([$a, $b, $c, $d] as $reg) {
        CompetitionScheduleEntry::create(['competition_schedule_id' => $heat->id, 'competition_registration_id' => $reg->id, 'order_number' => $i++]);
        CompetitionHeatResult::create(['competition_schedule_id' => $heat->id, 'competition_registration_id' => $reg->id, 'score' => $scores[$reg->id], 'status' => 'Lolos']);
    }

    $q = app(CompetitionMultiRoundHeatService::class)->qualifyHeat($event->id, $heat->id, 2);

    expect($q['qualified_count'])->toBe(2)
        ->and($q['qualifiers'])->toBe([(int) $d->id, (int) $c->id]);
});

test('6b. Score result_type: tertinggi menang, Top 2 benar', function () {
    $event = t500_event();
    $cat = t500_category($event);
    $class = t500_class($event, $cat, 'individual_heat', 'score');
    [$a, $b, $c, $d] = t500_register_n($event, $cat, $class, 4);

    $heat = CompetitionSchedule::create([
        'competition_class_id' => $class->id,
        'status' => 'Waiting Result',
        'required_participants' => 4,
        'sort_order' => 101,
    ]);

    $scores = [$a->id => 10.0, $b->id => 20.0, $c->id => 30.0, $d->id => 40.0];
    $i = 1;
    foreach ([$a, $b, $c, $d] as $reg) {
        CompetitionScheduleEntry::create(['competition_schedule_id' => $heat->id, 'competition_registration_id' => $reg->id, 'order_number' => $i++]);
        CompetitionHeatResult::create(['competition_schedule_id' => $heat->id, 'competition_registration_id' => $reg->id, 'score' => $scores[$reg->id], 'status' => 'Lolos']);
    }

    $q = app(CompetitionMultiRoundHeatService::class)->qualifyHeat($event->id, $heat->id, 2);

    expect($q['qualified_count'])->toBe(2)
        ->and($q['qualifiers'])->toBe([(int) $d->id, (int) $c->id]);
});

// ---------------------------------------------------------------------------
// 7. Non-normal status tidak advance
// ---------------------------------------------------------------------------

test('7. Status Diskualifikasi tidak masuk qualified pool', function () {
    $event = t500_event();
    $cat = t500_category($event);
    $class = t500_class($event, $cat);
    [$a, $b, $c, $d] = t500_register_n($event, $cat, $class, 4);

    $heat = CompetitionSchedule::create([
        'competition_class_id' => $class->id,
        'status' => 'Waiting Result',
        'required_participants' => 4,
        'sort_order' => 101,
    ]);

    $statuses = [$a->id => 'Lolos', $b->id => 'Lolos', $c->id => 'Diskualifikasi', $d->id => 'Lolos'];
    $scores = [$a->id => 70.0, $b->id => 80.0, $c->id => 60.0, $d->id => 90.0];
    $i = 1;
    foreach ([$a, $b, $c, $d] as $reg) {
        CompetitionScheduleEntry::create(['competition_schedule_id' => $heat->id, 'competition_registration_id' => $reg->id, 'order_number' => $i++]);
        CompetitionHeatResult::create(['competition_schedule_id' => $heat->id, 'competition_registration_id' => $reg->id, 'score' => $scores[$reg->id], 'status' => $statuses[$reg->id]]);
    }

    $q = app(CompetitionMultiRoundHeatService::class)->qualifyHeat($event->id, $heat->id, 3);

    expect($q['qualified_count'])->toBe(3)
        ->and($q['qualifiers'])->not->toContain((int) $c->id);
});

// ---------------------------------------------------------------------------
// 8. Team heat tidak regression
// ---------------------------------------------------------------------------

test('8. Team heat: R1 → R2 balanced, tidak 500', function () {
    $event = t500_event();
    $cat = t500_category($event);
    $class = t500_class($event, $cat, 'team_heat', 'time');

    t500_format($event, $class, 1, 3, 2, 2);
    t500_format($event, $class, 2, 4, 2, 2);

    $teams = [];
    for ($i = 1; $i <= 6; $i++) {
        $teams[] = CompetitionTeam::create([
            'event_id' => $event->id,
            'competition_class_id' => $class->id,
            'name' => "Team {$i}",
            'kelompok_id' => kelompok::create(['kelompok_asal' => "Klp {$i}"])->id,
            'is_active' => true,
        ]);
    }

    t500_generate($event, $class, 1);

    $multiRound = app(CompetitionMultiRoundHeatService::class);
    $round1 = $multiRound->roundSchedules($class->id, 1);
    expect($round1)->toHaveCount(2);

    // Team heat: generate hanya membuat heat kosong — tim harus di-assign dulu.
    $heatService = app(CompetitionHeatManagerService::class);
    collect($round1)->values()->each(function ($heat, $idx) use (&$teams, $heatService, $event, $class) {
        collect(array_splice($teams, 0, 3))->each(function ($team) use ($heatService, $event, $class, $idx) {
            $heatService->assignTeamToHeat($event->id, $class->id, 1, $idx + 1, $team->id);
        });
    });

    $time = 60.0;
    foreach ($round1 as $heat) {
        foreach ($heat->scheduleEntries()->pluck('competition_team_id') as $teamId) {
            CompetitionHeatResult::updateOrCreate(
                ['competition_schedule_id' => $heat->id, 'competition_team_id' => $teamId],
                ['score' => $time++, 'status' => 'Lolos', 'position' => null],
            );
        }
        $multiRound->rankHeat($event->id, $heat->id);
        $heat->update(['status' => 'Finished']);
    }

    // 2 heat x top2 = 4 qualifiers → R2 cap=4 → 1 heat [4].
    $result = t500_generate_next($event, $class, 1);

    expect($result['advanced'])->toBeTrue()
        ->and($result['qualifiers'])->toBe(4)
        ->and($result['heat_count'])->toBe(1);

    $r2 = $multiRound->roundSchedules($class->id, 2);
    expect($r2)->toHaveCount(1)
        ->and($r2->first()->scheduleEntries()->count())->toBe(4);
});
