<?php

use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionHeatFormat;
use App\Models\CompetitionHeatResult;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionSchedule;
use App\Models\CompetitionScheduleEntry;
use App\Models\Event;
use App\Models\kelompok;
use App\Models\Person;
use App\Services\Competition\CompetitionHeatManagerService;
use App\Services\Competition\CompetitionMultiRoundHeatService;
use App\Services\Competition\CompetitionRegistrationService;
use App\Services\Competition\CompetitionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function chrt_event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'CHRT Event '.str()->random(6),
        'slug' => 'chrt-'.str()->random(6),
        'status' => 'active',
        'event_type' => 'competition',
    ], $overrides));
}

function chrt_category(Event $event): CompetitionCategory
{
    $category = CompetitionCategory::create(['event_id' => $event->id, 'name' => 'CHRT Cat '.str()->random(4)]);
    $category->events()->syncWithoutDetaching([$event->id]);

    return $category;
}

function chrt_class(Event $event, CompetitionCategory $category, string $format = 'individual_heat', ?string $resultType = null): CompetitionClass
{
    return CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'CHRT Class '.str()->random(4),
        'gender' => 'M',
        'format' => $format,
        'status' => 'registration_open',
        'result_type' => $resultType,
        'is_active' => true,
    ]);
}

function chrt_person(string $nama, ?kelompok $kelompok = null): Person
{
    return Person::create(['nama' => $nama, 'jenis_kelamin' => 'L', 'kelompok_id' => $kelompok?->id]);
}

function chrt_register(Person $person, Event $event, CompetitionCategory $category, CompetitionClass $class): CompetitionRegistration
{
    return app(CompetitionRegistrationService::class)->registerForPerson(
        person: $person,
        eventId: $event->id,
        competitionCategoryId: $category->id,
        competitionClassId: $class->id,
    )['competition_registration'];
}

function chrt_register_many(Event $event, CompetitionCategory $category, CompetitionClass $class, int $count): array
{
    $regs = [];
    for ($i = 1; $i <= $count; $i++) {
        $regs[] = chrt_register(chrt_person('CHRT Ath '.$i), $event, $category, $class);
    }

    return $regs;
}

function chrt_save_format(Event $event, CompetitionClass $class, int $round, int $participants, int $qualifiers, int $min = 2): CompetitionHeatFormat
{
    return app(CompetitionHeatManagerService::class)->upsertFormat(
        $event->id,
        $class->id,
        $round,
        $participants,
        $qualifiers,
        $min,
    );
}

function chrt_generate(Event $event, CompetitionClass $class, int $round): array
{
    return app(CompetitionHeatManagerService::class)->generateRound($event->id, $class->id, $round);
}

function chrt_round_schedules(CompetitionClass $class, int $round)
{
    return app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, $round);
}

function chrt_heat_result(CompetitionSchedule $schedule, CompetitionRegistration $registration, ?float $seconds, ?string $status = 'Lolos'): CompetitionHeatResult
{
    return CompetitionHeatResult::updateOrCreate(
        ['competition_schedule_id' => $schedule->id, 'competition_registration_id' => $registration->id],
        ['score' => $seconds, 'status' => $status, 'position' => null],
    );
}

function chrt_first_heat(CompetitionClass $class, int $round): CompetitionSchedule
{
    return chrt_round_schedules($class, $round)->first();
}

// ---------------------------------------------------------------------------
// Sumber kebenaran: `required_participants` = kapasitas (bukan syarat start),
// `min_participants_to_start` (format heat) = syarat agar bisa dimainkan.
// ---------------------------------------------------------------------------

test('1. capacity 5 / actual 5 -> Ready dan bisa start (min default 2)', function () {
    $event = chrt_event();
    $category = chrt_category($event);
    $class = chrt_class($event, $category);
    chrt_register_many($event, $category, $class, 5);

    chrt_save_format($event, $class, 1, 5, 2);
    $result = chrt_generate($event, $class, 1);

    expect($result['generated'])->toBeTrue()
        ->and(chrt_round_schedules($class, 1))->toHaveCount(1);

    $heat = chrt_first_heat($class, 1);

    expect($heat->required_participants)->toBe(5)
        ->and($heat->minParticipantsToStart())->toBe(2)
        ->and($heat->status)->toBe('Ready')
        ->and($heat->isReadyForStart())->toBeTrue();
});

test('2. capacity 5 / actual 4 -> Ready & bisa start (contoh wajib UAT)', function () {
    $event = chrt_event();
    $category = chrt_category($event);
    $class = chrt_class($event, $category);
    chrt_register_many($event, $category, $class, 4);
    chrt_save_format($event, $class, 1, 5, 2);

    chrt_generate($event, $class, 1);

    $heat = chrt_first_heat($class, 1);

    expect($heat->scheduleEntries()->count())->toBe(4)
        ->and($heat->required_participants)->toBe(5)
        ->and($heat->minParticipantsToStart())->toBe(2)
        ->and($heat->status)->toBe('Ready')
        ->and($heat->isReadyForStart())->toBeTrue()
        ->and(app(CompetitionWorkflowService::class)->startMatch($heat->refresh()))->toBeTrue()
        ->and($heat->fresh()->status)->toBe('Playing');
});

test('3. capacity 5 / actual 3 -> Ready (masih >= min start)', function () {
    $event = chrt_event();
    $category = chrt_category($event);
    $class = chrt_class($event, $category);
    chrt_register_many($event, $category, $class, 3);
    chrt_save_format($event, $class, 1, 5, 2);

    chrt_generate($event, $class, 1);

    $heat = chrt_first_heat($class, 1);

    expect($heat->scheduleEntries()->count())->toBe(3)
        ->and($heat->status)->toBe('Ready')
        ->and($heat->isReadyForStart())->toBeTrue();
});

test('4. capacity 5 / actual 2 -> Ready (persis di batas min 2)', function () {
    $event = chrt_event();
    $category = chrt_category($event);
    $class = chrt_class($event, $category);
    chrt_register_many($event, $category, $class, 2);
    chrt_save_format($event, $class, 1, 5, 2);

    chrt_generate($event, $class, 1);

    $heat = chrt_first_heat($class, 1);

    expect($heat->scheduleEntries()->count())->toBe(2)
        ->and($heat->status)->toBe('Ready')
        ->and($heat->isReadyForStart())->toBeTrue();
});

test('5. capacity 5 / actual 1 -> Scheduled (di bawah min start)', function () {
    $event = chrt_event();
    $category = chrt_category($event);
    $class = chrt_class($event, $category);
    chrt_register_many($event, $category, $class, 1);
    chrt_save_format($event, $class, 1, 5, 2);

    chrt_generate($event, $class, 1);

    $heat = chrt_first_heat($class, 1);

    expect($heat->scheduleEntries()->count())->toBe(1)
        ->and($heat->status)->toBe('Scheduled')
        ->and($heat->canAutoReady())->toBeFalse()
        ->and($heat->isReadyForStart())->toBeFalse();
});

test('6. capacity 5 / actual 0 -> Scheduled; min tetap 2 & kapasitas tetap 5', function () {
    $event = chrt_event();
    $category = chrt_category($event);
    $class = chrt_class($event, $category);
    chrt_save_format($event, $class, 1, 5, 2);

    $heat = CompetitionSchedule::create([
        'competition_class_id' => $class->id,
        'status' => 'Scheduled',
        'required_participants' => 5,
        'sort_order' => 101,
    ]);

    expect($heat->scheduleEntries()->count())->toBe(0)
        ->and($heat->required_participants)->toBe(5)
        ->and($heat->minParticipantsToStart())->toBe(2)
        ->and($heat->canAutoReady())->toBeFalse()
        ->and($heat->isReadyForStart())->toBeFalse();
});

test('7. UAT penuh pada heat 4/5: Ready -> Start -> Playing -> Waiting Result -> Finish -> Rank (tanpa menunggu heat penuh)', function () {
    $event = chrt_event();
    $category = chrt_category($event);
    $class = chrt_class($event, $category);
    $regs = chrt_register_many($event, $category, $class, 4);
    chrt_save_format($event, $class, 1, 5, 2);

    chrt_generate($event, $class, 1);

    $heat = chrt_first_heat($class, 1);
    $workflow = app(CompetitionWorkflowService::class);

    expect($heat->status)->toBe('Ready');

    expect($workflow->startMatch($heat))->toBeTrue()
        ->and($heat->fresh()->status)->toBe('Playing');

    expect($workflow->moveToWaitingResult($heat->refresh()))->toBeTrue()
        ->and($heat->fresh()->status)->toBe('Waiting Result');

    expect($workflow->completeMatch($heat->refresh()))->toBe('Finished')
        ->and($heat->fresh()->status)->toBe('Finished');

    $time = 60.0;
    foreach ($regs as $reg) {
        chrt_heat_result($heat->fresh(), $reg, $time++, 'Lolos');
    }

    $ranked = app(CompetitionMultiRoundHeatService::class)->rankHeat($event->id, $heat->id);

    expect($ranked['ranked'])->toBeTrue()
        ->and($ranked['rows'])->toHaveCount(4)
        ->and($ranked['rows'][0]['position'])->toBe(1)
        ->and(CompetitionHeatResult::where('competition_schedule_id', $heat->id)->whereNotNull('position')->count())->toBe(4);
});

test('8. non-heat (mass) tetap berperilaku lama: min == required_participants, 3/5 tidak auto Ready', function () {
    $event = chrt_event();
    $category = chrt_category($event);
    $class = chrt_class($event, $category, 'individual_mass');
    $regs = chrt_register_many($event, $category, $class, 3);

    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $class->id,
        'status' => 'Scheduled',
        'required_participants' => 5,
        'sort_order' => 101,
    ]);

    foreach ($regs as $i => $reg) {
        CompetitionScheduleEntry::create([
            'competition_schedule_id' => $schedule->id,
            'competition_registration_id' => $reg->id,
            'order_number' => $i + 1,
        ]);
    }

    // Format non-heat tidak punya min_participants_to_start → min = kapasitas.
    expect($schedule->minParticipantsToStart())->toBe(5)
        ->and($schedule->canAutoReady())->toBeFalse()
        ->and($schedule->status)->toBe('Scheduled');
});

test('9. min start bisa dikonfigurasi per format (min 1): 1/5 Ready, 0/5 tetap Scheduled', function () {
    $event = chrt_event();
    $category = chrt_category($event);
    $class = chrt_class($event, $category);
    chrt_save_format($event, $class, 1, 5, 2, min: 1);

    $one = CompetitionSchedule::create([
        'competition_class_id' => $class->id,
        'status' => 'Scheduled',
        'required_participants' => 5,
        'sort_order' => 101,
    ]);
    CompetitionScheduleEntry::create([
        'competition_schedule_id' => $one->id,
        'competition_registration_id' => chrt_register(chrt_person('CHRT Solo'), $event, $category, $class)->id,
    ]);

    $zero = CompetitionSchedule::create([
        'competition_class_id' => $class->id,
        'status' => 'Scheduled',
        'required_participants' => 5,
        'sort_order' => 102,
    ]);

    app(CompetitionWorkflowService::class)->checkAutoReady($one->refresh());
    app(CompetitionWorkflowService::class)->checkAutoReady($zero->refresh());

    expect($one->fresh()->status)->toBe('Ready')
        ->and($zero->fresh()->status)->toBe('Scheduled');

    // Validasi: min tidak boleh 0 atau melebihi kapasitas.
    expect(fn () => chrt_save_format($event, $class, 2, 5, 2, min: 0))->toThrow(ValidationException::class);
});

test('10. splitting non-penuh valid: 9 -> [5,4] dua-duanya Ready; next round terisi 4 dan lanjut Ready', function () {
    $event = chrt_event();
    $category = chrt_category($event);
    $class = chrt_class($event, $category);
    $regs = chrt_register_many($event, $category, $class, 9);
    chrt_save_format($event, $class, 1, 5, 2);
    chrt_save_format($event, $class, 2, 4, 2, min: 2);

    $result = chrt_generate($event, $class, 1);

    expect($result['heat_count'])->toBe(2);

    $heats = chrt_round_schedules($class, 1);

    expect($heats->map(fn ($h) => (int) $h->required_participants)->all())->toBe([5, 5])
        ->and($heats->map(fn ($h) => $h->scheduleEntries()->count())->all())->toBe([5, 4])
        ->and($heats->every(fn ($h) => $h->status === 'Ready'))->toBeTrue()
        ->and($heats->every(fn ($h) => $h->isReadyForStart()))->toBeTrue();

    $time = 60.0;
    foreach ($heats as $heat) {
        foreach ($heat->scheduleEntries()->pluck('competition_registration_id') as $regId) {
            chrt_heat_result($heat, CompetitionRegistration::find($regId), $time++, 'Lolos');
        }
        app(CompetitionWorkflowService::class)->completeMatch($heat->refresh());
    }

    $adv = app(CompetitionHeatManagerService::class)->generateNextRound($event->id, $class->id, 1);

    expect($adv['advanced'])->toBeTrue()
        ->and($adv['qualifiers'])->toBe(4)
        ->and($adv['assigned'])->toBe(4);

    $next = chrt_round_schedules($class, 2)->first();

    expect($next->scheduleEntries()->count())->toBe(4)
        ->and($next->required_participants)->toBe(4)
        ->and($next->minParticipantsToStart())->toBe(2)
        ->and($next->status)->toBe('Ready');
});

// ---------------------------------------------------------------------------
// Status Heat: under-capacity (>= min start) TIDAK boleh diturunkan ke
// Scheduled oleh rekonsiliasi. Kapasitas bukan syarat start.
// ---------------------------------------------------------------------------

test('11. under-capacity heat (>= min start) tetap Ready setelah checkAutoReady', function () {
    $event = chrt_event();
    $category = chrt_category($event);
    $class = chrt_class($event, $category);
    chrt_register_many($event, $category, $class, 10);

    // 10 / capacity 4 → 3 heat, min start 2 (default).
    chrt_save_format($event, $class, 1, 4, 2);
    chrt_generate($event, $class, 1);

    $heats = chrt_round_schedules($class, 1);

    expect($heats->map(fn ($h) => $h->scheduleEntries()->count())->all())->toBe([4, 3, 3])
        ->and($heats->every(fn ($h) => $h->status === 'Ready'))->toBeTrue();

    $workflow = app(CompetitionWorkflowService::class);

    foreach ($heats as $heat) {
        $workflow->checkAutoReady($heat->refresh());
    }

    $refreshed = $heats->map(fn ($heat) => $heat->fresh());

    // Heat yang terisi >= min start tetap Ready meski di bawah kapasitas.
    expect($refreshed->every(fn ($h) => $h->status === 'Ready'))->toBeTrue()
        ->and($refreshed->every(fn ($h) => $h->isReadyForStart()))->toBeTrue();
});

test('12. heat di bawah min start tetap diturunkan/dibiarkan Scheduled (state machine)', function () {
    $event = chrt_event();
    $category = chrt_category($event);
    $class = chrt_class($event, $category);
    chrt_save_format($event, $class, 1, 5, 2);

    // 1 peserta < min start 2 → tidak boleh Ready.
    $heat = CompetitionSchedule::create([
        'competition_class_id' => $class->id,
        'status' => 'Ready', // sengaja salah: rekonsiliasi harus menurunkannya
        'required_participants' => 5,
        'sort_order' => 101,
    ]);
    CompetitionScheduleEntry::create([
        'competition_schedule_id' => $heat->id,
        'competition_registration_id' => chrt_register(chrt_person('CHRT Single'), $event, $category, $class)->id,
    ]);

    app(CompetitionWorkflowService::class)->checkAutoReady($heat->refresh());

    expect($heat->fresh()->status)->toBe('Scheduled');
});

test('13. distribuksi seimbang: heat 3/4 (>= min 2) Ready, bukan Scheduled', function () {
    $event = chrt_event();
    $category = chrt_category($event);
    $class = chrt_class($event, $category);
    chrt_register_many($event, $category, $class, 3);
    chrt_save_format($event, $class, 1, 4, 2);

    chrt_generate($event, $class, 1);

    $heat = chrt_first_heat($class, 1);

    expect($heat->required_participants)->toBe(4)
        ->and($heat->scheduleEntries()->count())->toBe(3)
        ->and($heat->minParticipantsToStart())->toBe(2)
        ->and($heat->status)->toBe('Ready');

    app(CompetitionWorkflowService::class)->checkAutoReady($heat->refresh());

    expect($heat->fresh()->status)->toBe('Ready');
});
