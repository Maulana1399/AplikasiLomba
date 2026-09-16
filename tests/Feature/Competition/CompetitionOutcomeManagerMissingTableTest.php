<?php

use App\Enums\Role;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionOutcome;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionSchedule;
use App\Models\CompetitionScheduleEntry;
use App\Models\Event;
use App\Models\Person;
use App\Models\User;
use App\Services\Competition\CompetitionRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Regression: halaman OutcomeManager untuk Individual Mass harus tetap terbuka
 * walau tabel R2/R4 (competition_heat_results / competition_team_outcomes)
 * belum ada di database (produksi yang belum menjalankan migration additive).
 *
 * Root cause produksi: `Table 'competition_heat_results' doesn't exist` (42S02)
 * karena OutcomeManager eager-load `competitionRegistration.heatResults` untuk
 * SEMUA format, termasuk Individual Mass.
 */
function cmt_event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'CMT Event '.str()->random(6),
        'slug' => 'cmt-'.str()->random(6),
        'status' => 'active',
        'event_type' => 'competition',
    ], $overrides));
}

function cmt_category(Event $event): CompetitionCategory
{
    $category = CompetitionCategory::create(['event_id' => $event->id, 'name' => 'CMT Cat '.str()->random(4)]);

    $category->events()->attach($event);

    return $category;
}

function cmt_class(Event $event, CompetitionCategory $category): CompetitionClass
{
    return CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'CMT Mass '.str()->random(4),
        'gender' => 'M',
        'format' => 'individual_mass',
        'status' => 'registration_open',
        'is_active' => true,
    ]);
}

function cmt_register(Person $person, Event $event, CompetitionCategory $category, CompetitionClass $class): CompetitionRegistration
{
    return app(CompetitionRegistrationService::class)->registerForPerson(
        person: $person,
        eventId: $event->id,
        competitionCategoryId: $category->id,
        competitionClassId: $class->id,
    )['competition_registration'];
}

test('Individual Mass outcome page renders without heat/team result tables', function () {
    // Simulasi produksi yang belum menjalankan migration additive R2/R4.
    Schema::dropIfExists('competition_heat_results');
    Schema::dropIfExists('competition_team_outcomes');

    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cmt_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cmt_category($event);
    $class = cmt_class($event, $category);
    $schedule = CompetitionSchedule::create([
        'competition_class_id' => $class->id,
        'status' => 'Scheduled',
        'required_participants' => 3,
    ]);

    $registrations = collect(['UAT 01', 'UAT 02', 'UAT 03'])->map(function ($nama) use ($event, $category, $class, $schedule) {
        $person = Person::create(['nama' => $nama, 'jenis_kelamin' => 'L']);
        $reg = cmt_register($person, $event, $category, $class);
        CompetitionScheduleEntry::create([
            'competition_schedule_id' => $schedule->id,
            'competition_registration_id' => $reg->id,
            'order_number' => $reg->id,
        ]);

        return $reg;
    });

    // Satu peserta sudah punya hasil (posisi) → podium tampil.
    CompetitionOutcome::create(['competition_registration_id' => $registrations[0]->id, 'score' => 30, 'position' => 1]);

    \Livewire::test(\App\Livewire\Competition\Schedule\OutcomeManager::class, ['schedule' => $schedule])
        ->assertSet('isHeat', false)
        ->assertSet('isTeam', false)
        ->assertHasNoErrors()
        ->assertSee('UAT 01')
        ->assertSee('UAT 02')
        ->assertSee('UAT 03')
        ->assertSee('Rank Otomatis')
        ->assertSee('Simpan Outcome')
        ->assertSee('Juara 1');
});
