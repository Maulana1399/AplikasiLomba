<?php

use App\Enums\Role;
use App\Models\CompetitionBracket;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionMatchOfficial;
use App\Models\CompetitionSchedule;
use App\Models\CompetitionScheduleEntry;
use App\Models\CompetitionTeam;
use App\Models\Event;
use App\Models\kelompok;
use App\Models\User;
use App\Services\Competition\CompetitionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function cnb_event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'CNB Event '.str()->random(6),
        'slug' => 'cnb-'.str()->random(6),
        'status' => 'active',
        'event_type' => 'competition',
    ], $overrides));
}

function cnb_category(Event $event): CompetitionCategory
{
    return CompetitionCategory::create(['event_id' => $event->id, 'name' => 'CNB Cat '.str()->random(4)]);
}

function cnb_class(Event $event, CompetitionCategory $category, string $format = 'team_vs_team'): CompetitionClass
{
    return CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'CNB Class '.str()->random(4),
        'gender' => 'M',
        'format' => $format,
        'status' => 'registration_open',
        'is_active' => true,
    ]);
}

function cnb_kelompok(string $name): kelompok
{
    return kelompok::create(['kelompok_asal' => $name]);
}

function cnb_team(Event $event, CompetitionClass $class, ?kelompok $kelompok, string $name): CompetitionTeam
{
    return CompetitionTeam::create([
        'event_id' => $event->id,
        'competition_class_id' => $class->id,
        'name' => $name,
        'kelompok_id' => $kelompok?->id,
        'is_active' => true,
    ]);
}

function cnb_schedule(CompetitionClass $class, string $status = 'Scheduled'): CompetitionSchedule
{
    return CompetitionSchedule::create([
        'competition_class_id' => $class->id,
        'status' => $status,
        'required_participants' => 2,
    ]);
}

function cnb_teamEntry(CompetitionSchedule $schedule, CompetitionTeam $team, int $order): void
{
    CompetitionScheduleEntry::create([
        'competition_schedule_id' => $schedule->id,
        'competition_team_id' => $team->id,
        'order_number' => $order,
    ]);
}

function cnb_official(CompetitionSchedule $schedule, User $user): CompetitionMatchOfficial
{
    return CompetitionMatchOfficial::create([
        'competition_schedule_id' => $schedule->id,
        'user_id' => $user->id,
        'role' => 'referee',
    ]);
}

/** Play → Waiting Result (operator). */
function cnb_toWaiting(CompetitionSchedule $schedule): string
{
    $workflow = app(CompetitionWorkflowService::class);

    return $workflow->completeMatch($schedule);
}

// ---------------------------------------------------------------------------
// A. requiresOfficial untuk non-bracket vs
// ---------------------------------------------------------------------------

test('non-bracket team_vs_team requires official', function () {
    $event = cnb_event();
    $category = cnb_category($event);
    $class = cnb_class($event, $category, 'team_vs_team');
    $schedule = cnb_schedule($class);

    expect($schedule->bracketMatch()->exists())->toBeFalse()
        ->and(app(CompetitionWorkflowService::class)->requiresOfficial($schedule))->toBeTrue();
});

test('non-bracket individual_vs_individual requires official; mass/heat do not', function () {
    $event = cnb_event();
    $category = cnb_category($event);
    $workflow = app(CompetitionWorkflowService::class);

    foreach (['individual_vs_individual', 'team_vs_team'] as $format) {
        $class = cnb_class($event, $category, $format);
        expect($workflow->requiresOfficial(cnb_schedule($class)))->toBeTrue("{$format} harus official");
    }

    foreach (['individual_mass', 'individual_heat', 'team_mass'] as $format) {
        $class = cnb_class($event, $category, $format);
        expect($workflow->requiresOfficial(cnb_schedule($class)))->toBeFalse("{$format} bukan official");
    }
});

// ---------------------------------------------------------------------------
// B. completeMatch → Waiting Result
// ---------------------------------------------------------------------------

test('non-bracket team match moves Playing → Waiting Result (not Finished)', function () {
    $event = cnb_event();
    $category = cnb_category($event);
    $class = cnb_class($event, $category, 'team_vs_team');
    $schedule = cnb_schedule($class, 'Playing');

    $next = cnb_toWaiting($schedule);

    expect($next)->toBe('Waiting Result')
        ->and($schedule->fresh()->status)->toBe('Waiting Result');

    // Tidak ada duplicate transition.
    expect(cnb_toWaiting($schedule->fresh()))->toBe('Waiting Result');
});

test('mass/heat non-bracket completeMatch still finishes directly', function () {
    $event = cnb_event();
    $category = cnb_category($event);
    $workflow = app(CompetitionWorkflowService::class);

    foreach (['individual_mass', 'individual_heat', 'team_mass'] as $format) {
        $class = cnb_class($event, $category, $format);
        $schedule = cnb_schedule($class, 'Playing');
        $next = $workflow->completeMatch($schedule);
        expect($next)->toBe('Finished')
            ->and($schedule->fresh()->status)->toBe('Finished')
            ->and($schedule->fresh()->winner_team_id)->toBeNull();
    }
});

// ---------------------------------------------------------------------------
// C. Official Panel hanya menampilkan match yang di-assign ke official
// ---------------------------------------------------------------------------

test('official panel shows assigned non-bracket team match', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $officialUser = User::factory()->create(['role' => null]);
    $event = cnb_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cnb_category($event);
    $class = cnb_class($event, $category, 'team_vs_team');
    $schedule = cnb_schedule($class, 'Waiting Result');
    $teamA = cnb_team($event, $class, cnb_kelompok('KM 7'), 'KM 7');
    cnb_teamEntry($schedule, $teamA, 1);
    cnb_official($schedule, $officialUser);

    // Official yang di-assign melihat match (nama team tampil di waiting card).
    $this->actingAs($officialUser);
    \Livewire::test(\App\Livewire\Competition\OfficialPanel::class)
        ->assertSee('Menunggu Hasil')
        ->assertSee($teamA->name);

    // AplikasiLomba: no-auth LAN app — all matches shown regardless of user
    $other = User::factory()->create(['role' => null]);
    $this->actingAs($other);
    \Livewire::test(\App\Livewire\Competition\OfficialPanel::class)
        ->assertSee($teamA->name);
});

// ---------------------------------------------------------------------------
// D + E. Official submit non-bracket team winner
// ---------------------------------------------------------------------------

test('official can submit non-bracket team winner via panel', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cnb_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cnb_category($event);
    $class = cnb_class($event, $category, 'team_vs_team');
    $schedule = cnb_schedule($class, 'Waiting Result');
    $teamA = cnb_team($event, $class, cnb_kelompok('KM 7'), 'KM 7');
    $teamB = cnb_team($event, $class, cnb_kelompok('KM 10'), 'KM 10');
    cnb_teamEntry($schedule, $teamA, 1);
    cnb_teamEntry($schedule, $teamB, 2);

    \Livewire::test(\App\Livewire\Competition\OfficialPanel::class)
        ->call('openSubmitDialog', $schedule->id)
        ->assertHasNoErrors()
        ->set('selectedWinnerId', $teamA->id)
        ->set('finishReason', 'Normal')
        ->call('submitResult')
        ->assertHasNoErrors();

    $schedule->refresh();
    expect($schedule->winner_team_id)->toBe($teamA->id)
        ->and($schedule->status)->toBe('Finished');
});

test('non-bracket team result sets winner_team_id and finishes', function () {
    $event = cnb_event();
    $category = cnb_category($event);
    $class = cnb_class($event, $category, 'team_vs_team');
    $schedule = cnb_schedule($class, 'Waiting Result');
    $teamA = cnb_team($event, $class, cnb_kelompok('A'), 'A');
    $teamB = cnb_team($event, $class, cnb_kelompok('B'), 'B');
    cnb_teamEntry($schedule, $teamA, 1);
    cnb_teamEntry($schedule, $teamB, 2);

    app(CompetitionWorkflowService::class)->submitTeamResult($schedule, $teamA->id, 'Normal', null, $event->id);

    $schedule->refresh();
    expect($schedule->winner_team_id)->toBe($teamA->id)
        ->and($schedule->status)->toBe('Finished');
});

test('non-bracket team result rejects a team that is not an entry', function () {
    $event = cnb_event();
    $category = cnb_category($event);
    $class = cnb_class($event, $category, 'team_vs_team');
    $schedule = cnb_schedule($class, 'Waiting Result');
    $teamA = cnb_team($event, $class, cnb_kelompok('A'), 'A');
    $outsider = cnb_team($event, $class, cnb_kelompok('O'), 'O');
    cnb_teamEntry($schedule, $teamA, 1);

    expect(fn () => app(CompetitionWorkflowService::class)->submitTeamResult($schedule, $outsider->id, 'Normal', null))
        ->toThrow(\RuntimeException::class, 'must be an entry');
});

test('non-bracket team result rejects schedule from another event', function () {
    $eventA = cnb_event();
    $eventB = cnb_event();
    $category = cnb_category($eventA);
    $class = cnb_class($eventA, $category, 'team_vs_team');
    $schedule = cnb_schedule($class, 'Waiting Result');
    $teamA = cnb_team($eventA, $class, cnb_kelompok('A'), 'A');
    cnb_teamEntry($schedule, $teamA, 1);

    expect(fn () => app(CompetitionWorkflowService::class)->submitTeamResult($schedule, $teamA->id, 'Normal', null, $eventB->id))
        ->toThrow(\RuntimeException::class, 'active event');
});

// ---------------------------------------------------------------------------
// F. Non-bracket → tidak ada bracket advancement / podium
// ---------------------------------------------------------------------------

test('non-bracket team match does not attempt bracket advancement', function () {
    $event = cnb_event();
    $category = cnb_category($event);
    $class = cnb_class($event, $category, 'team_vs_team');
    $schedule = cnb_schedule($class, 'Waiting Result');
    $teamA = cnb_team($event, $class, cnb_kelompok('A'), 'A');
    $teamB = cnb_team($event, $class, cnb_kelompok('B'), 'B');
    cnb_teamEntry($schedule, $teamA, 1);
    cnb_teamEntry($schedule, $teamB, 2);

    app(CompetitionWorkflowService::class)->submitTeamResult($schedule, $teamA->id, 'Normal', null, $event->id);

    expect(CompetitionBracket::count())->toBe(0)
        ->and(CompetitionSchedule::count())->toBe(1)
        ->and(\App\Models\CompetitionTeamOutcome::count())->toBe(0)
        ->and($schedule->fresh()->bracketMatch()->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// G + H. Regression bracket (R3 individual, R4B team)
// ---------------------------------------------------------------------------

test('regression: bracket matches still require official and move to waiting result', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cnb_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cnb_category($event);
    $workflow = app(CompetitionWorkflowService::class);

    foreach (['individual_vs_individual', 'team_vs_team'] as $format) {
        $class = cnb_class($event, $category, $format);
        \Livewire::test(\App\Livewire\Competition\BracketManager::class)
            ->set('newParticipantCount', '4')
            ->call('generate', $class->id);

        $bracket = CompetitionBracket::where('competition_class_id', $class->id)->first();
        $semi = $bracket->bracketMatches->where('round', 2)->first();
        $semi->schedule->update(['status' => 'Playing', 'required_participants' => 1]);

        expect($workflow->requiresOfficial($semi->schedule))->toBeTrue()
            ->and($workflow->completeMatch($semi->schedule))->toBe('Waiting Result');
    }
});

test('regression: finishMatch still finishes non-official (mass/heat) matches', function () {
    $event = cnb_event();
    $category = cnb_category($event);
    $workflow = app(CompetitionWorkflowService::class);

    foreach (['individual_mass', 'individual_heat', 'team_mass'] as $format) {
        $class = cnb_class($event, $category, $format);
        $schedule = cnb_schedule($class, 'Playing');
        expect($workflow->finishMatch($schedule))->toBeTrue()
            ->and($schedule->fresh()->status)->toBe('Finished');
    }
});
