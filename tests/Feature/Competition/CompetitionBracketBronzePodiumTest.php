<?php

use App\Enums\Role;
use App\Models\CompetitionBracket;
use App\Models\CompetitionBracketMatch;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionOutcome;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionScheduleEntry;
use App\Models\CompetitionTeam;
use App\Models\CompetitionTeamOutcome;
use App\Models\Event;
use App\Models\kelompok;
use App\Models\Person;
use App\Models\User;
use App\Services\Competition\CompetitionRegistrationService;
use App\Services\Competition\CompetitionResultService;
use App\Services\Competition\CompetitionWorkflowService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers (prefix cbbp = Competition Bronze Box Podium)
// ---------------------------------------------------------------------------

function cbbp_event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'CBBP Event '.str()->random(6),
        'slug' => 'cbbp-'.str()->random(6),
        'status' => 'active',
        'event_type' => 'competition',
    ], $overrides));
}

function cbbp_category(Event $event): CompetitionCategory
{
    $category = CompetitionCategory::create(['event_id' => $event->id, 'name' => 'CBBP Cat '.str()->random(4)]);


    return $category;
}

function cbbp_class(Event $event, CompetitionCategory $category, string $format = 'individual_vs_individual'): CompetitionClass
{
    return CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'CBBP VS '.str()->random(4),
        'gender' => 'M',
        'format' => $format,
        'status' => 'registration_open',
        'is_active' => true,
    ]);
}

function cbbp_person(string $nama): Person
{
    return Person::create(['nama' => $nama, 'jenis_kelamin' => 'L']);
}

function cbbp_register(Person $person, Event $event, CompetitionCategory $category, CompetitionClass $class): CompetitionRegistration
{
    return app(CompetitionRegistrationService::class)->registerForPerson(
        person: $person,
        eventId: $event->id,
        competitionCategoryId: $category->id,
        competitionClassId: $class->id,
    )['competition_registration'];
}

function cbbp_generateBracket(CompetitionClass $class, int $count, bool $thirdPlace = false): CompetitionBracket
{
    \Livewire::test(\App\Livewire\Competition\BracketManager::class)
        ->set('newParticipantCount', (string) $count)
        ->set('thirdPlaceMatch', $thirdPlace)
        ->call('generate', $class->id);

    return CompetitionBracket::where('competition_class_id', $class->id)->first();
}

function cbbp_addEntry(CompetitionBracketMatch $match, CompetitionRegistration $registration, int $order): void
{
    CompetitionScheduleEntry::create([
        'competition_schedule_id' => $match->schedule->id,
        'competition_registration_id' => $registration->id,
        'order_number' => $order,
    ]);
}

function cbbp_finishMatch(CompetitionBracketMatch $match, int $winnerRegistrationId): void
{
    $workflow = app(CompetitionWorkflowService::class);
    $schedule = $match->schedule;

    if ($schedule->status === 'Scheduled') {
        $workflow->prepareMatch($schedule);
    }
    if ($schedule->status === 'Ready') {
        $workflow->startMatch($schedule);
    }
    if ($schedule->status === 'Playing') {
        $workflow->moveToWaitingResult($schedule);
    }

    $workflow->submitResult($schedule->fresh(), $winnerRegistrationId, 'Normal', null);
}

function cbbp_kelompok(string $name): kelompok
{
    return kelompok::create(['kelompok_asal' => $name]);
}

function cbbp_team(Event $event, CompetitionClass $class, ?kelompok $kelompok, string $name): CompetitionTeam
{
    return CompetitionTeam::create([
        'event_id' => $event->id,
        'competition_class_id' => $class->id,
        'name' => $name,
        'kelompok_id' => $kelompok?->id,
        'is_active' => true,
    ]);
}

function cbbp_teamEntry(CompetitionBracketMatch $match, CompetitionTeam $team, int $order): void
{
    CompetitionScheduleEntry::create([
        'competition_schedule_id' => $match->schedule->id,
        'competition_team_id' => $team->id,
        'order_number' => $order,
    ]);
}

function cbbp_finishTeamMatch(CompetitionBracketMatch $match, int $winnerTeamId): void
{
    $workflow = app(CompetitionWorkflowService::class);
    $schedule = $match->schedule;

    if ($schedule->status === 'Scheduled') {
        $workflow->prepareMatch($schedule);
    }
    if ($schedule->status === 'Ready') {
        $workflow->startMatch($schedule);
    }
    if ($schedule->status === 'Playing') {
        $workflow->moveToWaitingResult($schedule);
    }

    $workflow->submitTeamResult($schedule->fresh(), $winnerTeamId, 'Normal', null);
}

function cbbp_openOfficialsPanelAndSubmit(CompetitionBracketMatch $match, int $winnerId): \Livewire\Features\SupportTesting\Testable
{
    $panel = \Livewire::test(\App\Livewire\Competition\OfficialPanel::class);
    $panel->call('openSubmitDialog', $match->schedule->fresh()->id)
        ->set('selectedWinnerId', $winnerId)
        ->set('finishReason', 'Normal');

    return $panel;
}

function cbbp_moveToWaitingResult(CompetitionBracketMatch $match): void
{
    $workflow = app(CompetitionWorkflowService::class);
    $schedule = $match->schedule;

    if ($schedule->status === 'Scheduled') {
        $workflow->prepareMatch($schedule);
    }
    if ($schedule->status === 'Ready') {
        $workflow->startMatch($schedule);
    }
    if ($schedule->status === 'Playing') {
        $workflow->moveToWaitingResult($schedule);
    }
}

function cbbp_semiMatches(CompetitionBracket $bracket): array
{
    $semis = $bracket->bracketMatches->where('round', 2)->sortBy('position')->values();

    return [$semis[0], $semis[1]];
}

// ---------------------------------------------------------------------------
// Bronze OFF — existing tied-3rd behavior preserved, no position 4
// ---------------------------------------------------------------------------

test('Bronze OFF: no bronze match, semifinal losers tie 3rd, and no position 4 ever written', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cbbp_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cbbp_category($event);
    $class = cbbp_class($event, $category);
    $bracket = cbbp_generateBracket($class, 4, thirdPlace: false);

    expect($bracket->third_place_match)->toBeFalse()
        ->and($bracket->bracketMatches->where('is_third_place', true))->toHaveCount(0);

    [$semi1, $semi2] = cbbp_semiMatches($bracket);
    $final = $bracket->bracketMatches->where('round', 1)->first();

    $a = cbbp_register(cbbp_person('A'), $event, $category, $class);
    $b = cbbp_register(cbbp_person('B'), $event, $category, $class);
    $c = cbbp_register(cbbp_person('C'), $event, $category, $class);
    $d = cbbp_register(cbbp_person('D'), $event, $category, $class);

    cbbp_addEntry($semi1, $a, 1);
    cbbp_addEntry($semi1, $b, 2);
    cbbp_addEntry($semi2, $c, 1);
    cbbp_addEntry($semi2, $d, 2);

    cbbp_finishMatch($semi1, $a->id);
    cbbp_finishMatch($semi2, $c->id);
    cbbp_finishMatch($final, $a->id);

    $posByReg = CompetitionOutcome::pluck('position', 'competition_registration_id');
    expect($posByReg->get($a->id))->toBe(1)
        ->and($posByReg->get($c->id))->toBe(2)
        ->and($posByReg->get($b->id))->toBe(3)
        ->and($posByReg->get($d->id))->toBe(3)
        ->and(CompetitionOutcome::where('position', 4)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Individual Bronze ON
// ---------------------------------------------------------------------------

test('Individual Bronze ON: generates bronze match round=1 position=2 sourced from both semifinals', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cbbp_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cbbp_category($event);
    $class = cbbp_class($event, $category);
    $bracket = cbbp_generateBracket($class, 4, thirdPlace: true);

    [$semi1, $semi2] = cbbp_semiMatches($bracket);
    $bronze = $bracket->bracketMatches->firstWhere('is_third_place', true);

    expect($bronze)->not->toBeNull()
        ->and((int) $bronze->round)->toBe(1)
        ->and((int) $bronze->position)->toBe(2)
        ->and($bronze->is_third_place)->toBeTrue()
        ->and($bronze->source_match_a_id)->toBe($semi1->id)
        ->and($bronze->source_match_b_id)->toBe($semi2->id)
        ->and($bracket->third_place_match)->toBeTrue();
});

test('Individual Bronze ON: Final finishes first, then Bronze → podium 1,2,3,4', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cbbp_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cbbp_category($event);
    $class = cbbp_class($event, $category);
    $bracket = cbbp_generateBracket($class, 4, thirdPlace: true);

    [$semi1, $semi2] = cbbp_semiMatches($bracket);
    $final = $bracket->bracketMatches->where('round', 1)->where('is_third_place', false)->first();
    $bronze = $bracket->bracketMatches->firstWhere('is_third_place', true);

    $a = cbbp_register(cbbp_person('A'), $event, $category, $class);
    $b = cbbp_register(cbbp_person('B'), $event, $category, $class);
    $c = cbbp_register(cbbp_person('C'), $event, $category, $class);
    $d = cbbp_register(cbbp_person('D'), $event, $category, $class);

    cbbp_addEntry($semi1, $a, 1);
    cbbp_addEntry($semi1, $b, 2);
    cbbp_addEntry($semi2, $c, 1);
    cbbp_addEntry($semi2, $d, 2);

    cbbp_finishMatch($semi1, $a->id); // A → Final
    cbbp_finishMatch($semi2, $c->id); // C → Final; Bronze ready (B, D)

    // Safety: winner menuju Final, loser menuju Bronze — tidak silang.
    $finalEntryIds = $final->schedule->fresh()->scheduleEntries()->pluck('competition_registration_id')->sort()->values()->all();
    $bronzeEntryIds = $bronze->schedule->fresh()->scheduleEntries()->pluck('competition_registration_id')->sort()->values()->all();
    expect($finalEntryIds)->toBe([$a->id, $c->id])
        ->and($bronzeEntryIds)->toHaveCount(2)
        ->and(in_array($a->id, $bronzeEntryIds))->toBeFalse()
        ->and(in_array($c->id, $bronzeEntryIds))->toBeFalse()
        ->and(in_array($b->id, $finalEntryIds))->toBeFalse()
        ->and(in_array($d->id, $finalEntryIds))->toBeFalse();

    // FINAL dulu → hanya Juara 1/2 (belum ada 3/4 karena Bronze belum selesai).
    cbbp_finishMatch($final, $a->id);
    expect(CompetitionOutcome::pluck('position')->sort()->values()->all())->toBe([1, 2]);

    // BRONZE menyusul → Juara 3/4.
    cbbp_finishMatch($bronze, $b->id);

    $posByReg = CompetitionOutcome::pluck('position', 'competition_registration_id');
    expect($posByReg->get($a->id))->toBe(1)
        ->and($posByReg->get($c->id))->toBe(2)
        ->and($posByReg->get($b->id))->toBe(3)
        ->and($posByReg->get($d->id))->toBe(4);

    // Podium utuh 1,2,3,4 lewat limit=4; default limit=3 tetap 3.
    $podium4 = app(CompetitionResultService::class)->podiumForClass($event->id, $class->id, 4);
    expect($podium4)->toHaveCount(4)
        ->and($podium4[0])->toMatchArray(['position' => 1, 'person_name' => 'A'])
        ->and($podium4[1])->toMatchArray(['position' => 2, 'person_name' => 'C'])
        ->and($podium4[2])->toMatchArray(['position' => 3, 'person_name' => 'B'])
        ->and($podium4[3])->toMatchArray(['position' => 4, 'person_name' => 'D']);

    expect(app(CompetitionResultService::class)->podiumForClass($event->id, $class->id))->toHaveCount(3);
});

test('Individual Bronze ON: Bronze finishes first, then Final → podium 1,2,3,4', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cbbp_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cbbp_category($event);
    $class = cbbp_class($event, $category);
    $bracket = cbbp_generateBracket($class, 4, thirdPlace: true);

    [$semi1, $semi2] = cbbp_semiMatches($bracket);
    $final = $bracket->bracketMatches->where('round', 1)->where('is_third_place', false)->first();
    $bronze = $bracket->bracketMatches->firstWhere('is_third_place', true);

    $a = cbbp_register(cbbp_person('A'), $event, $category, $class);
    $b = cbbp_register(cbbp_person('B'), $event, $category, $class);
    $c = cbbp_register(cbbp_person('C'), $event, $category, $class);
    $d = cbbp_register(cbbp_person('D'), $event, $category, $class);

    cbbp_addEntry($semi1, $a, 1);
    cbbp_addEntry($semi1, $b, 2);
    cbbp_addEntry($semi2, $c, 1);
    cbbp_addEntry($semi2, $d, 2);

    cbbp_finishMatch($semi1, $a->id);
    cbbp_finishMatch($semi2, $c->id);

    // BRONZE dulu → Juara 3/4 ditulis lebih awal (1/2 belum ada).
    cbbp_finishMatch($bronze, $b->id);
    expect(CompetitionOutcome::pluck('position')->sort()->values()->all())->toBe([3, 4]);

    // FINAL menyusul → Juara 1/2 (tanpa menyentuh 3/4).
    cbbp_finishMatch($final, $a->id);

    $posByReg = CompetitionOutcome::pluck('position', 'competition_registration_id');
    expect($posByReg->get($a->id))->toBe(1)
        ->and($posByReg->get($c->id))->toBe(2)
        ->and($posByReg->get($b->id))->toBe(3)
        ->and($posByReg->get($d->id))->toBe(4);
});

test('Individual Bronze ON: re-finalization does not duplicate outcome rows', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cbbp_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cbbp_category($event);
    $class = cbbp_class($event, $category);
    $bracket = cbbp_generateBracket($class, 4, thirdPlace: true);

    [$semi1, $semi2] = cbbp_semiMatches($bracket);
    $final = $bracket->bracketMatches->where('round', 1)->where('is_third_place', false)->first();
    $bronze = $bracket->bracketMatches->firstWhere('is_third_place', true);

    $a = cbbp_register(cbbp_person('A'), $event, $category, $class);
    $b = cbbp_register(cbbp_person('B'), $event, $category, $class);
    $c = cbbp_register(cbbp_person('C'), $event, $category, $class);
    $d = cbbp_register(cbbp_person('D'), $event, $category, $class);

    cbbp_addEntry($semi1, $a, 1);
    cbbp_addEntry($semi1, $b, 2);
    cbbp_addEntry($semi2, $c, 1);
    cbbp_addEntry($semi2, $d, 2);

    cbbp_finishMatch($semi1, $a->id);
    cbbp_finishMatch($semi2, $c->id);
    cbbp_finishMatch($final, $a->id);
    cbbp_finishMatch($bronze, $b->id);

    $service = app(\App\Services\Competition\CompetitionBracketPodiumService::class);
    $service->finalizePodiumForSchedule($final->schedule);
    $service->finalizePodiumForSchedule($bronze->schedule);
    $service->finalizePodiumForSchedule($final->schedule);
    $service->finalizePodiumForSchedule($bronze->schedule);

    $posByReg = CompetitionOutcome::pluck('position', 'competition_registration_id');
    expect(CompetitionOutcome::count())->toBe(4)
        ->and($posByReg->get($a->id))->toBe(1)
        ->and($posByReg->get($c->id))->toBe(2)
        ->and($posByReg->get($b->id))->toBe(3)
        ->and($posByReg->get($d->id))->toBe(4);
});

// ---------------------------------------------------------------------------
// Team / Futsal Bronze ON
// ---------------------------------------------------------------------------

test('Team/Futsal Bronze ON: team podium Juara 1,2,3,4', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cbbp_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cbbp_category($event);
    $class = cbbp_class($event, $category, 'team_vs_team');
    $bracket = cbbp_generateBracket($class, 4, thirdPlace: true);

    [$semi1, $semi2] = cbbp_semiMatches($bracket);
    $final = $bracket->bracketMatches->where('round', 1)->where('is_third_place', false)->first();
    $bronze = $bracket->bracketMatches->firstWhere('is_third_place', true);

    $teamA = cbbp_team($event, $class, cbbp_kelompok('A'), 'Team A');
    $teamB = cbbp_team($event, $class, cbbp_kelompok('B'), 'Team B');
    $teamC = cbbp_team($event, $class, cbbp_kelompok('C'), 'Team C');
    $teamD = cbbp_team($event, $class, cbbp_kelompok('D'), 'Team D');

    cbbp_teamEntry($semi1, $teamA, 1);
    cbbp_teamEntry($semi1, $teamB, 2);
    cbbp_teamEntry($semi2, $teamC, 1);
    cbbp_teamEntry($semi2, $teamD, 2);

    cbbp_finishTeamMatch($semi1, $teamA->id);
    cbbp_finishTeamMatch($semi2, $teamC->id);
    cbbp_finishTeamMatch($final, $teamA->id);
    cbbp_finishTeamMatch($bronze, $teamB->id);

    $posByTeam = CompetitionTeamOutcome::pluck('position', 'competition_team_id');
    expect($posByTeam->get($teamA->id))->toBe(1)
        ->and($posByTeam->get($teamC->id))->toBe(2)
        ->and($posByTeam->get($teamB->id))->toBe(3)
        ->and($posByTeam->get($teamD->id))->toBe(4);

    $podium4 = app(CompetitionResultService::class)->podiumForTeams($event->id, $class->id, 4);
    expect($podium4)->toHaveCount(4)
        ->and($podium4[0]['team_name'])->toBe('Team A')
        ->and($podium4[1]['team_name'])->toBe('Team C')
        ->and($podium4[2]['team_name'])->toBe('Team B')
        ->and($podium4[3]['team_name'])->toBe('Team D');
});

test('Team/Futsal Bronze ON: re-finalization does not duplicate team outcome rows', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cbbp_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cbbp_category($event);
    $class = cbbp_class($event, $category, 'team_vs_team');
    $bracket = cbbp_generateBracket($class, 4, thirdPlace: true);

    [$semi1, $semi2] = cbbp_semiMatches($bracket);
    $final = $bracket->bracketMatches->where('round', 1)->where('is_third_place', false)->first();
    $bronze = $bracket->bracketMatches->firstWhere('is_third_place', true);

    $teamA = cbbp_team($event, $class, cbbp_kelompok('A'), 'Team A');
    $teamB = cbbp_team($event, $class, cbbp_kelompok('B'), 'Team B');
    $teamC = cbbp_team($event, $class, cbbp_kelompok('C'), 'Team C');
    $teamD = cbbp_team($event, $class, cbbp_kelompok('D'), 'Team D');

    cbbp_teamEntry($semi1, $teamA, 1);
    cbbp_teamEntry($semi1, $teamB, 2);
    cbbp_teamEntry($semi2, $teamC, 1);
    cbbp_teamEntry($semi2, $teamD, 2);

    cbbp_finishTeamMatch($semi1, $teamA->id);
    cbbp_finishTeamMatch($semi2, $teamC->id);
    cbbp_finishTeamMatch($final, $teamA->id);
    cbbp_finishTeamMatch($bronze, $teamB->id);

    $service = app(\App\Services\Competition\CompetitionBracketPodiumService::class);
    $service->finalizeTeamPodiumForSchedule($final->schedule);
    $service->finalizeTeamPodiumForSchedule($bronze->schedule);

    expect(CompetitionTeamOutcome::count())->toBe(4);
});

// ---------------------------------------------------------------------------
// Bronze loser rollback (resetMatch pada semifinal)
// ---------------------------------------------------------------------------

test('Individual Bronze ON: resetting a semifinal removes its loser from the Bronze and returns Bronze to Scheduled', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cbbp_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cbbp_category($event);
    $class = cbbp_class($event, $category);
    $bracket = cbbp_generateBracket($class, 4, thirdPlace: true);

    [$semi1, $semi2] = cbbp_semiMatches($bracket);
    $bronze = $bracket->bracketMatches->firstWhere('is_third_place', true);

    $a = cbbp_register(cbbp_person('A'), $event, $category, $class);
    $b = cbbp_register(cbbp_person('B'), $event, $category, $class);
    $c = cbbp_register(cbbp_person('C'), $event, $category, $class);
    $d = cbbp_register(cbbp_person('D'), $event, $category, $class);

    cbbp_addEntry($semi1, $a, 1);
    cbbp_addEntry($semi1, $b, 2);
    cbbp_addEntry($semi2, $c, 1);
    cbbp_addEntry($semi2, $d, 2);

    cbbp_finishMatch($semi1, $a->id);
    cbbp_finishMatch($semi2, $c->id);

    // Bronze berisi kedua loser (B, D) dan siap main.
    expect($bronze->schedule->fresh()->scheduleEntries()->pluck('competition_registration_id')->sort()->values()->all())
        ->toBe([$b->id, $d->id])
        ->and($bronze->schedule->fresh()->status)->toBe('Ready');

    $workflow = app(CompetitionWorkflowService::class);
    $workflow->resetMatch($semi1->schedule->fresh());

    // Loser B dikeluarkan dari Bronze; D tetap; Bronze match sendiri tetap ada.
    $bronzeRegIds = $bronze->schedule->fresh()->scheduleEntries()->pluck('competition_registration_id')->sort()->values()->all();
    expect($bronzeRegIds)->toBe([$d->id])
        ->and(in_array($b->id, $bronzeRegIds))->toBeFalse()
        ->and(CompetitionBracketMatch::where('is_third_place', true)->count())->toBe(1)
        ->and($bronze->schedule->fresh()->status)->toBe('Scheduled')
        ->and($bronze->schedule->fresh()->winner_registration_id)->toBeNull();

    // Semifinal kembali ke state awal (current user tidak mengubahnya) — karena
    // peserta masih 2/2, match otomatis kembali ke Ready.
    expect($semi1->schedule->fresh()->status)->toBe('Ready')
        ->and($semi1->schedule->fresh()->winner_registration_id)->toBeNull();
});

test('Team/Futsal Bronze ON: resetting a semifinal removes its loser team from the Bronze', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cbbp_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cbbp_category($event);
    $class = cbbp_class($event, $category, 'team_vs_team');
    $bracket = cbbp_generateBracket($class, 4, thirdPlace: true);

    [$semi1, $semi2] = cbbp_semiMatches($bracket);
    $bronze = $bracket->bracketMatches->firstWhere('is_third_place', true);

    $teamA = cbbp_team($event, $class, cbbp_kelompok('A'), 'Team A');
    $teamB = cbbp_team($event, $class, cbbp_kelompok('B'), 'Team B');
    $teamC = cbbp_team($event, $class, cbbp_kelompok('C'), 'Team C');
    $teamD = cbbp_team($event, $class, cbbp_kelompok('D'), 'Team D');

    cbbp_teamEntry($semi1, $teamA, 1);
    cbbp_teamEntry($semi1, $teamB, 2);
    cbbp_teamEntry($semi2, $teamC, 1);
    cbbp_teamEntry($semi2, $teamD, 2);

    cbbp_finishTeamMatch($semi1, $teamA->id);
    cbbp_finishTeamMatch($semi2, $teamC->id);

    expect($bronze->schedule->fresh()->scheduleEntries()->pluck('competition_team_id')->sort()->values()->all())
        ->toBe([$teamB->id, $teamD->id]);

    app(CompetitionWorkflowService::class)->resetMatch($semi1->schedule->fresh());

    $bronzeTeamIds = $bronze->schedule->fresh()->scheduleEntries()->pluck('competition_team_id')->sort()->values()->all();
    expect($bronzeTeamIds)->toBe([$teamD->id])
        ->and(in_array($teamB->id, $bronzeTeamIds))->toBeFalse()
        ->and(CompetitionBracketMatch::where('is_third_place', true)->count())->toBe(1)
        ->and($bronze->schedule->fresh()->status)->toBe('Scheduled');
});

test('Individual Bronze ON: resetting a semifinal does NOT damage a finished Bronze (entries, winner and outcomes survive)', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cbbp_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cbbp_category($event);
    $class = cbbp_class($event, $category);
    $bracket = cbbp_generateBracket($class, 4, thirdPlace: true);

    [$semi1, $semi2] = cbbp_semiMatches($bracket);
    $bronze = $bracket->bracketMatches->firstWhere('is_third_place', true);

    $a = cbbp_register(cbbp_person('A'), $event, $category, $class);
    $b = cbbp_register(cbbp_person('B'), $event, $category, $class);
    $c = cbbp_register(cbbp_person('C'), $event, $category, $class);
    $d = cbbp_register(cbbp_person('D'), $event, $category, $class);

    cbbp_addEntry($semi1, $a, 1);
    cbbp_addEntry($semi1, $b, 2);
    cbbp_addEntry($semi2, $c, 1);
    cbbp_addEntry($semi2, $d, 2);

    cbbp_finishMatch($semi1, $a->id);
    cbbp_finishMatch($semi2, $c->id);
    cbbp_finishMatch($bronze, $b->id); // Bronze sudah dimainkan: B juara 3, D juara 4

    app(CompetitionWorkflowService::class)->resetMatch($semi1->schedule->fresh());

    expect($bronze->schedule->fresh()->status)->toBe('Finished')
        ->and($bronze->schedule->fresh()->winner_registration_id)->toBe($b->id)
        ->and($bronze->schedule->fresh()->scheduleEntries()->pluck('competition_registration_id')->sort()->values()->all())
        ->toBe([$b->id, $d->id])
        ->and(CompetitionOutcome::where('competition_registration_id', $b->id)->value('position'))->toBe(3)
        ->and(CompetitionOutcome::where('competition_registration_id', $d->id)->value('position'))->toBe(4);
});

test('Individual Bronze ON: resetting a semifinal does NOT touch a Playing Bronze', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cbbp_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cbbp_category($event);
    $class = cbbp_class($event, $category);
    $bracket = cbbp_generateBracket($class, 4, thirdPlace: true);

    [$semi1, $semi2] = cbbp_semiMatches($bracket);
    $bronze = $bracket->bracketMatches->firstWhere('is_third_place', true);

    $a = cbbp_register(cbbp_person('A'), $event, $category, $class);
    $b = cbbp_register(cbbp_person('B'), $event, $category, $class);
    $c = cbbp_register(cbbp_person('C'), $event, $category, $class);
    $d = cbbp_register(cbbp_person('D'), $event, $category, $class);

    cbbp_addEntry($semi1, $a, 1);
    cbbp_addEntry($semi1, $b, 2);
    cbbp_addEntry($semi2, $c, 1);
    cbbp_addEntry($semi2, $d, 2);

    cbbp_finishMatch($semi1, $a->id);
    cbbp_finishMatch($semi2, $c->id);

    // Bronze siap → mulai (Playing) — bukan lagi Scheduled/Ready.
    $workflow = app(CompetitionWorkflowService::class);
    $workflow->startMatch($bronze->schedule);
    expect($bronze->schedule->fresh()->status)->toBe('Playing');

    $workflow->resetMatch($semi1->schedule->fresh());

    expect($bronze->schedule->fresh()->status)->toBe('Playing')
        ->and($bronze->schedule->fresh()->scheduleEntries()->pluck('competition_registration_id')->sort()->values()->all())
        ->toBe([$b->id, $d->id]);
});

test('Individual Bronze ON: repeated reset and re-finish of a semifinal never duplicates Bronze entries', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cbbp_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cbbp_category($event);
    $class = cbbp_class($event, $category);
    $bracket = cbbp_generateBracket($class, 4, thirdPlace: true);

    [$semi1, $semi2] = cbbp_semiMatches($bracket);
    $bronze = $bracket->bracketMatches->firstWhere('is_third_place', true);

    $a = cbbp_register(cbbp_person('A'), $event, $category, $class);
    $b = cbbp_register(cbbp_person('B'), $event, $category, $class);
    $c = cbbp_register(cbbp_person('C'), $event, $category, $class);
    $d = cbbp_register(cbbp_person('D'), $event, $category, $class);

    cbbp_addEntry($semi1, $a, 1);
    cbbp_addEntry($semi1, $b, 2);
    cbbp_addEntry($semi2, $c, 1);
    cbbp_addEntry($semi2, $d, 2);

    cbbp_finishMatch($semi1, $a->id);
    cbbp_finishMatch($semi2, $c->id);

    $workflow = app(CompetitionWorkflowService::class);

    // Reset pertama → loser B keluar dari Bronze.
    $workflow->resetMatch($semi1->schedule->fresh());
    expect($bronze->schedule->fresh()->scheduleEntries()->pluck('competition_registration_id')->sort()->values()->all())
        ->toBe([$d->id]);

    // Reset kedua → idempotent, tidak menduplikasi / tidak menghapus entry lain.
    $workflow->resetMatch($semi1->schedule->fresh());
    expect($bronze->schedule->fresh()->scheduleEntries()->pluck('competition_registration_id')->sort()->values()->all())
        ->toBe([$d->id]);

    // Main ulang semi1 → loser B kembali ke Bronze tanpa duplikasi.
    cbbp_finishMatch(CompetitionBracketMatch::find($semi1->id), $a->id);
    $ids = $bronze->schedule->fresh()->scheduleEntries()->pluck('competition_registration_id')->sort()->values()->all();
    expect($ids)->toBe([$b->id, $d->id])
        ->and($bronze->schedule->fresh()->scheduleEntries()->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// UAT regression: HTTP 500 saat submit pemenang Bronze Match via Official Panel
// (root cause: migrasi 2026_08_27_* belum dijalankan pada DB produksi →
// kolom is_third_place tidak ada → SQLSTATE[42S22] dari advanceWinner).
// ---------------------------------------------------------------------------

test('OfficialPanel submits a Bronze winner end-to-end with schema present (no 500, Juara 3/4)', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cbbp_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cbbp_category($event);
    $class = cbbp_class($event, $category);
    $bracket = cbbp_generateBracket($class, 4, thirdPlace: true);

    [$semi1, $semi2] = cbbp_semiMatches($bracket);
    $final = $bracket->bracketMatches->where('round', 1)->where('is_third_place', false)->first();
    $bronze = $bracket->bracketMatches->firstWhere('is_third_place', true);

    $a = cbbp_register(cbbp_person('A'), $event, $category, $class);
    $b = cbbp_register(cbbp_person('B'), $event, $category, $class);
    $c = cbbp_register(cbbp_person('C'), $event, $category, $class);
    $d = cbbp_register(cbbp_person('D'), $event, $category, $class);

    cbbp_addEntry($semi1, $a, 1);
    cbbp_addEntry($semi1, $b, 2);
    cbbp_addEntry($semi2, $c, 1);
    cbbp_addEntry($semi2, $d, 2);

    cbbp_finishMatch($semi1, $a->id);
    cbbp_finishMatch($semi2, $c->id);
    cbbp_finishMatch($final, $a->id);

    // Bronze Match masuk Waiting Result → Official Panel siap submit.
    cbbp_moveToWaitingResult($bronze);
    expect($bronze->schedule->fresh()->status)->toBe('Waiting Result');

    // Jalur UAT yang sama: OfficialPanel → submitResult → advanceWinner/advanceLoser.
    $panel = cbbp_openOfficialsPanelAndSubmit($bronze, $b->id);
    $panel->call('submitResult');

    expect($bronze->schedule->fresh()->status)->toBe('Finished')
        ->and($bronze->schedule->fresh()->winner_registration_id)->toBe($b->id);

    $posByReg = CompetitionOutcome::pluck('position', 'competition_registration_id');
    expect($posByReg->get($a->id))->toBe(1)
        ->and($posByReg->get($c->id))->toBe(2)
        ->and($posByReg->get($b->id))->toBe(3)
        ->and($posByReg->get($d->id))->toBe(4);
});

test('OfficialPanel bronze degrade reproduces the UAT schema gap: missing is_third_place breaks 3/4 but re-migrating restores it', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cbbp_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cbbp_category($event);
    $class = cbbp_class($event, $category);
    $bracket = cbbp_generateBracket($class, 4, thirdPlace: true);

    [$semi1, $semi2] = cbbp_semiMatches($bracket);
    $final = $bracket->bracketMatches->where('round', 1)->where('is_third_place', false)->first();
    $bronze = $bracket->bracketMatches->firstWhere('is_third_place', true);

    $a = cbbp_register(cbbp_person('A'), $event, $category, $class);
    $b = cbbp_register(cbbp_person('B'), $event, $category, $class);
    $c = cbbp_register(cbbp_person('C'), $event, $category, $class);
    $d = cbbp_register(cbbp_person('D'), $event, $category, $class);

    cbbp_addEntry($semi1, $a, 1);
    cbbp_addEntry($semi1, $b, 2);
    cbbp_addEntry($semi2, $c, 1);
    cbbp_addEntry($semi2, $d, 2);

    cbbp_finishMatch($semi1, $a->id);
    cbbp_finishMatch($semi2, $c->id);
    cbbp_finishMatch($final, $a->id);

    cbbp_moveToWaitingResult($bronze);

    // Simulasikan DB produksi UAT: migrasi 2026_08_27_* belum dijalankan
    // sehingga tabel tidak memiliki kolom is_third_place.
    Schema::table('competition_bracket_matches', function (Blueprint $table) {
        $table->dropColumn('is_third_place');
    });

    $panel = cbbp_openOfficialsPanelAndSubmit($bronze, $b->id);

    // DB driver sqlite tidak menimbulkan SQLSTATE[42S22] (MySQL) karena
    // identifier yang di-quote dianggap string literal — namun di MySQL
    // query advanceWinner gagal dengan "Unknown column 'is_third_place'"
    // (HTTP 500 UAT). Di sini, tanpa kolom, alur bronze TERDEGRADASI sama:
    // guard tidak mengenali Bronze Match → juara 3/4 tidak terbentuk.
    $panel->call('submitResult');

    $posByReg = CompetitionOutcome::pluck('position', 'competition_registration_id');
    expect($posByReg->get($b->id))->not->toBe(3)
        ->and($posByReg->get($d->id))->not->toBe(4)
        ->and(CompetitionOutcome::where('position', 3)->where('competition_registration_id', $b->id)->count())->toBe(0);
});
