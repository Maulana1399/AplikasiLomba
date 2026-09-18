<?php

use App\Enums\Role;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionHeatFormat;
use App\Models\CompetitionHeatResult;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionSchedule;
use App\Models\CompetitionScheduleEntry;
use App\Models\CompetitionTeam;
use App\Models\Event;
use App\Models\kelompok;
use App\Models\Person;
use App\Models\User;
use App\Services\Competition\CompetitionHeatManagerService;
use App\Services\Competition\CompetitionMultiRoundHeatService;
use App\Services\Competition\CompetitionRegistrationService;
use App\Services\Competition\CompetitionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function tqn_event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'TQN Event '.str()->random(6),
        'slug' => 'tqn-'.str()->random(6),
        'status' => 'active',
        'event_type' => 'competition',
    ], $overrides));
}

function tqn_category(Event $event): CompetitionCategory
{
    $category = CompetitionCategory::create(['event_id' => $event->id, 'name' => 'TQN Cat '.str()->random(4)]);


    return $category;
}

function tqn_class(Event $event, CompetitionCategory $category, string $format = 'individual_heat', string $resultType = 'time'): CompetitionClass
{
    return CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'TQN Class '.str()->random(4),
        'gender' => 'M',
        'format' => $format,
        'status' => 'registration_open',
        'result_type' => $resultType,
        'is_active' => true,
    ]);
}

function tqn_person(string $nama, ?kelompok $kelompok = null): Person
{
    return Person::create(['nama' => $nama, 'jenis_kelamin' => 'L', 'kelompok_id' => $kelompok?->id]);
}

function tqn_register(Person $person, Event $event, CompetitionCategory $category, CompetitionClass $class): CompetitionRegistration
{
    return app(CompetitionRegistrationService::class)->registerForPerson(
        person: $person,
        eventId: $event->id,
        competitionCategoryId: $category->id,
        competitionClassId: $class->id,
    )['competition_registration'];
}

function tqn_register_many(Event $event, CompetitionCategory $category, CompetitionClass $class, int $count): array
{
    $regs = [];
    for ($i = 1; $i <= $count; $i++) {
        $regs[] = tqn_register(tqn_person('TQN P'.$i), $event, $category, $class);
    }

    return $regs;
}

function tqn_kelompok(string $name): kelompok
{
    return kelompok::create(['kelompok_asal' => $name]);
}

function tqn_team(Event $event, CompetitionClass $class, string $name): CompetitionTeam
{
    return CompetitionTeam::create([
        'event_id' => $event->id,
        'competition_class_id' => $class->id,
        'name' => $name,
        'kelompok_id' => tqn_kelompok('TQN K ')->id,
        'is_active' => true,
    ]);
}

function tqn_heat(CompetitionClass $class, int $sortOrder, int $capacity, string $status = 'Waiting Result'): CompetitionSchedule
{
    return CompetitionSchedule::create([
        'competition_class_id' => $class->id,
        'status' => $status,
        'required_participants' => $capacity,
        'sort_order' => $sortOrder,
    ]);
}

function tqn_entry(CompetitionSchedule $schedule, CompetitionRegistration $registration, int $order = 1): void
{
    CompetitionScheduleEntry::create([
        'competition_schedule_id' => $schedule->id,
        'competition_registration_id' => $registration->id,
        'order_number' => $order,
    ]);
}

function tqn_team_entry(CompetitionSchedule $schedule, CompetitionTeam $team, int $order = 1): void
{
    CompetitionScheduleEntry::create([
        'competition_schedule_id' => $schedule->id,
        'competition_team_id' => $team->id,
        'order_number' => $order,
    ]);
}

function tqn_result(CompetitionSchedule $schedule, CompetitionRegistration $registration, ?float $score, string $status = 'Lolos'): void
{
    CompetitionHeatResult::updateOrCreate(
        ['competition_schedule_id' => $schedule->id, 'competition_registration_id' => $registration->id],
        ['score' => $score, 'status' => $status, 'position' => null],
    );
}

function tqn_team_result(CompetitionSchedule $schedule, CompetitionTeam $team, ?float $score, string $status = 'Lolos'): void
{
    CompetitionHeatResult::updateOrCreate(
        ['competition_schedule_id' => $schedule->id, 'competition_team_id' => $team->id],
        ['score' => $score, 'status' => $status, 'position' => null],
    );
}

function tqn_save_format(Event $event, CompetitionClass $class, int $round, int $participants, int $qualifiers, int $min = 2): CompetitionHeatFormat
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

function tqn_generate(Event $event, CompetitionClass $class, int $round): array
{
    return app(CompetitionHeatManagerService::class)->generateRound($event->id, $class->id, $round);
}

function tqn_rank_and_qualify(Event $event, CompetitionSchedule $heat, int $topN): array
{
    $service = app(CompetitionMultiRoundHeatService::class);
    $service->rankHeat($event->id, $heat->id);

    return $service->qualifyHeat($event->id, $heat->id, $topN);
}

// ---------------------------------------------------------------------------
// Top-N PER HEAT — status `Lolos` tidak boleh mengabaikan batas Top-N.
// ---------------------------------------------------------------------------

test('A. 4 peserta, semua Lolos, Top 3 -> EXACTLY 3 qualified (rank 4 tidak lolos)', function () {
    $event = tqn_event();
    $category = tqn_category($event);
    $class = tqn_class($event, $category);
    [$a, $b, $c, $d] = tqn_register_many($event, $category, $class, 4);
    $heat = tqn_heat($class, 101, 4);

    foreach ([$a, $b, $c, $d] as $i => $reg) {
        tqn_entry($heat, $reg, $i + 1);
        tqn_result($heat, $reg, 60.0 + $i, 'Lolos');
    }

    $q = tqn_rank_and_qualify($event, $heat, 3);

    // a=60 (tercepat) rank1, b=61 rank2, c=62 rank3, d=63 rank4 → hanya 3.
    expect($q['qualified'])->toBeTrue()
        ->and($q['qualified_count'])->toBe(3)
        ->and($q['qualifiers'])->toHaveCount(3)
        ->and($q['qualifiers'])->not->toContain((int) $d->id)
        ->and($q['qualifiers'])->toContain((int) $a->id)
        ->and($q['qualifiers'])->toContain((int) $b->id)
        ->and($q['qualifiers'])->toContain((int) $c->id);
});

test('B. 2 heat x 4 peserta, masing-masing Top 3, semua Lolos -> EXACTLY 6 qualified', function () {
    $event = tqn_event();
    $category = tqn_category($event);
    $class = tqn_class($event, $category);
    $regs = tqn_register_many($event, $category, $class, 8);

    $h1 = tqn_heat($class, 101, 4);
    $h2 = tqn_heat($class, 102, 4);

    foreach (array_slice($regs, 0, 4) as $i => $reg) {
        tqn_entry($h1, $reg, $i + 1);
        tqn_result($h1, $reg, 60.0 + $i, 'Lolos');
    }
    foreach (array_slice($regs, 4, 4) as $i => $reg) {
        tqn_entry($h2, $reg, $i + 1);
        tqn_result($h2, $reg, 70.0 + $i, 'Lolos');
    }

    $service = app(CompetitionMultiRoundHeatService::class);
    $pool = $service->qualifiedPool($event->id, $class->id, 1, 3);

    expect($pool['qualified_count'])->toBe(6)
        ->and(collect($pool['heats'])->pluck('advanced')->all())->toBe([3, 3])
        ->and($pool['qualifiers'])->toHaveCount(6);
});

test('C. court: rank 4 tetap TIDAK advance walau Lolos; bahkan saat seri position [1,1,1,1] cap Top 3 tetap 3', function () {
    $event = tqn_event();
    $category = tqn_category($event);
    $class = tqn_class($event, $category);
    $regs = tqn_register_many($event, $category, $class, 4);
    $heat = tqn_heat($class, 101, 4);

    // Semua skor SAMA → seri position [1,1,1,1] (ranking engine tidak diubah).
    foreach ($regs as $i => $reg) {
        tqn_entry($heat, $reg, $i + 1);
        tqn_result($heat, $reg, 60.0, 'Lolos');
    }

    $service = app(CompetitionMultiRoundHeatService::class);
    $service->rankHeat($event->id, $heat->id);

    expect($heat->heatResults->pluck('position')->all())->toBe([1, 1, 1, 1]);

    $q = $service->qualifyHeat($event->id, $heat->id, 3);

    expect($q['qualified_count'])->toBe(3)
        ->and($q['qualifiers'])->toHaveCount(3);
});

test('D. time result_type: ranking naik-benar (tercepat menang) + Top-N tepat', function () {
    $event = tqn_event();
    $category = tqn_category($event);
    $class = tqn_class($event, $category, 'individual_heat', 'time');
    [$a, $b, $c, $d] = tqn_register_many($event, $category, $class, 4);
    $heat = tqn_heat($class, 101, 4);

    // a=90, b=100, c=80, d=70 (detik; 70 = tercepat) semua Lolos, Top 2.
    $scores = [$a->id => 90.0, $b->id => 100.0, $c->id => 80.0, $d->id => 70.0];
    $i = 0;
    foreach ([$a, $b, $c, $d] as $reg) {
        tqn_entry($heat, $reg, ++$i);
        tqn_result($heat, $reg, $scores[$reg->id], 'Lolos');
    }

    $q = tqn_rank_and_qualify($event, $heat, 2);

    expect($q['qualified_count'])->toBe(2)
        ->and($q['qualifiers'])->toBe([(int) $d->id, (int) $c->id]);
});

test('E. score result_type: ranking turun-benar (skor tertinggi menang) + Top-N tepat', function () {
    $event = tqn_event();
    $category = tqn_category($event);
    $class = tqn_class($event, $category, 'individual_heat', 'score');
    [$a, $b, $c, $d] = tqn_register_many($event, $category, $class, 4);
    $heat = tqn_heat($class, 101, 4);

    // a=10, b=20, c=30, d=40 (skor; 40 = tertinggi menang) semua Lolos, Top 2.
    $scores = [$a->id => 10.0, $b->id => 20.0, $c->id => 30.0, $d->id => 40.0];
    $i = 0;
    foreach ([$a, $b, $c, $d] as $reg) {
        tqn_entry($heat, $reg, ++$i);
        tqn_result($heat, $reg, $scores[$reg->id], 'Lolos');
    }

    $q = tqn_rank_and_qualify($event, $heat, 2);

    expect($q['qualified_count'])->toBe(2)
        ->and($q['qualifiers'])->toBe([(int) $d->id, (int) $c->id]);
});

test('F. status non-normal (Diskualifikasi/DSQ) tidak masuk qualified pool walaupun ranking terbaik', function () {
    $event = tqn_event();
    $category = tqn_category($event);
    $class = tqn_class($event, $category);
    [$a, $b, $c, $d] = tqn_register_many($event, $category, $class, 4);
    $heat = tqn_heat($class, 101, 4);

    $scores = [$a->id => 70.0, $b->id => 80.0, $c->id => 90.0, $d->id => 100.0];
    $statuses = [$a->id => 'Lolos', $b->id => 'Lolos', $c->id => 'Diskualifikasi', $d->id => 'Lolos'];
    $i = 0;
    foreach ([$a, $b, $c, $d] as $reg) {
        tqn_entry($heat, $reg, ++$i);
        tqn_result($heat, $reg, $scores[$reg->id], $statuses[$reg->id]);
    }

    $q = tqn_rank_and_qualify($event, $heat, 3);

    expect($q['qualified_count'])->toBe(3)
        ->and($q['qualifiers'])->not->toContain((int) $c->id)
        ->and($q['qualifiers'])->toContain((int) $a->id)
        ->and($q['qualifiers'])->toContain((int) $b->id)
        ->and($q['qualifiers'])->toContain((int) $d->id);
});

test('G. underfilled heat (1 peserta) memakai source-of-truth: boleh qualify 1 (heat lengkap, rank 1, dalam Top-N)', function () {
    $event = tqn_event();
    $category = tqn_category($event);
    $class = tqn_class($event, $category);
    [$a] = tqn_register_many($event, $category, $class, 1);
    $heat = tqn_heat($class, 101, 4);

    tqn_entry($heat, $a, 1);
    tqn_result($heat, $a, 60.0, 'Lolos');

    $q = tqn_rank_and_qualify($event, $heat, 3);

    expect($q['qualified'])->toBeTrue()
        ->and($q['qualified_count'])->toBe(1)
        ->and($q['qualifiers'])->toBe([(int) $a->id]);

    // Gabungan heat [4,4,1] top 3 → 3 + 3 + 1 = 7 (bukan 9).
    $h2 = tqn_heat($class, 102, 4);
    $h3 = tqn_heat($class, 103, 4);
    [$b, $c, $d, $e] = tqn_register_many($event, $category, $class, 4);
    foreach ([$b, $c, $d, $e] as $i => $reg) {
        tqn_entry($h2, $reg, $i + 1);
        tqn_result($h2, $reg, 80.0 + $i, 'Lolos');
    }
    [$f, $g, $h] = tqn_register_many($event, $category, $class, 3);
    foreach ([$f, $g, $h] as $i => $reg) {
        tqn_entry($h3, $reg, $i + 1);
        tqn_result($h3, $reg, 100.0 + $i, 'Lolos');
    }

    $svc = app(CompetitionMultiRoundHeatService::class);
    $pool = $svc->qualifiedPool($event->id, $class->id, 1, 3);

    // [1,4,4] peserta → 1 + 3 + 3 = 7 (selalu cap Top-N per heat).
    expect($pool['qualified_count'])->toBe(7)
        ->and($pool['qualified_count'])->not->toBe(9);
});

test('H. multi-round: R1 -> R2 -> R3 konsisten, Top-N PER HEAT di tiap round', function () {
    $event = tqn_event();
    $category = tqn_category($event);
    $class = tqn_class($event, $category);
    tqn_register_many($event, $category, $class, 8);
    tqn_save_format($event, $class, 1, 4, 2);
    tqn_save_format($event, $class, 2, 4, 2);
    tqn_save_format($event, $class, 3, 2, 1);

    tqn_generate($event, $class, 1);

    $service = app(CompetitionMultiRoundHeatService::class);
    $manager = app(CompetitionHeatManagerService::class);

    $round1 = $service->roundSchedules($class->id, 1);

    expect($round1->count())->toBe(2);

    $time = 60.0;
    foreach ($round1 as $heat) {
        foreach ($heat->scheduleEntries()->pluck('competition_registration_id') as $regId) {
            tqn_result($heat, CompetitionRegistration::find($regId), $time++, 'Lolos');
        }
        app(CompetitionWorkflowService::class)->completeMatch($heat->refresh());
    }

    $adv1 = $manager->generateNextRound($event->id, $class->id, 1);

    expect($adv1['qualifiers'])->toBe(4)
        ->and($adv1['assigned'])->toBe(4);

    $round2 = $service->roundSchedules($class->id, 2);

    expect($round2)->toHaveCount(1)
        ->and($round2->first()->scheduleEntries()->count())->toBe(4);

    $time = 200.0;
    foreach ($round2 as $heat) {
        foreach ($heat->scheduleEntries()->pluck('competition_registration_id') as $regId) {
            tqn_result($heat, CompetitionRegistration::find($regId), $time++, 'Lolos');
        }
        app(CompetitionWorkflowService::class)->completeMatch($heat->refresh());
    }

    $adv2 = $manager->generateNextRound($event->id, $class->id, 2);

    // R2 hanya 1 heat Top 2 → 2 lolos, bukan lebih.
    expect($adv2['qualifiers'])->toBe(2)
        ->and($adv2['assigned'])->toBe(2);

    $round3 = $service->roundSchedules($class->id, 3);

    expect($round3)->toHaveCount(1)
        ->and($round3->first()->scheduleEntries()->count())->toBe(2);
});

test('I. Top-N berasal dari konfigurasi (qualifiers_per_heat), bukan hardcode', function () {
    $event = tqn_event();
    $category = tqn_category($event);
    $class = tqn_class($event, $category);
    tqn_register_many($event, $category, $class, 8);
    tqn_save_format($event, $class, 1, 4, 3);
    tqn_save_format($event, $class, 2, 4, 3);

    tqn_generate($event, $class, 1);

    $service = app(CompetitionMultiRoundHeatService::class);
    $round1 = $service->roundSchedules($class->id, 1);

    $time = 60.0;
    foreach ($round1 as $heat) {
        foreach ($heat->scheduleEntries()->pluck('competition_registration_id') as $regId) {
            tqn_result($heat, CompetitionRegistration::find($regId), $time++, 'Lolos');
        }
        app(CompetitionWorkflowService::class)->completeMatch($heat->refresh());
    }

    // Format menetapkan Top 3 PER HEAT → 2 heat x 3 = 6, bukan 8.
    $pool = $service->qualifiedPool($event->id, $class->id, 1, 3);

    expect($pool['qualified_count'])->toBe(6)
        ->and(collect($pool['heats'])->pluck('advanced')->all())->toBe([3, 3]);
});

test('J. team heat tidak regression: Top-N per heat tetap (individual & team sama, competition_team_id dipakai)', function () {
    $event = tqn_event();
    $category = tqn_category($event);
    $class = tqn_class($event, $category, 'team_heat', 'time');

    $h1 = tqn_heat($class, 101, 4);
    $h2 = tqn_heat($class, 102, 4);

    $teams1 = collect([tqn_team($event, $class, 'TQN T1A'), tqn_team($event, $class, 'TQN T1B'), tqn_team($event, $class, 'TQN T1C')]);
    $teams2 = collect([tqn_team($event, $class, 'TQN T2A'), tqn_team($event, $class, 'TQN T2B'), tqn_team($event, $class, 'TQN T2C')]);

    $teams1->each(function ($team, $i) use ($h1) {
        tqn_team_entry($h1, $team, $i + 1);
        tqn_team_result($h1, $team, 60.0 + $i, 'Lolos');
    });
    $teams2->each(function ($team, $i) use ($h2) {
        tqn_team_entry($h2, $team, $i + 1);
        tqn_team_result($h2, $team, 70.0 + $i, 'Lolos');
    });

    $service = app(CompetitionMultiRoundHeatService::class);

    $q1 = tqn_rank_and_qualify($event, $h1, 2);
    $q2 = tqn_rank_and_qualify($event, $h2, 2);

    expect($q1['qualified_count'])->toBe(2)
        ->and($q2['qualified_count'])->toBe(2);

    $pool = $service->qualifiedPool($event->id, $class->id, 1, 2);

    expect($pool['qualified_count'])->toBe(4)
        ->and(collect($pool['heats'])->pluck('advanced')->all())->toBe([2, 2]);

    // Tie-cap team: 3 team skor sama, Top 2 → tetap 2, bukan 3.
    $h3 = tqn_heat($class, 103, 4);
    $ties = collect([tqn_team($event, $class, 'TQN T3A'), tqn_team($event, $class, 'TQN T3B'), tqn_team($event, $class, 'TQN T3C')]);
    $ties->each(function ($team, $i) use ($h3) {
        tqn_team_entry($h3, $team, $i + 1);
        tqn_team_result($h3, $team, 80.0, 'Lolos');
    });

    $service->rankHeat($event->id, $h3->id);
    $q3 = $service->qualifyHeat($event->id, $h3->id, 2);

    expect($q3['qualified_count'])->toBe(2);
});

test('K. status Lolos tetap tersimpan di competition_heat_results setelah qualification', function () {
    $event = tqn_event();
    $category = tqn_category($event);
    $class = tqn_class($event, $category);
    $regs = tqn_register_many($event, $category, $class, 4);
    $heat = tqn_heat($class, 101, 4);

    foreach ($regs as $i => $reg) {
        tqn_entry($heat, $reg, $i + 1);
        tqn_result($heat, $reg, 60.0 + $i, 'Lolos');
    }

    tqn_rank_and_qualify($event, $heat, 3);

    $saved = CompetitionHeatResult::where('competition_schedule_id', $heat->id)
        ->get()
        ->pluck('status');

    expect($saved)->toHaveCount(4)
        ->and($saved->every(fn ($s) => $s === 'Lolos'))->toBeTrue()
        ->and(CompetitionHeatResult::where('competition_schedule_id', $heat->id)->whereNotNull('position')->count())->toBe(4);
});

test('L. Livewire OutcomeManager memakai Top-N dari format, bukan hardcode', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $this->actingAs($admin);

    $event = tqn_event();
    app(\App\Support\ActiveEventContext::class)->set($event);

    $category = tqn_category($event);
    $class = tqn_class($event, $category);
    $regs = tqn_register_many($event, $category, $class, 4);
    $heat = tqn_heat($class, 101, 4);

    foreach ($regs as $i => $reg) {
        tqn_entry($heat, $reg, $i + 1);
        tqn_result($heat, $reg, 60.0 + $i, 'Lolos');
    }

    tqn_save_format($event, $class, 1, 4, 3);

    $component = \Livewire::test(\App\Livewire\Competition\Schedule\OutcomeManager::class, ['schedule' => $heat]);

    expect($component->get('formatTopN'))->toBe(3)
        ->and($component->get('round'))->toBe(1);

    $component->call('advanceHeat')
        ->assertHasNoErrors();

    $qualified = CompetitionScheduleEntry::where('competition_schedule_id', $heat->id)->count();

    expect($qualified)->toBe(4)
        ->and(CompetitionHeatResult::where('competition_schedule_id', $heat->id)->count())->toBe(4)
        ->and($component->get('round'))->toBe(1);
});
