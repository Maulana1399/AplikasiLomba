<?php

use App\Exports\CompetitionExport;
use App\Models\CompetitionAnnouncement;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionOutcome;
use App\Models\CompetitionSchedule;
use App\Models\Event;
use App\Models\Person;
use App\Models\User;
use App\Models\Venue;
use App\Services\Competition\CompetitionRegistrationService;
use App\Services\Competition\CompetitionReportService;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'super_admin']);
    actingAs($this->user);

    $this->event = Event::create([
        'name' => 'Report Test Event',
        'slug' => 'report-test',
        'event_type' => 'competition',
        'status' => 'active',
    ]);

    app(\App\Support\ActiveEventContext::class)->set($this->event);

    $this->category = CompetitionCategory::create([
        'event_id' => $this->event->id, 'name' => 'Category A',
    ]);

    $this->class = CompetitionClass::create([
        'event_id' => $this->event->id,
        'competition_category_id' => $this->category->id,
        'name' => 'Class A',
        'gender' => 'M',
    ]);

    $this->venue = Venue::create([
        'event_id' => $this->event->id, 'name' => 'Venue A',
    ]);

    $this->person = Person::create([
        'nama' => 'Report Person',
        'jenis_kelamin' => 'L',
        'desa_id' => null,
    ]);

    $service = app(CompetitionRegistrationService::class);
    $this->registration = $service->registerForPerson(
        person: $this->person,
        eventId: $this->event->id,
        competitionCategoryId: $this->category->id,
        competitionClassId: $this->class->id,
    );

    $this->schedule = CompetitionSchedule::create([
        'competition_class_id' => $this->class->id,
        'venue_id' => $this->venue->id,
        'status' => 'Scheduled',
    ]);

    CompetitionOutcome::create([
        'competition_registration_id' => $this->registration['competition_registration']->id,
        'position' => 1,
        'status' => 'Lolos',
        'score' => 100,
    ]);

    CompetitionAnnouncement::create([
        'event_id' => $this->event->id,
        'message' => 'Test Announce',
        'is_active' => true,
        'expires_at' => now()->addMinutes(5),
    ]);
});

test('1. summary report', function () {
    $service = app(CompetitionReportService::class);
    $summary = $service->summary($this->event);

    expect($summary['total_categories'])->toBe(1);
    expect($summary['total_classes'])->toBe(1);
    expect($summary['total_registrations'])->toBe(1);
    expect($summary['total_venues'])->toBe(1);
    expect($summary['total_schedules'])->toBe(1);
    expect($summary['scheduled'])->toBe(1);
    expect($summary['active_announcements'])->toBe(1);
});

test('2. registration report', function () {
    $service = app(CompetitionReportService::class);
    $registrations = $service->registrationReport($this->event);

    expect($registrations)->toHaveCount(1);
    expect($registrations->first()->participation->person->nama)->toBe('Report Person');
});

test('3. registration report filtered by category', function () {
    $service = app(CompetitionReportService::class);
    $result = $service->registrationReport($this->event, ['category_id' => $this->category->id]);
    expect($result)->toHaveCount(1);

    $result = $service->registrationReport($this->event, ['category_id' => 99999]);
    expect($result)->toHaveCount(0);
});

test('4. registration report search', function () {
    $service = app(CompetitionReportService::class);
    $result = $service->registrationReport($this->event, ['search' => 'Report']);
    expect($result)->toHaveCount(1);

    $result = $service->registrationReport($this->event, ['search' => 'Nonexistent']);
    expect($result)->toHaveCount(0);
});

test('5. schedule report', function () {
    $service = app(CompetitionReportService::class);
    $schedules = $service->scheduleReport($this->event);

    expect($schedules)->toHaveCount(1);
    expect($schedules->first()->status)->toBe('Scheduled');
});

test('6. schedule report filtered by status', function () {
    $service = app(CompetitionReportService::class);
    $result = $service->scheduleReport($this->event, ['status' => 'Scheduled']);
    expect($result)->toHaveCount(1);

    $result = $service->scheduleReport($this->event, ['status' => 'Playing']);
    expect($result)->toHaveCount(0);
});

test('7. outcome report', function () {
    $service = app(CompetitionReportService::class);
    $outcomes = $service->outcomeReport($this->event);

    expect($outcomes)->toHaveCount(1);
    expect($outcomes->first()->position)->toBe(1);
});

test('8. outcome report filtered by category', function () {
    $service = app(CompetitionReportService::class);
    $result = $service->outcomeReport($this->event, ['category_id' => $this->category->id]);
    expect($result)->toHaveCount(1);

    $result = $service->outcomeReport($this->event, ['category_id' => 99999]);
    expect($result)->toHaveCount(0);
});

test('9. venue statistics', function () {
    $service = app(CompetitionReportService::class);
    $stats = $service->venueStatistics($this->event);

    expect($stats)->toHaveCount(1);
    expect($stats->first()->total_schedules)->toBe(1);
});

test('10. category statistics', function () {
    $service = app(CompetitionReportService::class);
    $stats = $service->categoryStatistics($this->event);

    expect($stats)->toHaveCount(1);
    expect($stats->first()->total_classes)->toBe(1);
});

test('11. class statistics', function () {
    $service = app(CompetitionReportService::class);
    $stats = $service->classStatistics($this->event);

    expect($stats)->toHaveCount(1);
    expect($stats->first()->total_registrations)->toBe(1);
});

test('12. export registration CSV', function () {
    $export = app(CompetitionExport::class);
    $csv = $export->registrationCsv($this->event);

    expect($csv)->toContain('Report Person');
    expect($csv)->toContain('No. Peserta');
});

test('13. export outcome CSV', function () {
    $export = app(CompetitionExport::class);
    $csv = $export->outcomeCsv($this->event);

    expect($csv)->toContain('Lolos');
    expect($csv)->toContain('Peserta');
});

test('14. export schedule CSV', function () {
    $export = app(CompetitionExport::class);
    $csv = $export->scheduleCsv($this->event);

    expect($csv)->toContain('Scheduled');
    expect($csv)->toContain('Venue A');
});
