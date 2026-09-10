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

    $this->person = Person::factory()->create(['jenis_kelamin' => 'L']);
    $this->participation = Participation::create([
        'person_id' => $this->person->id,
        'event_id' => $this->event->id,
        'participant_number' => 'TST0001',
        'jenis_peserta' => 'Peserta',
    ]);

    $regService = app(\App\Services\Competition\CompetitionRegistrationService::class);
    $this->registration = $regService->registerForPerson(
        person: $this->person,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $this->class->id,
    )['competition_registration'];

    $this->schedule = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'status' => 'Scheduled',
        'required_participants' => 1,
    ]);

    CompetitionScheduleEntry::create([
        'competition_schedule_id' => $this->schedule->id,
        'competition_registration_id' => $this->registration->id,
    ]);

    $this->workflow = app(CompetitionWorkflowService::class);
});

// -----------------------------------------------------------------------
// Legal transitions
// -----------------------------------------------------------------------

test('Scheduled can transition to Ready', function () {
    $result = $this->workflow->prepareMatch($this->schedule);

    expect($result)->toBeTrue();
    expect($this->schedule->fresh()->status)->toBe('Ready');
});

test('Ready can transition to Playing', function () {
    $this->schedule->update(['status' => 'Ready']);

    $result = $this->workflow->startMatch($this->schedule);

    expect($result)->toBeTrue();
    expect($this->schedule->fresh()->status)->toBe('Playing');
});

test('Playing can transition to Waiting Result (non-bracket)', function () {
    $this->schedule->update(['status' => 'Playing']);

    $result = $this->workflow->moveToWaitingResult($this->schedule);

    expect($result)->toBeTrue();
    expect($this->schedule->fresh()->status)->toBe('Waiting Result');
});

test('Waiting Result can transition to Finished via submitResult', function () {
    $this->schedule->update(['status' => 'Waiting Result']);

    $this->workflow->submitResult(
        $this->schedule,
        $this->registration->id,
        'Normal',
        null,
    );

    expect($this->schedule->fresh()->status)->toBe('Finished');
    expect($this->schedule->fresh()->winner_registration_id)->toBe($this->registration->id);
});

test('Finished can transition to Scheduled via resetMatch', function () {
    $this->schedule->update([
        'status' => 'Finished',
        'winner_registration_id' => $this->registration->id,
        'finished_at' => now(),
    ]);

    $this->workflow->resetMatch($this->schedule);

    $fresh = $this->schedule->fresh();
    expect($fresh->status)->toBe('Scheduled');
    expect($fresh->winner_registration_id)->toBeNull();
});

// -----------------------------------------------------------------------
// Illegal transitions
// -----------------------------------------------------------------------

test('Scheduled cannot transition directly to Finished', function () {
    expect($this->workflow->canTransitionTo($this->schedule, 'Finished'))->toBeFalse();
});

test('Ready cannot transition directly to Finished', function () {
    $this->schedule->update(['status' => 'Ready']);

    expect($this->workflow->canTransitionTo($this->schedule, 'Finished'))->toBeFalse();
});

test('Playing cannot transition directly to Ready', function () {
    $this->schedule->update(['status' => 'Playing']);

    expect($this->workflow->canTransitionTo($this->schedule, 'Ready'))->toBeFalse();
});

test('Finished cannot transition directly to Playing', function () {
    $this->schedule->update(['status' => 'Finished']);

    expect($this->workflow->canTransitionTo($this->schedule, 'Playing'))->toBeFalse();
});

test('finishMatch rejects bracket matches', function () {
    $bracket = CompetitionBracket::create([
        'competition_class_id' => $this->class->id,
        'name' => 'Test Bracket',
        'participant_count' => 4,
        'status' => 'active',
    ]);

    CompetitionBracketMatch::create([
        'competition_bracket_id' => $bracket->id,
        'competition_schedule_id' => $this->schedule->id,
        'round' => 2,
        'position' => 1,
    ]);

    $this->schedule->update(['status' => 'Playing']);

    $result = $this->workflow->finishMatch($this->schedule);

    expect($result)->toBeFalse();
    expect($this->schedule->fresh()->status)->toBe('Playing');
});

// -----------------------------------------------------------------------
// Outcome only loads assigned participants
// -----------------------------------------------------------------------

test('OutcomeManager only loads schedule entries', function () {
    $regService = app(\App\Services\Competition\CompetitionRegistrationService::class);
    $person2 = Person::factory()->create(['jenis_kelamin' => 'L']);
    $participation2 = Participation::create([
        'person_id' => $person2->id,
        'event_id' => $this->event->id,
        'participant_number' => 'TST0002',
        'jenis_peserta' => 'Peserta',
    ]);
    $registration2 = $regService->registerForPerson(
        person: $person2,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $this->class->id,
    )['competition_registration'];

    $component = \Livewire::test(\App\Livewire\Competition\Schedule\OutcomeManager::class, [
        'schedule' => $this->schedule,
    ]);

    // Class tanpa format default ke individual_heat → OutcomeManager memakai
    // jalur heat (per-heat results), tetap hanya memuat entries schedule ini.
    $ids = collect($component->heatResults)->pluck('registration_id')->toArray();
    expect($ids)->toContain($this->registration->id);
    expect($ids)->not->toContain($registration2->id);
});
