<?php

use App\Enums\Role;
use App\Models\CompetitionBracketMatch;
use App\Models\CompetitionOutcome;
use App\Models\CompetitionScheduleEntry;
use App\Models\CompetitionTeamOutcome;
use App\Models\User;
use App\Services\Competition\CompetitionBracketSeederService;
use App\Services\Competition\CompetitionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $this->actingAs($this->admin);
});

function bye_event(array $overrides = []): \App\Models\Event
{
    return \App\Models\Event::create(array_merge([
        'name' => 'BYE Event '.str()->random(6),
        'slug' => 'bye-'.str()->random(6),
        'status' => 'active',
        'event_type' => 'competition',
    ], $overrides));
}

function bye_category(\App\Models\Event $event): \App\Models\CompetitionCategory
{
    return \App\Models\CompetitionCategory::create(['event_id' => $event->id, 'name' => 'BYE Cat '.str()->random(4)]);
}

function bye_class(\App\Models\Event $event, \App\Models\CompetitionCategory $category, string $format = 'individual_vs_individual'): \App\Models\CompetitionClass
{
    return \App\Models\CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'BYE Class '.str()->random(4),
        'gender' => 'M',
        'format' => $format,
        'status' => 'registration_open',
        'is_active' => true,
    ]);
}

function bye_register(string $nama, \App\Models\Event $event, \App\Models\CompetitionCategory $category, \App\Models\CompetitionClass $class): \App\Models\CompetitionRegistration
{
    $person = \App\Models\Person::create(['nama' => $nama, 'jenis_kelamin' => 'L']);

    return app(\App\Services\Competition\CompetitionRegistrationService::class)->registerForPerson(
        person: $person,
        eventId: $event->id,
        competitionCategoryId: $category->id,
        competitionClassId: $class->id,
    )['competition_registration'];
}

function bye_team(string $name, \App\Models\Event $event, \App\Models\CompetitionClass $class): \App\Models\CompetitionTeam
{
    $kel = \App\Models\kelompok::create(['kelompok_asal' => 'KM '.str()->random(4)]);

    return \App\Models\CompetitionTeam::create([
        'event_id' => $event->id,
        'competition_class_id' => $class->id,
        'name' => $name,
        'kelompok_id' => $kel->id,
        'is_active' => true,
    ]);
}

function bye_generate(\App\Models\CompetitionClass $class, int $count, bool $thirdPlace = false): \App\Models\CompetitionBracket
{
    \Livewire::test(\App\Livewire\Competition\BracketManager::class)
        ->set('newParticipantCount', (string) $count)
        ->set('thirdPlaceMatch', $thirdPlace)
        ->call('generate', $class->id);

    return \App\Models\CompetitionBracket::where('competition_class_id', $class->id)->first();
}

function bye_initialMatches(\App\Models\CompetitionBracket $bracket)
{
    $totalRounds = (int) log($bracket->participant_count, 2);

    return CompetitionBracketMatch::where('competition_bracket_id', $bracket->id)
        ->where('round', $totalRounds)
        ->orderBy('position')
        ->get();
}

function bye_finish(CompetitionBracketMatch $match, int $winnerId): void
{
    $w = app(CompetitionWorkflowService::class);
    $s = $match->schedule->fresh();

    if ($s->status === 'Scheduled') {
        $w->prepareMatch($s);
    }
    if ($s->status === 'Ready') {
        $w->startMatch($s);
    }
    if ($s->status === 'Playing') {
        $w->moveToWaitingResult($s);
    }
    $w->submitResult($s->fresh(), $winnerId, 'Normal', null);
}

function bye_finishTeam(CompetitionBracketMatch $match, int $winnerTeamId): void
{
    $w = app(CompetitionWorkflowService::class);
    $s = $match->schedule->fresh();

    if ($s->status === 'Scheduled') {
        $w->prepareMatch($s);
    }
    if ($s->status === 'Ready') {
        $w->startMatch($s);
    }
    if ($s->status === 'Playing') {
        $w->moveToWaitingResult($s);
    }
    $w->submitTeamResult($s->fresh(), $winnerTeamId, 'Normal', null);
}

test('IND 6 peserta auto-bye merata 2 bye menghasilkan 4 semifinalist tanpa duplicate', function () {
    $event = bye_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $category = bye_category($event);
    $class = bye_class($event, $category, 'individual_vs_individual');

    $regs = [];
    for ($i = 1; $i <= 6; $i++) {
        $regs[] = bye_register('P'.$i, $event, $category, $class);
    }

    $bracket = bye_generate($class, 8);
    $initial = bye_initialMatches($bracket);

    expect($initial)->toHaveCount(4);

    $counts = $initial->map(fn ($m) => $m->schedule->scheduleEntries()->count())->all();
    expect(array_sum($counts))->toBe(6)
        ->and(max($counts) - min($counts))->toBeLessThanOrEqual(1);

    $allIds = $initial->flatMap(fn ($m) => $m->schedule->scheduleEntries()->pluck('competition_registration_id'))->filter()->all();
    expect(count($allIds))->toBe(6)
        ->and(count(array_unique($allIds)))->toBe(6);

    expect($counts)->not->toBe([2, 2, 2, 0]);

    $byeMatches = $initial->filter(fn ($m) => $m->schedule->scheduleEntries()->count() === 1);
    expect($byeMatches)->toHaveCount(2);

    foreach ($byeMatches as $bye) {
        expect($bye->schedule->fresh()->status)->toBe('Finished')
            ->and($bye->schedule->fresh()->winner_registration_id)->not->toBeNull();
    }

    $semi = CompetitionBracketMatch::where('competition_bracket_id', $bracket->id)->where('round', 2)->orderBy('position')->get();
    expect($semi)->toHaveCount(2);

    $semiEntryCount = CompetitionScheduleEntry::whereIn('competition_schedule_id', $semi->pluck('competition_schedule_id'))->count();
    expect($semiEntryCount)->toBe(2);

    $semiIds = CompetitionScheduleEntry::whereIn('competition_schedule_id', $semi->pluck('competition_schedule_id'))->pluck('competition_registration_id')->filter()->all();
    expect(count(array_unique($semiIds)))->toBe(2);

    $result = app(CompetitionBracketSeederService::class)->seedInitialRound($event->id, $bracket->id);
    expect(CompetitionScheduleEntry::whereIn('competition_schedule_id', $initial->pluck('competition_schedule_id'))->count())->toBe(6);
});

test('IND 6 peserta auto-bye final dan third-place dapat dimainkan', function () {
    $event = bye_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $category = bye_category($event);
    $class = bye_class($event, $category, 'individual_vs_individual');

    $regs = [];
    for ($i = 1; $i <= 6; $i++) {
        $regs[] = bye_register('P'.$i, $event, $category, $class);
    }

    $bracket = bye_generate($class, 8, thirdPlace: true);
    $initial = bye_initialMatches($bracket);
    $semi = CompetitionBracketMatch::where('competition_bracket_id', $bracket->id)->where('round', 2)->orderBy('position')->get();
    $final = CompetitionBracketMatch::where('competition_bracket_id', $bracket->id)->where('round', 1)->where('is_third_place', false)->first();
    $bronze = CompetitionBracketMatch::where('competition_bracket_id', $bracket->id)->where('is_third_place', true)->first();

    $contested = $initial->filter(fn ($m) => $m->schedule->scheduleEntries()->count() === 2);

    foreach ($contested as $match) {
        $ids = $match->schedule->scheduleEntries()->pluck('competition_registration_id')->all();
        bye_finish($match, $ids[0]);
    }

    expect($semi->map(fn ($m) => $m->schedule->fresh()->scheduleEntries()->count())->all())->toBe([2, 2]);
    expect($semi[0]->schedule->fresh()->status)->toBe('Ready');
    expect($semi[1]->schedule->fresh()->status)->toBe('Ready');

    $semiWinners = $semi->map(fn ($m) => $m->schedule->scheduleEntries()->first()->competition_registration_id)->all();
    bye_finish($semi[0], $semiWinners[0]);
    bye_finish($semi[1], $semiWinners[1]);

    expect($final->schedule->fresh()->scheduleEntries()->count())->toBe(2);
    expect($bronze->schedule->fresh()->scheduleEntries()->count())->toBe(2);

    $finalIds = $final->schedule->scheduleEntries()->pluck('competition_registration_id')->all();
    bye_finish($final, $finalIds[0]);

    $bronzeIds = $bronze->schedule->scheduleEntries()->pluck('competition_registration_id')->all();
    bye_finish($bronze, $bronzeIds[0]);

    $pos = CompetitionOutcome::pluck('position', 'competition_registration_id');
    expect($pos->filter()->count())->toBe(4)
        ->and(CompetitionOutcome::where('position', 1)->count())->toBe(1)
        ->and(CompetitionOutcome::where('position', 2)->count())->toBe(1);
});

test('TEAM 6 team auto-bye 2 bye menghasilkan 4 semifinalist tanpa duplicate', function () {
    $event = bye_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $category = bye_category($event);
    $class = bye_class($event, $category, 'team_vs_team');

    $teams = [];
    for ($i = 1; $i <= 6; $i++) {
        $teams[] = bye_team('Team '.$i, $event, $class);
    }

    $bracket = bye_generate($class, 8);
    $initial = bye_initialMatches($bracket);

    expect($initial)->toHaveCount(4);

    $counts = $initial->map(fn ($m) => $m->schedule->scheduleEntries()->count())->all();
    expect(array_sum($counts))->toBe(6);

    $allTeamIds = $initial->flatMap(fn ($m) => $m->schedule->scheduleEntries()->pluck('competition_team_id'))->filter()->all();
    expect(count($allTeamIds))->toBe(6)
        ->and(count(array_unique($allTeamIds)))->toBe(6);

    $byeMatches = $initial->filter(fn ($m) => $m->schedule->scheduleEntries()->count() === 1);
    expect($byeMatches)->toHaveCount(2);

    foreach ($byeMatches as $bye) {
        expect($bye->schedule->fresh()->status)->toBe('Finished')
            ->and($bye->schedule->fresh()->winner_team_id)->not->toBeNull();
    }

    $semi = CompetitionBracketMatch::where('competition_bracket_id', $bracket->id)->where('round', 2)->orderBy('position')->get();
    $semiTeamCount = CompetitionScheduleEntry::whereIn('competition_schedule_id', $semi->pluck('competition_schedule_id'))->whereNotNull('competition_team_id')->count();
    expect($semiTeamCount)->toBe(2);

    $regEntries = CompetitionScheduleEntry::whereIn('competition_schedule_id', $initial->pluck('competition_schedule_id'))->whereNotNull('competition_registration_id')->count();
    expect($regEntries)->toBe(0);
});

test('TEAM 6 team bye final dan third-place', function () {
    $event = bye_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $category = bye_category($event);
    $class = bye_class($event, $category, 'team_vs_team');

    $teams = [];
    for ($i = 1; $i <= 6; $i++) {
        $teams[] = bye_team('Team '.$i, $event, $class);
    }

    $bracket = bye_generate($class, 8, thirdPlace: true);
    $initial = bye_initialMatches($bracket);
    $semi = CompetitionBracketMatch::where('competition_bracket_id', $bracket->id)->where('round', 2)->orderBy('position')->get();
    $final = CompetitionBracketMatch::where('competition_bracket_id', $bracket->id)->where('round', 1)->where('is_third_place', false)->first();
    $bronze = CompetitionBracketMatch::where('competition_bracket_id', $bracket->id)->where('is_third_place', true)->first();

    $contested = $initial->filter(fn ($m) => $m->schedule->scheduleEntries()->count() === 2);

    foreach ($contested as $match) {
        $teamId = $match->schedule->scheduleEntries()->first()->competition_team_id;
        bye_finishTeam($match, $teamId);
    }

    $semiWinners = $semi->map(fn ($m) => $m->schedule->scheduleEntries()->first()->competition_team_id)->all();
    bye_finishTeam($semi[0], $semiWinners[0]);
    bye_finishTeam($semi[1], $semiWinners[1]);

    expect($final->schedule->fresh()->scheduleEntries()->count())->toBe(2);
    expect($bronze->schedule->fresh()->scheduleEntries()->count())->toBe(2);

    $finalTeamId = $final->schedule->scheduleEntries()->first()->competition_team_id;
    bye_finishTeam($final, $finalTeamId);

    $bronzeTeamId = $bronze->schedule->scheduleEntries()->first()->competition_team_id;
    bye_finishTeam($bronze, $bronzeTeamId);

    expect(CompetitionTeamOutcome::where('position', 1)->count())->toBe(1)
        ->and(CompetitionTeamOutcome::where('position', 3)->count())->toBe(1);
});
