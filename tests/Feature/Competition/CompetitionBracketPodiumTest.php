<?php

use App\Enums\Role;
use App\Models\CompetitionBracket;
use App\Models\CompetitionBracketMatch;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionOutcome;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionScheduleEntry;
use App\Models\Event;
use App\Models\Person;
use App\Models\User;
use App\Services\Competition\CompetitionRegistrationService;
use App\Services\Competition\CompetitionResultService;
use App\Services\Competition\CompetitionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function cbp_event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'CBP Event '.str()->random(6),
        'slug' => 'cbp-'.str()->random(6),
        'status' => 'active',
        'event_type' => 'competition',
    ], $overrides));
}

function cbp_category(Event $event): CompetitionCategory
{
    $category = CompetitionCategory::create(['event_id' => $event->id, 'name' => 'CBP Cat '.str()->random(4)]);

    $category->events()->attach($event);

    return $category;
}

function cbp_class(Event $event, CompetitionCategory $category): CompetitionClass
{
    return CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'CBP VS '.str()->random(4),
        'gender' => 'M',
        'format' => 'individual_vs_individual',
        'status' => 'registration_open',
        'is_active' => true,
    ]);
}

function cbp_person(string $nama): Person
{
    return Person::create(['nama' => $nama, 'jenis_kelamin' => 'L']);
}

function cbp_register(Person $person, Event $event, CompetitionCategory $category, CompetitionClass $class): CompetitionRegistration
{
    return app(CompetitionRegistrationService::class)->registerForPerson(
        person: $person,
        eventId: $event->id,
        competitionCategoryId: $category->id,
        competitionClassId: $class->id,
    )['competition_registration'];
}

function cbp_generateBracket(CompetitionClass $class, int $count): CompetitionBracket
{
    \Livewire::test(\App\Livewire\Competition\BracketManager::class)
        ->set('newParticipantCount', (string) $count)
        ->call('generate', $class->id);

    return CompetitionBracket::where('competition_class_id', $class->id)->first();
}

function cbp_addEntry(CompetitionBracketMatch $match, CompetitionRegistration $registration, int $order): void
{
    CompetitionScheduleEntry::create([
        'competition_schedule_id' => $match->schedule->id,
        'competition_registration_id' => $registration->id,
        'order_number' => $order,
    ]);
}

function cbp_finishMatch(CompetitionBracketMatch $match, int $winnerRegistrationId): void
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

// ---------------------------------------------------------------------------
// 4-participant bracket → Juara 1/2/3
// ---------------------------------------------------------------------------

test('finishing the final writes Juara 1/2/3 (semifinal losers tie 3rd)', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cbp_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cbp_category($event);
    $class = cbp_class($event, $category);
    $bracket = cbp_generateBracket($class, 4);

    $matches = $bracket->bracketMatches;
    $semi1 = $matches->where('round', 2)->where('position', 1)->first();
    $semi2 = $matches->where('round', 2)->where('position', 2)->first();
    $final = $matches->where('round', 1)->first();

    $a = cbp_register(cbp_person('A'), $event, $category, $class);
    $b = cbp_register(cbp_person('B'), $event, $category, $class);
    $c = cbp_register(cbp_person('C'), $event, $category, $class);
    $d = cbp_register(cbp_person('D'), $event, $category, $class);

    cbp_addEntry($semi1, $a, 1);
    cbp_addEntry($semi1, $b, 2);
    cbp_addEntry($semi2, $c, 1);
    cbp_addEntry($semi2, $d, 2);

    cbp_finishMatch($semi1, $a->id); // A advances
    cbp_finishMatch($semi2, $c->id); // C advances
    cbp_finishMatch($final, $a->id); // A juara 1

    $posByReg = CompetitionOutcome::pluck('position', 'competition_registration_id');
    expect($posByReg->get($a->id))->toBe(1)
        ->and($posByReg->get($c->id))->toBe(2)
        ->and($posByReg->get($b->id))->toBe(3)
        ->and($posByReg->get($d->id))->toBe(3);
});

test('podiumForClass returns Juara 1/2/3 after bracket final', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cbp_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cbp_category($event);
    $class = cbp_class($event, $category);
    $bracket = cbp_generateBracket($class, 4);

    $matches = $bracket->bracketMatches;
    $semi1 = $matches->where('round', 2)->where('position', 1)->first();
    $semi2 = $matches->where('round', 2)->where('position', 2)->first();
    $final = $matches->where('round', 1)->first();

    $a = cbp_register(cbp_person('A'), $event, $category, $class);
    $b = cbp_register(cbp_person('B'), $event, $category, $class);
    $c = cbp_register(cbp_person('C'), $event, $category, $class);
    $d = cbp_register(cbp_person('D'), $event, $category, $class);

    cbp_addEntry($semi1, $a, 1);
    cbp_addEntry($semi1, $b, 2);
    cbp_addEntry($semi2, $c, 1);
    cbp_addEntry($semi2, $d, 2);

    cbp_finishMatch($semi1, $a->id);
    cbp_finishMatch($semi2, $c->id);
    cbp_finishMatch($final, $a->id);

    $podium = app(CompetitionResultService::class)->podiumForClass($event->id, $class->id);

    expect($podium)->toHaveCount(3)
        ->and($podium[0]['position'])->toBe(1)
        ->and($podium[0]['person_name'])->toBe('A')
        ->and($podium[1]['person_name'])->toBe('C')
        ->and($podium[2]['person_name'])->toBe('B');
});

// ---------------------------------------------------------------------------
// 8-participant bracket → quarterfinal losers not placed
// ---------------------------------------------------------------------------

test('8-bracket: only semifinal losers get 3rd place, quarterfinal losers not placed', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cbp_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cbp_category($event);
    $class = cbp_class($event, $category);
    $bracket = cbp_generateBracket($class, 8);

    $matches = $bracket->bracketMatches;
    // round 3 = quarterfinals (pos 1..4), round 2 = semis (pos 1..2), round 1 = final
    $q = $matches->where('round', 3)->sortBy('position')->values();
    $semi1 = $matches->where('round', 2)->where('position', 1)->first();
    $semi2 = $matches->where('round', 2)->where('position', 2)->first();
    $final = $matches->where('round', 1)->first();

    $regs = collect(range('A', 'H'))->map(fn ($n) => cbp_register(cbp_person('P'.$n), $event, $category, $class));

    // quarterfinals: winner of each is the even-indexed participant (A, C, E, G)
    foreach ($q as $i => $match) {
        $w = $regs[$i * 2];       // A, C, E, G
        $l = $regs[$i * 2 + 1];   // B, D, F, H
        cbp_addEntry($match, $w, 1);
        cbp_addEntry($match, $l, 2);
        cbp_finishMatch($match, $w->id);
    }

    // semifinals: A beats C; E beats G
    cbp_finishMatch($semi1, $regs[0]->id);
    cbp_finishMatch($semi2, $regs[4]->id);

    // final: A juara 1
    cbp_finishMatch($final, $regs[0]->id);

    $posByReg = CompetitionOutcome::pluck('position', 'competition_registration_id');
    expect($posByReg->get($regs[0]->id))->toBe(1)   // A
        ->and($posByReg->get($regs[4]->id))->toBe(2) // E
        ->and($posByReg->get($regs[2]->id))->toBe(3) // C (semi loser)
        ->and($posByReg->get($regs[6]->id))->toBe(3) // G (semi loser)
        ->and($posByReg->get($regs[1]->id))->toBeNull() // B (QF loser)
        ->and($posByReg->get($regs[3]->id))->toBeNull() // D
        ->and($posByReg->get($regs[5]->id))->toBeNull() // F
        ->and($posByReg->get($regs[7]->id))->toBeNull(); // H
});

// ---------------------------------------------------------------------------
// No-op cases
// ---------------------------------------------------------------------------

test('non-final finished match does not finalize podium', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cbp_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cbp_category($event);
    $class = cbp_class($event, $category);
    $bracket = cbp_generateBracket($class, 4);

    $matches = $bracket->bracketMatches;
    $semi1 = $matches->where('round', 2)->where('position', 1)->first();
    $final = $matches->where('round', 1)->first();

    $a = cbp_register(cbp_person('A'), $event, $category, $class);
    $b = cbp_register(cbp_person('B'), $event, $category, $class);
    cbp_addEntry($semi1, $a, 1);
    cbp_addEntry($semi1, $b, 2);
    cbp_finishMatch($semi1, $a->id);

    // Semi sudah Finished, tetapi bukan final → tidak ada Juara yang ditulis.
    expect(CompetitionOutcome::count())->toBe(0);

    // Final belum dimainkan.
    expect($final->schedule->fresh()->status)->not->toBe('Finished');
});

test('unfinished final does not finalize podium', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cbp_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cbp_category($event);
    $class = cbp_class($event, $category);
    $bracket = cbp_generateBracket($class, 4);

    $final = $bracket->bracketMatches->where('round', 1)->first();
    $final->schedule->update(['status' => 'Scheduled']);

    app(\App\Services\Competition\CompetitionBracketPodiumService::class)
        ->finalizePodiumForSchedule($final->schedule);

    expect(CompetitionOutcome::count())->toBe(0);
});
