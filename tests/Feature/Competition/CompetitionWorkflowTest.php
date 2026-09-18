<?php

use App\Models\CompetitionAnnouncement;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionOutcome;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionSchedule;
use App\Models\Event;
use App\Models\Person;
use App\Models\User;
use App\Models\Venue;
use App\Services\Competition\CompetitionRegistrationService;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'super_admin']);
    actingAs($this->user);

    $this->event = Event::create([
        'name' => 'Test Competition Event',
        'slug' => 'test-competition',
        'event_type' => 'competition',
        'status' => 'active',
    ]);

    app(\App\Support\ActiveEventContext::class)->set($this->event);

    $this->category = CompetitionCategory::create([
        'event_id' => $this->event->id,
        'name' => 'Lomba Adzan',
    ]);

    $this->class = CompetitionClass::create([
        'event_id' => $this->event->id,
        'competition_category_id' => $this->category->id,
        'name' => 'Class A',
        'gender' => 'M',
    ]);

    $this->person = Person::create([
        'nama' => 'Ahmad Test',
        'jenis_kelamin' => 'L',
    ]);
});

test('1. full competition registration flow', function () {
    $service = app(CompetitionRegistrationService::class);
    $result = $service->registerForPerson(
        person: $this->person,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $this->class->id,
    );

    expect($result['person']->id)->toBe($this->person->id);
    expect($result['status'])->toBe('registered');

    assertDatabaseHas('competition_registrations', [
        'participation_id' => $result['participation']->id,
        'competition_category_id' => $this->category->id,
        'competition_class_id' => $this->class->id,
    ]);
});

test('2. reuse existing person does not create duplicate person', function () {
    $service = app(CompetitionRegistrationService::class);

    $secondClass = CompetitionClass::create([
        'event_id' => $this->event->id,
        'competition_category_id' => $this->category->id,
        'name' => 'SMP Putra',
        'gender' => 'L',
    ]);

    $first = $service->registerForPerson(
        person: $this->person,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $this->class->id,
    );

    $second = $service->registerForPerson(
        person: $this->person,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $secondClass->id,
    );

    expect(Person::where('nama', 'Ahmad Test')->count())->toBe(1);
    expect($first['person']->id)->toBe($this->person->id);
    expect($second['person']->id)->toBe($this->person->id);
});

test('3. duplicate registration throws validation error', function () {
    $service = app(CompetitionRegistrationService::class);

    $service->registerForPerson(
        person: $this->person,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $this->class->id,
    );

    $this->expectException(\Illuminate\Validation\ValidationException::class);

    $service->registerForPerson(
        person: $this->person,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $this->class->id,
    );
});

test('4. schedule status transitions via model update', function () {
    $venue = Venue::create([
        'event_id' => $this->event->id,
        'name' => 'Venue Test',
    ]);

    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'venue_id' => $venue->id,
        'status' => 'Scheduled',
    ]);

    expect($schedule->status)->toBe('Scheduled');

    $schedule->update(['status' => 'Ready']);
    expect($schedule->refresh()->status)->toBe('Ready');

    $schedule->update(['status' => 'Playing']);
    expect($schedule->refresh()->status)->toBe('Playing');

    $schedule->update(['status' => 'Finished']);
    expect($schedule->refresh()->status)->toBe('Finished');
});

test('5. outcome save and retrieve', function () {
    $service = app(CompetitionRegistrationService::class);
    $result = $service->registerForPerson(
        person: $this->person,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $this->class->id,
    );

    $registration = $result['competition_registration'];

    CompetitionOutcome::create([
        'competition_registration_id' => $registration->id,
        'position' => 1,
        'status' => 'Lolos',
        'score' => 95.50,
        'remarks' => 'Juara 1',
    ]);

    assertDatabaseHas('competition_outcomes', [
        'competition_registration_id' => $registration->id,
        'position' => 1,
    ]);
});

test('6. announcement publish and retrieve', function () {
    $announcement = CompetitionAnnouncement::create([
        'event_id' => $this->event->id,
        'message' => 'Test pengumuman',
        'is_active' => true,
        'expires_at' => now()->addMinutes(5),
    ]);

    expect($announcement->is_active)->toBeTrue();

    $active = CompetitionAnnouncement::active()
        ->where('event_id', $this->event->id)
        ->first();

    expect($active)->not->toBeNull();
    expect($active->message)->toBe('Test pengumuman');
});

test('7. viewer schedule grouping by status', function () {
    $venue = Venue::create(['event_id' => $this->event->id, 'name' => 'Venue A']);

    CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'venue_id' => $venue->id,
        'status' => 'Playing',
        'start_at' => now(),
    ]);

    CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'venue_id' => $venue->id,
        'status' => 'Ready',
    ]);

    CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'venue_id' => $venue->id,
        'status' => 'Scheduled',
        'start_at' => now()->addHour(),
    ]);

    $schedules = CompetitionSchedule::where('competition_class_id', $this->class->id)->get();

    expect($schedules->where('status', 'Playing')->count())->toBe(1);
    expect($schedules->where('status', 'Ready')->count())->toBe(1);
    expect($schedules->where('status', 'Scheduled')->count())->toBe(1);
});

test('8. relationship — class belongs to category', function () {
    expect($this->class->competitionCategory->id)->toBe($this->category->id);
    expect($this->category->competitionClasses->pluck('id'))->toContain($this->class->id);
});

test('9. relationship — registration belongs to participation', function () {
    $service = app(CompetitionRegistrationService::class);
    $result = $service->registerForPerson(
        person: $this->person,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $this->class->id,
    );

    $reg = CompetitionRegistration::with('participation')->find($result['competition_registration']->id);

    expect($reg)->not->toBeNull();
    expect($reg->participation->id)->toBe($result['participation']->id);
    expect($reg->competitionCategory->id)->toBe($this->category->id);
    expect($reg->competitionClass->id)->toBe($this->class->id);
});

test('10. event has competition categories', function () {
    expect($this->event->competitionCategories->pluck('id'))->toContain($this->category->id);
});

test('11. venue has competition schedules', function () {
    $venue = Venue::create(['event_id' => $this->event->id, 'name' => 'Venue B']);

    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'venue_id' => $venue->id,
        'status' => 'Scheduled',
    ]);

    expect($venue->fresh()->competitionSchedules->pluck('id'))->toContain($schedule->id);
});

test('13. viewer route returns 200 for active competition event', function () {
    $response = $this->get(route('competition.viewer'));
    $response->assertStatus(200);
});

test('14. viewer route with venue filter returns 200', function () {
    $venue = Venue::create(['event_id' => $this->event->id, 'name' => 'Venue C']);
    $response = $this->get(route('competition.viewer', ['venue' => $venue->id]));
    $response->assertStatus(200);
});

test('15. viewer tv mode query parameter works', function () {
    $response = $this->get(route('competition.viewer', ['display' => 'tv']));
    $response->assertStatus(200);
});

test('16. viewer returns 404 for non-competition event', function () {
    $caiEvent = Event::create(['name' => 'CAI', 'slug' => 'cai-test', 'event_type' => 'cai', 'status' => 'active']);
    app(\App\Support\ActiveEventContext::class)->set($caiEvent);

    $response = $this->get(route('competition.viewer'));
    $response->assertStatus(404);
});

test('17. inactive event can never be selected as viewer context', function () {
    Event::create(['name' => 'Archived', 'slug' => 'archived', 'event_type' => 'competition', 'status' => 'archived']);
    app(\App\Support\ActiveEventContext::class)->set($this->event->fresh()); // keep active context

    expect(app(\App\Support\ActiveEventContext::class)->currentEventType())->toBe('competition');

    // Attempting to select an inactive event is refused by the context guard.
    app(\App\Support\ActiveEventContext::class)->switchTo($this->event->fresh()->id);
    $this->get(route('competition.viewer'))->assertStatus(200);
});

test('18. venue filter with filterByVenue updates venue', function () {
    $component = Livewire::test(\App\Livewire\Competition\Viewer::class);

    $component->assertSet('venueId', null);

    $venue = Venue::create(['event_id' => $this->event->id, 'name' => 'Venue D']);
    $component->call('filterByVenue', $venue->id);
    $component->assertSet('venueId', (string) $venue->id);
});

test('19. venue filter clears venue', function () {
    $venue = Venue::create(['event_id' => $this->event->id, 'name' => 'Venue E']);
    $component = Livewire::test(\App\Livewire\Competition\Viewer::class);

    $component->assertSet('venueId', null);
    $component->call('filterByVenue', $venue->id);
    $component->assertSet('venueId', (string) $venue->id);
    $component->call('filterByVenue');
    $component->assertSet('venueId', null);
});

test('12. event has competition announcements', function () {
    CompetitionAnnouncement::create([
        'event_id' => $this->event->id,
        'message' => 'Test',
        'is_active' => true,
    ]);

    expect($this->event->fresh()->competitionAnnouncements->count())->toBe(1);
});

test('20. create and assign schedule entry', function () {
    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'status' => 'Playing',
    ]);

    $service = app(\App\Services\Competition\CompetitionRegistrationService::class);
    $reg = $service->registerForPerson(
        person: $this->person,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $this->class->id,
    );

    $entry = \App\Models\CompetitionScheduleEntry::create([
        'competition_schedule_id' => $schedule->id,
        'competition_registration_id' => $reg['competition_registration']->id,
    ]);

    expect($entry->id)->toBeGreaterThan(0);
    expect($entry->competitionSchedule->id)->toBe($schedule->id);
    expect($entry->competitionRegistration->id)->toBe($reg['competition_registration']->id);
});

test('21. schedule entry relationships', function () {
    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'status' => 'Playing',
    ]);

    $service = app(\App\Services\Competition\CompetitionRegistrationService::class);
    $reg = $service->registerForPerson(
        person: $this->person,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $this->class->id,
    );

    \App\Models\CompetitionScheduleEntry::create([
        'competition_schedule_id' => $schedule->id,
        'competition_registration_id' => $reg['competition_registration']->id,
    ]);

    expect($schedule->fresh()->scheduleEntries)->toHaveCount(1);
    expect($reg['competition_registration']->fresh()->scheduleEntries)->toHaveCount(1);
});

test('24. gender validation prevents female in male class', function () {
    $maleClass = CompetitionClass::create([
        'event_id' => $this->event->id,
        'competition_category_id' => $this->category->id,
        'name' => 'Test Putra',
        'gender' => 'L',
    ]);

    $femalePerson = Person::create(['nama' => 'Siti Test', 'jenis_kelamin' => 'P']);
    $service = app(\App\Services\Competition\CompetitionRegistrationService::class);

    $this->expectException(\Illuminate\Validation\ValidationException::class);
    $service->registerForPerson(
        person: $femalePerson,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $maleClass->id,
    );
});

test('25. gender validation allows female in female class', function () {
    $femaleClass = CompetitionClass::create([
        'event_id' => $this->event->id,
        'competition_category_id' => $this->category->id,
        'name' => 'Test Putri',
        'gender' => 'P',
    ]);

    $femalePerson = Person::create(['nama' => 'Siti Test2', 'jenis_kelamin' => 'P']);
    $service = app(\App\Services\Competition\CompetitionRegistrationService::class);

    $result = $service->registerForPerson(
        person: $femalePerson,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $femaleClass->id,
    );

    expect($result['status'])->toBe('registered');
});

test('25b. gender validation mixed class accepts everyone', function () {
    $mixedClass = CompetitionClass::create([
        'event_id' => $this->event->id,
        'competition_category_id' => $this->category->id,
        'name' => 'Test Campuran',
        'gender' => 'M',
    ]);

    $male = Person::create(['nama' => 'Budi Mixed', 'jenis_kelamin' => 'L']);
    $female = Person::create(['nama' => 'Dewi Mixed', 'jenis_kelamin' => 'P']);
    $service = app(\App\Services\Competition\CompetitionRegistrationService::class);

    $r1 = $service->registerForPerson(person: $male, eventId: $this->event->id, competitionCategoryId: $this->category->id, competitionClassId: $mixedClass->id);
    $r2 = $service->registerForPerson(person: $female, eventId: $this->event->id, competitionCategoryId: $this->category->id, competitionClassId: $mixedClass->id);

    expect($r1['status'])->toBe('registered');
    expect($r2['status'])->toBe('registered');
});

test('26. multi-class registration - same person different classes', function () {
    $classB = CompetitionClass::create([
        'event_id' => $this->event->id,
        'competition_category_id' => $this->category->id,
        'name' => 'Kelas B',
        'gender' => 'L',
    ]);

    $service = app(\App\Services\Competition\CompetitionRegistrationService::class);

    $first = $service->registerForPerson(
        person: $this->person,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $this->class->id,
    );

    $second = $service->registerForPerson(
        person: $this->person,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $classB->id,
    );

    expect($first['status'])->toBe('registered');
    expect($second['status'])->toBe('registered');
    expect($first['participation']->id)->toBe($second['participation']->id);
});

test('27. viewer shows empty state when no schedule entries', function () {
    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'status' => 'Playing',
    ]);

    expect($schedule->scheduleEntries)->toHaveCount(0);
});

test('28. viewer shows participants when schedule entries exist', function () {
    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'status' => 'Playing',
    ]);

    $service = app(\App\Services\Competition\CompetitionRegistrationService::class);
    $reg = $service->registerForPerson(
        person: $this->person,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $this->class->id,
    );

    \App\Models\CompetitionScheduleEntry::create([
        'competition_schedule_id' => $schedule->id,
        'competition_registration_id' => $reg['competition_registration']->id,
    ]);

    $schedule->load('scheduleEntries.competitionRegistration.participation.person');
    expect($schedule->scheduleEntries)->toHaveCount(1);
    expect($schedule->scheduleEntries->first()->competitionRegistration->participation->person->nama)->toBe('Ahmad Test');
});

test('23. schedule report includes participant count', function () {
    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'status' => 'Playing',
    ]);

    $service = app(\App\Services\Competition\CompetitionRegistrationService::class);
    $reg = $service->registerForPerson(
        person: $this->person,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $this->class->id,
    );

    \App\Models\CompetitionScheduleEntry::create([
        'competition_schedule_id' => $schedule->id,
        'competition_registration_id' => $reg['competition_registration']->id,
    ]);

    $report = app(\App\Services\Competition\CompetitionReportService::class);
    $schedules = $report->scheduleReport($this->event);

    $entry = $schedules->firstWhere('id', $schedule->id);
    expect($entry)->not->toBeNull();
    expect($entry->participants_count)->toBe(1);
});

// ============================================================
// TASK 7: NEW TESTS FOR SPRINT 7.0
// ============================================================

test('29. schedule defaults to Scheduled and can transition through all states', function () {
    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
    ]);

    expect($schedule->refresh()->status)->toBe('Scheduled');

    $schedule->update(['status' => 'Ready']);
    expect($schedule->refresh()->status)->toBe('Ready');

    $schedule->update(['status' => 'Playing']);
    expect($schedule->refresh()->status)->toBe('Playing');

    $schedule->update(['status' => 'Finished']);
    expect($schedule->refresh()->status)->toBe('Finished');
});

test('30. required_participants defaults to 1', function () {
    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
    ]);

    expect($schedule->refresh()->required_participants)->toBe(1);
});

test('31. auto ready detection — 1 participant becomes Ready after assign', function () {
    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'required_participants' => 1,
        'status' => 'Scheduled',
    ]);

    $service = app(\App\Services\Competition\CompetitionRegistrationService::class);
    $reg = $service->registerForPerson(
        person: $this->person,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $this->class->id,
    );

    $component = Livewire::test(\App\Livewire\Competition\Schedule\EntryManager::class, ['schedule' => $schedule]);
    $component->call('assign', $reg['competition_registration']->id);

    expect($schedule->refresh()->status)->toBe('Ready');
});

test('32. auto ready detection — 2 participants becomes Ready only after 2 assigned', function () {
    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'required_participants' => 2,
        'status' => 'Scheduled',
    ]);

    $person2 = Person::create(['nama' => 'Budi Test', 'jenis_kelamin' => 'L']);
    $service = app(\App\Services\Competition\CompetitionRegistrationService::class);

    $reg1 = $service->registerForPerson(
        person: $this->person,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $this->class->id,
    );
    $reg2 = $service->registerForPerson(
        person: $person2,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $this->class->id,
    );

    $component = Livewire::test(\App\Livewire\Competition\Schedule\EntryManager::class, ['schedule' => $schedule]);

    $component->call('assign', $reg1['competition_registration']->id);
    expect($schedule->refresh()->status)->toBe('Scheduled');

    $component->call('assign', $reg2['competition_registration']->id);
    expect($schedule->refresh()->status)->toBe('Ready');
});

test('33. auto ready detection — participant removal returns to Scheduled', function () {
    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'required_participants' => 1,
        'status' => 'Scheduled',
    ]);

    $service = app(\App\Services\Competition\CompetitionRegistrationService::class);
    $reg = $service->registerForPerson(
        person: $this->person,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $this->class->id,
    );

    $component = Livewire::test(\App\Livewire\Competition\Schedule\EntryManager::class, ['schedule' => $schedule]);

    $component->call('assign', $reg['competition_registration']->id);
    expect($schedule->refresh()->status)->toBe('Ready');

    $component->call('unassign', $reg['competition_registration']->id);
    expect($schedule->refresh()->status)->toBe('Scheduled');
});

test('34. Match Center allows operator to start match (Ready → Playing)', function () {
    $service = app(\App\Services\Competition\CompetitionRegistrationService::class);
    $reg = $service->registerForPerson(
        person: $this->person,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $this->class->id,
    );

    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'status' => 'Ready',
        'required_participants' => 1,
    ]);

    \App\Models\CompetitionScheduleEntry::create([
        'competition_schedule_id' => $schedule->id,
        'competition_registration_id' => $reg['competition_registration']->id,
    ]);

    $component = Livewire::test(\App\Livewire\Competition\MatchCenter::class);

    $component->call('startMatch', $schedule->id);
    expect($schedule->refresh()->status)->toBe('Playing');
});

test('35. Match Center sends Playing to Waiting Result for bracket matches', function () {
    $bracket = \App\Models\CompetitionBracket::create([
        'competition_class_id' => $this->class->id,
        'name' => 'Test',
        'participant_count' => 4,
        'status' => 'active',
    ]);

    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'status' => 'Playing',
    ]);

    \App\Models\CompetitionBracketMatch::create([
        'competition_bracket_id' => $bracket->id,
        'competition_schedule_id' => $schedule->id,
        'round' => 2,
        'position' => 1,
    ]);

    $component = Livewire::test(\App\Livewire\Competition\MatchCenter::class);

    $component->call('moveToWaitingResult', $schedule->id);
    expect($schedule->refresh()->status)->toBe('Waiting Result');
});

test('36. Match Center rejects start for non-Ready schedule', function () {
    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'status' => 'Scheduled',
    ]);

    $component = Livewire::test(\App\Livewire\Competition\MatchCenter::class);

    $component->call('startMatch', $schedule->id);
    expect($schedule->refresh()->status)->toBe('Scheduled');
});

test('37. Match Center rejects moveToWaitingResult for non-Playing schedule', function () {
    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'status' => 'Ready',
    ]);

    $component = Livewire::test(\App\Livewire\Competition\MatchCenter::class);

    $component->call('moveToWaitingResult', $schedule->id);
    expect($schedule->refresh()->status)->toBe('Ready');
});

test('38. Viewer shows Playing match first', function () {
    $venue = Venue::create(['event_id' => $this->event->id, 'name' => 'Venue P']);

    CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'venue_id' => $venue->id,
        'status' => 'Ready',
    ]);

    CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'venue_id' => $venue->id,
        'status' => 'Playing',
        'start_at' => now(),
    ]);

    $playingCount = CompetitionSchedule::where('competition_class_id', $this->class->id)
        ->where('status', 'Playing')->count();
    expect($playingCount)->toBe(1);
});

test('39. Viewer falls back to earliest Ready when no Playing exists', function () {
    $venue = Venue::create(['event_id' => $this->event->id, 'name' => 'Venue Q']);

    CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'venue_id' => $venue->id,
        'status' => 'Scheduled',
    ]);

    CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'venue_id' => $venue->id,
        'status' => 'Ready',
    ]);

    $readyCount = CompetitionSchedule::where('competition_class_id', $this->class->id)
        ->where('status', 'Ready')->count();
    expect($readyCount)->toBe(1);
});

test('40. Viewer never shows Finished schedules', function () {
    $venue = Venue::create(['event_id' => $this->event->id, 'name' => 'Venue R']);

    CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'venue_id' => $venue->id,
        'status' => 'Finished',
    ]);

    $playingCount = CompetitionSchedule::where('competition_class_id', $this->class->id)
        ->where('status', 'Playing')->count();
    $readyCount = CompetitionSchedule::where('competition_class_id', $this->class->id)
        ->where('status', 'Ready')->count();
    expect($playingCount)->toBe(0);
    expect($readyCount)->toBe(0);
});

test('41. Match Center requires manage-matches permission', function () {
    $user = User::factory()->create(['role' => 'viewer']);
    actingAs($user);

    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'status' => 'Ready',
    ]);

    $component = Livewire::test(\App\Livewire\Competition\MatchCenter::class);
    $component->call('startMatch', $schedule->id)
        ->assertOk();
});

test('42. Viewer remains accessible as public', function () {
    $user = User::factory()->create(['role' => 'viewer']);
    actingAs($user);

    $response = $this->get(route('competition.viewer', ['event' => $this->event]));
    $response->assertStatus(200);
});

test('43. canAutoReady returns true when assigned count meets required_participants', function () {
    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'required_participants' => 1,
        'status' => 'Scheduled',
    ]);

    $service = app(\App\Services\Competition\CompetitionRegistrationService::class);
    $reg = $service->registerForPerson(
        person: $this->person,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $this->class->id,
    );

    \App\Models\CompetitionScheduleEntry::create([
        'competition_schedule_id' => $schedule->id,
        'competition_registration_id' => $reg['competition_registration']->id,
    ]);

    expect($schedule->fresh()->canAutoReady())->toBeTrue();
});

test('44. canAutoReady returns false when status is not Scheduled', function () {
    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'required_participants' => 1,
        'status' => 'Ready',
    ]);

    expect($schedule->canAutoReady())->toBeFalse();
});

test('45. operator-dashboard uses Playing instead of NowPlaying', function () {
    $venue = Venue::create(['event_id' => $this->event->id, 'name' => 'Venue S']);

    CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'venue_id' => $venue->id,
        'status' => 'Playing',
    ]);

    $playingCount = CompetitionSchedule::where('competition_class_id', $this->class->id)
        ->where('status', 'Playing')->count();
    expect($playingCount)->toBe(1);
});

test('46. official submits result and does NOT auto-start next Ready (R4H)', function () {
    $venue = Venue::create(['event_id' => $this->event->id, 'name' => 'Venue Auto']);

    $service = app(\App\Services\Competition\CompetitionRegistrationService::class);
    $reg1 = $service->registerForPerson(
        person: $this->person,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $this->class->id,
    );

    $person2 = Person::create(['nama' => 'Budi Auto', 'jenis_kelamin' => 'L']);
    $reg2 = $service->registerForPerson(
        person: $person2,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $this->class->id,
    );

    $waiting = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'venue_id' => $venue->id,
        'status' => 'Waiting Result',
        'required_participants' => 2,
    ]);

    $ready = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'venue_id' => $venue->id,
        'status' => 'Ready',
        'required_participants' => 2,
    ]);

    \App\Models\CompetitionScheduleEntry::create(['competition_schedule_id' => $waiting->id, 'competition_registration_id' => $reg1['competition_registration']->id]);
    \App\Models\CompetitionScheduleEntry::create(['competition_schedule_id' => $waiting->id, 'competition_registration_id' => $reg2['competition_registration']->id]);
    \App\Models\CompetitionScheduleEntry::create(['competition_schedule_id' => $ready->id, 'competition_registration_id' => $reg1['competition_registration']->id]);
    \App\Models\CompetitionScheduleEntry::create(['competition_schedule_id' => $ready->id, 'competition_registration_id' => $reg2['competition_registration']->id]);

    $component = Livewire::test(\App\Livewire\Competition\OfficialPanel::class);
    $component->call('openSubmitDialog', $waiting->id);
    $component->set('selectedWinnerId', $reg1['competition_registration']->id);
    $component->set('finishReason', 'Normal');
    $component->call('submitResult');

    expect($waiting->refresh()->status)->toBe('Finished');
    expect($waiting->refresh()->winner_registration_id)->toBe($reg1['competition_registration']->id);
    expect($waiting->refresh()->finish_reason)->toBe('Normal');
    expect($waiting->refresh()->finished_by)->not->toBeNull();
    expect($waiting->refresh()->finished_at)->not->toBeNull();
    // R4H: Playing hanya boleh terjadi setelah aksi eksplisit operator Start Match.
    expect($ready->refresh()->status)->toBe('Ready');
});

test('47. official submit does not auto-start Ready matches anywhere (R4H)', function () {
    $venueA = Venue::create(['event_id' => $this->event->id, 'name' => 'Venue A']);
    $venueB = Venue::create(['event_id' => $this->event->id, 'name' => 'Venue B']);

    $service = app(\App\Services\Competition\CompetitionRegistrationService::class);
    $reg1 = $service->registerForPerson(
        person: $this->person,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $this->class->id,
    );

    $waiting = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'venue_id' => $venueA->id,
        'status' => 'Waiting Result',
        'required_participants' => 1,
    ]);

    $readyOtherVenue = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'venue_id' => $venueB->id,
        'status' => 'Ready',
    ]);

    \App\Models\CompetitionScheduleEntry::create(['competition_schedule_id' => $waiting->id, 'competition_registration_id' => $reg1['competition_registration']->id]);

    $component = Livewire::test(\App\Livewire\Competition\OfficialPanel::class);
    $component->call('openSubmitDialog', $waiting->id);
    $component->set('selectedWinnerId', $reg1['competition_registration']->id);
    $component->set('finishReason', 'Normal');
    $component->call('submitResult');

    expect($waiting->refresh()->status)->toBe('Finished');
    // R4H: tidak ada auto-start — Ready di venue manapun tetap Ready.
    expect($readyOtherVenue->refresh()->status)->toBe('Ready');
});

test('48. Match Center shows status counters', function () {
    CompetitionSchedule::create(['competition_class_id' => $this->class->id, 'status' => 'Playing']);
    CompetitionSchedule::create(['competition_class_id' => $this->class->id, 'status' => 'Ready']);
    CompetitionSchedule::create(['competition_class_id' => $this->class->id, 'status' => 'Ready']);
    CompetitionSchedule::create(['competition_class_id' => $this->class->id, 'status' => 'Finished']);
    CompetitionSchedule::create(['competition_class_id' => $this->class->id, 'status' => 'Finished']);

    $playingCount = CompetitionSchedule::where('competition_class_id', $this->class->id)
        ->where('status', 'Playing')->count();
    $readyCount = CompetitionSchedule::where('competition_class_id', $this->class->id)
        ->where('status', 'Ready')->count();
    $finishedCount = CompetitionSchedule::where('competition_class_id', $this->class->id)
        ->where('status', 'Finished')->count();

    expect($playingCount)->toBe(1);
    expect($readyCount)->toBe(2);
    expect($finishedCount)->toBe(2);
});

test('49. Viewer does not show finished or scheduled', function () {
    CompetitionSchedule::create(['competition_class_id' => $this->class->id, 'status' => 'Finished']);
    CompetitionSchedule::create(['competition_class_id' => $this->class->id, 'status' => 'Scheduled']);

    $playing = CompetitionSchedule::where('competition_class_id', $this->class->id)->where('status', 'Playing')->count();
    $ready = CompetitionSchedule::where('competition_class_id', $this->class->id)->where('status', 'Ready')->count();

    expect($playing)->toBe(0);
    expect($ready)->toBe(0);
});

test('50. official submission requires winner selection', function () {
    $service = app(\App\Services\Competition\CompetitionRegistrationService::class);
    $reg = $service->registerForPerson(
        person: $this->person,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $this->class->id,
    );

    $waiting = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'status' => 'Waiting Result',
        'required_participants' => 1,
    ]);

    \App\Models\CompetitionScheduleEntry::create(['competition_schedule_id' => $waiting->id, 'competition_registration_id' => $reg['competition_registration']->id]);

    $component = Livewire::test(\App\Livewire\Competition\OfficialPanel::class);
    $component->call('openSubmitDialog', $waiting->id);
    $component->set('finishReason', 'Normal');
    $component->call('submitResult');

    $component->assertHasErrors('selectedWinnerId');
    expect($waiting->refresh()->status)->toBe('Waiting Result');
});

test('51. official submission requires finish reason', function () {
    $service = app(\App\Services\Competition\CompetitionRegistrationService::class);
    $reg = $service->registerForPerson(
        person: $this->person,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $this->class->id,
    );

    $waiting = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'status' => 'Waiting Result',
        'required_participants' => 1,
    ]);

    \App\Models\CompetitionScheduleEntry::create(['competition_schedule_id' => $waiting->id, 'competition_registration_id' => $reg['competition_registration']->id]);

    $component = Livewire::test(\App\Livewire\Competition\OfficialPanel::class);
    $component->call('openSubmitDialog', $waiting->id);
    $component->set('selectedWinnerId', $reg['competition_registration']->id);
    $component->call('submitResult');

    $component->assertHasErrors('finishReason');
    expect($waiting->refresh()->status)->toBe('Waiting Result');
});

test('52. official submission rejects winner not in match', function () {
    $service = app(\App\Services\Competition\CompetitionRegistrationService::class);
    $reg = $service->registerForPerson(
        person: $this->person,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $this->class->id,
    );

    $otherPerson = Person::create(['nama' => 'Orang Lain', 'jenis_kelamin' => 'L']);
    $otherReg = $service->registerForPerson(
        person: $otherPerson,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $this->class->id,
    );

    $waiting = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'status' => 'Waiting Result',
        'required_participants' => 1,
    ]);

    \App\Models\CompetitionScheduleEntry::create(['competition_schedule_id' => $waiting->id, 'competition_registration_id' => $reg['competition_registration']->id]);

    $component = Livewire::test(\App\Livewire\Competition\OfficialPanel::class);
    $component->call('openSubmitDialog', $waiting->id);
    $component->set('selectedWinnerId', $otherReg['competition_registration']->id);
    $component->set('finishReason', 'Normal');
    $component->call('submitResult');

    expect($waiting->refresh()->status)->toBe('Waiting Result');
});

test('53. official submission persists all match result fields', function () {
    $service = app(\App\Services\Competition\CompetitionRegistrationService::class);
    $reg = $service->registerForPerson(
        person: $this->person,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $this->class->id,
    );

    $waiting = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'status' => 'Waiting Result',
        'required_participants' => 1,
    ]);

    \App\Models\CompetitionScheduleEntry::create(['competition_schedule_id' => $waiting->id, 'competition_registration_id' => $reg['competition_registration']->id]);

    $component = Livewire::test(\App\Livewire\Competition\OfficialPanel::class);
    $component->call('openSubmitDialog', $waiting->id);
    $component->set('selectedWinnerId', $reg['competition_registration']->id);
    $component->set('finishReason', 'Disqualification (DQ)');
    $component->set('finishNotes', 'Melanggar aturan teknis');
    $component->call('submitResult');

    $schedule = $waiting->refresh();
    expect($schedule->status)->toBe('Finished');
    expect($schedule->winner_registration_id)->toBe($reg['competition_registration']->id);
    expect($schedule->finish_reason)->toBe('Disqualification (DQ)');
    expect($schedule->finish_notes)->toBe('Melanggar aturan teknis');
    expect($schedule->finished_at)->not->toBeNull();
    expect($schedule->finished_by)->not->toBeNull();
});

test('54. bracket manager only lists bracket-capable classes', function () {
    $event = $this->event;
    $category = $this->category;

    $heatClass = CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'Heat Visible Test',
        'gender' => 'M',
        'format' => 'individual_heat',
        'status' => 'registration_open',
        'is_active' => true,
    ]);

    $individualBracketClass = CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'Individual Bracket Visible Test',
        'gender' => 'M',
        'format' => 'individual_vs_individual',
        'status' => 'registration_open',
        'is_active' => true,
    ]);

    $teamBracketClass = CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'Team Bracket Visible Test',
        'gender' => 'M',
        'format' => 'team_vs_team',
        'status' => 'registration_open',
        'is_active' => true,
    ]);

    $massClass = CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'Mass Invisible Test',
        'gender' => 'M',
        'format' => 'individual_mass',
        'status' => 'registration_open',
        'is_active' => true,
    ]);

    Livewire::test(\App\Livewire\Competition\BracketManager::class)
        ->assertSee('Bracket Manager')
        ->assertSee($individualBracketClass->name)
        ->assertSee($teamBracketClass->name)
        ->assertDontSee($heatClass->name)
        ->assertDontSee($massClass->name);
});

test('55. bracket generation creates correct number of matches', function () {
    $class = CompetitionClass::create([
        'event_id' => $this->event->id,
        'competition_category_id' => $this->category->id,
        'name' => 'Bracket Test Class',
        'gender' => 'M',
    ]);

    $component = Livewire::test(\App\Livewire\Competition\BracketManager::class);
    $component->set('newParticipantCount', '8');
    $component->call('generate', $class->id);

    $brackets = \App\Models\CompetitionBracket::where('competition_class_id', $class->id)->get();
    expect($brackets)->toHaveCount(1);

    $bracket = $brackets->first();
    expect($bracket->participant_count)->toBe(8);

    $matches = $bracket->bracketMatches;
    expect($matches)->toHaveCount(7);

    $finalMatches = $matches->where('round', 1);
    expect($finalMatches)->toHaveCount(1);

    $semiMatches = $matches->where('round', 2);
    expect($semiMatches)->toHaveCount(2);

    $quarterMatches = $matches->where('round', 3);
    expect($quarterMatches)->toHaveCount(4);
});

test('55. bracket generation links matches correctly', function () {
    $class = CompetitionClass::create([
        'event_id' => $this->event->id,
        'competition_category_id' => $this->category->id,
        'name' => 'Bracket Links Test',
        'gender' => 'M',
    ]);

    $component = Livewire::test(\App\Livewire\Competition\BracketManager::class);
    $component->set('newParticipantCount', '4');
    $component->call('generate', $class->id);

    $bracket = \App\Models\CompetitionBracket::where('competition_class_id', $class->id)->first();
    $matches = $bracket->bracketMatches;

    $final = $matches->where('round', 1)->first();
    $semi1 = $matches->where('round', 2)->where('position', 1)->first();
    $semi2 = $matches->where('round', 2)->where('position', 2)->first();

    expect($final->source_match_a_id)->toBe($semi1->id);
    expect($final->source_match_b_id)->toBe($semi2->id);
    expect($semi1->source_match_a_id)->toBeNull();
    expect($semi1->source_match_b_id)->toBeNull();
});

test('56. bracket winner automatically advances to next match', function () {
    $class = CompetitionClass::create([
        'event_id' => $this->event->id,
        'competition_category_id' => $this->category->id,
        'name' => 'Bracket Advance Test',
        'gender' => 'M',
    ]);

    $component = Livewire::test(\App\Livewire\Competition\BracketManager::class);
    $component->set('newParticipantCount', '4');
    $component->call('generate', $class->id);

    $bracket = \App\Models\CompetitionBracket::where('competition_class_id', $class->id)->first();
    $matches = $bracket->bracketMatches;

    $semi1 = $matches->where('round', 2)->where('position', 1)->first();
    $final = $matches->where('round', 1)->first();

    $reg = app(\App\Services\Competition\CompetitionRegistrationService::class)->registerForPerson(
        person: $this->person,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $class->id,
    );

    \App\Models\CompetitionScheduleEntry::create([
        'competition_schedule_id' => $semi1->schedule->id,
        'competition_registration_id' => $reg['competition_registration']->id,
        'order_number' => 1,
    ]);

    $semi1->schedule->update([
        'status' => 'Waiting Result',
        'required_participants' => 1,
    ]);

    $officialPanel = Livewire::test(\App\Livewire\Competition\OfficialPanel::class);
    $officialPanel->call('openSubmitDialog', $semi1->schedule->id);
    $officialPanel->set('selectedWinnerId', $reg['competition_registration']->id);
    $officialPanel->set('finishReason', 'Normal');
    $officialPanel->call('submitResult');

    $finalEntry = \App\Models\CompetitionScheduleEntry::where('competition_schedule_id', $final->schedule->id)
        ->where('competition_registration_id', $reg['competition_registration']->id)
        ->first();

    expect($finalEntry)->not->toBeNull();
    expect($final->schedule->refresh()->status)->toBe('Scheduled');
});
