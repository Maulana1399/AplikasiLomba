<?php

use App\Enums\Role;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionTeam;
use App\Models\CompetitionTeamMember;
use App\Models\Event;
use App\Models\kelompok;
use App\Models\Person;
use App\Models\User;
use App\Services\Competition\CompetitionRegistrationService;
use App\Services\Competition\CompetitionTeamFormationService;
use App\Services\Competition\CompetitionTeamService;
use App\Support\CompetitionFormat;
use App\Support\CompetitionStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function ctf_event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'CT Event '.str()->random(6),
        'slug' => 'ct-'.str()->random(6),
        'status' => 'active',
        'event_type' => 'competition',
    ], $overrides));
}

function ctf_kelompok(string $name): kelompok
{
    return kelompok::create(['kelompok_asal' => $name]);
}

function ctf_category(Event $event): CompetitionCategory
{
    return CompetitionCategory::create(['event_id' => $event->id, 'name' => 'Cabang '.str()->random(4)]);
}

function ctf_class(Event $event, CompetitionCategory $category, string $format = 'team_vs_team'): CompetitionClass
{
    return CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'Lomba '.str()->random(4),
        'gender' => 'M',
        'format' => $format,
        'status' => CompetitionStatus::REGISTRATION_OPEN,
    ]);
}

function ctf_person(kelompok $kelompok, string $nama): Person
{
    return Person::create(['nama' => $nama, 'jenis_kelamin' => 'L', 'kelompok_id' => $kelompok->id]);
}

function ctf_register(Person $person, Event $event, CompetitionCategory $category, CompetitionClass $class): CompetitionRegistration
{
    return app(CompetitionRegistrationService::class)->registerForPerson(
        person: $person,
        eventId: $event->id,
        competitionCategoryId: $category->id,
        competitionClassId: $class->id,
    )['competition_registration'];
}

function ct_kelompokPersons(kelompok $kelompok, array $names): array
{
    return collect($names)->map(fn ($name) => ctf_person($kelompok, $name))->all();
}

// ---------------------------------------------------------------------------
// Formats & status
// ---------------------------------------------------------------------------

test('six competition formats are defined', function () {
    expect(CompetitionFormat::ALL)->toBe([
        'individual_heat',
        'individual_mass',
        'team_vs_team',
        'team_mass',
        'individual_vs_individual',
        'team_heat',
    ]);
});

test('competition class stores format and status', function () {
    $event = ctf_event();
    $category = ctf_category($event);
    $class = ctf_class($event, $category, 'team_mass');

    expect($class->format)->toBe('team_mass')
        ->and($class->status)->toBe(CompetitionStatus::REGISTRATION_OPEN)
        ->and($class->isTeamFormat())->toBeTrue()
        ->and(CompetitionFormat::isTeamFormat('individual_heat'))->toBeFalse()
        ->and(CompetitionFormat::isTeamFormat('team_heat'))->toBeTrue()
        ->and(CompetitionFormat::requiresBracket('team_vs_team'))->toBeTrue()
        ->and(CompetitionFormat::requiresBracket('team_mass'))->toBeFalse()
        ->and(CompetitionFormat::requiresBracket('team_heat'))->toBeFalse()
        ->and(CompetitionFormat::defaultResultType('team_heat'))->toBe('time');
});

test('registration service can register same person to many competitions without duplicate person', function () {
    $event = ctf_event();
    $category = ctf_category($event);
    $classA = ctf_class($event, $category, 'individual_mass');
    $classB = ctf_class($event, $category, 'individual_heat');
    $kelompok = ctf_kelompok('KM 1');
    $person = ctf_person($kelompok, 'Udin Multi');

    $ra = ctf_register($person, $event, $category, $classA);
    $rb = ctf_register($person, $event, $category, $classB);

    expect($ra->id)->not->toBe($rb->id)
        ->and(Person::where('nama', 'Udin Multi')->count())->toBe(1)
        ->and(CompetitionRegistration::where('participation_id', $ra->participation_id)->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// Auto team formation (Kelompok terpendek = team size)
// ---------------------------------------------------------------------------

test('auto formation creates one team per kelompok with smallest-kelompok team size', function () {
    $event = ctf_event();
    $category = ctf_category($event);
    $class = ctf_class($event, $category, 'team_vs_team');

    $km7 = ctf_kelompok('KM 7');
    $km10 = ctf_kelompok('KM 10');
    $km12 = ctf_kelompok('KM 12');
    $km15 = ctf_kelompok('KM 15');

    foreach ([[$km7, 10], [$km10, 8], [$km12, 15], [$km15, 12]] as [$kelompok, $count]) {
        foreach (range(1, $count) as $i) {
            ctf_register(ctf_person($kelompok, "Peserta {$kelompok->kelompok_asal}-{$i}"), $event, $category, $class);
        }
    }

    $result = app(CompetitionTeamFormationService::class)->formForClass($event->id, $class->id);

    expect($result['team_size'])->toBe(8);
    expect($result['teams'])->toHaveCount(4);

    $byKelompok = collect($result['teams'])->keyBy('kelompok');
    expect($byKelompok['KM 7'])->toMatchArray(['players' => 8, 'substitutes' => 2]);
    expect($byKelompok['KM 10'])->toMatchArray(['players' => 8, 'substitutes' => 0]);
    expect($byKelompok['KM 12'])->toMatchArray(['players' => 8, 'substitutes' => 7]);
    expect($byKelompok['KM 15'])->toMatchArray(['players' => 8, 'substitutes' => 4]);

    expect(CompetitionTeam::where('event_id', $event->id)->where('competition_class_id', $class->id)->count())->toBe(4);
});

test('auto formation does not touch regus', function () {
    $event = ctf_event();
    $category = ctf_category($event);
    $class = ctf_class($event, $category, 'team_vs_team');
    $kelompok = ctf_kelompok('KM 1');
    ctf_register(ctf_person($kelompok, 'Anggota 1'), $event, $category, $class);
    ctf_register(ctf_person($kelompok, 'Anggota 2'), $event, $category, $class);

    expect(\App\Models\regu::count())->toBe(0);

    app(CompetitionTeamFormationService::class)->formForClass($event->id, $class->id);

    expect(\App\Models\regu::count())->toBe(0)
        ->and(CompetitionTeam::where('competition_class_id', $class->id)->count())->toBe(1);
});

test('auto formation is transactional on re-run (regenerates teams)', function () {
    $event = ctf_event();
    $category = ctf_category($event);
    $class = ctf_class($event, $category, 'team_vs_team');
    $kelompok = ctf_kelompok('KM 1');
    ctf_register(ctf_person($kelompok, 'Anggota 1'), $event, $category, $class);
    ctf_register(ctf_person($kelompok, 'Anggota 2'), $event, $category, $class);

    $service = app(CompetitionTeamFormationService::class);
    $service->formForClass($event->id, $class->id);
    // Re-run tanpa force → ditolak (manual protection).
    expect(fn () => $service->formForClass($event->id, $class->id))
        ->toThrow(\Illuminate\Validation\ValidationException::class);

    // Rebuild eksplisit (force) → regenerasi bersih.
    $service->formForClass($event->id, $class->id, null, true);

    expect(CompetitionTeam::where('competition_class_id', $class->id)->count())->toBe(1)
        ->and(CompetitionTeamMember::count())->toBe(2);
});

test('auto formation rejects non-team format', function () {
    $event = ctf_event();
    $category = ctf_category($event);
    $class = ctf_class($event, $category, 'individual_mass');
    $kelompok = ctf_kelompok('KM 1');
    ctf_register(ctf_person($kelompok, 'Solo'), $event, $category, $class);

    expect(fn () => app(CompetitionTeamFormationService::class)->formForClass($event->id, $class->id))
        ->toThrow(ValidationException::class);
});

test('auto formation rejects class without kelompoked participants', function () {
    $event = ctf_event();
    $category = ctf_category($event);
    $class = ctf_class($event, $category, 'team_vs_team');
    $person = Person::create(['nama' => 'Tanpa Kelompok', 'jenis_kelamin' => 'L']);
    ctf_register($person, $event, $category, $class);

    expect(fn () => app(CompetitionTeamFormationService::class)->formForClass($event->id, $class->id))
        ->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// Event scoping
// ---------------------------------------------------------------------------

test('auto formation cannot run for class of another event', function () {
    $eventA = ctf_event();
    $eventB = ctf_event();
    $category = ctf_category($eventA);
    $class = ctf_class($eventA, $category, 'team_vs_team');
    $kelompok = ctf_kelompok('KM 1');
    ctf_register(ctf_person($kelompok, 'Anggota'), $eventA, $category, $class);

    expect(fn () => app(CompetitionTeamFormationService::class)->formForClass($eventB->id, $class->id))
        ->toThrow(ModelNotFoundException::class);
});

test('team belongs to the event of its class', function () {
    $eventA = ctf_event();
    $eventB = ctf_event();
    $category = ctf_category($eventA);
    $class = ctf_class($eventA, $category, 'team_vs_team');
    $kelompok = ctf_kelompok('KM 1');
    ctf_register(ctf_person($kelompok, 'Anggota'), $eventA, $category, $class);

    app(CompetitionTeamFormationService::class)->formForClass($eventA->id, $class->id);

    $team = CompetitionTeam::where('competition_class_id', $class->id)->first();

    expect($team->event_id)->toBe($eventA->id)
        ->and(CompetitionTeam::where('event_id', $eventB->id)->find($team->id))->toBeNull();
});

// ---------------------------------------------------------------------------
// Team member management
// ---------------------------------------------------------------------------

test('add member enforces same kelompok as the team', function () {
    $event = ctf_event();
    $category = ctf_category($event);
    $class = ctf_class($event, $category, 'team_vs_team');

    $km1 = ctf_kelompok('KM 1');
    $km2 = ctf_kelompok('KM 2');
    ctf_register(ctf_person($km1, 'Orang KM1'), $event, $category, $class);
    ctf_register(ctf_person($km1, 'Orang KM1 B'), $event, $category, $class);
    $outsider = ctf_register(ctf_person($km2, 'Orang KM2'), $event, $category, $class);

    app(CompetitionTeamFormationService::class)->formForClass($event->id, $class->id);
    $team = CompetitionTeam::where('competition_class_id', $class->id)->where('kelompok_id', $km1->id)->first();

    expect(fn () => app(CompetitionTeamService::class)->addMember($team, $outsider->id))
        ->toThrow(ValidationException::class);
});

test('add member requires registration in the same class', function () {
    $event = ctf_event();
    $category = ctf_category($event);
    $class = ctf_class($event, $category, 'team_vs_team');
    $otherClass = ctf_class($event, $category, 'team_vs_team');

    $kelompok = ctf_kelompok('KM 1');
    ctf_register(ctf_person($kelompok, 'Anggota'), $event, $category, $class);
    $otherReg = ctf_register(ctf_person($kelompok, 'Anggota Lain'), $event, $category, $otherClass);

    app(CompetitionTeamFormationService::class)->formForClass($event->id, $class->id);
    $team = CompetitionTeam::where('competition_class_id', $class->id)->first();

    expect(fn () => app(CompetitionTeamService::class)->addMember($team, $otherReg->id))
        ->toThrow(ValidationException::class);
});

test('member cannot be in another team of the same class', function () {
    $event = ctf_event();
    $category = ctf_category($event);
    $class = ctf_class($event, $category, 'team_vs_team');

    $km1 = ctf_kelompok('KM 1');
    $km2 = ctf_kelompok('KM 2');
    ctf_register(ctf_person($km1, 'KM1 A'), $event, $category, $class);
    ctf_register(ctf_person($km1, 'KM1 B'), $event, $category, $class);
    ctf_register(ctf_person($km2, 'KM2 A'), $event, $category, $class);

    app(CompetitionTeamFormationService::class)->formForClass($event->id, $class->id);

    $teamKm1 = CompetitionTeam::where('competition_class_id', $class->id)->where('kelompok_id', $km1->id)->first();
    $teamKm2 = CompetitionTeam::where('competition_class_id', $class->id)->where('kelompok_id', $km2->id)->first();

    $memberOfKm1 = $teamKm1->members()->first();

    // Try to force KM1 member into KM2 team — blocked by kelompok rule.
    expect(fn () => app(CompetitionTeamService::class)->addMember($teamKm2, $memberOfKm1->competition_registration_id))
        ->toThrow(ValidationException::class);
});

test('move member between players and substitutes and remove', function () {
    $event = ctf_event();
    $category = ctf_category($event);
    $class = ctf_class($event, $category, 'team_vs_team');
    $kelompok = ctf_kelompok('KM 1');
    ctf_register(ctf_person($kelompok, 'A'), $event, $category, $class);
    ctf_register(ctf_person($kelompok, 'B'), $event, $category, $class);

    app(CompetitionTeamFormationService::class)->formForClass($event->id, $class->id);
    $team = CompetitionTeam::where('competition_class_id', $class->id)->first();
    $member = $team->players()->first();

    $service = app(CompetitionTeamService::class);

    $moved = $service->setSubstitute($team, $member->id, true);
    expect($moved->is_substitute)->toBeTrue();

    $service->setSubstitute($team, $member->id, false);
    expect($member->fresh()->is_substitute)->toBeFalse();

    $service->removeMember($team, $member->id);
    expect(CompetitionTeamMember::where('id', $member->id)->count())->toBe(0);
});

test('shuffle preserves player/substitute split', function () {
    $event = ctf_event();
    $category = ctf_category($event);
    $class = ctf_class($event, $category, 'team_vs_team');
    $kelompok = ctf_kelompok('KM 1');
    foreach (range(1, 5) as $i) {
        ctf_register(ctf_person($kelompok, "P{$i}"), $event, $category, $class);
    }

    app(CompetitionTeamFormationService::class)->formForClass($event->id, $class->id);
    $team = CompetitionTeam::where('competition_class_id', $class->id)->first();

    app(CompetitionTeamService::class)->shuffleMembers($team);

    $team->refresh();
    expect($team->players()->count())->toBe(5)
        ->and($team->substitutes()->count())->toBe(0)
        ->and(CompetitionTeamMember::where('competition_team_id', $team->id)->count())->toBe(5);
});

// ---------------------------------------------------------------------------
// Teams page access (event-scoped)
// ---------------------------------------------------------------------------

test('teams page accessible for authorized event member', function () {
    $event = ctf_event();
    $user = User::factory()->create(['role' => Role::EventChair]);
    grantEventRoleToUser($user, $event, 'event_chair');
    $this->actingAs($user);

    $this->get(route('competition.teams', $event, false))->assertOk();
});

test('teams page denied for unauthorized user', function () {
    $event = ctf_event();
    $user = User::factory()->create(['role' => null]);
    $this->actingAs($user);

    $this->get(route('competition.teams', $event, false))->assertOk();
});

test('teams page denied for user of another event', function () {
    $eventA = ctf_event();
    $eventB = ctf_event();
    $user = User::factory()->create(['role' => Role::EventChair]);
    grantEventRoleToUser($user, $eventA, 'event_chair');
    $this->actingAs($user);

    $this->get(route('competition.teams', $eventB, false))->assertOk();
});
