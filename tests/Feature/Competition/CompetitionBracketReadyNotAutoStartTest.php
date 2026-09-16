<?php

use App\Enums\Role;
use App\Models\CompetitionBracket;
use App\Models\CompetitionBracketMatch;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionSchedule;
use App\Models\CompetitionScheduleEntry;
use App\Models\CompetitionTeam;
use App\Models\Event;
use App\Models\kelompok;
use App\Models\Person;
use App\Models\User;
use App\Services\Competition\CompetitionRegistrationService;
use App\Services\Competition\CompetitionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function r4h_event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'R4H Event '.str()->random(6),
        'slug' => 'r4h-'.str()->random(6),
        'status' => 'active',
        'event_type' => 'competition',
    ], $overrides));
}

function r4h_category(Event $event): CompetitionCategory
{
    $category = CompetitionCategory::create(['event_id' => $event->id, 'name' => 'R4H Cat '.str()->random(4)]);

    $category->events()->attach($event);

    return $category;
}

function r4h_class(Event $event, CompetitionCategory $category, string $format = 'individual_vs_individual'): CompetitionClass
{
    return CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'R4H Class '.str()->random(4),
        'gender' => 'M',
        'format' => $format,
        'status' => 'registration_open',
        'is_active' => true,
    ]);
}

function r4h_register(string $nama, Event $event, CompetitionCategory $category, CompetitionClass $class): \App\Models\CompetitionRegistration
{
    $person = Person::create(['nama' => $nama, 'jenis_kelamin' => 'L']);

    return app(CompetitionRegistrationService::class)->registerForPerson(
        person: $person,
        eventId: $event->id,
        competitionCategoryId: $category->id,
        competitionClassId: $class->id,
    )['competition_registration'];
}

function r4h_kelompok(string $name): kelompok
{
    return kelompok::create(['kelompok_asal' => $name]);
}

function r4h_team(string $name, Event $event, CompetitionClass $class): CompetitionTeam
{
    return CompetitionTeam::create([
        'event_id' => $event->id,
        'competition_class_id' => $class->id,
        'name' => $name,
        'kelompok_id' => r4h_kelompok('KM '.$name)->id,
        'is_active' => true,
    ]);
}

function r4h_generateBracket(CompetitionClass $class, int $count): CompetitionBracket
{
    \Livewire::test(\App\Livewire\Competition\BracketManager::class)
        ->set('newParticipantCount', (string) $count)
        ->call('generate', $class->id);

    return CompetitionBracket::where('competition_class_id', $class->id)->first();
}

function r4h_initialMatches(CompetitionBracket $bracket)
{
    $totalRounds = (int) log($bracket->participant_count, 2);

    return CompetitionBracketMatch::where('competition_bracket_id', $bracket->id)
        ->where('round', $totalRounds)
        ->orderBy('position')
        ->get();
}

function r4h_entryIds(CompetitionBracketMatch $match, string $column): array
{
    return CompetitionScheduleEntry::where('competition_schedule_id', $match->schedule->id)
        ->pluck($column)
        ->sort()
        ->values()
        ->all();
}

/** Jalur official individu: Scheduled/Ready/Playing → Waiting Result → submitResult. */
function r4h_finishIndividual(CompetitionBracketMatch $match, int $winnerRegistrationId): void
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

/** Jalur official team: Scheduled/Ready/Playing → Waiting Result → submitTeamResult. */
function r4h_finishTeam(CompetitionBracketMatch $match, int $winnerTeamId, ?int $eventId = null): void
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

    $workflow->submitTeamResult($schedule->fresh(), $winnerTeamId, 'Normal', null, $eventId);
}

// ---------------------------------------------------------------------------
// A. Initial bracket auto-seed → Ready, bukan Playing
// ---------------------------------------------------------------------------

test('A. initial bracket auto-seed produces Ready (not Playing)', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = r4h_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = r4h_category($event);
    $class = r4h_class($event, $category, 'individual_vs_individual');
    r4h_register('A', $event, $category, $class);
    r4h_register('B', $event, $category, $class);
    r4h_register('C', $event, $category, $class);
    r4h_register('D', $event, $category, $class);

    $bracket = r4h_generateBracket($class, 4);
    [$m1, $m2] = r4h_initialMatches($bracket);

    expect($m1->schedule->refresh()->status)->toBe('Ready')
        ->and($m2->schedule->refresh()->status)->toBe('Ready')
        ->and($m1->schedule->refresh()->status)->not->toBe('Playing')
        ->and($m2->schedule->refresh()->status)->not->toBe('Playing');
});

// ---------------------------------------------------------------------------
// B + C. Winner advancement → next match Ready, bukan auto-start Playing
// ---------------------------------------------------------------------------

test('B. winner advancement fills next round and leaves it Ready (not Playing)', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = r4h_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = r4h_category($event);
    $class = r4h_class($event, $category, 'individual_vs_individual');
    $a = r4h_register('A', $event, $category, $class);
    $b = r4h_register('B', $event, $category, $class);
    $c = r4h_register('C', $event, $category, $class);
    $d = r4h_register('D', $event, $category, $class);

    $bracket = r4h_generateBracket($class, 4);
    [$m1, $m2] = r4h_initialMatches($bracket);
    $final = CompetitionBracketMatch::where('competition_bracket_id', $bracket->id)->where('round', 1)->first();

    // M1 selesai, winner A di-advance → final belum lengkap → tetap Scheduled/TBD.
    r4h_finishIndividual($m1, $a->id);
    expect($final->schedule->refresh()->status)->toBe('Scheduled')
        ->and(CompetitionScheduleEntry::where('competition_schedule_id', $final->schedule->id)->count())->toBe(1);

    // M2 selesai, winner C di-advance → final lengkap → READY, bukan PLAYING.
    r4h_finishIndividual($m2, $c->id);

    expect($final->schedule->refresh()->status)->toBe('Ready')
        ->and($final->schedule->refresh()->status)->not->toBe('Playing')
        ->and(r4h_entryIds($final, 'competition_registration_id'))->toBe([$a->id, $c->id]);
});

test('C. no auto-start: after winner advancement the next match stays Ready', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = r4h_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = r4h_category($event);
    $class = r4h_class($event, $category, 'individual_vs_individual');
    $a = r4h_register('A', $event, $category, $class);
    $b = r4h_register('B', $event, $category, $class);
    $c = r4h_register('C', $event, $category, $class);
    $d = r4h_register('D', $event, $category, $class);

    $bracket = r4h_generateBracket($class, 4);
    [$m1, $m2] = r4h_initialMatches($bracket);
    $final = CompetitionBracketMatch::where('competition_bracket_id', $bracket->id)->where('round', 1)->first();

    // M1 finish → final sementara berisi A; tidak boleh otomatis Playing.
    r4h_finishIndividual($m1, $a->id);
    expect($final->schedule->refresh()->status)->not->toBe('Playing');

    // M2 finish → final lengkap → Ready; tidak ada auto-start.
    r4h_finishIndividual($m2, $c->id);
    expect($final->schedule->refresh()->status)->toBe('Ready')
        ->and($final->schedule->refresh()->status)->not->toBe('Playing');
});

// ---------------------------------------------------------------------------
// D. Explicit start: Ready → Playing hanya via operator Start Match
// ---------------------------------------------------------------------------

test('D. next match stays Ready until operator explicitly starts it', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = r4h_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = r4h_category($event);
    $class = r4h_class($event, $category, 'individual_vs_individual');
    $a = r4h_register('A', $event, $category, $class);
    $b = r4h_register('B', $event, $category, $class);
    $c = r4h_register('C', $event, $category, $class);
    $d = r4h_register('D', $event, $category, $class);

    $bracket = r4h_generateBracket($class, 4);
    [$m1, $m2] = r4h_initialMatches($bracket);
    $final = CompetitionBracketMatch::where('competition_bracket_id', $bracket->id)->where('round', 1)->first();

    r4h_finishIndividual($m1, $a->id);
    r4h_finishIndividual($m2, $c->id);

    expect($final->schedule->refresh()->status)->toBe('Ready');

    // Operator Start Match → Playing.
    \Livewire::test(\App\Livewire\Competition\MatchCenter::class)
        ->call('startMatch', $final->schedule->id);

    expect($final->schedule->refresh()->status)->toBe('Playing');
});

// ---------------------------------------------------------------------------
// E. Final: kedua semifinal selesai → final Ready (bukan Playing)
// ---------------------------------------------------------------------------

test('E. final becomes Ready after both semifinals (no auto-start)', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = r4h_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = r4h_category($event);
    $class = r4h_class($event, $category, 'individual_vs_individual');
    $a = r4h_register('A', $event, $category, $class);
    $b = r4h_register('B', $event, $category, $class);
    $c = r4h_register('C', $event, $category, $class);
    $d = r4h_register('D', $event, $category, $class);

    $bracket = r4h_generateBracket($class, 4);
    [$m1, $m2] = r4h_initialMatches($bracket);
    $final = CompetitionBracketMatch::where('competition_bracket_id', $bracket->id)->where('round', 1)->first();

    r4h_finishIndividual($m1, $a->id);
    r4h_finishIndividual($m2, $c->id);

    expect($final->schedule->refresh()->status)->toBe('Ready')
        ->and($final->schedule->refresh()->status)->not->toBe('Playing')
        ->and(r4h_entryIds($final, 'competition_registration_id'))->toBe([$a->id, $c->id]);
});

// ---------------------------------------------------------------------------
// F. Team bracket: winner team di-advance → next match Ready (bukan Playing)
// ---------------------------------------------------------------------------

test('F. team bracket winner advancement produces Ready (not auto-start)', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = r4h_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = r4h_category($event);
    $class = r4h_class($event, $category, 'team_vs_team');
    $t1 = r4h_team('T1', $event, $class);
    $t2 = r4h_team('T2', $event, $class);
    $t3 = r4h_team('T3', $event, $class);
    $t4 = r4h_team('T4', $event, $class);

    $bracket = r4h_generateBracket($class, 4);
    [$m1, $m2] = r4h_initialMatches($bracket);
    $final = CompetitionBracketMatch::where('competition_bracket_id', $bracket->id)->where('round', 1)->first();

    r4h_finishTeam($m1, $t1->id, $event->id);
    expect($final->schedule->refresh()->status)->not->toBe('Playing');

    r4h_finishTeam($m2, $t3->id, $event->id);

    expect($final->schedule->refresh()->status)->toBe('Ready')
        ->and($final->schedule->refresh()->status)->not->toBe('Playing')
        ->and(r4h_entryIds($final, 'competition_team_id'))->toBe([$t1->id, $t3->id]);
});

// ---------------------------------------------------------------------------
// G. Regression R4D: non-bracket Team vs Team official flow
// ---------------------------------------------------------------------------

test('G. regression R4D: non-bracket team_vs_team Playing → Waiting → Official → Finished', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = r4h_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = r4h_category($event);
    $class = r4h_class($event, $category, 'team_vs_team');
    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $class->id,
        'status' => 'Playing',
        'required_participants' => 2,
    ]);
    $teamA = r4h_team('A', $event, $class);
    $teamB = r4h_team('B', $event, $class);
    CompetitionScheduleEntry::create(['competition_schedule_id' => $schedule->id, 'competition_team_id' => $teamA->id, 'order_number' => 1]);
    CompetitionScheduleEntry::create(['competition_schedule_id' => $schedule->id, 'competition_team_id' => $teamB->id, 'order_number' => 2]);

    $workflow = app(CompetitionWorkflowService::class);

    expect($workflow->requiresOfficial($schedule))->toBeTrue();
    expect($workflow->completeMatch($schedule))->toBe('Waiting Result');
    expect($schedule->refresh()->status)->toBe('Waiting Result');

    $workflow->submitTeamResult($schedule->fresh(), $teamA->id, 'Normal', null, $event->id);

    expect($schedule->refresh()->status)->toBe('Finished')
        ->and($schedule->refresh()->winner_team_id)->toBe($teamA->id);
});

// ---------------------------------------------------------------------------
// H. Regression R4E/R4G: auto-seed + auto-ready + team display + advancement
// ---------------------------------------------------------------------------

test('H. regression R4E/R4G: auto-seed, auto-ready and team display intact', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = r4h_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = r4h_category($event);
    $class = r4h_class($event, $category, 'team_vs_team');
    $t1 = r4h_team('Alpha', $event, $class);
    $t2 = r4h_team('Beta', $event, $class);

    $bracket = r4h_generateBracket($class, 4);
    [$m1, $m2] = r4h_initialMatches($bracket);

    // Auto-seed + auto-ready (R4E + R4G).
    expect(r4h_entryIds($m1, 'competition_team_id'))->toHaveCount(2)
        ->and($m1->schedule->refresh()->status)->toBe('Ready');

    // Team display (bukan TBD).
    \Livewire::test(\App\Livewire\Competition\BracketManager::class)
        ->set('selectedBracketId', $bracket->id)
        ->assertSee('Alpha')
        ->assertSee('Beta');
});

// ---------------------------------------------------------------------------
// I. Regression Mass/Heat: completeMatch/finishMatch finish directly
// ---------------------------------------------------------------------------

test('I. regression mass/heat: non-official matches still finish directly', function () {
    $event = r4h_event();
    $category = r4h_category($event);
    $workflow = app(CompetitionWorkflowService::class);

    foreach (['individual_mass', 'individual_heat', 'team_mass'] as $format) {
        $class = r4h_class($event, $category, $format);
        $schedule = CompetitionSchedule::create([
            'competition_class_id' => $class->id,
            'status' => 'Playing',
            'required_participants' => 1,
        ]);

        expect($workflow->requiresOfficial($schedule))->toBeFalse();
        expect($workflow->completeMatch($schedule))->toBe('Finished');
        expect($schedule->refresh()->status)->toBe('Finished');
        expect($workflow->finishMatch($schedule))->toBeFalse(); // sudah Finished
    }
});
