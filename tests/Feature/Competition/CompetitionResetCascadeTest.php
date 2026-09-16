<?php

use App\Enums\Role;
use App\Models\CompetitionBracket;
use App\Models\CompetitionBracketMatch;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionOutcome;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionTeam;
use App\Models\CompetitionTeamOutcome;
use App\Models\Event;
use App\Models\kelompok;
use App\Models\Person;
use App\Models\User;
use App\Services\Competition\CompetitionRegistrationService;
use App\Services\Competition\CompetitionResultService;
use App\Services\Competition\CompetitionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers (prefix crc = Competition Reset Cascade)
// ---------------------------------------------------------------------------

function crc_event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'CRC Event '.str()->random(6),
        'slug' => 'crc-'.str()->random(6),
        'status' => 'active',
        'event_type' => 'competition',
    ], $overrides));
}

function crc_category(Event $event): CompetitionCategory
{
    $category = CompetitionCategory::create(['event_id' => $event->id, 'name' => 'CRC Cat '.str()->random(4)]);

    $category->events()->attach($event);

    return $category;
}

function crc_class(Event $event, CompetitionCategory $category, string $format = 'individual_vs_individual'): CompetitionClass
{
    return CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'CRC '.str()->random(4),
        'gender' => 'M',
        'format' => $format,
        'status' => 'registration_open',
        'is_active' => true,
    ]);
}

function crc_person(string $nama): Person
{
    return Person::create(['nama' => $nama, 'jenis_kelamin' => 'L']);
}

function crc_register(Person $person, Event $event, CompetitionCategory $category, CompetitionClass $class): CompetitionRegistration
{
    return app(CompetitionRegistrationService::class)->registerForPerson(
        person: $person,
        eventId: $event->id,
        competitionCategoryId: $category->id,
        competitionClassId: $class->id,
    )['competition_registration'];
}

function crc_generateBracket(CompetitionClass $class, int $count, bool $thirdPlace = false): CompetitionBracket
{
    \Livewire::test(\App\Livewire\Competition\BracketManager::class)
        ->set('newParticipantCount', (string) $count)
        ->set('thirdPlaceMatch', $thirdPlace)
        ->call('generate', $class->id);

    return CompetitionBracket::where('competition_class_id', $class->id)->first();
}

function crc_finishMatch(CompetitionBracketMatch $match, int $winnerRegistrationId): void
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

function crc_kelompok(string $name): kelompok
{
    return kelompok::create(['kelompok_asal' => $name]);
}

function crc_team(Event $event, CompetitionClass $class, string $name): CompetitionTeam
{
    return CompetitionTeam::create([
        'event_id' => $event->id,
        'competition_class_id' => $class->id,
        'name' => $name,
        'kelompok_id' => crc_kelompok($name)->id,
        'is_active' => true,
    ]);
}

function crc_finishTeamMatch(CompetitionBracketMatch $match, int $winnerTeamId): void
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

function crc_moveToWaitingResult(CompetitionBracketMatch $match): void
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

function crc_playRounds(CompetitionBracket $bracket, array $winnersByRound): void
{
    foreach ($winnersByRound as $round => $winners) {
        $matches = $bracket->bracketMatches->where('round', $round)->sortBy('position')->values();

        foreach ($winners as $index => $winnerId) {
            crc_finishMatch($matches[$index], $winnerId);
        }
    }
}

// ---------------------------------------------------------------------------
// Root cause regression: reset must reconcile status back to Ready when 2/2
// ---------------------------------------------------------------------------

test('UAT readiness: Finished bracket final 2/2 reset → Ready', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = crc_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = crc_category($event);
    $class = crc_class($event, $category);

    $a = crc_register(crc_person('A'), $event, $category, $class);
    $b = crc_register(crc_person('B'), $event, $category, $class);
    $c = crc_register(crc_person('C'), $event, $category, $class);
    $d = crc_register(crc_person('D'), $event, $category, $class);

    $bracket = crc_generateBracket($class, 4);
    [$semi1, $semi2] = [$bracket->bracketMatches->where('round', 2)->sortBy('position')->values()[0], $bracket->bracketMatches->where('round', 2)->sortBy('position')->values()[1]];
    $final = $bracket->bracketMatches->where('round', 1)->where('is_third_place', false)->first();

    crc_finishMatch($semi1, $a->id);
    crc_finishMatch($semi2, $c->id);
    crc_finishMatch($final, $a->id);

    expect($final->schedule->fresh()->status)->toBe('Finished')
        ->and($final->schedule->fresh()->scheduleEntries()->count())->toBe(2);

    $workflow = app(CompetitionWorkflowService::class);
    $result = $workflow->resetMatch($final->schedule->fresh());

    expect($result['reset'])->toBeTrue()
        ->and($result['status'])->toBe('Ready')
        ->and($final->schedule->fresh()->status)->toBe('Ready')
        ->and($final->schedule->fresh()->winner_registration_id)->toBeNull()
        ->and($final->schedule->fresh()->scheduleEntries()->count())->toBe(2)
        ->and(CompetitionOutcome::count())->toBe(0);
});

test('UAT readiness: Playing bracket match 2/2 reset → Ready', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = crc_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = crc_category($event);
    $class = crc_class($event, $category);

    $a = crc_register(crc_person('A'), $event, $category, $class);
    $b = crc_register(crc_person('B'), $event, $category, $class);
    $c = crc_register(crc_person('C'), $event, $category, $class);
    $d = crc_register(crc_person('D'), $event, $category, $class);

    $bracket = crc_generateBracket($class, 4);
    [$semi1, $semi2] = [$bracket->bracketMatches->where('round', 2)->sortBy('position')->values()[0], $bracket->bracketMatches->where('round', 2)->sortBy('position')->values()[1]];
    $final = $bracket->bracketMatches->where('round', 1)->where('is_third_place', false)->first();

    crc_finishMatch($semi1, $a->id);
    crc_finishMatch($semi2, $c->id);

    app(CompetitionWorkflowService::class)->startMatch($final->schedule);
    expect($final->schedule->fresh()->status)->toBe('Playing');

    $result = app(CompetitionWorkflowService::class)->resetMatch($final->schedule->fresh());

    expect($result['reset'])->toBeTrue()
        ->and($result['status'])->toBe('Ready')
        ->and($final->schedule->fresh()->status)->toBe('Ready')
        ->and($final->schedule->fresh()->winner_registration_id)->toBeNull()
        ->and($final->schedule->fresh()->scheduleEntries()->count())->toBe(2)
        ->and($semi1->schedule->fresh()->status)->toBe('Finished');
});

test('UAT readiness: bracket match reset with 1/2 participants stays Scheduled', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = crc_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = crc_category($event);
    $class = crc_class($event, $category);

    $a = crc_register(crc_person('A'), $event, $category, $class);
    $b = crc_register(crc_person('B'), $event, $category, $class);

    $bracket = crc_generateBracket($class, 4);
    $initial = $bracket->bracketMatches->where('round', 2)->sortBy('position')->first();

    $initial->schedule->scheduleEntries()
        ->where('competition_registration_id', $b->id)
        ->delete();
    $initial->schedule->update(['status' => 'Finished']);

    $result = app(CompetitionWorkflowService::class)->resetMatch($initial->schedule->fresh());

    expect($result['reset'])->toBeTrue()
        ->and($result['status'])->toBe('Scheduled')
        ->and($initial->schedule->fresh()->status)->toBe('Scheduled')
        ->and($initial->schedule->fresh()->scheduleEntries()->count())->toBe(1);
});

test('UAT readiness: bracket match reset with 0/2 participants stays Scheduled', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = crc_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = crc_category($event);
    $class = crc_class($event, $category);

    crc_register(crc_person('A'), $event, $category, $class);
    crc_register(crc_person('B'), $event, $category, $class);

    $bracket = crc_generateBracket($class, 4);
    $initial = $bracket->bracketMatches->where('round', 2)->sortBy('position')->first();

    $initial->schedule->scheduleEntries()->delete();
    $initial->schedule->update(['status' => 'Finished']);

    $result = app(CompetitionWorkflowService::class)->resetMatch($initial->schedule->fresh());

    expect($result['reset'])->toBeTrue()
        ->and($result['status'])->toBe('Scheduled')
        ->and($initial->schedule->fresh()->status)->toBe('Scheduled')
        ->and($initial->schedule->fresh()->scheduleEntries()->count())->toBe(0);
});

test('UAT readiness: Team/Futsal finished final 2/2 reset → Ready', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = crc_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = crc_category($event);
    $class = crc_class($event, $category, 'team_vs_team');

    $t1 = crc_team($event, $class, 'T1');
    $t2 = crc_team($event, $class, 'T2');
    $t3 = crc_team($event, $class, 'T3');
    $t4 = crc_team($event, $class, 'T4');

    $bracket = crc_generateBracket($class, 4);
    [$semi1, $semi2] = [$bracket->bracketMatches->where('round', 2)->sortBy('position')->values()[0], $bracket->bracketMatches->where('round', 2)->sortBy('position')->values()[1]];
    $final = $bracket->bracketMatches->where('round', 1)->where('is_third_place', false)->first();

    crc_finishTeamMatch($semi1, $t1->id);
    crc_finishTeamMatch($semi2, $t3->id);
    crc_finishTeamMatch($final, $t1->id);

    $result = app(CompetitionWorkflowService::class)->resetMatch($final->schedule->fresh());

    expect($result['reset'])->toBeTrue()
        ->and($result['status'])->toBe('Ready')
        ->and($final->schedule->fresh()->status)->toBe('Ready')
        ->and($final->schedule->fresh()->winner_team_id)->toBeNull()
        ->and($final->schedule->fresh()->scheduleEntries()->count())->toBe(2)
        ->and(CompetitionTeamOutcome::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Bronze ON readiness and consistency
// ---------------------------------------------------------------------------

test('Bronze ON: resetting the final keeps Final and Bronze both Ready 2/2', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = crc_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = crc_category($event);
    $class = crc_class($event, $category);

    $a = crc_register(crc_person('A'), $event, $category, $class);
    $b = crc_register(crc_person('B'), $event, $category, $class);
    $c = crc_register(crc_person('C'), $event, $category, $class);
    $d = crc_register(crc_person('D'), $event, $category, $class);

    $bracket = crc_generateBracket($class, 4, thirdPlace: true);
    [$semi1, $semi2] = [$bracket->bracketMatches->where('round', 2)->sortBy('position')->values()[0], $bracket->bracketMatches->where('round', 2)->sortBy('position')->values()[1]];
    $final = $bracket->bracketMatches->where('round', 1)->where('is_third_place', false)->first();
    $bronze = $bracket->bracketMatches->firstWhere('is_third_place', true);

    crc_finishMatch($semi1, $a->id);
    crc_finishMatch($semi2, $c->id);

    expect($bronze->schedule->fresh()->status)->toBe('Ready')
        ->and($bronze->schedule->fresh()->scheduleEntries()->count())->toBe(2);

    crc_finishMatch($final, $a->id);

    $result = app(CompetitionWorkflowService::class)->resetMatch($final->schedule->fresh());

    expect($result['reset'])->toBeTrue()
        ->and($final->schedule->fresh()->status)->toBe('Ready')
        ->and($final->schedule->fresh()->scheduleEntries()->count())->toBe(2)
        ->and($bronze->schedule->fresh()->status)->toBe('Ready')
        ->and($bronze->schedule->fresh()->scheduleEntries()->count())->toBe(2);
});

test('Bronze ON: resetting a semifinal leaves Bronze 1/2 → Scheduled and Final 1/2 → Scheduled', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = crc_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = crc_category($event);
    $class = crc_class($event, $category);

    $a = crc_register(crc_person('A'), $event, $category, $class);
    $b = crc_register(crc_person('B'), $event, $category, $class);
    $c = crc_register(crc_person('C'), $event, $category, $class);
    $d = crc_register(crc_person('D'), $event, $category, $class);

    $bracket = crc_generateBracket($class, 4, thirdPlace: true);
    [$semi1, $semi2] = [$bracket->bracketMatches->where('round', 2)->sortBy('position')->values()[0], $bracket->bracketMatches->where('round', 2)->sortBy('position')->values()[1]];
    $final = $bracket->bracketMatches->where('round', 1)->where('is_third_place', false)->first();
    $bronze = $bracket->bracketMatches->firstWhere('is_third_place', true);

    crc_finishMatch($semi1, $a->id);
    crc_finishMatch($semi2, $c->id);
    crc_finishMatch($final, $a->id);

    app(CompetitionWorkflowService::class)->resetMatch($semi1->schedule->fresh());

    expect($bronze->schedule->fresh()->status)->toBe('Scheduled')
        ->and($bronze->schedule->fresh()->scheduleEntries()->pluck('competition_registration_id')->all())->toBe([$d->id])
        ->and($final->schedule->fresh()->status)->toBe('Scheduled')
        ->and($final->schedule->fresh()->scheduleEntries()->pluck('competition_registration_id')->all())->toBe([$c->id])
        ->and($semi1->schedule->fresh()->status)->toBe('Ready')
        ->and($semi1->schedule->fresh()->scheduleEntries()->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// Cascade invalidation with readiness reconciliation
// ---------------------------------------------------------------------------

test('cascade: resetting an individual QF invalidates its Finished SF and Final (1/2 → Scheduled)', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = crc_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = crc_category($event);
    $class = crc_class($event, $category);

    $regs = [];
    foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'] as $name) {
        $regs[] = crc_register(crc_person($name), $event, $category, $class);
    }
    [$a, $b, $c, $d, $e, $f, $g, $h] = $regs;

    $bracket = crc_generateBracket($class, 8);

    $qf = $bracket->bracketMatches->where('round', 3)->sortBy('position')->values();
    $sf = $bracket->bracketMatches->where('round', 2)->sortBy('position')->values();
    $final = $bracket->bracketMatches->where('round', 1)->where('is_third_place', false)->first();

    crc_finishMatch($qf[0], $a->id);
    crc_finishMatch($qf[1], $c->id);
    crc_finishMatch($qf[2], $e->id);
    crc_finishMatch($qf[3], $g->id);
    crc_finishMatch($sf[0], $a->id);
    crc_finishMatch($sf[1], $e->id);
    crc_finishMatch($final, $a->id);

    $posByReg = CompetitionOutcome::pluck('position', 'competition_registration_id');
    expect($posByReg->get($a->id))->toBe(1)
        ->and($posByReg->get($e->id))->toBe(2)
        ->and($posByReg->get($c->id))->toBe(3)
        ->and($posByReg->get($g->id))->toBe(3);

    $result = app(CompetitionWorkflowService::class)->resetMatch($qf[0]->schedule->fresh());

    expect($result['reset'])->toBeTrue()
        ->and($result['invalidated_downstream'])->toBeTrue();

    // Reset match sendiri: peserta masih 2/2 → Ready.
    expect($qf[0]->schedule->fresh()->status)->toBe('Ready')
        ->and($qf[0]->schedule->fresh()->scheduleEntries()->count())->toBe(2)
        ->and($qf[0]->schedule->fresh()->winner_registration_id)->toBeNull();

    // Downstream SF1: kehilangan A → 1/2 → Scheduled.
    expect($sf[0]->schedule->fresh()->status)->toBe('Scheduled')
        ->and($sf[0]->schedule->fresh()->winner_registration_id)->toBeNull()
        ->and($sf[0]->schedule->fresh()->scheduleEntries()->pluck('competition_registration_id')->all())->toBe([$c->id]);

    // Final: kehilangan A → 1/2 → Scheduled, outcome podium bersih.
    expect($final->schedule->fresh()->status)->toBe('Scheduled')
        ->and($final->schedule->fresh()->winner_registration_id)->toBeNull()
        ->and($final->schedule->fresh()->scheduleEntries()->pluck('competition_registration_id')->all())->toBe([$e->id]);

    expect(CompetitionOutcome::count())->toBe(0);

    // Branch lain tidak tersentuh.
    expect($sf[1]->schedule->fresh()->status)->toBe('Finished')
        ->and($qf[2]->schedule->fresh()->status)->toBe('Finished')
        ->and($qf[3]->schedule->fresh()->status)->toBe('Finished');
});

test('cascade: resetting a team QF invalidates its Finished SF and Final (1/2 → Scheduled)', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = crc_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = crc_category($event);
    $class = crc_class($event, $category, 'team_vs_team');

    $teams = [];
    foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $n) {
        $teams[] = crc_team($event, $class, 'XT'.$n);
    }
    [$t1, $t2, $t3, $t4, $t5, $t6, $t7, $t8] = $teams;

    $bracket = crc_generateBracket($class, 8);

    $qf = $bracket->bracketMatches->where('round', 3)->sortBy('position')->values();
    $sf = $bracket->bracketMatches->where('round', 2)->sortBy('position')->values();
    $final = $bracket->bracketMatches->where('round', 1)->where('is_third_place', false)->first();

    crc_finishTeamMatch($qf[0], $t1->id);
    crc_finishTeamMatch($qf[1], $t3->id);
    crc_finishTeamMatch($qf[2], $t5->id);
    crc_finishTeamMatch($qf[3], $t7->id);
    crc_finishTeamMatch($sf[0], $t1->id);
    crc_finishTeamMatch($sf[1], $t5->id);
    crc_finishTeamMatch($final, $t1->id);

    $result = app(CompetitionWorkflowService::class)->resetMatch($qf[0]->schedule->fresh());

    expect($result['reset'])->toBeTrue()
        ->and($qf[0]->schedule->fresh()->status)->toBe('Ready')
        ->and($sf[0]->schedule->fresh()->status)->toBe('Scheduled')
        ->and($sf[0]->schedule->fresh()->scheduleEntries()->pluck('competition_team_id')->all())->toBe([$t3->id])
        ->and($final->schedule->fresh()->status)->toBe('Scheduled')
        ->and($final->schedule->fresh()->scheduleEntries()->pluck('competition_team_id')->all())->toBe([$t5->id])
        ->and(CompetitionTeamOutcome::count())->toBe(0)
        ->and($sf[1]->schedule->fresh()->status)->toBe('Finished');
});

test('cascade: resetting a semifinal with Finished Final clears all podium (bronze OFF tied-3rd recompute)', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = crc_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = crc_category($event);
    $class = crc_class($event, $category);

    $a = crc_register(crc_person('A'), $event, $category, $class);
    $b = crc_register(crc_person('B'), $event, $category, $class);
    $c = crc_register(crc_person('C'), $event, $category, $class);
    $d = crc_register(crc_person('D'), $event, $category, $class);

    $bracket = crc_generateBracket($class, 4);
    [$semi1, $semi2] = [$bracket->bracketMatches->where('round', 2)->sortBy('position')->values()[0], $bracket->bracketMatches->where('round', 2)->sortBy('position')->values()[1]];
    $final = $bracket->bracketMatches->where('round', 1)->first();

    crc_finishMatch($semi1, $a->id);
    crc_finishMatch($semi2, $c->id);
    crc_finishMatch($final, $a->id);

    $posByReg = CompetitionOutcome::pluck('position', 'competition_registration_id');
    expect($posByReg->get($a->id))->toBe(1)
        ->and($posByReg->get($c->id))->toBe(2)
        ->and($posByReg->get($b->id))->toBe(3)
        ->and($posByReg->get($d->id))->toBe(3);

    app(CompetitionWorkflowService::class)->resetMatch($semi1->schedule->fresh());

    expect(CompetitionOutcome::count())->toBe(0)
        ->and($final->schedule->fresh()->status)->toBe('Scheduled')
        ->and($final->schedule->fresh()->scheduleEntries()->pluck('competition_registration_id')->all())->toBe([$c->id])
        ->and($semi1->schedule->fresh()->status)->toBe('Ready')
        ->and(app(CompetitionResultService::class)->podiumForClass($event->id, $class->id))->toBe([]);
});

test('cascade: reset does not touch Playing/Waiting Result downstream (blocked)', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = crc_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = crc_category($event);
    $class = crc_class($event, $category);

    $regs = [];
    foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'] as $name) {
        $regs[] = crc_register(crc_person($name), $event, $category, $class);
    }
    [$a, $b, $c, $d, $e, $f, $g, $h] = $regs;

    $bracket = crc_generateBracket($class, 8);
    $qf = $bracket->bracketMatches->where('round', 3)->sortBy('position')->values();
    $sf = $bracket->bracketMatches->where('round', 2)->sortBy('position')->values();

    crc_finishMatch($qf[0], $a->id);
    crc_finishMatch($qf[1], $c->id);
    crc_finishMatch($qf[2], $e->id);
    crc_finishMatch($qf[3], $g->id);
    crc_finishMatch($sf[0], $a->id);

    crc_moveToWaitingResult($sf[1]);
    expect($sf[1]->schedule->fresh()->status)->toBe('Waiting Result');

    $result = app(CompetitionWorkflowService::class)->resetMatch($qf[2]->schedule->fresh());

    expect($result['reset'])->toBeFalse()
        ->and($result['reason'])->toBe('downstream_active')
        ->and($qf[2]->schedule->fresh()->status)->toBe('Finished')
        ->and($qf[2]->schedule->fresh()->winner_registration_id)->toBe($e->id)
        ->and($sf[1]->schedule->fresh()->status)->toBe('Waiting Result')
        ->and($sf[1]->schedule->fresh()->scheduleEntries()->pluck('competition_registration_id')->sort()->values()->all())->toBe([$e->id, $g->id])
        ->and($sf[0]->schedule->fresh()->status)->toBe('Finished');
});

// ---------------------------------------------------------------------------
// Idempotency, cross-bracket isolation, and re-progression
// ---------------------------------------------------------------------------

test('cascade: repeated reset never duplicates entries and a Ready match is left untouched', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = crc_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = crc_category($event);
    $class = crc_class($event, $category);

    $a = crc_register(crc_person('A'), $event, $category, $class);
    $b = crc_register(crc_person('B'), $event, $category, $class);
    $c = crc_register(crc_person('C'), $event, $category, $class);
    $d = crc_register(crc_person('D'), $event, $category, $class);

    $bracket = crc_generateBracket($class, 4);
    [$semi1, $semi2] = [$bracket->bracketMatches->where('round', 2)->sortBy('position')->values()[0], $bracket->bracketMatches->where('round', 2)->sortBy('position')->values()[1]];
    $final = $bracket->bracketMatches->where('round', 1)->first();

    crc_finishMatch($semi1, $a->id);
    crc_finishMatch($semi2, $c->id);
    crc_finishMatch($final, $a->id);

    $workflow = app(CompetitionWorkflowService::class);

    $first = $workflow->resetMatch($final->schedule->fresh());

    expect($first['reset'])->toBeTrue()
        ->and($first['status'])->toBe('Ready')
        ->and($final->schedule->fresh()->scheduleEntries()->count())->toBe(2)
        ->and($final->schedule->fresh()->scheduleEntries()->pluck('competition_registration_id')->unique()->count())->toBe(2)
        ->and(CompetitionOutcome::count())->toBe(0);

    // Match yang sudah kembali Ready tidak di-reset ulang (behavior D).
    $second = $workflow->resetMatch($final->schedule->fresh());

    expect($second['reset'])->toBeFalse()
        ->and($second['reason'])->toBe('not_allowed')
        ->and($final->schedule->fresh()->status)->toBe('Ready')
        ->and($final->schedule->fresh()->scheduleEntries()->count())->toBe(2)
        ->and($final->schedule->fresh()->scheduleEntries()->pluck('competition_registration_id')->unique()->count())->toBe(2)
        ->and(CompetitionOutcome::count())->toBe(0);
});

test('cascade: resetting one bracket never touches another bracket podium', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = crc_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = crc_category($event);

    // Bracket 1 — diisi penuh, podium dijaga.
    $class1 = crc_class($event, $category);
    $a = crc_register(crc_person('A'), $event, $category, $class1);
    $b = crc_register(crc_person('B'), $event, $category, $class1);
    $c = crc_register(crc_person('C'), $event, $category, $class1);
    $d = crc_register(crc_person('D'), $event, $category, $class1);
    $bracket1 = crc_generateBracket($class1, 4);
    [$s1_1, $s1_2] = [$bracket1->bracketMatches->where('round', 2)->sortBy('position')->values()[0], $bracket1->bracketMatches->where('round', 2)->sortBy('position')->values()[1]];
    $final1 = $bracket1->bracketMatches->where('round', 1)->first();
    crc_finishMatch($s1_1, $a->id);
    crc_finishMatch($s1_2, $c->id);
    crc_finishMatch($final1, $a->id);

    $pos1 = CompetitionOutcome::pluck('position', 'competition_registration_id');
    expect($pos1->get($a->id))->toBe(1)
        ->and($pos1->get($c->id))->toBe(2)
        ->and($pos1->get($b->id))->toBe(3)
        ->and($pos1->get($d->id))->toBe(3);

    // Bracket 2 — direset dari semifinal.
    $class2 = crc_class($event, $category);
    $e = crc_register(crc_person('E'), $event, $category, $class2);
    $f = crc_register(crc_person('F'), $event, $category, $class2);
    $g = crc_register(crc_person('G'), $event, $category, $class2);
    $h = crc_register(crc_person('H'), $event, $category, $class2);
    $bracket2 = crc_generateBracket($class2, 4);
    [$s2_1, $s2_2] = [$bracket2->bracketMatches->where('round', 2)->sortBy('position')->values()[0], $bracket2->bracketMatches->where('round', 2)->sortBy('position')->values()[1]];
    $final2 = $bracket2->bracketMatches->where('round', 1)->first();
    crc_finishMatch($s2_1, $e->id);
    crc_finishMatch($s2_2, $g->id);
    crc_finishMatch($final2, $e->id);

    app(CompetitionWorkflowService::class)->resetMatch($s2_1->schedule->fresh());

    // Bracket 1 utuh; Bracket 2 podium bersih.
    $posAfter = CompetitionOutcome::pluck('position', 'competition_registration_id');
    expect($posAfter->get($a->id))->toBe(1)
        ->and($posAfter->get($c->id))->toBe(2)
        ->and($posAfter->get($b->id))->toBe(3)
        ->and($posAfter->get($d->id))->toBe(3)
        ->and($posAfter->get($e->id))->not->toBe(1)
        ->and($final2->schedule->fresh()->status)->toBe('Scheduled')
        ->and($final2->schedule->fresh()->scheduleEntries()->pluck('competition_registration_id')->all())->toBe([$g->id]);
});

test('cascade: re-playing the source after a reset re-advances and returns downstream to Ready (normal progression intact)', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = crc_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = crc_category($event);
    $class = crc_class($event, $category);

    $regs = [];
    foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'] as $name) {
        $regs[] = crc_register(crc_person($name), $event, $category, $class);
    }
    [$a, $b, $c, $d, $e, $f, $g, $h] = $regs;

    $bracket = crc_generateBracket($class, 8);
    $qf = $bracket->bracketMatches->where('round', 3)->sortBy('position')->values();
    $sf = $bracket->bracketMatches->where('round', 2)->sortBy('position')->values();
    $final = $bracket->bracketMatches->where('round', 1)->where('is_third_place', false)->first();

    crc_finishMatch($qf[0], $a->id);
    crc_finishMatch($qf[1], $c->id);
    crc_finishMatch($qf[2], $e->id);
    crc_finishMatch($qf[3], $g->id);
    crc_finishMatch($sf[0], $a->id);
    crc_finishMatch($sf[1], $e->id);
    crc_finishMatch($final, $a->id);

    $workflow = app(CompetitionWorkflowService::class);
    $workflow->resetMatch($qf[0]->schedule->fresh());

    // Reset → QF Ready (2/2), SF1 1/2 Scheduled, Final 1/2 Scheduled.
    expect($qf[0]->schedule->fresh()->status)->toBe('Ready')
        ->and($sf[0]->schedule->fresh()->status)->toBe('Scheduled')
        ->and($final->schedule->fresh()->status)->toBe('Scheduled');

    // Main ulang QF1 → A kembali ke SF1 → 2/2 → Ready otomatis.
    crc_finishMatch(CompetitionBracketMatch::find($qf[0]->id), $a->id);
    expect($sf[0]->schedule->fresh()->status)->toBe('Ready')
        ->and($sf[0]->schedule->fresh()->scheduleEntries()->count())->toBe(2);

    // Lanjut normal: SF1 dimenangkan A, Final 2/2 → Ready, Final selesai → podium utuh.
    crc_finishMatch(CompetitionBracketMatch::find($sf[0]->id), $a->id);
    expect($final->schedule->fresh()->status)->toBe('Ready')
        ->and($final->schedule->fresh()->scheduleEntries()->count())->toBe(2);

    crc_finishMatch(CompetitionBracketMatch::find($final->id), $a->id);

    $posByReg = CompetitionOutcome::pluck('position', 'competition_registration_id');
    expect($posByReg->get($a->id))->toBe(1)
        ->and($posByReg->get($e->id))->toBe(2)
        ->and($posByReg->get($c->id))->toBe(3)
        ->and($posByReg->get($g->id))->toBe(3)
        ->and(CompetitionOutcome::count())->toBe(4);
});
