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
use App\Models\CompetitionTeamMember;
use App\Models\Event;
use App\Models\kelompok;
use App\Models\Person;
use App\Models\User;
use App\Services\Competition\CompetitionHeatManagerService;
use App\Services\Competition\CompetitionMultiRoundHeatService;
use App\Services\Competition\CompetitionRegistrationService;
use App\Support\ActiveEventContext;
use App\Support\CompetitionFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function th_event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'TH Event '.str()->random(6),
        'slug' => 'th-'.str()->random(6),
        'status' => 'active',
        'event_type' => 'competition',
    ], $overrides));
}

function th_category(Event $event): CompetitionCategory
{
    $category = CompetitionCategory::create(['event_id' => $event->id, 'name' => 'TH Cat '.str()->random(4)]);
    $category->events()->syncWithoutDetaching([$event->id]);

    return $category;
}

function th_class(Event $event, CompetitionCategory $category, string $format = CompetitionFormat::TEAM_HEAT, array $overrides = []): CompetitionClass
{
    return CompetitionClass::create(array_merge([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'TH Class '.str()->random(4),
        'gender' => 'M',
        'format' => $format,
        'result_type' => 'time',
        'is_active' => true,
    ], $overrides));
}

function th_person(string $nama, ?kelompok $kelompok = null): Person
{
    return Person::create(['nama' => $nama, 'jenis_kelamin' => 'L', 'kelompok_id' => $kelompok?->id]);
}

function th_register(Person $person, Event $event, CompetitionCategory $category, CompetitionClass $class): CompetitionRegistration
{
    return app(CompetitionRegistrationService::class)->registerForPerson(
        person: $person,
        eventId: $event->id,
        competitionCategoryId: $category->id,
        competitionClassId: $class->id,
    )['competition_registration'];
}

function th_team(Event $event, CompetitionClass $class, string $name): CompetitionTeam
{
    return CompetitionTeam::create([
        'event_id' => $event->id,
        'competition_class_id' => $class->id,
        'name' => $name,
        'kelompok_id' => th_kelompok('K')->id,
        'is_active' => true,
    ]);
}

function th_kelompok(string $name): kelompok
{
    return kelompok::create(['kelompok_asal' => $name]);
}

function th_add_member(CompetitionTeam $team, CompetitionRegistration $registration, bool $substitute = false, int $order = 1): CompetitionTeamMember
{
    return CompetitionTeamMember::create([
        'competition_team_id' => $team->id,
        'competition_registration_id' => $registration->id,
        'is_substitute' => $substitute,
        'sort_order' => $order,
    ]);
}

function th_save_format(Event $event, CompetitionClass $class, int $round, int $perHeat, int $qualifiers, int $min = 2): CompetitionHeatFormat
{
    return app(CompetitionHeatManagerService::class)->upsertFormat(
        $event->id,
        $class->id,
        $round,
        $perHeat,
        $qualifiers,
        $min,
    );
}

function th_team_heat_result(CompetitionSchedule $schedule, CompetitionTeam $team, ?float $seconds, ?string $status = null): CompetitionHeatResult
{
    return CompetitionHeatResult::updateOrCreate(
        ['competition_schedule_id' => $schedule->id, 'competition_team_id' => $team->id],
        ['score' => $seconds, 'status' => $status, 'position' => null],
    );
}

// ---------------------------------------------------------------------------
// A. TEAM_HEAT memakai jumlah Team, bukan jumlah participant.
// ---------------------------------------------------------------------------

test('A. TEAM_HEAT heat count uses number of teams, not number of members/registrations', function () {
    $event = th_event();
    $category = th_category($event);
    $class = th_class($event, $category, CompetitionFormat::TEAM_HEAT, ['team_size' => 4]);

    // 8 tim, masing-masing 4 anggota = 32 registrasi.
    foreach (range(1, 8) as $i) {
        $team = th_team($event, $class, "Tim {$i}");
        foreach (range(1, 4) as $j) {
            $reg = th_register(th_person("T{$i}M{$j}"), $event, $category, $class);
            th_add_member($team, $reg, false, $j);
        }
    }

    expect(CompetitionRegistration::where('competition_class_id', $class->id)->count())->toBe(32);
    expect(CompetitionTeam::where('competition_class_id', $class->id)->count())->toBe(8);

    th_save_format($event, $class, 1, 4, 2);

    $service = app(CompetitionHeatManagerService::class);

    expect($service->competitorCount($class->id))->toBe(8)
        ->and($service->computeHeatCount($class->id, 1))->toBe(2);

    $result = $service->generateRound($event->id, $class->id, 1);

    expect($result['generated'])->toBeTrue()
        ->and($result['heat_count'])->toBe(2);

    $heats = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1);

    expect($heats)->toHaveCount(2)
        ->and($heats->pluck('required_participants')->all())->toBe([4, 4]);
});

// ---------------------------------------------------------------------------
// B. team_size TIDAK memengaruhi jumlah Heat.
// ---------------------------------------------------------------------------

test('B. team_size does not affect heat count', function () {
    $event = th_event();
    $category = th_category($event);

    foreach ([1, 2, 4, 10] as $teamSize) {
        $class = th_class($event, $category, CompetitionFormat::TEAM_HEAT, ['team_size' => $teamSize]);
        foreach (range(1, 16) as $i) {
            th_team($event, $class, "TS{$teamSize} Tim {$i}");
        }

        th_save_format($event, $class, 1, 4, 2);

        $service = app(CompetitionHeatManagerService::class);

        expect($service->computeHeatCount($class->id, 1))->toBe(4);
    }
});

// ---------------------------------------------------------------------------
// C. 16 Team, 4 Team/Heat, 2 lolos → 4 Heat dan 8 Team qualifier.
// ---------------------------------------------------------------------------

test('C. 16 teams, 4 per heat, 2 advance -> 4 heats and 8 team qualifiers', function () {
    $event = th_event();
    $category = th_category($event);
    $class = th_class($event, $category, CompetitionFormat::TEAM_HEAT, ['team_size' => 4]);

    $teams = [];
    foreach (range(1, 16) as $i) {
        $teams[] = th_team($event, $class, "Tim {$i}");
    }

    th_save_format($event, $class, 1, 4, 2);
    th_save_format($event, $class, 2, 4, 2);

    $service = app(CompetitionHeatManagerService::class);
    $service->generateRound($event->id, $class->id, 1);

    $heats = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1);
    expect($heats)->toHaveCount(4);

    // Assign 4 tim per heat, lalu input hasil (fastest first) per heat.
    foreach ($heats as $h => $heat) {
        $chunk = array_slice($teams, $h * 4, 4);
        foreach ($chunk as $i => $team) {
            $service->assignTeamToHeat($event->id, $class->id, 1, $h + 1, $team->id);
        }
        $time = 60.0;
        foreach ($chunk as $team) {
            th_team_heat_result($heat, $team, $time++, 'Lolos');
        }
    }

    $result = $service->generateNextRound($event->id, $class->id, 1);

    expect($result['advanced'])->toBeTrue()
        ->and($result['qualifiers'])->toBe(8)
        ->and($result['heat_count'])->toBe(2);

    $nextHeats = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 2);

    expect($nextHeats)->toHaveCount(2)
        ->and($nextHeats->every(fn ($h) => $h->scheduleEntries()->count() === 4))->toBeTrue()
        ->and($nextHeats->every(fn ($h) => $h->scheduleEntries()->whereNotNull('competition_registration_id')->count() === 0))->toBeTrue()
        ->and($nextHeats->sum(fn ($h) => $h->scheduleEntries()->whereNotNull('competition_team_id')->count()))->toBe(8);
});

// ---------------------------------------------------------------------------
// D. minimum 2 Team → Heat tidak ready jika hanya 1 Team.
// ---------------------------------------------------------------------------

test('D. min 2 teams -> heat not ready with only 1 team', function () {
    $event = th_event();
    $category = th_category($event);
    $class = th_class($event, $category, CompetitionFormat::TEAM_HEAT);

    $teamA = th_team($event, $class, 'Tim A');
    $teamB = th_team($event, $class, 'Tim B');

    th_save_format($event, $class, 1, 4, 2, min: 2);

    $service = app(CompetitionHeatManagerService::class);
    $service->generateRound($event->id, $class->id, 1);

    $heat = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1)->first();

    $service->assignTeamToHeat($event->id, $class->id, 1, 1, $teamA->id);

    expect($heat->fresh()->scheduleEntries()->count())->toBe(1)
        ->and($heat->fresh()->status)->toBe('Scheduled')
        ->and($heat->fresh()->isReadyForStart())->toBeFalse();

    $service->assignTeamToHeat($event->id, $class->id, 1, 1, $teamB->id);

    expect($heat->fresh()->status)->toBe('Ready')
        ->and($heat->fresh()->isReadyForStart())->toBeTrue();
});

// ---------------------------------------------------------------------------
// E. qualifier tetap berupa CompetitionTeam.
// ---------------------------------------------------------------------------

test('E. qualifiers remain CompetitionTeam (no registration entries)', function () {
    $event = th_event();
    $category = th_category($event);
    $class = th_class($event, $category, CompetitionFormat::TEAM_HEAT);

    $teams = [];
    foreach (range(1, 8) as $i) {
        $teams[] = th_team($event, $class, "Tim {$i}");
    }

    th_save_format($event, $class, 1, 4, 2);
    th_save_format($event, $class, 2, 4, 2);

    $service = app(CompetitionHeatManagerService::class);
    $service->generateRound($event->id, $class->id, 1);

    $heats = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1);
    foreach ($heats as $h => $heat) {
        $chunk = array_slice($teams, $h * 4, 4);
        foreach ($chunk as $i => $team) {
            $service->assignTeamToHeat($event->id, $class->id, 1, $h + 1, $team->id);
        }
        $time = 60.0;
        foreach ($chunk as $team) {
            th_team_heat_result($heat, $team, $time++, 'Lolos');
        }
    }

    $service->generateNextRound($event->id, $class->id, 1);

    $next = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 2)->first();

    $teamIds = $next->scheduleEntries()->pluck('competition_team_id')->filter()->all();

    expect($teamIds)->not->toBeEmpty()
        ->and(CompetitionTeam::whereIn('id', $teamIds)->count())->toBe(count($teamIds));
});

// ---------------------------------------------------------------------------
// F. anggota dalam Team tidak dihitung sebagai slot Heat.
// ---------------------------------------------------------------------------

test('F. team members are not counted as heat slots', function () {
    $event = th_event();
    $category = th_category($event);
    $class = th_class($event, $category, CompetitionFormat::TEAM_HEAT);

    $team = th_team($event, $class, 'Tim Besar');
    foreach (range(1, 6) as $i) {
        $reg = th_register(th_person("M{$i}"), $event, $category, $class);
        th_add_member($team, $reg, false, $i);
    }

    th_save_format($event, $class, 1, 4, 2);

    $service = app(CompetitionHeatManagerService::class);
    $service->generateRound($event->id, $class->id, 1);

    $heat = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1)->first();

    // Satu tim (6 anggota) = satu slot, bukan 6 slot.
    $service->assignTeamToHeat($event->id, $class->id, 1, 1, $team->id);

    expect($heat->fresh()->scheduleEntries()->count())->toBe(1)
        ->and($heat->fresh()->scheduleEntries()->whereNotNull('competition_team_id')->count())->toBe(1)
        ->and($heat->fresh()->scheduleEntries()->whereNotNull('competition_registration_id')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// G. Heat tidak membuat Team baru.
// ---------------------------------------------------------------------------

test('G. heat generation/assignment does not create new teams', function () {
    $event = th_event();
    $category = th_category($event);
    $class = th_class($event, $category, CompetitionFormat::TEAM_HEAT);

    $teams = [];
    foreach (range(1, 8) as $i) {
        $teams[] = th_team($event, $class, "Tim {$i}");
    }

    th_save_format($event, $class, 1, 4, 2);

    $service = app(CompetitionHeatManagerService::class);
    $service->generateRound($event->id, $class->id, 1);
    $service->autoAssignRound($event->id, $class->id, 1);

    expect(CompetitionTeam::where('competition_class_id', $class->id)->count())->toBe(8);
    expect(CompetitionTeamMember::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// H. TEAM_VS_TEAM tidak rusak (formasi tim tetap jalan).
// ---------------------------------------------------------------------------

test('H. team_vs_team formation still works', function () {
    $event = th_event();
    $category = th_category($event);
    $class = th_class($event, $category, CompetitionFormat::TEAM_VS_TEAM, ['team_size' => 2]);

    $km = th_kelompok('KM VS');
    foreach (range(1, 4) as $i) {
        th_register(th_person("VS{$i}", $km), $event, $category, $class);
    }

    $result = app(\App\Services\Competition\CompetitionTeamFormationService::class)
        ->formBalancedForClass($event->id, $class->id);

    expect($result['team_count'])->toBe(2);
    expect(CompetitionTeam::where('competition_class_id', $class->id)->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// I. individual_heat tidak bisa dibuat lewat UI (sudah di SettingTest; guard format disini).
// ---------------------------------------------------------------------------

test('I. heat manager team assignment refuses individual_heat', function () {
    $event = th_event();
    $category = th_category($event);
    $class = th_class($event, $category, CompetitionFormat::INDIVIDUAL_HEAT);

    $person = th_person('Atlet A');
    th_register($person, $event, $category, $class);

    th_save_format($event, $class, 1, 4, 2);
    app(CompetitionHeatManagerService::class)->generateRound($event->id, $class->id, 1);

    $service = app(CompetitionHeatManagerService::class);
    $regId = CompetitionRegistration::first()->id;

    expect(fn () => $service->assignTeamToHeat($event->id, $class->id, 1, 1, $regId))
        ->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// J. preview (computeHeatCount) tetap DB-free (tidak menulis schedule/team).
// ---------------------------------------------------------------------------

test('J. computeHeatCount is a pure read (no DB writes)', function () {
    $event = th_event();
    $category = th_category($event);
    $class = th_class($event, $category, CompetitionFormat::TEAM_HEAT);

    foreach (range(1, 10) as $i) {
        th_team($event, $class, "Tim {$i}");
    }

    th_save_format($event, $class, 1, 4, 2);

    $schedulesBefore = CompetitionSchedule::count();
    $entriesBefore = CompetitionScheduleEntry::count();

    $count = app(CompetitionHeatManagerService::class)->computeHeatCount($class->id, 1);

    expect($count)->toBe(3)
        ->and(CompetitionSchedule::count())->toBe($schedulesBefore)
        ->and(CompetitionScheduleEntry::count())->toBe($entriesBefore);
});

// ---------------------------------------------------------------------------
// UI labels: format form memakai istilah Tim untuk TEAM_HEAT.
// ---------------------------------------------------------------------------

test('format form shows team-based labels for team_heat', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = th_event();
    app(ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = th_category($event);
    $class = th_class($event, $category, CompetitionFormat::TEAM_HEAT);

    Livewire::test(\App\Livewire\Competition\Heat\Index::class)
        ->set('selectedClassId', (string) $class->id)
        ->call('toggleFormatForm')
        ->assertSee('Tim per Heat')
        ->assertSee('Minimum Tim Untuk Start')
        ->assertSee('Tim Lolos per Heat')
        ->assertDontSee('Peserta per Heat');
});

// ---------------------------------------------------------------------------
// L. Rebuild path TEAM_HEAT — regresi UAT (3 Heat legacy -> 2 Heat).
// ---------------------------------------------------------------------------

function th_legacy_heats(CompetitionClass $class, int $count, int $capacity = 2): array
{
    $schedules = [];

    for ($i = 1; $i <= $count; $i++) {
        $schedules[] = CompetitionSchedule::create([
            'competition_class_id' => $class->id,
            'status' => 'Scheduled',
            'required_participants' => $capacity,
            'sort_order' => 100 + $i,
        ]);
    }

    return $schedules;
}

test('L1. UAT regression: 4 teams + legacy 3 heats -> rebuild via UI -> 2 heats -> auto distribute 2+2', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = th_event();
    app(ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = th_category($event);
    $class = th_class($event, $category, CompetitionFormat::TEAM_HEAT, ['team_size' => 4]);

    for ($i = 1; $i <= 4; $i++) {
        th_team($event, $class, "Tim {$i}");
    }

    // Legacy: 3 heat records (dari era individual_heat) dengan kapasitas 2.
    th_legacy_heats($class, 3, 2);

    th_save_format($event, $class, 1, 2, 1, min: 2);

    $service = app(CompetitionHeatManagerService::class);
    expect($service->needsRebuild($class->id, 1))->toBeTrue();

    $component = Livewire::test(\App\Livewire\Competition\Heat\Index::class);

    $component->set('selectedClassId', (string) $class->id)
        ->assertSee('Generate Ulang Babak Ini')
        ->call('rebuildRound', 1)
        ->assertDontSee('Generate Ulang Babak Ini');

    $heats = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1);

    expect($heats)->toHaveCount(2)
        ->and($heats->pluck('required_participants')->all())->toBe([2, 2])
        ->and($heats->every(fn ($h) => $h->scheduleEntries()->count() === 0))->toBeTrue();

    // Auto distribusi via UI — hanya mengisi heat yang ada, tidak membuat heat baru.
    $component->call('autoAssignTeams', 1);

    $heats = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1);

    expect($heats)->toHaveCount(2)
        ->and($heats->map(fn ($h) => $h->scheduleEntries()->count())->all())->toBe([2, 2])
        ->and($heats->sum(fn ($h) => $h->scheduleEntries()->whereNotNull('competition_team_id')->count()))->toBe(4);
});

test('L2. rebuild is refused when a heat already started or has results (protection)', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = th_event();
    app(ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = th_category($event);
    $class = th_class($event, $category, CompetitionFormat::TEAM_HEAT, ['team_size' => 4]);

    $teams = [];
    for ($i = 1; $i <= 4; $i++) {
        $teams[] = th_team($event, $class, "Tim {$i}");
    }

    $legacy = th_legacy_heats($class, 3, 2);
    th_save_format($event, $class, 1, 2, 1, min: 2);

    // Heat sudah punya hasil -> guard `has_results`.
    CompetitionHeatResult::create([
        'competition_schedule_id' => $legacy[0]->id,
        'competition_team_id' => $teams[0]->id,
        'score' => 60.0,
        'status' => 'Lolos',
    ]);

    Livewire::test(\App\Livewire\Competition\Heat\Index::class)
        ->set('selectedClassId', (string) $class->id)
        ->call('rebuildRound', 1)
        ->assertSee('hasil');

    expect(app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1))->toHaveCount(3)
        ->and(CompetitionHeatResult::count())->toBe(1);
});

test('L3. matching configuration does not require rebuild', function () {
    $event = th_event();
    $category = th_category($event);
    $class = th_class($event, $category, CompetitionFormat::TEAM_HEAT, ['team_size' => 4]);

    for ($i = 1; $i <= 4; $i++) {
        th_team($event, $class, "Tim {$i}");
    }

    th_save_format($event, $class, 1, 2, 1, min: 2);

    $service = app(CompetitionHeatManagerService::class);
    $service->generateRound($event->id, $class->id, 1);

    expect($service->needsRebuild($class->id, 1))->toBeFalse();
    expect(app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1))->toHaveCount(2);
});

test('L4. capacity mismatch alone triggers needs rebuild', function () {
    $event = th_event();
    $category = th_category($event);
    $class = th_class($event, $category, CompetitionFormat::TEAM_HEAT, ['team_size' => 4]);

    for ($i = 1; $i <= 4; $i++) {
        th_team($event, $class, "Tim {$i}");
    }

    // Dua heat legacy dengan kapasitas 4 (bukan 2).
    th_legacy_heats($class, 2, 4);
    th_save_format($event, $class, 1, 2, 1, min: 2);

    expect(app(CompetitionHeatManagerService::class)->needsRebuild($class->id, 1))->toBeTrue();
});

test('L5. autoAssignRound never creates new heats', function () {
    $event = th_event();
    $category = th_category($event);
    $class = th_class($event, $category, CompetitionFormat::TEAM_HEAT, ['team_size' => 4]);

    for ($i = 1; $i <= 4; $i++) {
        th_team($event, $class, "Tim {$i}");
    }

    th_save_format($event, $class, 1, 2, 1, min: 2);

    $service = app(CompetitionHeatManagerService::class);
    $service->generateRound($event->id, $class->id, 1);

    expect(app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1))->toHaveCount(2);

    $service->autoAssignRound($event->id, $class->id, 1);

    $heats = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1);

    expect($heats)->toHaveCount(2)
        ->and($heats->map(fn ($h) => $h->scheduleEntries()->count())->all())->toBe([2, 2]);
});

test('L6. fresh generate + auto distribute via UI yields exactly 2 heats of 2', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = th_event();
    app(ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = th_category($event);
    $class = th_class($event, $category, CompetitionFormat::TEAM_HEAT, ['team_size' => 4]);

    for ($i = 1; $i <= 4; $i++) {
        th_team($event, $class, "Tim {$i}");
    }

    $component = Livewire::test(\App\Livewire\Competition\Heat\Index::class);

    $component->set('selectedClassId', (string) $class->id)
        ->set('formatRound', 1)
        ->set('formatParticipants', 2)
        ->set('formatQualifiers', 1)
        ->set('formatMinParticipants', 2)
        ->call('createFormat')
        ->call('generateRound', 1)
        ->call('autoAssignTeams', 1);

    $heats = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1);

    expect($heats)->toHaveCount(2)
        ->and($heats->map(fn ($h) => $h->scheduleEntries()->count())->all())->toBe([2, 2])
        ->and($heats->pluck('required_participants')->all())->toBe([2, 2]);
});

// ---------------------------------------------------------------------------
// K. Kontrak heat_count = ceil(eligible_team_count / teams_per_heat).
// ---------------------------------------------------------------------------

test('K1. heat count matrix: 1..6 teams with 2 per heat', function () {
    $expected = [
        1 => [1, [1]],
        2 => [1, [2]],
        3 => [2, [2, 1]],
        4 => [2, [2, 2]],
        5 => [3, [2, 2, 1]],
        6 => [3, [2, 2, 2]],
    ];

    foreach ($expected as $nTeams => [$heatCount, $distribution]) {
        $event = th_event();
        $category = th_category($event);
        $class = th_class($event, $category, CompetitionFormat::TEAM_HEAT, ['team_size' => 4]);

        for ($i = 1; $i <= $nTeams; $i++) {
            th_team($event, $class, "Tim {$i}");
        }

        th_save_format($event, $class, 1, 2, 1, min: 2);

        $service = app(CompetitionHeatManagerService::class);

        expect($service->computeHeatCount($class->id, 1))->toBe($heatCount);

        $result = $service->generateRound($event->id, $class->id, 1);

        expect($result['generated'])->toBeTrue()
            ->and($result['heat_count'])->toBe($heatCount);

        $service->autoAssignRound($event->id, $class->id, 1);

        $schedules = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1);

        expect($schedules)->toHaveCount($heatCount)
            ->and($schedules->map(fn ($h) => $h->scheduleEntries()->count())->all())->toBe($distribution);
    }
});

test('K2. 4 teams with 1/4/10 members each always produce 2 heats', function () {
    foreach ([1, 4, 10] as $membersPerTeam) {
        $event = th_event();
        $category = th_category($event);
        $class = th_class($event, $category, CompetitionFormat::TEAM_HEAT, ['team_size' => 4]);

        for ($t = 1; $t <= 4; $t++) {
            $team = th_team($event, $class, "Tim {$t}");
            for ($m = 1; $m <= $membersPerTeam; $m++) {
                $reg = th_register(th_person("T{$t}M{$m}"), $event, $category, $class);
                th_add_member($team, $reg, false, $m);
            }
        }

        th_save_format($event, $class, 1, 2, 1, min: 2);

        $service = app(CompetitionHeatManagerService::class);

        expect($service->computeHeatCount($class->id, 1))->toBe(2);

        $result = $service->generateRound($event->id, $class->id, 1);

        expect($result['heat_count'])->toBe(2);
    }
});

test('K3. min_teams_to_start does not affect heat_count', function () {
    foreach ([1, 2, 3, 4] as $min) {
        $event = th_event();
        $category = th_category($event);
        $class = th_class($event, $category, CompetitionFormat::TEAM_HEAT);

        for ($i = 1; $i <= 4; $i++) {
            th_team($event, $class, "Tim {$i}");
        }

        th_save_format($event, $class, 1, 4, 1, min: $min);

        $service = app(CompetitionHeatManagerService::class);
        $result = $service->generateRound($event->id, $class->id, 1);

        expect($result['heat_count'])->toBe(1)
            ->and($service->computeHeatCount($class->id, 1))->toBe(1);
    }
});

test('K4. teams_advance_per_heat does not affect heat_count', function () {
    foreach ([1, 2, 3] as $qualifiers) {
        $event = th_event();
        $category = th_category($event);
        $class = th_class($event, $category, CompetitionFormat::TEAM_HEAT);

        for ($i = 1; $i <= 6; $i++) {
            th_team($event, $class, "Tim {$i}");
        }

        th_save_format($event, $class, 1, 4, $qualifiers, min: 2);

        $service = app(CompetitionHeatManagerService::class);
        $result = $service->generateRound($event->id, $class->id, 1);

        expect($result['heat_count'])->toBe(2)
            ->and($service->computeHeatCount($class->id, 1))->toBe(2);
    }
});

test('K5. auto distribution never creates extra heats (4 teams, 2 per heat -> 2 heats only)', function () {
    $event = th_event();
    $category = th_category($event);
    $class = th_class($event, $category, CompetitionFormat::TEAM_HEAT, ['team_size' => 4]);

    for ($i = 1; $i <= 4; $i++) {
        th_team($event, $class, "Tim {$i}");
    }

    th_save_format($event, $class, 1, 2, 1, min: 2);

    $service = app(CompetitionHeatManagerService::class);
    $service->generateRound($event->id, $class->id, 1);

    $before = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1)->count();

    $result = $service->autoAssignRound($event->id, $class->id, 1);

    expect($before)->toBe(2)
        ->and($result['heat_count'])->toBe(2)
        ->and(app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1)->count())->toBe(2);
});
