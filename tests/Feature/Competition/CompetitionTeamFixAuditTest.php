<?php

use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionHeatResult;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionSchedule;
use App\Models\CompetitionScheduleEntry;
use App\Models\CompetitionTeam;
use App\Models\CompetitionTeamMember;
use App\Models\CompetitionTeamOutcome;
use App\Models\Event;
use App\Models\kelompok;
use App\Models\Participation;
use App\Models\Person;
use App\Services\Competition\CompetitionRegistrationService;
use App\Services\Competition\CompetitionTeamFormationService;
use App\Services\Competition\CompetitionTeamService;
use App\Support\CompetitionFormat;
use App\Support\CompetitionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function fix_event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'Fix Event '.str()->random(6),
        'slug' => 'fix-'.str()->random(6),
        'status' => 'active',
        'event_type' => 'competition',
    ], $overrides));
}

function fix_category(Event $event): CompetitionCategory
{
    $category = CompetitionCategory::create(['name' => 'Fix Cat '.str()->random(4)]);
    $event->competitionCategories()->attach($category->id);

    return $category;
}

function fix_class(Event $event, CompetitionCategory $category, string $format = 'team_vs_team', array $overrides = []): CompetitionClass
{
    return CompetitionClass::create(array_merge([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'Fix Class '.str()->random(4),
        'gender' => 'M',
        'format' => $format,
        'status' => CompetitionStatus::REGISTRATION_OPEN,
        'is_active' => true,
    ], $overrides));
}

function fix_kelompok(string $name): kelompok
{
    return kelompok::create(['kelompok_asal' => $name]);
}

function fix_person(string $nama, ?kelompok $kelompok): Person
{
    return Person::create(['nama' => $nama, 'jenis_kelamin' => 'L', 'kelompok_id' => $kelompok?->id]);
}

function fix_register(Person $person, Event $event, CompetitionCategory $category, CompetitionClass $class): CompetitionRegistration
{
    return app(CompetitionRegistrationService::class)->registerForPerson(
        person: $person,
        eventId: $event->id,
        competitionCategoryId: $category->id,
        competitionClassId: $class->id,
    )['competition_registration'];
}

test('heat result membuat team inUse dan blokir reform tanpa force', function () {
    $event = fix_event();
    $category = fix_category($event);
    $class = fix_class($event, $category, CompetitionFormat::TEAM_VS_TEAM, ['team_size' => 2]);
    $km = fix_kelompok('KM Fix');
    foreach (range(1, 4) as $i) {
        fix_register(fix_person("H{$i}", $km), $event, $category, $class);
    }
    $service = app(CompetitionTeamFormationService::class);
    $service->formForClass($event->id, $class->id);
    $team = CompetitionTeam::where('competition_class_id', $class->id)->first();
    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $class->id,
        'status' => 'Scheduled',
        'required_participants' => 2,
        'sort_order' => 1,
    ]);
    CompetitionScheduleEntry::create([
        'competition_schedule_id' => $schedule->id,
        'competition_team_id' => $team->id,
        'order_number' => 1,
    ]);
    CompetitionHeatResult::create([
        'competition_schedule_id' => $schedule->id,
        'competition_team_id' => $team->id,
        'score' => 10,
        'status' => 'Lolos',
    ]);
    expect(fn () => $service->formForClass($event->id, $class->id))->toThrow(ValidationException::class);
    try {
        $service->formForClass($event->id, $class->id);
    } catch (ValidationException $e) {
        expect($e->getMessage())->toContain('memerlukan konfirmasi');
    }
    expect(fn () => $service->formForClass($event->id, $class->id, null, true))->toThrow(ValidationException::class);
    try {
        $service->formForClass($event->id, $class->id, null, true);
    } catch (ValidationException $e) {
        expect($e->getMessage())->toContain('integritas hasil');
        expect($e->getMessage())->toContain('Tidak ada penghapusan otomatis');
    }
});

test('reform tanpa force ditolak dan dengan force bypass outcome saja', function () {
    $event = fix_event();
    $category = fix_category($event);
    $class = fix_class($event, $category, CompetitionFormat::TEAM_VS_TEAM);
    $km = fix_kelompok('KM Force');
    foreach (range(1, 3) as $i) {
        fix_register(fix_person("F{$i}", $km), $event, $category, $class);
    }
    $service = app(CompetitionTeamFormationService::class);
    $service->formForClass($event->id, $class->id);
    expect(fn () => $service->formForClass($event->id, $class->id))->toThrow(ValidationException::class);
    $team = CompetitionTeam::where('competition_class_id', $class->id)->first();
    CompetitionTeamOutcome::create([
        'competition_team_id' => $team->id,
        'position' => 1,
        'status' => 'Juara',
    ]);
    expect(fn () => $service->formForClass($event->id, $class->id))->toThrow(ValidationException::class);
    expect(fn () => $service->formForClass($event->id, $class->id, null, true))->not->toThrow(ValidationException::class);
    expect(CompetitionTeam::where('competition_class_id', $class->id)->count())->toBe(1);
});

test('reform dengan force tetap diblokir jika scheduleEntries ada', function () {
    $event = fix_event();
    $category = fix_category($event);
    $class = fix_class($event, $category, CompetitionFormat::TEAM_VS_TEAM, ['team_size' => 2]);
    $km = fix_kelompok('KM Sched');
    foreach (range(1, 2) as $i) {
        fix_register(fix_person("S{$i}", $km), $event, $category, $class);
    }
    $service = app(CompetitionTeamFormationService::class);
    $service->formForClass($event->id, $class->id);
    $team = CompetitionTeam::where('competition_class_id', $class->id)->first();
    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $class->id,
        'status' => 'Scheduled',
        'required_participants' => 1,
        'sort_order' => 2,
    ]);
    CompetitionScheduleEntry::create([
        'competition_schedule_id' => $schedule->id,
        'competition_team_id' => $team->id,
        'order_number' => 1,
    ]);
    expect(fn () => $service->formBalancedForClass($event->id, $class->id))->toThrow(ValidationException::class);
    expect(fn () => $service->formBalancedForClass($event->id, $class->id, null, true))->toThrow(ValidationException::class);
});

test('manual ops ditolak ketika inUse', function () {
    $event = fix_event();
    $category = fix_category($event);
    $class = fix_class($event, $category, CompetitionFormat::TEAM_VS_TEAM, ['team_size' => 2]);
    $km1 = fix_kelompok('KM M1');
    $km2 = fix_kelompok('KM M2');
    foreach (range(1, 2) as $i) {
        fix_register(fix_person("M1-{$i}", $km1), $event, $category, $class);
        fix_register(fix_person("M2-{$i}", $km2), $event, $category, $class);
    }
    $service = app(CompetitionTeamFormationService::class);
    $service->formForClass($event->id, $class->id);
    $team = CompetitionTeam::where('competition_class_id', $class->id)->where('kelompok_id', $km1->id)->first();
    $otherTeam = CompetitionTeam::where('competition_class_id', $class->id)->where('kelompok_id', $km2->id)->first();
    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $class->id,
        'status' => 'Scheduled',
        'required_participants' => 2,
        'sort_order' => 10,
    ]);
    CompetitionScheduleEntry::create([
        'competition_schedule_id' => $schedule->id,
        'competition_team_id' => $team->id,
        'order_number' => 1,
    ]);
    $memberId = $team->members()->first()->id;
    $otherMemberId = $otherTeam->members()->first()->id;
    $extraPerson = fix_person('Extra', $km1);
    $extraReg = fix_register($extraPerson, $event, $category, $class);
    $svc = app(CompetitionTeamService::class);
    expect(fn () => $svc->addMember($team, $extraReg->id))->toThrow(ValidationException::class);
    expect(fn () => $svc->removeMember($team, $memberId))->toThrow(ValidationException::class);
    expect(fn () => $svc->setSubstitute($team, $memberId, true))->toThrow(ValidationException::class);
    expect(fn () => $svc->shuffleMembers($team))->toThrow(ValidationException::class);
    expect(fn () => $svc->swapMembers($team, $memberId, $otherTeam, $otherMemberId))->toThrow(ValidationException::class);
});

test('mode Berdasarkan Kelompok menolak peserta tanpa Kelompok', function () {
    $event = fix_event();
    $category = fix_category($event);
    $class = fix_class($event, $category, CompetitionFormat::TEAM_VS_TEAM);
    $km = fix_kelompok('KM G');
    fix_register(fix_person('Dengan Kelompok', $km), $event, $category, $class);
    $tanpa = Person::create(['nama' => 'Tanpa Kelompok', 'jenis_kelamin' => 'L', 'kelompok_id' => null]);
    fix_register($tanpa, $event, $category, $class);
    $service = app(CompetitionTeamFormationService::class);
    expect(fn () => $service->formForClass($event->id, $class->id))->toThrow(ValidationException::class);
    try {
        $service->formForClass($event->id, $class->id);
    } catch (ValidationException $e) {
        expect($e->getMessage())->toContain('belum memiliki Kelompok');
    }
    expect(CompetitionTeam::where('competition_class_id', $class->id)->count())->toBe(0);
    expect(fn () => $service->previewForClass($event->id, $class->id))->toThrow(ValidationException::class);
    expect(CompetitionTeam::count())->toBe(0);
    expect(CompetitionTeamMember::count())->toBe(0);
});

test('mode Acak dan Seimbang tetap menerima peserta tanpa Kelompok', function () {
    $event = fix_event();
    $category = fix_category($event);
    $class = fix_class($event, $category, CompetitionFormat::TEAM_VS_TEAM, ['team_size' => 2]);
    $km = fix_kelompok('KM X');
    fix_register(fix_person('Dengan', $km), $event, $category, $class);
    $tanpa = Person::create(['nama' => 'Tanpa', 'jenis_kelamin' => 'L', 'kelompok_id' => null]);
    fix_register($tanpa, $event, $category, $class);
    $service = app(CompetitionTeamFormationService::class);
    $result = $service->formBalancedForClass($event->id, $class->id);
    expect($result['team_count'])->toBe(1);
    expect(CompetitionTeamMember::count())->toBe(2);
});

test('preview tidak menulis DB untuk kedua mode', function () {
    $event = fix_event();
    $category = fix_category($event);
    $groupClass = fix_class($event, $category, CompetitionFormat::TEAM_VS_TEAM);
    $balancedClass = fix_class($event, $category, CompetitionFormat::TEAM_VS_TEAM, ['name' => 'Balanced '.str()->random(4), 'team_size' => 2]);
    $km = fix_kelompok('KM Prev');
    foreach (range(1, 4) as $i) {
        fix_register(fix_person("G{$i}", $km), $event, $category, $groupClass);
        fix_register(fix_person("B{$i}", $km), $event, $category, $balancedClass);
    }
    $service = app(CompetitionTeamFormationService::class);
    $gp = $service->previewForClass($event->id, $groupClass->id);
    $bp = $service->previewBalancedForClass($event->id, $balancedClass->id);
    expect(CompetitionTeam::count())->toBe(0)
        ->and(CompetitionTeamMember::count())->toBe(0)
        ->and($gp['team_size'])->toBe(4)
        ->and($bp['team_count'])->toBe(2);
});

test('format team_mass dan team_heat ditolak oleh formation service', function () {
    $event = fix_event();
    $category = fix_category($event);
    $km = fix_kelompok('KM Fmt');
    foreach ([CompetitionFormat::TEAM_MASS, CompetitionFormat::TEAM_HEAT] as $format) {
        $class = fix_class($event, $category, $format, ['name' => 'Fmt '.str()->random(4)]);
        fix_register(fix_person("P {$format}", $km), $event, $category, $class);
        $svc = app(CompetitionTeamFormationService::class);
        expect(fn () => $svc->formForClass($event->id, $class->id))->toThrow(ValidationException::class);
        expect(fn () => $svc->previewForClass($event->id, $class->id))->toThrow(ValidationException::class);
        expect(fn () => $svc->formBalancedForClass($event->id, $class->id, 2))->toThrow(ValidationException::class);
        expect(fn () => $svc->previewBalancedForClass($event->id, $class->id, 2))->toThrow(ValidationException::class);
    }
});

test('team_vs_team tetap berhasil untuk kedua mode', function () {
    $event = fix_event();
    $category = fix_category($event);
    $km1 = fix_kelompok('KM A');
    $km2 = fix_kelompok('KM B');
    $groupClass = fix_class($event, $category, CompetitionFormat::TEAM_VS_TEAM, ['name' => 'Group '.str()->random(4)]);
    fix_register(fix_person('A1', $km1), $event, $category, $groupClass);
    fix_register(fix_person('A2', $km1), $event, $category, $groupClass);
    fix_register(fix_person('B1', $km2), $event, $category, $groupClass);
    $balancedClass = fix_class($event, $category, CompetitionFormat::TEAM_VS_TEAM, ['name' => 'Balanced '.str()->random(4), 'team_size' => 2]);
    fix_register(fix_person('X1', $km1), $event, $category, $balancedClass);
    fix_register(fix_person('X2', $km2), $event, $category, $balancedClass);
    fix_register(fix_person('X3', $km1), $event, $category, $balancedClass);
    $svc = app(CompetitionTeamFormationService::class);
    $g = $svc->formForClass($event->id, $groupClass->id);
    expect($g['teams'])->toHaveCount(2);
    $b = $svc->formBalancedForClass($event->id, $balancedClass->id);
    expect($b['team_count'])->toBe(2);
});
