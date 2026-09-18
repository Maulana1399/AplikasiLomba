<?php

use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionTeam;
use App\Models\CompetitionTeamMember;
use App\Models\Event;
use App\Models\kelompok;
use App\Models\Person;
use App\Services\Competition\CompetitionRegistrationService;
use App\Services\Competition\CompetitionTeamFormationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function r4f_event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'R4F Event '.str()->random(6),
        'slug' => 'r4f-'.str()->random(6),
        'status' => 'active',
        'event_type' => 'competition',
    ], $overrides));
}

function r4f_category(Event $event): CompetitionCategory
{
    $category = CompetitionCategory::create(['event_id' => $event->id, 'name' => 'R4F Cat '.str()->random(4)]);


    return $category;
}

function r4f_class(Event $event, CompetitionCategory $category, string $format = 'team_vs_team'): CompetitionClass
{
    return CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'R4F Class '.str()->random(4),
        'gender' => 'M',
        'format' => $format,
        'status' => 'registration_open',
        'is_active' => true,
    ]);
}

function r4f_kelompok(string $name): kelompok
{
    return kelompok::create(['kelompok_asal' => $name]);
}

function r4f_person(string $nama, kelompok $kelompok): Person
{
    return Person::create(['nama' => $nama, 'jenis_kelamin' => 'L', 'kelompok_id' => $kelompok->id]);
}

function r4f_register(Person $person, Event $event, CompetitionCategory $category, CompetitionClass $class): CompetitionRegistration
{
    return app(CompetitionRegistrationService::class)->registerForPerson(
        person: $person,
        eventId: $event->id,
        competitionCategoryId: $category->id,
        competitionClassId: $class->id,
    )['competition_registration'];
}

function r4f_teamMembers(CompetitionTeam $team): int
{
    return CompetitionTeamMember::where('competition_team_id', $team->id)->count();
}

// ---------------------------------------------------------------------------
// J — Manual protection
// ---------------------------------------------------------------------------

test('formation does not silently overwrite teams; rebuild is explicit', function () {
    $event = r4f_event();
    $category = r4f_category($event);
    $class = r4f_class($event, $category, 'team_vs_team');
    $km = r4f_kelompok('KM 7');
    foreach (range(1, 5) as $i) {
        r4f_register(r4f_person("P{$i}", $km), $event, $category, $class);
    }

    $service = app(CompetitionTeamFormationService::class);
    $service->formForClass($event->id, $class->id);

    $team = CompetitionTeam::where('competition_class_id', $class->id)->first();
    expect($team)->not->toBeNull();

    // Pengaturan manual: pindahkan anggota pertama jadi substitute.
    $firstMember = $team->players()->first();
    app(\App\Services\Competition\CompetitionTeamService::class)->setSubstitute($team, $firstMember->id, true);

    // Re-form tanpa force → ditolak (tidak menimpa diam-diam).
    expect(fn () => $service->formForClass($event->id, $class->id))
        ->toThrow(ValidationException::class);

    // Rebuild eksplisit → regenerasi.
    $service->formForClass($event->id, $class->id, null, true);

    $fresh = CompetitionTeam::where('competition_class_id', $class->id)->first();
    expect(r4f_teamMembers($fresh))->toBe(5)
        ->and($fresh->players()->count())->toBe(5);
});

// ---------------------------------------------------------------------------
// F — Class isolation (Mahasiswa KM 7 ≠ Umum KM 7)
// ---------------------------------------------------------------------------

test('same kelompok forms separate teams per class (no cross-class mixing)', function () {
    $event = r4f_event();
    $category = r4f_category($event);
    $classA = r4f_class($event, $category, 'team_vs_team');
    $classB = r4f_class($event, $category, 'team_vs_team');
    $km = r4f_kelompok('KM 7');

    $a1 = r4f_register(r4f_person('A1', $km), $event, $category, $classA);
    $a2 = r4f_register(r4f_person('A2', $km), $event, $category, $classA);
    $b1 = r4f_register(r4f_person('B1', $km), $event, $category, $classB);
    $b2 = r4f_register(r4f_person('B2', $km), $event, $category, $classB);

    $service = app(CompetitionTeamFormationService::class);
    $service->formForClass($event->id, $classA->id);
    $service->formForClass($event->id, $classB->id);

    $teamA = CompetitionTeam::where('competition_class_id', $classA->id)->first();
    $teamB = CompetitionTeam::where('competition_class_id', $classB->id)->first();

    expect($teamA->id)->not->toBe($teamB->id)
        ->and($teamA->kelompok_id)->toBe($km->id)
        ->and($teamB->kelompok_id)->toBe($km->id)
        ->and(CompetitionTeamMember::where('competition_team_id', $teamA->id)->pluck('competition_registration_id')->all())
        ->toContain($a1->id, $a2->id)
        ->and(CompetitionTeamMember::where('competition_team_id', $teamA->id)->pluck('competition_registration_id')->all())
        ->not->toContain($b1->id, $b2->id)
        ->and(CompetitionTeamMember::where('competition_team_id', $teamB->id)->pluck('competition_registration_id')->all())
        ->toContain($b1->id, $b2->id);
});

// ---------------------------------------------------------------------------
// G — Participant overlap: satu Person boleh di banyak team-competition
// ---------------------------------------------------------------------------

test('same person can join teams in multiple competitions (no duplicate Person)', function () {
    $event = r4f_event();
    $category = r4f_category($event);
    $classA = r4f_class($event, $category, 'team_vs_team');
    $classB = r4f_class($event, $category, 'team_vs_team');
    $km = r4f_kelompok('KM 7');

    $ahmad = Person::create(['nama' => 'Ahmad', 'jenis_kelamin' => 'L', 'kelompok_id' => $km->id]);
    $regA = r4f_register($ahmad, $event, $category, $classA);
    $regB = r4f_register($ahmad, $event, $category, $classB);
    r4f_register(r4f_person('Budi', $km), $event, $category, $classA);
    r4f_register(r4f_person('Candra', $km), $event, $category, $classB);

    $service = app(CompetitionTeamFormationService::class);
    $service->formForClass($event->id, $classA->id);
    $service->formForClass($event->id, $classB->id);

    $teamA = CompetitionTeam::where('competition_class_id', $classA->id)->first();
    $teamB = CompetitionTeam::where('competition_class_id', $classB->id)->first();

    // Ahmad ada di Team class A dan Team class B — Person tetap satu.
    expect(CompetitionTeamMember::where('competition_team_id', $teamA->id)->pluck('competition_registration_id')->all())
        ->toContain($regA->id)
        ->and(CompetitionTeamMember::where('competition_team_id', $teamB->id)->pluck('competition_registration_id')->all())
        ->toContain($regB->id)
        ->and(Person::where('nama', 'Ahmad')->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// A — team size smallest group (regression dari R4A, di-reuse utk R4F contract)
// ---------------------------------------------------------------------------

test('formation uses smallest group as team size (KM7=10,KM10=8,KM12=15,KM15=12 → 8)', function () {
    $event = r4f_event();
    $category = r4f_category($event);
    $class = r4f_class($event, $category, 'team_vs_team');

    $km7 = r4f_kelompok('KM 7');
    $km10 = r4f_kelompok('KM 10');
    $km12 = r4f_kelompok('KM 12');
    $km15 = r4f_kelompok('KM 15');
    $plan = [[$km7, 10], [$km10, 8], [$km12, 15], [$km15, 12]];
    foreach ($plan as [$kelompok, $count]) {
        foreach (range(1, $count) as $i) {
            r4f_register(r4f_person("{$kelompok->kelompok_asal}-{$i}", $kelompok), $event, $category, $class);
        }
    }

    $result = app(CompetitionTeamFormationService::class)->formForClass($event->id, $class->id);

    expect($result['team_size'])->toBe(8)
        ->and($result['teams'])->toHaveCount(4);

    $byName = collect($result['teams'])->keyBy('kelompok');
    expect($byName['KM 7'])->toMatchArray(['players' => 8, 'substitutes' => 2])
        ->and($byName['KM 10'])->toMatchArray(['players' => 8, 'substitutes' => 0])
        ->and($byName['KM 12'])->toMatchArray(['players' => 8, 'substitutes' => 7])
        ->and($byName['KM 15'])->toMatchArray(['players' => 8, 'substitutes' => 4]);
});
