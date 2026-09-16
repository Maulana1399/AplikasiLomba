<?php

use App\Enums\Role;
use App\Livewire\Competition\Schedule\OutcomeManager;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionOutcome;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionSchedule;
use App\Models\CompetitionScheduleEntry;
use App\Models\CompetitionTeam;
use App\Models\CompetitionTeamOutcome;
use App\Models\Event;
use App\Models\kelompok;
use App\Models\Person;
use App\Models\User;
use App\Services\Competition\CompetitionRegistrationService;
use App\Support\ActiveEventContext;
use App\Support\CompetitionFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Regresi 404 "Input Hasil" ketika schedule berasal dari event yang BERBEDA
 * dari active event session (Heat Manager menampilkan kelas lintas event).
 *
 * Root cause: OutcomeManager memakai `ActiveEventContext::requireCurrent()` untuk
 * memanggil service result yang event-scoped (`CompetitionClass::where('event_id',
 * ...)->findOrFail(...)`), sehingga schedule milik event lain → ModelNotFound → 404.
 */
function oes_event(): Event
{
    return Event::create([
        'name' => 'OES Event '.str()->random(6),
        'slug' => 'oes-'.str()->random(6),
        'status' => 'active',
        'event_type' => 'competition',
    ]);
}

function oes_category(Event $event): CompetitionCategory
{
    $category = CompetitionCategory::create(['event_id' => $event->id, 'name' => 'OES Cat '.str()->random(4)]);
    $category->events()->syncWithoutDetaching([$event->id]);

    return $category;
}

function oes_class(Event $event, CompetitionCategory $category, string $format): CompetitionClass
{
    return CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'OES Class '.str()->random(4),
        'gender' => 'M',
        'format' => $format,
        'is_active' => true,
    ]);
}

function oes_register(Person $person, Event $event, CompetitionCategory $category, CompetitionClass $class): CompetitionRegistration
{
    return app(CompetitionRegistrationService::class)->registerForPerson(
        person: $person,
        eventId: $event->id,
        competitionCategoryId: $category->id,
        competitionClassId: $class->id,
    )['competition_registration'];
}

test('mass outcomes page opens when schedule belongs to a different event than active event', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $this->actingAs($admin);

    $eventA = oes_event();
    $eventB = oes_event();

    // Active event = A, schedule milik B.
    app(ActiveEventContext::class)->set($eventA);

    $categoryB = oes_category($eventB);
    $classB = oes_class($eventB, $categoryB, CompetitionFormat::INDIVIDUAL_MASS);

    $reg = oes_register(Person::create(['nama' => 'Peserta B Mass', 'jenis_kelamin' => 'L']), $eventB, $categoryB, $classB);

    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $classB->id,
        'status' => 'Scheduled',
        'required_participants' => 1,
        'sort_order' => 1,
    ]);

    CompetitionScheduleEntry::create([
        'competition_schedule_id' => $schedule->id,
        'competition_registration_id' => $reg->id,
        'order_number' => 1,
    ]);

    CompetitionOutcome::create(['competition_registration_id' => $reg->id, 'score' => 90, 'position' => 1]);

    // Route harus 200, bukan 404.
    $this->get(route('competition.schedule.outcomes', ['schedule' => $schedule->id]))
        ->assertOk();

    // Komponen memakai event milik kelas schedule (B), bukan active event (A).
    Livewire::test(OutcomeManager::class, ['schedule' => $schedule])
        ->assertSet('eventId', $eventB->id)
        ->assertSee('Peserta B Mass')
        ->assertSee('Juara 1');
});

test('heat outcomes page opens when schedule belongs to a different event than active event', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $this->actingAs($admin);

    $eventA = oes_event();
    $eventB = oes_event();

    app(ActiveEventContext::class)->set($eventA);

    $categoryB = oes_category($eventB);
    $classB = oes_class($eventB, $categoryB, CompetitionFormat::INDIVIDUAL_HEAT);

    $reg = oes_register(Person::create(['nama' => 'Peserta B Heat', 'jenis_kelamin' => 'L']), $eventB, $categoryB, $classB);

    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $classB->id,
        'status' => 'Scheduled',
        'required_participants' => 1,
        'sort_order' => 101,
    ]);

    CompetitionScheduleEntry::create([
        'competition_schedule_id' => $schedule->id,
        'competition_registration_id' => $reg->id,
        'order_number' => 1,
    ]);

    CompetitionOutcome::create(['competition_registration_id' => $reg->id, 'score' => 80, 'position' => 1]);

    $this->get(route('competition.schedule.outcomes', ['schedule' => $schedule->id]))
        ->assertOk();

    Livewire::test(OutcomeManager::class, ['schedule' => $schedule])
        ->assertSet('eventId', $eventB->id)
        ->assertSet('isHeat', true)
        ->assertDontSee('NOT FOUND');
});

test('team outcomes page opens when schedule belongs to a different event than active event', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $this->actingAs($admin);

    $eventA = oes_event();
    $eventB = oes_event();

    app(ActiveEventContext::class)->set($eventA);

    $categoryB = oes_category($eventB);
    $classB = oes_class($eventB, $categoryB, CompetitionFormat::TEAM_VS_TEAM);

    $team = CompetitionTeam::create([
        'event_id' => $eventB->id,
        'competition_class_id' => $classB->id,
        'name' => 'Tim B',
        'kelompok_id' => kelompok::create(['kelompok_asal' => 'OES Kel'])->id,
        'is_active' => true,
    ]);

    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $classB->id,
        'status' => 'Scheduled',
        'required_participants' => 2,
        'sort_order' => 1,
    ]);

    CompetitionScheduleEntry::create([
        'competition_schedule_id' => $schedule->id,
        'competition_team_id' => $team->id,
        'order_number' => 1,
    ]);

    CompetitionTeamOutcome::create(['competition_team_id' => $team->id, 'score' => 70, 'position' => 1]);

    $this->get(route('competition.schedule.outcomes', ['schedule' => $schedule->id]))
        ->assertOk();

    Livewire::test(OutcomeManager::class, ['schedule' => $schedule])
        ->assertSet('eventId', $eventB->id)
        ->assertSet('isTeam', true)
        ->assertSee('Tim B');
});
