<?php

use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionHeatResult;
use App\Models\CompetitionRegistration;
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
// Per-heat qualification (qualifyHeat) + qualified-pool round generation.
// Business requirement UAT 2026-08: qualification PER-HEAT — Heat 01 bisa
// qualify tanpa menunggu Heat 02; round berikutnya dibangun hanya saat pool
// qualified mencukupi kapasitas format.
// ---------------------------------------------------------------------------

function phq_event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'PHQ Event '.str()->random(6),
        'slug' => 'phq-'.str()->random(6),
        'status' => 'active',
        'event_type' => 'competition',
    ], $overrides));
}

function phq_category(Event $event): CompetitionCategory
{
    $category = CompetitionCategory::create(['event_id' => $event->id, 'name' => 'PHQ Cat '.str()->random(4)]);


    return $category;
}

function phq_class(Event $event, CompetitionCategory $category, string $format = 'individual_heat', ?string $resultType = 'time'): CompetitionClass
{
    return CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'PHQ Class '.str()->random(4),
        'gender' => 'M',
        'format' => $format,
        'status' => 'registration_open',
        'result_type' => $resultType,
        'is_active' => true,
    ]);
}

function phq_register(Person $person, Event $event, CompetitionCategory $category, CompetitionClass $class): CompetitionRegistration
{
    return app(CompetitionRegistrationService::class)->registerForPerson(
        person: $person,
        eventId: $event->id,
        competitionCategoryId: $category->id,
        competitionClassId: $class->id,
    )['competition_registration'];
}

function phq_register_many(Event $event, CompetitionCategory $category, CompetitionClass $class, int $count): array
{
    $regs = [];
    for ($i = 1; $i <= $count; $i++) {
        $regs[] = phq_register(
            Person::create(['nama' => 'PHQ Ath '.str()->random(6), 'jenis_kelamin' => 'L']),
            $event,
            $category,
            $class,
        );
    }

    return $regs;
}

function phq_heat(CompetitionClass $class, int $sortOrder, int $capacity): CompetitionSchedule
{
    return CompetitionSchedule::create([
        'competition_class_id' => $class->id,
        'status' => 'Scheduled',
        'required_participants' => $capacity,
        'sort_order' => $sortOrder,
    ]);
}

function phq_entry(CompetitionSchedule $schedule, CompetitionRegistration $registration): void
{
    CompetitionScheduleEntry::create([
        'competition_schedule_id' => $schedule->id,
        'competition_registration_id' => $registration->id,
    ]);
}

function phq_heat_result(CompetitionSchedule $schedule, CompetitionRegistration $registration, ?float $score, ?string $status = 'Lolos'): CompetitionHeatResult
{
    return CompetitionHeatResult::updateOrCreate(
        ['competition_schedule_id' => $schedule->id, 'competition_registration_id' => $registration->id],
        ['score' => $score, 'status' => $status, 'position' => null],
    );
}

function phq_save_format(CompetitionHeatManagerService $service, Event $event, CompetitionClass $class, int $round, int $per, int $qual): void
{
    $service->upsertFormat($event->id, $class->id, $round, $per, $qual);
}

// ---------------------------------------------------------------------------

test('Test 1 — qualifyHeat berhasil per-heat tanpa menunggu sibling heat', function () {
    $event = phq_event();
    $category = phq_category($event);
    $class = phq_class($event, $category);

    $regs = phq_register_many($event, $category, $class, 9);

    $h1 = phq_heat($class, 101, 5);
    $h2 = phq_heat($class, 102, 4);

    foreach ($regs as $i => $reg) {
        phq_entry($i < 5 ? $h1 : $h2, $reg);
    }

    // Heat 01 selesai: rank 1..5 (semakin kecil waktu = terbaik), semua 'Lolos'.
    $time = 60.0;
    foreach (array_slice($regs, 0, 5) as $reg) {
        phq_heat_result($h1, $reg, $time++, 'Lolos');
    }

    $service = app(CompetitionMultiRoundHeatService::class);
    $result = $service->qualifyHeat($event->id, $h1->id, 2);

    expect($result['qualified'])->toBeTrue()
        ->and($result['qualified_count'])->toBe(2)
        ->and($result['reason'] ?? null)->toBeNull();

    // Top 2 Heat 01 = 2 tercepat (60.0 dan 61.0 = regs[0] dan regs[1]).
    $fastest = [(int) $regs[0]->id, (int) $regs[1]->id];
    sort($result['qualifiers']);
    sort($fastest);
    expect($result['qualifiers'])->toBe($fastest);

    // Heat 02 tidak tersentuh (belum ada hasil heat yang di-qualify di sana).
    expect(CompetitionHeatResult::where('competition_schedule_id', $h2->id)->count())->toBe(0);

    // Round 2 belum dibuat oleh qualification.
    expect($service->roundSchedules($class->id, 2)->isEmpty())->toBeTrue();
});

test('Test 2 — generateNextRound tidak membuat Round 2 saat pool < capacity', function () {
    $event = phq_event();
    $category = phq_category($event);
    $class = phq_class($event, $category);

    $regs = phq_register_many($event, $category, $class, 9);

    $heatManager = app(CompetitionHeatManagerService::class);
    $multiRound = app(CompetitionMultiRoundHeatService::class);

    phq_save_format($heatManager, $event, $class, 1, 5, 2);
    phq_save_format($heatManager, $event, $class, 2, 4, 2);

    $heatManager->generateRound($event->id, $class->id, 1);
    $heats = $multiRound->roundSchedules($class->id, 1)->values();

    // Heat 01 selesai (2 qualified), Heat 02 belum selesai.
    $time = 60.0;
    foreach ($heats[0]->scheduleEntries()->pluck('competition_registration_id') as $regId) {
        phq_heat_result($heats[0], CompetitionRegistration::find($regId), $time++, 'Lolos');
    }

    $result = $heatManager->generateNextRound($event->id, $class->id, 1);

    // Pool = 2 < capacity 4 → jangan membuat Round 2.
    expect($result['advanced'])->toBeFalse()
        ->and($result['reason'])->toBe('qualified_pool_insufficient')
        ->and($result['qualifiers'])->toBe(2)
        ->and($multiRound->roundSchedules($class->id, 2)->isEmpty())->toBeTrue();
});

test('Test 3 — generateNextRound membangun Round 2 saat pool >= capacity', function () {
    $event = phq_event();
    $category = phq_category($event);
    $class = phq_class($event, $category);

    $regs = phq_register_many($event, $category, $class, 9);

    $heatManager = app(CompetitionHeatManagerService::class);
    $multiRound = app(CompetitionMultiRoundHeatService::class);

    phq_save_format($heatManager, $event, $class, 1, 5, 2);
    phq_save_format($heatManager, $event, $class, 2, 4, 2);

    $heatManager->generateRound($event->id, $class->id, 1);
    $heats = $multiRound->roundSchedules($class->id, 1)->values();

    // Kedua heat selesai: heat 01 top-2, heat 02 top-2 → pool 4.
    foreach ($heats as $heat) {
        $time = 60.0;
        foreach ($heat->scheduleEntries()->pluck('competition_registration_id') as $regId) {
            phq_heat_result($heat, CompetitionRegistration::find($regId), $time++, 'Lolos');
        }
    }

    $result = $heatManager->generateNextRound($event->id, $class->id, 1);

    expect($result['advanced'])->toBeTrue()
        ->and($result['qualifiers'])->toBe(4)
        ->and($result['assigned'])->toBe(4)
        ->and($result['heat_count'])->toBe(1);

    $round2 = $multiRound->roundSchedules($class->id, 2);

    expect($round2)->toHaveCount(1)
        ->and((int) $round2->first()->required_participants)->toBe(4)
        ->and($round2->first()->scheduleEntries()->count())->toBe(4);

    // 4 qualifier yang benar = 2 tercepat tiap heat.
    $expected = [(int) $regs[0]->id, (int) $regs[1]->id, (int) $regs[5]->id, (int) $regs[6]->id];
    sort($expected);

    $actual = $round2->first()->scheduleEntries()->pluck('competition_registration_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    expect($actual)->toBe($expected);
});

test('Test 4 — qualifyHeat gagal heat_incomplete saat ada kompetitor tanpa hasil/status', function () {
    $event = phq_event();
    $category = phq_category($event);
    $class = phq_class($event, $category);

    $regs = phq_register_many($event, $category, $class, 5);
    $h1 = phq_heat($class, 101, 5);

    foreach ($regs as $i => $reg) {
        phq_entry($h1, $reg);
        // Kompetitor ke-3 belum punya status hasil.
        phq_heat_result($h1, $reg, 60.0 + $i, $i === 2 ? null : 'Lolos');
    }

    $result = app(CompetitionMultiRoundHeatService::class)->qualifyHeat($event->id, $h1->id, 2);

    expect($result['qualified'])->toBeFalse()
        ->and($result['reason'])->toBe('heat_incomplete')
        ->and($result['qualified_count'])->toBe(0);
});

test('Test 5 — qualifyHeat idempotent: dua kali tidak menggandakan data', function () {
    $event = phq_event();
    $category = phq_category($event);
    $class = phq_class($event, $category);

    $regs = phq_register_many($event, $category, $class, 5);
    $h1 = phq_heat($class, 101, 5);

    foreach ($regs as $i => $reg) {
        phq_entry($h1, $reg);
        phq_heat_result($h1, $reg, 60.0 + $i, 'Lolos');
    }

    $service = app(CompetitionMultiRoundHeatService::class);

    $first = $service->qualifyHeat($event->id, $h1->id, 2);
    $second = $service->qualifyHeat($event->id, $h1->id, 2);

    expect($first['qualified'])->toBeTrue()
        ->and($second['qualified'])->toBeTrue()
        ->and($first['qualified_count'])->toBe(2)
        ->and($second['qualified_count'])->toBe(2)
        ->and($first['qualifiers'])->toBe($second['qualifiers']);

    // Tidak ada schedule entry / hasil tambahan.
    expect(CompetitionHeatResult::where('competition_schedule_id', $h1->id)->count())->toBe(5)
        ->and(CompetitionScheduleEntry::where('competition_schedule_id', $h1->id)->count())->toBe(5);
});

test('Test 7 — full UAT scenario: qualify per-heat lalu generate round berikutnya', function () {
    $event = phq_event();
    $category = phq_category($event);
    $class = phq_class($event, $category);

    phq_register_many($event, $category, $class, 9);

    $heatManager = app(CompetitionHeatManagerService::class);
    $multiRound = app(CompetitionMultiRoundHeatService::class);

    phq_save_format($heatManager, $event, $class, 1, 5, 2);
    phq_save_format($heatManager, $event, $class, 2, 4, 2);

    $heatManager->generateRound($event->id, $class->id, 1);
    $heats = $multiRound->roundSchedules($class->id, 1)->sortBy('sort_order')->values();

    // Step 1: Heat 01 selesai → Advance Top 2 Heat 01 berhasil.
    $time = 60.0;
    foreach ($heats[0]->scheduleEntries()->pluck('competition_registration_id') as $regId) {
        phq_heat_result($heats[0], CompetitionRegistration::find($regId), $time++, 'Lolos');
    }
    $q1 = $multiRound->qualifyHeat($event->id, $heats[0]->id, 2);
    expect($q1['qualified'])->toBeTrue()->and($q1['qualified_count'])->toBe(2);

    // Heat 02 belum selesai → round berikutnya belum bisa dibangun.
    $premature = $heatManager->generateNextRound($event->id, $class->id, 1);
    expect($premature['advanced'])->toBeFalse()
        ->and($premature['reason'])->toBe('qualified_pool_insufficient');

    // Step 2: Heat 02 selesai → Advance Top 2 Heat 02 berhasil.
    $time = 70.0;
    foreach ($heats[1]->scheduleEntries()->pluck('competition_registration_id') as $regId) {
        phq_heat_result($heats[1], CompetitionRegistration::find($regId), $time++, 'Lolos');
    }
    $q2 = $multiRound->qualifyHeat($event->id, $heats[1]->id, 2);
    expect($q2['qualified'])->toBeTrue()->and($q2['qualified_count'])->toBe(2);

    // Step 3: Generate Round Berikutnya → Round 2 menerima 4 qualifier.
    $advance = $heatManager->generateNextRound($event->id, $class->id, 1);

    expect($advance['advanced'])->toBeTrue()
        ->and($advance['qualifiers'])->toBe(4)
        ->and($advance['assigned'])->toBe(4);

    $round2 = $multiRound->roundSchedules($class->id, 2);
    expect($round2)->toHaveCount(1)
        ->and($round2->first()->scheduleEntries()->count())->toBe(4);
});
