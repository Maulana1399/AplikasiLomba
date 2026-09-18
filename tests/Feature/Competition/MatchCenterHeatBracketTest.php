<?php

use App\Models\CompetitionBracket;
use App\Models\CompetitionBracketMatch;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionMatchOfficial;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionSchedule;
use App\Models\CompetitionScheduleEntry;
use App\Models\CompetitionTeam;
use App\Models\Event;
use App\Models\kelompok;
use App\Models\Person;
use App\Models\User;
use App\Services\Competition\CompetitionRegistrationService;
use App\Support\CompetitionFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Match Center — pemisahan workflow HEAT vs BRACKET.
//   - Heat:   tampilkan "Input Hasil" (badge HEAT).
//   - Bracket: jangan tampilkan "Input Hasil"; tampilkan "Buka Official Panel"
//              (badge BRACKET).
// Source of truth: $schedule->bracketMatch()->exists() + CompetitionFormat +
//   CompetitionWorkflowService::requiresOfficial().
// ---------------------------------------------------------------------------

function mhb_event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'MHB Event '.str()->random(6),
        'slug' => 'mhb-'.str()->random(6),
        'status' => 'active',
        'event_type' => 'competition',
    ], $overrides));
}

function mhb_category(Event $event): CompetitionCategory
{
    $category = CompetitionCategory::create(['event_id' => $event->id, 'name' => 'MHB Cat '.str()->random(4)]);


    return $category;
}

function mhb_class(Event $event, CompetitionCategory $category, string $format): CompetitionClass
{
    $resultType = match ($format) {
        CompetitionFormat::TEAM_VS_TEAM => 'win_loss',
        CompetitionFormat::INDIVIDUAL_VS_INDIVIDUAL => 'score',
        default => 'time',
    };

    return CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'MHB Class '.str()->random(4),
        'gender' => 'M',
        'format' => $format,
        'status' => 'registration_open',
        'result_type' => $resultType,
        'is_active' => true,
    ]);
}

function mhb_register(Person $person, Event $event, CompetitionCategory $category, CompetitionClass $class): CompetitionRegistration
{
    return app(CompetitionRegistrationService::class)->registerForPerson(
        person: $person,
        eventId: $event->id,
        competitionCategoryId: $category->id,
        competitionClassId: $class->id,
    )['competition_registration'];
}

function mhb_entry(CompetitionSchedule $schedule, CompetitionRegistration $registration): void
{
    CompetitionScheduleEntry::create([
        'competition_schedule_id' => $schedule->id,
        'competition_registration_id' => $registration->id,
    ]);
}

function mhb_schedule(CompetitionClass $class, string $status, int $required = 1): CompetitionSchedule
{
    return CompetitionSchedule::create([
        'competition_class_id' => $class->id,
        'status' => $status,
        'required_participants' => $required,
    ]);
}

function mhb_bracket(CompetitionClass $class): CompetitionBracket
{
    return CompetitionBracket::create([
        'competition_class_id' => $class->id,
        'name' => $class->name.' Bracket',
        'participant_count' => 4,
        'status' => 'active',
    ]);
}

function mhb_bracket_match(CompetitionBracket $bracket, CompetitionSchedule $schedule, int $round = 2): CompetitionBracketMatch
{
    return CompetitionBracketMatch::create([
        'competition_bracket_id' => $bracket->id,
        'competition_schedule_id' => $schedule->id,
        'round' => $round,
        'position' => 1,
    ]);
}

function mhb_team(Event $event, CompetitionClass $class, string $name): CompetitionTeam
{
    return CompetitionTeam::create([
        'event_id' => $event->id,
        'competition_class_id' => $class->id,
        'name' => $name,
        'kelompok_id' => kelompok::create(['kelompok_asal' => 'K '.str()->random(5)])->id,
        'is_active' => true,
    ]);
}

// ---------------------------------------------------------------------------

test('Heat match menampilkan badge HEAT dan tombol Input Hasil', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $event = mhb_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = mhb_category($event);
    $class = mhb_class($event, $category, CompetitionFormat::INDIVIDUAL_HEAT);

    $schedule = mhb_schedule($class, 'Playing', 1);
    $reg = mhb_register(Person::create(['nama' => 'Heat Ath 01', 'jenis_kelamin' => 'L']), $event, $category, $class);
    mhb_entry($schedule, $reg);

    $component = \Livewire::test(\App\Livewire\Competition\MatchCenter::class);

    $component->assertSee('HEAT')
        ->assertSee('Input Hasil')
        ->assertDontSee('Buka Official Panel');
});

test('Individual bracket match menampilkan badge BRACKET dan Buka Official Panel, bukan Input Hasil', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $event = mhb_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = mhb_category($event);
    $class = mhb_class($event, $category, CompetitionFormat::INDIVIDUAL_VS_INDIVIDUAL);

    $bracket = mhb_bracket($class);
    $schedule = mhb_schedule($class, 'Waiting Result', 2);
    mhb_bracket_match($bracket, $schedule);

    $a = mhb_register(Person::create(['nama' => 'UAT Peserta 043', 'jenis_kelamin' => 'L']), $event, $category, $class);
    $b = mhb_register(Person::create(['nama' => 'UAT Peserta 044', 'jenis_kelamin' => 'L']), $event, $category, $class);
    mhb_entry($schedule, $a);
    mhb_entry($schedule, $b);

    $component = \Livewire::test(\App\Livewire\Competition\MatchCenter::class);

    $component->assertSee('BRACKET')
        ->assertSee('Buka Official Panel')
        ->assertDontSee('Input Hasil');
});

test('Team/Futsal bracket match menampilkan badge BRACKET dan Buka Official Panel, bukan Input Hasil', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $event = mhb_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = mhb_category($event);
    $class = mhb_class($event, $category, CompetitionFormat::TEAM_VS_TEAM);

    $bracket = mhb_bracket($class);
    $schedule = mhb_schedule($class, 'Waiting Result', 2);
    mhb_bracket_match($bracket, $schedule);

    $teamA = mhb_team($event, $class, 'UAT Futsal Team 01');
    $teamB = mhb_team($event, $class, 'UAT Futsal Team 02');
    CompetitionScheduleEntry::create(['competition_schedule_id' => $schedule->id, 'competition_team_id' => $teamA->id, 'order_number' => 1]);
    CompetitionScheduleEntry::create(['competition_schedule_id' => $schedule->id, 'competition_team_id' => $teamB->id, 'order_number' => 2]);

    $component = \Livewire::test(\App\Livewire\Competition\MatchCenter::class);

    $component->assertSee('BRACKET')
        ->assertSee('Buka Official Panel')
        ->assertDontSee('Input Hasil');
});

test('Buka Official Panel deep-link membuka match yang di-assign dan menampilkan dialog submit', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $event = mhb_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = mhb_category($event);
    $class = mhb_class($event, $category, CompetitionFormat::TEAM_VS_TEAM);

    $bracket = mhb_bracket($class);
    $schedule = mhb_schedule($class, 'Waiting Result', 2);
    mhb_bracket_match($bracket, $schedule);

    $teamA = mhb_team($event, $class, 'UAT Futsal Team 01');
    $teamB = mhb_team($event, $class, 'UAT Futsal Team 02');
    CompetitionScheduleEntry::create(['competition_schedule_id' => $schedule->id, 'competition_team_id' => $teamA->id, 'order_number' => 1]);
    CompetitionScheduleEntry::create(['competition_schedule_id' => $schedule->id, 'competition_team_id' => $teamB->id, 'order_number' => 2]);

    // Assign official (super admin) ke match ini.
    CompetitionMatchOfficial::create([
        'competition_schedule_id' => $schedule->id,
        'user_id' => $admin->id,
        'role' => 'referee',
    ]);

    $response = $this->get(route('competition.official-panel', ['schedule' => $schedule->id]));

    $response->assertOk()
        ->assertSee('Kirim Hasil Pertandingan')
        ->assertSee('UAT Futsal Team 01')
        ->assertSee('UAT Futsal Team 02');
});

test('Buka Official Panel deep-link tidak membuka match yang bukan milik user', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $event = mhb_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = mhb_category($event);
    $class = mhb_class($event, $category, CompetitionFormat::TEAM_VS_TEAM);

    $bracket = mhb_bracket($class);
    $schedule = mhb_schedule($class, 'Waiting Result', 2);
    mhb_bracket_match($bracket, $schedule);

    $teamA = mhb_team($event, $class, 'UAT Futsal Team 01');
    CompetitionScheduleEntry::create(['competition_schedule_id' => $schedule->id, 'competition_team_id' => $teamA->id, 'order_number' => 1]);

    // AplikasiLomba: no-auth LAN app — all matches shown, dialog auto-opens for Waiting Result
    $response = $this->get(route('competition.official-panel', ['schedule' => $schedule->id]));

    $response->assertOk()
        ->assertSee('Kirim Hasil Pertandingan');
});

// ---------------------------------------------------------------------------
// Filter/tab: Semua / Heat / Bracket
// ---------------------------------------------------------------------------

test('Filter Heat hanya menampilkan pertandingan Heat', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $event = mhb_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = mhb_category($event);
    $heatClass = mhb_class($event, $category, CompetitionFormat::INDIVIDUAL_HEAT);
    $bracketClass = mhb_class($event, $category, CompetitionFormat::INDIVIDUAL_VS_INDIVIDUAL);

    $heatSchedule = mhb_schedule($heatClass, 'Playing', 1);
    $heatReg = mhb_register(Person::create(['nama' => 'Heat Only', 'jenis_kelamin' => 'L']), $event, $category, $heatClass);
    mhb_entry($heatSchedule, $heatReg);

    $bracket = mhb_bracket($bracketClass);
    $bracketSchedule = mhb_schedule($bracketClass, 'Waiting Result', 2);
    mhb_bracket_match($bracket, $bracketSchedule);
    $bRegA = mhb_register(Person::create(['nama' => 'Bracket Only A', 'jenis_kelamin' => 'L']), $event, $category, $bracketClass);
    $bRegB = mhb_register(Person::create(['nama' => 'Bracket Only B', 'jenis_kelamin' => 'L']), $event, $category, $bracketClass);
    mhb_entry($bracketSchedule, $bRegA);
    mhb_entry($bracketSchedule, $bRegB);

    $component = \Livewire::test(\App\Livewire\Competition\MatchCenter::class)
        ->call('setFilterType', 'heat');

    $component->assertSee('Heat Only')
        ->assertDontSee('Bracket Only A')
        ->assertDontSee('Bracket Only B');
});

test('Filter Bracket hanya menampilkan pertandingan Bracket', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $event = mhb_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = mhb_category($event);
    $heatClass = mhb_class($event, $category, CompetitionFormat::INDIVIDUAL_HEAT);
    $bracketClass = mhb_class($event, $category, CompetitionFormat::INDIVIDUAL_VS_INDIVIDUAL);

    $heatSchedule = mhb_schedule($heatClass, 'Playing', 1);
    $heatReg = mhb_register(Person::create(['nama' => 'Heat Only', 'jenis_kelamin' => 'L']), $event, $category, $heatClass);
    mhb_entry($heatSchedule, $heatReg);

    $bracket = mhb_bracket($bracketClass);
    $bracketSchedule = mhb_schedule($bracketClass, 'Waiting Result', 2);
    mhb_bracket_match($bracket, $bracketSchedule);
    $bRegA = mhb_register(Person::create(['nama' => 'Bracket Only A', 'jenis_kelamin' => 'L']), $event, $category, $bracketClass);
    $bRegB = mhb_register(Person::create(['nama' => 'Bracket Only B', 'jenis_kelamin' => 'L']), $event, $category, $bracketClass);
    mhb_entry($bracketSchedule, $bRegA);
    mhb_entry($bracketSchedule, $bRegB);

    $component = \Livewire::test(\App\Livewire\Competition\MatchCenter::class)
        ->call('setFilterType', 'bracket');

    $component->assertSee('Bracket Only A')
        ->assertSee('Bracket Only B')
        ->assertDontSee('Heat Only');
});

test('Filter Semua menampilkan Heat dan Bracket', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $event = mhb_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = mhb_category($event);
    $heatClass = mhb_class($event, $category, CompetitionFormat::INDIVIDUAL_HEAT);
    $bracketClass = mhb_class($event, $category, CompetitionFormat::INDIVIDUAL_VS_INDIVIDUAL);

    $heatSchedule = mhb_schedule($heatClass, 'Playing', 1);
    $heatReg = mhb_register(Person::create(['nama' => 'Heat Only', 'jenis_kelamin' => 'L']), $event, $category, $heatClass);
    mhb_entry($heatSchedule, $heatReg);

    $bracket = mhb_bracket($bracketClass);
    $bracketSchedule = mhb_schedule($bracketClass, 'Waiting Result', 2);
    mhb_bracket_match($bracket, $bracketSchedule);
    $bRegA = mhb_register(Person::create(['nama' => 'Bracket Only A', 'jenis_kelamin' => 'L']), $event, $category, $bracketClass);
    mhb_entry($bracketSchedule, $bRegA);
    $bRegB = mhb_register(Person::create(['nama' => 'Bracket Only B', 'jenis_kelamin' => 'L']), $event, $category, $bracketClass);
    mhb_entry($bracketSchedule, $bRegB);

    $component = \Livewire::test(\App\Livewire\Competition\MatchCenter::class);

    $component->assertSee('Heat Only')
        ->assertSee('Bracket Only A')
        ->assertSee('Bracket Only B');
});
