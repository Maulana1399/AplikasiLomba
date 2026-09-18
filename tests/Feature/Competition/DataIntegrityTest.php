<?php

use App\Enums\Role;
use App\Models\CompetitionBracket;
use App\Models\CompetitionBracketMatch;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionOutcome;
use App\Models\CompetitionSchedule;
use App\Models\CompetitionScheduleEntry;
use App\Models\Event;
use App\Models\Participation;
use App\Models\Person;
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

    $regService = app(\App\Services\Competition\CompetitionRegistrationService::class);

    $this->persons = [];
    $this->registrations = [];
    for ($i = 0; $i < 4; $i++) {
        $person = Person::factory()->create(['jenis_kelamin' => 'L']);
        Participation::create([
            'person_id' => $person->id,
            'event_id' => $this->event->id,
            'participant_number' => 'TST'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
            'jenis_peserta' => 'Peserta',
        ]);
        $reg = $regService->registerForPerson(
            person: $person,
            eventId: $this->event->id,
            competitionCategoryId: $this->category->id,
            competitionClassId: $this->class->id,
        )['competition_registration'];
        $this->persons[] = $person;
        $this->registrations[] = $reg;
    }
});

// -----------------------------------------------------------------------
// Schedule Delete Cascade
// -----------------------------------------------------------------------

test('deleting schedule removes entries and outcomes', function () {
    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'status' => 'Scheduled',
        'required_participants' => 2,
    ]);

    CompetitionScheduleEntry::create([
        'competition_schedule_id' => $schedule->id,
        'competition_registration_id' => $this->registrations[0]->id,
    ]);
    CompetitionScheduleEntry::create([
        'competition_schedule_id' => $schedule->id,
        'competition_registration_id' => $this->registrations[1]->id,
    ]);

    CompetitionOutcome::create([
        'competition_registration_id' => $this->registrations[0]->id,
        'position' => 1,
    ]);

    $scheduleId = $schedule->id;

    $schedule->delete();

    expect(CompetitionScheduleEntry::where('competition_schedule_id', $scheduleId)->count())->toBe(0);
    expect(CompetitionOutcome::where('competition_registration_id', $this->registrations[0]->id)->count())->toBe(0);
});

test('deleting schedule cleans bracket match link', function () {
    $bracket = CompetitionBracket::create([
        'competition_class_id' => $this->class->id,
        'name' => 'Test Bracket',
        'participant_count' => 4,
        'status' => 'active',
    ]);

    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'status' => 'Scheduled',
        'required_participants' => 2,
    ]);

    CompetitionBracketMatch::create([
        'competition_bracket_id' => $bracket->id,
        'competition_schedule_id' => $schedule->id,
        'round' => 2,
        'position' => 1,
    ]);

    $scheduleId = $schedule->id;
    $schedule->delete();

    expect(CompetitionBracketMatch::where('competition_schedule_id', $scheduleId)->count())->toBe(0);
});

// -----------------------------------------------------------------------
// Registration Delete Cascade
// -----------------------------------------------------------------------

test('deleting registration removes schedule entries and outcomes', function () {
    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'status' => 'Scheduled',
        'required_participants' => 2,
    ]);

    CompetitionScheduleEntry::create([
        'competition_schedule_id' => $schedule->id,
        'competition_registration_id' => $this->registrations[0]->id,
    ]);

    CompetitionOutcome::create([
        'competition_registration_id' => $this->registrations[0]->id,
        'position' => 1,
    ]);

    $regId = $this->registrations[0]->id;

    $this->registrations[0]->delete();

    expect(CompetitionScheduleEntry::where('competition_registration_id', $regId)->count())->toBe(0);
    expect(CompetitionOutcome::where('competition_registration_id', $regId)->count())->toBe(0);
});

// -----------------------------------------------------------------------
// Bracket Delete Cascade
// -----------------------------------------------------------------------

test('deleting bracket removes matches, schedules, entries, and outcomes', function () {
    $bracket = CompetitionBracket::create([
        'competition_class_id' => $this->class->id,
        'name' => 'Test Bracket',
        'participant_count' => 4,
        'status' => 'active',
    ]);

    $schedule1 = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'status' => 'Scheduled',
        'required_participants' => 2,
    ]);
    $schedule2 = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'status' => 'Scheduled',
        'required_participants' => 2,
    ]);
    $schedule3 = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'status' => 'Scheduled',
        'required_participants' => 1,
    ]);

    $bm1 = CompetitionBracketMatch::create([
        'competition_bracket_id' => $bracket->id,
        'competition_schedule_id' => $schedule1->id,
        'round' => 2,
        'position' => 1,
    ]);
    $bm2 = CompetitionBracketMatch::create([
        'competition_bracket_id' => $bracket->id,
        'competition_schedule_id' => $schedule2->id,
        'round' => 2,
        'position' => 2,
    ]);
    $bm3 = CompetitionBracketMatch::create([
        'competition_bracket_id' => $bracket->id,
        'competition_schedule_id' => $schedule3->id,
        'round' => 1,
        'position' => 1,
        'source_match_a_id' => $bm1->id,
        'source_match_b_id' => $bm2->id,
    ]);

    CompetitionScheduleEntry::create([
        'competition_schedule_id' => $schedule1->id,
        'competition_registration_id' => $this->registrations[0]->id,
    ]);
    CompetitionScheduleEntry::create([
        'competition_schedule_id' => $schedule2->id,
        'competition_registration_id' => $this->registrations[1]->id,
    ]);

    CompetitionOutcome::create([
        'competition_registration_id' => $this->registrations[0]->id,
        'position' => 1,
    ]);

    $bracketId = $bracket->id;

    $bracket->delete();

    expect(CompetitionBracket::where('id', $bracketId)->count())->toBe(0);
    expect(CompetitionBracketMatch::where('competition_bracket_id', $bracketId)->count())->toBe(0);
    expect(CompetitionSchedule::where('id', $schedule1->id)->count())->toBe(0);
    expect(CompetitionSchedule::where('id', $schedule2->id)->count())->toBe(0);
    expect(CompetitionSchedule::where('id', $schedule3->id)->count())->toBe(0);
    expect(CompetitionScheduleEntry::where('competition_registration_id', $this->registrations[0]->id)->count())->toBe(0);
    expect(CompetitionOutcome::where('competition_registration_id', $this->registrations[0]->id)->count())->toBe(0);
});

// -----------------------------------------------------------------------
// Orphan Detection
// -----------------------------------------------------------------------

test('no orphan schedule entries exist', function () {
    $orphans = CompetitionScheduleEntry::whereDoesntHave('competitionSchedule')->count();
    expect($orphans)->toBe(0);
});

test('no orphan outcomes exist', function () {
    $orphans = CompetitionOutcome::whereDoesntHave('competitionRegistration')->count();
    expect($orphans)->toBe(0);
});

test('no orphan bracket matches exist', function () {
    $orphans = CompetitionBracketMatch::whereDoesntHave('bracket')->count();
    expect($orphans)->toBe(0);
});
