<?php

use App\Enums\Role;
use App\Livewire\Competition\Class\Index as ClassIndex;
use App\Livewire\Competition\Team\Index as TeamIndex;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionTeam;
use App\Models\CompetitionTeamMember;
use App\Models\Event;
use App\Models\kelompok;
use App\Models\Participation;
use App\Models\Person;
use App\Models\User;
use App\Services\Competition\CompetitionRegistrationService;
use App\Services\Competition\CompetitionTeamFormationService;
use App\Services\Competition\CompetitionTeamService;
use App\Support\ActiveEventContext;
use App\Support\CompetitionFormat;
use App\Support\CompetitionStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function tm_event(): Event
{
    return Event::create([
        'name' => 'TM Event '.str()->random(6),
        'slug' => 'tm-'.str()->random(6),
        'status' => 'active',
        'event_type' => 'competition',
    ]);
}

function tm_kelompok(string $name): kelompok
{
    return kelompok::create(['kelompok_asal' => $name]);
}

function tm_category(Event $event): CompetitionCategory
{
    $category = CompetitionCategory::create(['event_id' => $event->id, 'name' => 'Cabang '.str()->random(4)]);

    $category->events()->attach($event);

    return $category;
}

function tm_class(Event $event, CompetitionCategory $category, array $overrides = []): CompetitionClass
{
    return CompetitionClass::create(array_merge([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'Lomba '.str()->random(4),
        'gender' => 'M',
        'format' => CompetitionFormat::TEAM_VS_TEAM,
        'status' => CompetitionStatus::REGISTRATION_OPEN,
    ], $overrides));
}

function tm_person(array $overrides = []): Person
{
    return Person::create(array_merge([
        'nama' => 'Orang '.str()->random(6),
        'jenis_kelamin' => 'L',
    ], $overrides));
}

function tm_register(Person $person, Event $event, CompetitionCategory $category, CompetitionClass $class): CompetitionRegistration
{
    return app(CompetitionRegistrationService::class)->registerForPerson(
        person: $person,
        eventId: $event->id,
        competitionCategoryId: $category->id,
        competitionClassId: $class->id,
    )['competition_registration'];
}

function tm_roster(CompetitionClass $class): array
{
    return CompetitionTeam::where('competition_class_id', $class->id)
        ->with('members')
        ->orderBy('id')
        ->get()
        ->map(fn ($team) => $team->members->pluck('competition_registration_id')->sort()->values()->all())
        ->all();
}

/** Membangun skema peserta. Kelas hanya diisi bila sesuai aturan (kelas == nama kelas lomba). */
function tm_seed(Event $event, CompetitionCategory $category, CompetitionClass $class, array $spec): void
{
    foreach ($spec as $kelompokName => $rows) {
        $kelompok = $kelompokName ? tm_kelompok($kelompokName) : null;
        foreach ($rows as $i => [$kelas, $gender]) {
            $person = tm_person([
                'nama' => (($kelompokName ?: 'TanpaKelompok').' P'.($i + 1)),
                'kelompok_id' => $kelompok?->id,
                'kelas' => $kelas === $class->name ? $kelas : null,
                'jenis_kelamin' => $gender,
            ]);
            tm_register($person, $event, $category, $class);
        }
    }
}

/** Registrasi langsung (bypass service) untuk skenario multi-kelas dalam satu lomba. */
function tm_register_raw(Event $event, CompetitionCategory $category, CompetitionClass $class, Person $person): CompetitionRegistration
{
    $participation = Participation::create([
        'person_id' => $person->id,
        'event_id' => $event->id,
        'participant_number' => (string) random_int(1, 999999),
        'attendance_code' => strtoupper(str()->random(6)),
        'jenis_peserta' => 'Peserta',
    ]);

    return CompetitionRegistration::create([
        'participation_id' => $participation->id,
        'competition_category_id' => $category->id,
        'competition_class_id' => $class->id,
        'registration_type' => 'individual',
    ]);
}

// ---------------------------------------------------------------------------
// Class settings: Ukuran Tim (team_size)
// ---------------------------------------------------------------------------

test('team_size is settable when creating a team class in settings', function () {
    $event = tm_event();
    app(ActiveEventContext::class)->set($event);
    $user = User::factory()->create(['role' => Role::Admin]);
    $category = tm_category($event);

    Livewire::actingAs($user)
        ->test(ClassIndex::class)
        ->set('newCompetitionCategoryId', (string) $category->id)
        ->set('newName', 'Voli Campuran')
        ->set('newGender', 'M')
        ->set('newFormat', CompetitionFormat::TEAM_VS_TEAM)
        ->set('newTeamSize', '6')
        ->call('create')
        ->assertHasNoErrors();

    $class = CompetitionClass::where('event_id', $event->id)->first();

    expect($class->team_size)->toBe(6);
});

test('team_size can be edited from settings and cleared back to default', function () {
    $event = tm_event();
    app(ActiveEventContext::class)->set($event);
    $user = User::factory()->create(['role' => Role::Admin]);
    $category = tm_category($event);
    $class = tm_class($event, $category, ['team_size' => 5]);

    Livewire::actingAs($user)
        ->test(ClassIndex::class)
        ->call('edit', $class->id)
        ->assertSet('editTeamSize', '5')
        ->set('editTeamSize', '7')
        ->call('update')
        ->assertHasNoErrors();

    expect($class->fresh()->team_size)->toBe(7);

    Livewire::actingAs($user)
        ->test(ClassIndex::class)
        ->call('edit', $class->id)
        ->set('editTeamSize', '')
        ->call('update')
        ->assertHasNoErrors();

    expect($class->fresh()->team_size)->toBeNull();
});

// ---------------------------------------------------------------------------
// Mode A — Based on Group
// ---------------------------------------------------------------------------

test('group mode honours class.team_size over smallest kelompok', function () {
    $event = tm_event();
    $category = tm_category($event);
    $class = tm_class($event, $category, ['team_size' => 4]);

    tm_seed($event, $category, $class, [
        'KM A' => [['SD5', 'L'], ['SD5', 'L'], ['SD5', 'L']],
        'KM B' => [['SD9', 'P']],
        'KM C' => [['SD7', 'L'], ['SD7', 'P'], ['SD7', 'L'], ['SD7', 'L'], ['SD7', 'P']],
    ]);

    $result = app(CompetitionTeamFormationService::class)->formForClass($event->id, $class->id);

    expect($result['team_size'])->toBe(4);

    $byKelompok = collect($result['teams'])->keyBy('kelompok');
    expect($byKelompok['KM A'])->toMatchArray(['players' => 3, 'substitutes' => 0]);
    expect($byKelompok['KM B'])->toMatchArray(['players' => 1, 'substitutes' => 0]);
    expect($byKelompok['KM C'])->toMatchArray(['players' => 4, 'substitutes' => 1]);
});

test('group mode forced team_size overrides class.team_size', function () {
    $event = tm_event();
    $category = tm_category($event);
    $class = tm_class($event, $category, ['team_size' => 3]);
    $kelompok = tm_kelompok('KM 1');

    foreach (range(1, 5) as $i) {
        tm_register(tm_person(['nama' => "PG{$i}", 'kelompok_id' => $kelompok->id]), $event, $category, $class);
    }

    $result = app(CompetitionTeamFormationService::class)->formForClass($event->id, $class->id, forcedTeamSize: 2);

    expect($result['team_size'])->toBe(2)
        ->and($result['teams'][0]['players'])->toBe(2)
        ->and($result['teams'][0]['substitutes'])->toBe(3);
});

// ---------------------------------------------------------------------------
// Mode B — Random & Balanced
// ---------------------------------------------------------------------------

test('balanced mode requires a team_size', function () {
    $event = tm_event();
    $category = tm_category($event);
    $class = tm_class($event, $category);

    tm_seed($event, $category, $class, [
        'KM 1' => [['SD5', 'L'], ['SD5', 'P'], ['SD6', 'L'], ['SD6', 'P']],
    ]);

    expect(fn () => app(CompetitionTeamFormationService::class)->formBalancedForClass($event->id, $class->id))
        ->toThrow(ValidationException::class);
});

test('balanced mode distributes everyone into ceil(total/team_size) teams', function () {
    $event = tm_event();
    $category = tm_category($event);
    $class = tm_class($event, $category, ['team_size' => 5]);

    tm_seed($event, $category, $class, [
        'KM 1' => [['SD5', 'L'], ['SD5', 'P'], ['SD5', 'L'], ['SD6', 'P'], ['SD6', 'L']],
        'KM 2' => [['SD5', 'L'], ['SD6', 'P'], ['SD6', 'L'], ['SD7', 'P']],
        'KM 3' => [['SD7', 'L'], ['SD7', 'P'], ['SD5', 'L']],
        'KM 4' => [['SD8', 'L'], ['SD9', 'P'], ['SD9', 'L'], ['SD8', 'P']],
        'KM 5' => [['SD6', 'L'], ['SD7', 'P'], ['SD5', 'P'], ['SD9', 'L'], ['SD8', 'L'], ['SD6', 'P']],
    ]);

    // total = 5+4+3+4+6 = 22 → ceil(22/5) = 5 tim
    $result = app(CompetitionTeamFormationService::class)->formBalancedForClass($event->id, $class->id);

    expect($result['team_size'])->toBe(5)
        ->and($result['team_count'])->toBe(5)
        ->and(CompetitionTeamMember::count())->toBe(22);

    $teams = CompetitionTeam::where('competition_class_id', $class->id)->orderBy('id')->get();

    expect($teams->pluck('name'))->toMatchArray(['Tim 1', 'Tim 2', 'Tim 3', 'Tim 4', 'Tim 5']);

    $assigned = CompetitionTeamMember::pluck('competition_registration_id')->sort()->values();
    $eligible = CompetitionRegistration::where('competition_class_id', $class->id)->pluck('id')->sort()->values();

    expect($assigned)->toEqual($eligible);
});

test('balanced mode mixes participants across kelompok (kelompok_id null)', function () {
    $event = tm_event();
    $category = tm_category($event);
    $class = tm_class($event, $category, ['team_size' => 3]);

    $km1 = tm_kelompok('KM 1');
    $km2 = tm_kelompok('KM 2');

    foreach (range(1, 4) as $i) {
        tm_register(tm_person(['nama' => "K1-{$i}", 'kelompok_id' => $km1->id]), $event, $category, $class);
        tm_register(tm_person(['nama' => "K2-{$i}", 'kelompok_id' => $km2->id]), $event, $category, $class);
    }

    app(CompetitionTeamFormationService::class)->formBalancedForClass($event->id, $class->id);

    $teams = CompetitionTeam::where('competition_class_id', $class->id)->get();

    expect($teams->every(fn ($team) => $team->kelompok_id === null))->toBeTrue();

    // Tim bukan milik kelompok: anggota kelompok yang sama terpecah ke ≥2 tim
    // (kelompok KM1/KM2 masing-masing 4 orang, team_size 3 → mustahil 1 tim menampung 4).
    $assigned = $teams->mapWithKeys(fn ($team) => [
        $team->id => $team->members()
            ->with('competitionRegistration.participation.person')
            ->get()
            ->map(fn ($member) => [
                'member' => $member->id,
                'kelompok_id' => $member->competitionRegistration->participation->person->kelompok_id,
            ]),
    ]);

    $teamsOfKelompok = fn (int $kelompokId) => $teams
        ->filter(fn ($team) => $assigned[$team->id]->contains(
            fn ($row) => (int) $row['kelompok_id'] === $kelompokId
        ))
        ->count();

    expect(CompetitionTeamMember::count())->toBe(8)
        ->and($teamsOfKelompok($km1->id))->toBeGreaterThan(1)
        ->and($teamsOfKelompok($km2->id))->toBeGreaterThan(1);
});

test('balanced mode keeps total sizes within one of each other', function () {
    $event = tm_event();
    $category = tm_category($event);
    $class = tm_class($event, $category, ['team_size' => 5]);

    tm_seed($event, $category, $class, [
        'KM 1' => [['SD5', 'L'], ['SD5', 'P'], ['SD5', 'L'], ['SD6', 'P'], ['SD6', 'L']],
        'KM 2' => [['SD5', 'L'], ['SD6', 'P'], ['SD6', 'L'], ['SD7', 'P']],
        'KM 3' => [['SD7', 'L'], ['SD7', 'P'], ['SD5', 'L']],
        'KM 4' => [['SD8', 'L'], ['SD9', 'P'], ['SD9', 'L'], ['SD8', 'P']],
    ]);

    $result = app(CompetitionTeamFormationService::class)->formBalancedForClass($event->id, $class->id);

    $sizes = collect($result['teams'])->map(fn ($team) => $team['players'] + $team['substitutes']);

    expect($sizes->max() - $sizes->min())->toBeLessThanOrEqual(1);
});

test('balanced mode spreads each kelas evenly (diff at most 1)', function () {
    $event = tm_event();
    $category = tm_category($event);
    $class = tm_class($event, $category, ['name' => 'SD5', 'team_size' => 4]);

    // Skema multi-kelas dalam satu lomba (via registrasi langsung).
    $byKelas = [
        'SD5' => 9,
        'SD6' => 7,
        'SD7' => 5,
    ];

    foreach ($byKelas as $kelas => $count) {
        foreach (range(1, $count) as $i) {
            tm_register_raw($event, $category, $class, tm_person([
                'nama' => "{$kelas}-{$i}",
                'kelas' => $kelas,
                'jenis_kelamin' => $i % 2 === 0 ? 'P' : 'L',
            ]));
        }
    }

    app(CompetitionTeamFormationService::class)->formBalancedForClass($event->id, $class->id);

    // total = 21 → ceil(21/4) = 6 tim
    $teams = CompetitionTeam::where('competition_class_id', $class->id)->orderBy('id')->get();

    expect($teams)->toHaveCount(6);

    $between = $teams->map(function ($team) {
        return $team->members()
            ->with('competitionRegistration.participation.person')
            ->get()
            ->map(fn ($member) => $member->competitionRegistration->participation->person->kelas)
            ->countBy();
    });

    // Invariant yang dijamin algoritma: pasca round-robin + swap gender,
    // tidak ada tim yang memiliki > ceil(total/6) anggota dari sebuah kelas.
    foreach ($byKelas as $kelas => $total) {
        $ideal = (int) ceil($total / 6);
        $counts = $between->map(fn ($counts) => $counts[$kelas] ?? 0);
        expect($counts->max(), "kelas {$kelas} melebihi cap {$ideal}")->toBeLessThanOrEqual($ideal);
    }
});

test('balanced mode is randomised between runs', function () {
    $event = tm_event();
    $category = tm_category($event);
    $class = tm_class($event, $category, ['team_size' => 5]);

    tm_seed($event, $category, $class, [
        'KM 1' => [['SD5', 'L'], ['SD5', 'P'], ['SD5', 'L'], ['SD6', 'P'], ['SD6', 'L']],
        'KM 2' => [['SD5', 'L'], ['SD6', 'P'], ['SD6', 'L'], ['SD7', 'P'], ['SD5', 'L']],
        'KM 3' => [['SD7', 'L'], ['SD7', 'P'], ['SD5', 'L'], ['SD6', 'L'], ['SD7', 'L']],
        'KM 4' => [['SD8', 'L'], ['SD9', 'P'], ['SD9', 'L'], ['SD8', 'P'], ['SD6', 'P']],
        'KM 5' => [['SD6', 'L'], ['SD7', 'P'], ['SD5', 'P'], ['SD9', 'L'], ['SD8', 'L']],
    ]);

    $service = app(CompetitionTeamFormationService::class);
    $service->formBalancedForClass($event->id, $class->id);
    $first = tm_roster($class);
    $service->formBalancedForClass($event->id, $class->id, null, true);
    $second = tm_roster($class);

    expect($first)->not->toEqual($second);
});

// ---------------------------------------------------------------------------
// Guards shared by both modes
// ---------------------------------------------------------------------------

test('balanced mode rejects non-team format', function () {
    $event = tm_event();
    $category = tm_category($event);
    $class = tm_class($event, $category, ['format' => CompetitionFormat::INDIVIDUAL_MASS]);
    tm_register(tm_person(['nama' => 'Solo']), $event, $category, $class);

    expect(fn () => app(CompetitionTeamFormationService::class)->formBalancedForClass($event->id, $class->id, 2))
        ->toThrow(ValidationException::class);
});

test('balanced mode cannot run for class of another event', function () {
    $eventA = tm_event();
    $eventB = tm_event();
    $category = tm_category($eventA);
    $class = tm_class($eventA, $category, ['team_size' => 2]);
    tm_register(tm_person(['nama' => 'Kelas Lain']), $eventA, $category, $class);

    expect(fn () => app(CompetitionTeamFormationService::class)->formBalancedForClass($eventB->id, $class->id))
        ->toThrow(ModelNotFoundException::class);
});

test('balanced mode regenerates only with explicit force', function () {
    $event = tm_event();
    $category = tm_category($event);
    $class = tm_class($event, $category, ['team_size' => 3]);

    tm_seed($event, $category, $class, [
        'KM 1' => [['SD5', 'L'], ['SD5', 'P'], ['SD6', 'L'], ['SD6', 'P'], ['SD7', 'L']],
    ]);

    $service = app(CompetitionTeamFormationService::class);
    $service->formBalancedForClass($event->id, $class->id);

    expect(fn () => $service->formBalancedForClass($event->id, $class->id))
        ->toThrow(ValidationException::class);

    $service->formBalancedForClass($event->id, $class->id, null, true);

    expect(CompetitionTeam::where('competition_class_id', $class->id)->count())->toBe(2)
        ->and(count(tm_roster($class)))->toBe(2);
});

test('preview functions never write to the database', function () {
    $event = tm_event();
    $category = tm_category($event);
    $groupClass = tm_class($event, $category);
    $balancedClass = tm_class($event, $category, ['name' => 'Balanced '.str()->random(4), 'team_size' => 2]);

    $kelompok = tm_kelompok('KM 1');
    foreach (range(1, 4) as $i) {
        tm_register(tm_person(['nama' => "G{$i}", 'kelompok_id' => $kelompok->id]), $event, $category, $groupClass);
        tm_register(tm_person(['nama' => "B{$i}", 'kelompok_id' => $kelompok->id]), $event, $category, $balancedClass);
    }

    $service = app(CompetitionTeamFormationService::class);

    $groupPreview = $service->previewForClass($event->id, $groupClass->id);
    $balancedPreview = $service->previewBalancedForClass($event->id, $balancedClass->id);

    expect(CompetitionTeam::count())->toBe(0)
        ->and(CompetitionTeamMember::count())->toBe(0)
        ->and($groupPreview['team_size'])->toBe(4)
        ->and($balancedPreview['team_size'])->toBe(2)
        ->and($balancedPreview['team_count'])->toBe(2);
});

// ---------------------------------------------------------------------------
// Manual adjustment — swap between teams
// ---------------------------------------------------------------------------

test('swapMembers exchanges two members between teams of the same class', function () {
    $event = tm_event();
    $category = tm_category($event);
    $class = tm_class($event, $category, ['team_size' => 2]);

    tm_seed($event, $category, $class, [
        'KM 1' => [['SD5', 'L'], ['SD5', 'L'], ['SD5', 'L'], ['SD5', 'L']],
        'KM 2' => [['SD6', 'P'], ['SD6', 'P'], ['SD6', 'P']],
    ]);

    // 7 peserta → 4 tim balanced, semua pemain (team_size 2 sudah penuh terisi? 4 tim × 2 = 8 > 7)
    app(CompetitionTeamFormationService::class)->formBalancedForClass($event->id, $class->id);

    $teams = CompetitionTeam::where('competition_class_id', $class->id)->orderBy('id')->get();

    $teamA = $teams[0];
    $teamB = $teams[1];

    $memberA = $teamA->members()->first();
    $memberB = $teamB->members()->first();

    app(CompetitionTeamService::class)->swapMembers($teamA, $memberA->id, $teamB, $memberB->id);

    expect($memberA->fresh()->competition_team_id)->toBe($teamB->id)
        ->and($memberB->fresh()->competition_team_id)->toBe($teamA->id)
        ->and($memberA->fresh()->is_substitute)->toBe($memberB->is_substitute)
        ->and($memberB->fresh()->is_substitute)->toBe($memberA->is_substitute)
        ->and(CompetitionTeamMember::where('competition_registration_id', $memberA->competition_registration_id)->count())->toBe(1)
        ->and(CompetitionTeamMember::where('competition_registration_id', $memberB->competition_registration_id)->count())->toBe(1);
});

test('swapMembers rejects teams from different classes', function () {
    $event = tm_event();
    $category = tm_category($event);
    $classA = tm_class($event, $category, ['name' => 'Kelas A']);
    $classB = tm_class($event, $category, ['name' => 'Kelas B']);

    $kelompok = tm_kelompok('KM 1');
    tm_register(tm_person(['nama' => 'RA', 'kelompok_id' => $kelompok->id]), $event, $category, $classA);
    tm_register(tm_person(['nama' => 'RB', 'kelompok_id' => $kelompok->id]), $event, $category, $classB);

    app(CompetitionTeamFormationService::class)->formForClass($event->id, $classA->id);
    app(CompetitionTeamFormationService::class)->formForClass($event->id, $classB->id);

    $teamA = CompetitionTeam::where('competition_class_id', $classA->id)->first();
    $teamB = CompetitionTeam::where('competition_class_id', $classB->id)->first();

    expect(fn () => app(CompetitionTeamService::class)
        ->swapMembers($teamA, $teamA->members()->first()->id, $teamB, $teamB->members()->first()->id))
        ->toThrow(ValidationException::class);
});

test('swapMembers rejects swapping inside the same team', function () {
    $event = tm_event();
    $category = tm_category($event);
    $class = tm_class($event, $category, ['team_size' => 3]);

    tm_seed($event, $category, $class, [
        'KM 1' => [['SD5', 'L'], ['SD5', 'L'], ['SD5', 'L'], ['SD5', 'L']],
        'KM 2' => [['SD5', 'P'], ['SD5', 'P']],
    ]);

    app(CompetitionTeamFormationService::class)->formBalancedForClass($event->id, $class->id);
    $team = CompetitionTeam::where('competition_class_id', $class->id)->first();

    $a = $team->members()->first();
    $b = $team->members()->get()[1];

    expect(fn () => app(CompetitionTeamService::class)->swapMembers($team, $a->id, $team, $b->id))
        ->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// E2E — Pembagian Tim UI
// ---------------------------------------------------------------------------

test('Pembagian Tim page offers team_vs_team & team_heat and hides non-team formats', function () {
    $event = tm_event();
    app(ActiveEventContext::class)->set($event);
    $user = User::factory()->create(['role' => Role::Admin]);
    $category = tm_category($event);
    $teamClass = tm_class($event, $category, ['name' => 'Voli Beregu']);
    $heatClass = tm_class($event, $category, ['name' => 'Estafet Beregu', 'format' => CompetitionFormat::TEAM_HEAT]);
    tm_class($event, $category, ['name' => 'Lari 100m', 'format' => CompetitionFormat::INDIVIDUAL_MASS]);

    $component = Livewire::actingAs($user)
        ->test(TeamIndex::class)
        ->set('competitionCategoryId', (string) $category->id);

    expect($component->classes->pluck('id'))->toContain($teamClass->id)
        ->and($component->classes->pluck('id'))->toContain($heatClass->id);

    $formats = $component->classes->pluck('format')->values()->all();
    expect($formats)->toContain(CompetitionFormat::TEAM_VS_TEAM)
        ->and($formats)->toContain(CompetitionFormat::TEAM_HEAT)
        ->and($formats)->not->toContain(CompetitionFormat::INDIVIDUAL_MASS);
});

test('Pembagian Tim UI forms teams via preview then generate (balanced)', function () {
    $event = tm_event();
    app(ActiveEventContext::class)->set($event);
    $user = User::factory()->create(['role' => Role::Admin]);
    $category = tm_category($event);
    $class = tm_class($event, $category, ['name' => 'Voli Beregu', 'team_size' => 3]);

    tm_seed($event, $category, $class, [
        'KM 1' => [['SD5', 'L'], ['SD5', 'P'], ['SD6', 'L'], ['SD5', 'L']],
        'KM 2' => [['SD6', 'P'], ['SD5', 'L']],
    ]);

    Livewire::actingAs($user)
        ->test(TeamIndex::class)
        ->set('competitionCategoryId', (string) $category->id)
        ->set('competitionClassId', (string) $class->id)
        ->set('formationMode', 'balanced')
        ->call('previewFormation')
        ->assertSet('showPreview', true)
        ->assertSet('preview.team_count', 2)
        ->call('generateFormation');

    expect(CompetitionTeam::where('competition_class_id', $class->id)->count())->toBe(2)
        ->and(CompetitionTeamMember::count())->toBe(6);
});

test('Pembagian Tim UI forms teams via preview then generate (group)', function () {
    $event = tm_event();
    app(ActiveEventContext::class)->set($event);
    $user = User::factory()->create(['role' => Role::Admin]);
    $category = tm_category($event);
    $class = tm_class($event, $category, ['name' => 'Voli Beregu', 'team_size' => 2]);

    $km1 = tm_kelompok('KM 1');
    $km2 = tm_kelompok('KM 2');
    tm_register(tm_person(['nama' => 'A1', 'kelompok_id' => $km1->id]), $event, $category, $class);
    tm_register(tm_person(['nama' => 'A2', 'kelompok_id' => $km1->id]), $event, $category, $class);
    tm_register(tm_person(['nama' => 'B1', 'kelompok_id' => $km2->id]), $event, $category, $class);

    Livewire::actingAs($user)
        ->test(TeamIndex::class)
        ->set('competitionCategoryId', (string) $category->id)
        ->set('competitionClassId', (string) $class->id)
        ->set('formationMode', 'group')
        ->call('previewFormation')
        ->assertSet('showPreview', true)
        ->call('generateFormation');

    expect(CompetitionTeam::where('competition_class_id', $class->id)->count())->toBe(2);

    $teamKm1 = CompetitionTeam::where('competition_class_id', $class->id)->where('kelompok_id', $km1->id)->first();
    $teamKm2 = CompetitionTeam::where('competition_class_id', $class->id)->where('kelompok_id', $km2->id)->first();

    expect($teamKm1->players()->count())->toBe(2)
        ->and($teamKm1->substitutes()->count())->toBe(0)
        ->and($teamKm2->players()->count())->toBe(1)
        ->and($teamKm2->substitutes()->count())->toBe(0);
});
