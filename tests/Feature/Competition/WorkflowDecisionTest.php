<?php

use App\Enums\Role;
use App\Models\CompetitionBracket;
use App\Models\CompetitionBracketMatch;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionSchedule;
use App\Models\CompetitionScheduleEntry;
use App\Models\Event;
use App\Models\Participation;
use App\Models\Person;
use App\Services\Competition\CompetitionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = \App\Models\User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($this->user);

    $this->event = Event::factory()->create(['status' => 'active', 'event_type' => 'competition']);
    app(\App\Support\ActiveEventContext::class)->set($this->event);

    $this->category = CompetitionCategory::create([
        'event_id' => $this->event->id,
        'name' => 'Test Category',
        'is_active' => true,
    ]);

    $this->class = CompetitionClass::create([
        'event_id' => $this->event->id,
        'competition_category_id' => $this->category->id,
        'name' => 'Test Class',
        'gender' => 'M',
        'is_active' => true,
    ]);

    $person = Person::factory()->create(['jenis_kelamin' => 'L']);
    Participation::create([
        'person_id' => $person->id,
        'event_id' => $this->event->id,
        'participant_number' => 'TST0001',
        'jenis_peserta' => 'Peserta',
    ]);
    $regService = app(\App\Services\Competition\CompetitionRegistrationService::class);
    $this->registration = $regService->registerForPerson(
        person: $person,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $this->class->id,
    )['competition_registration'];

    $this->schedule = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'status' => 'Playing',
        'required_participants' => 1,
    ]);

    CompetitionScheduleEntry::create([
        'competition_schedule_id' => $this->schedule->id,
        'competition_registration_id' => $this->registration->id,
    ]);

    $this->workflow = app(CompetitionWorkflowService::class);
});

// -----------------------------------------------------------------------
// requiresOfficial
// -----------------------------------------------------------------------

test('requiresOfficial returns true for bracket matches', function () {
    $bracket = CompetitionBracket::create([
        'competition_class_id' => $this->class->id,
        'name' => 'Test',
        'participant_count' => 4,
        'status' => 'active',
    ]);
    CompetitionBracketMatch::create([
        'competition_bracket_id' => $bracket->id,
        'competition_schedule_id' => $this->schedule->id,
        'round' => 2,
        'position' => 1,
    ]);

    expect($this->workflow->requiresOfficial($this->schedule))->toBeTrue();
});

test('requiresOfficial returns false for non-bracket matches', function () {
    expect($this->workflow->requiresOfficial($this->schedule))->toBeFalse();
});

// -----------------------------------------------------------------------
// completeMatch decision
// -----------------------------------------------------------------------

test('completeMatch sends bracket matches to Waiting Result', function () {
    $bracket = CompetitionBracket::create([
        'competition_class_id' => $this->class->id,
        'name' => 'Test',
        'participant_count' => 4,
        'status' => 'active',
    ]);
    CompetitionBracketMatch::create([
        'competition_bracket_id' => $bracket->id,
        'competition_schedule_id' => $this->schedule->id,
        'round' => 2,
        'position' => 1,
    ]);

    $result = $this->workflow->completeMatch($this->schedule);

    expect($result)->toBe('Waiting Result');
    expect($this->schedule->fresh()->status)->toBe('Waiting Result');
});

test('completeMatch finishes non-bracket matches directly', function () {
    $result = $this->workflow->completeMatch($this->schedule);

    expect($result)->toBe('Finished');
    expect($this->schedule->fresh()->status)->toBe('Finished');
});

test('completeMatch rejects non-Playing schedules', function () {
    $this->schedule->update(['status' => 'Scheduled']);

    $result = $this->workflow->completeMatch($this->schedule);

    expect($result)->toBe('Scheduled');
});

// -----------------------------------------------------------------------
// OperatorDashboard no longer contains workflow branching
// -----------------------------------------------------------------------

test('OperatorDashboard advanceStatus uses completeMatch for Playing', function () {
    \Livewire::test(\App\Livewire\Competition\OperatorDashboard::class)
        ->call('advanceStatus', $this->schedule->id);

    expect($this->schedule->fresh()->status)->toBe('Finished');
});

test('OperatorDashboard advanceStatus sends bracket matches to Waiting Result', function () {
    $bracket = CompetitionBracket::create([
        'competition_class_id' => $this->class->id,
        'name' => 'Test',
        'participant_count' => 4,
        'status' => 'active',
    ]);
    CompetitionBracketMatch::create([
        'competition_bracket_id' => $bracket->id,
        'competition_schedule_id' => $this->schedule->id,
        'round' => 2,
        'position' => 1,
    ]);

    \Livewire::test(\App\Livewire\Competition\OperatorDashboard::class)
        ->call('advanceStatus', $this->schedule->id);

    expect($this->schedule->fresh()->status)->toBe('Waiting Result');
});

// -----------------------------------------------------------------------
// MatchCenter moveToWaitingResult
// -----------------------------------------------------------------------

test('MatchCenter moveToWaitingResult sends bracket match to Waiting Result', function () {
    $bracket = CompetitionBracket::create([
        'competition_class_id' => $this->class->id,
        'name' => 'Test',
        'participant_count' => 4,
        'status' => 'active',
    ]);
    CompetitionBracketMatch::create([
        'competition_bracket_id' => $bracket->id,
        'competition_schedule_id' => $this->schedule->id,
        'round' => 2,
        'position' => 1,
    ]);

    \Livewire::test(\App\Livewire\Competition\MatchCenter::class)
        ->call('moveToWaitingResult', $this->schedule->id);

    expect($this->schedule->fresh()->status)->toBe('Waiting Result');
});

test('MatchCenter moveToWaitingResult sends non-bracket (heat) match to Waiting Result, not Finished', function () {
    $this->class->update(['format' => 'individual_heat']);
    $this->schedule->refresh();

    \Livewire::test(\App\Livewire\Competition\MatchCenter::class)
        ->call('moveToWaitingResult', $this->schedule->id);

    expect($this->schedule->fresh()->status)->toBe('Waiting Result')
        ->not->toBe('Finished');
});
