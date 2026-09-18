<?php

use App\Enums\Role;
use App\Models\CompetitionBracket;
use App\Models\CompetitionBracketMatch;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionScheduleEntry;
use App\Models\CompetitionTeam;
use App\Models\Event;
use App\Models\kelompok;
use App\Models\Person;
use App\Models\User;
use App\Services\Competition\CompetitionBracketSeederService;
use App\Services\Competition\CompetitionRegistrationService;
use App\Services\Competition\CompetitionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function cas_event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'CAS Event '.str()->random(6),
        'slug' => 'cas-'.str()->random(6),
        'status' => 'active',
        'event_type' => 'competition',
    ], $overrides));
}

function cas_category(Event $event): CompetitionCategory
{
    $category = CompetitionCategory::create(['event_id' => $event->id, 'name' => 'CAS Cat '.str()->random(4)]);


    return $category;
}

function cas_class(Event $event, CompetitionCategory $category, string $format = 'individual_vs_individual'): CompetitionClass
{
    return CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'CAS Class '.str()->random(4),
        'gender' => 'M',
        'format' => $format,
        'status' => 'registration_open',
        'is_active' => true,
    ]);
}

function cas_register(string $nama, Event $event, CompetitionCategory $category, CompetitionClass $class): CompetitionRegistration
{
    $person = Person::create(['nama' => $nama, 'jenis_kelamin' => 'L']);

    return app(CompetitionRegistrationService::class)->registerForPerson(
        person: $person,
        eventId: $event->id,
        competitionCategoryId: $category->id,
        competitionClassId: $class->id,
    )['competition_registration'];
}

function cas_kelompok(string $name): kelompok
{
    return kelompok::create(['kelompok_asal' => $name]);
}

function cas_team(string $name, Event $event, CompetitionClass $class): CompetitionTeam
{
    return CompetitionTeam::create([
        'event_id' => $event->id,
        'competition_class_id' => $class->id,
        'name' => $name,
        'kelompok_id' => cas_kelompok('KM '.$name)->id,
        'is_active' => true,
    ]);
}

function cas_generateBracket(CompetitionClass $class, int $count): CompetitionBracket
{
    \Livewire::test(\App\Livewire\Competition\BracketManager::class)
        ->set('newParticipantCount', (string) $count)
        ->call('generate', $class->id);

    return CompetitionBracket::where('competition_class_id', $class->id)->first();
}

function cas_initialMatches(CompetitionBracket $bracket)
{
    $totalRounds = (int) log($bracket->participant_count, 2);

    return CompetitionBracketMatch::where('competition_bracket_id', $bracket->id)
        ->where('round', $totalRounds)
        ->orderBy('position')
        ->get();
}

function cas_entryIds(CompetitionBracketMatch $match, string $column): array
{
    return CompetitionScheduleEntry::where('competition_schedule_id', $match->schedule->id)
        ->pluck($column)
        ->sort()
        ->values()
        ->all();
}

// ---------------------------------------------------------------------------
// A. Individual auto-seed
// ---------------------------------------------------------------------------

test('individual bracket auto-seeds registrations deterministically', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cas_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cas_category($event);
    $class = cas_class($event, $category, 'individual_vs_individual');
    $a = cas_register('Ath A', $event, $category, $class);
    $b = cas_register('Ath B', $event, $category, $class);
    $c = cas_register('Ath C', $event, $category, $class);
    $d = cas_register('Ath D', $event, $category, $class);

    $bracket = cas_generateBracket($class, 4);
    [$m1, $m2] = cas_initialMatches($bracket);

    expect(cas_entryIds($m1, 'competition_registration_id'))->toBe([$a->id, $b->id])
        ->and(cas_entryIds($m2, 'competition_registration_id'))->toBe([$c->id, $d->id]);
});

// ---------------------------------------------------------------------------
// B. Team auto-seed
// ---------------------------------------------------------------------------

test('team bracket auto-seeds teams deterministically', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cas_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cas_category($event);
    $class = cas_class($event, $category, 'team_vs_team');
    $t1 = cas_team('Team A', $event, $class);
    $t2 = cas_team('Team B', $event, $class);
    $t3 = cas_team('Team C', $event, $class);
    $t4 = cas_team('Team D', $event, $class);

    $bracket = cas_generateBracket($class, 4);
    [$m1, $m2] = cas_initialMatches($bracket);

    expect(cas_entryIds($m1, 'competition_team_id'))->toBe([$t1->id, $t2->id])
        ->and(cas_entryIds($m2, 'competition_team_id'))->toBe([$t3->id, $t4->id]);
});

// ---------------------------------------------------------------------------
// C. Idempotency
// ---------------------------------------------------------------------------

test('auto-seed is idempotent (no duplicate entries on retry)', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cas_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cas_category($event);
    $class = cas_class($event, $category, 'individual_vs_individual');
    cas_register('Ath A', $event, $category, $class);
    cas_register('Ath B', $event, $category, $class);
    cas_register('Ath C', $event, $category, $class);
    cas_register('Ath D', $event, $category, $class);

    $bracket = cas_generateBracket($class, 4);
    $initial = cas_initialMatches($bracket);
    $before = CompetitionScheduleEntry::whereIn('competition_schedule_id', $initial->pluck('competition_schedule_id'))->count();

    app(CompetitionBracketSeederService::class)->seedInitialRound($event->id, $bracket->id);
    $after = CompetitionScheduleEntry::whereIn('competition_schedule_id', $initial->pluck('competition_schedule_id'))->count();

    expect($after)->toBe($before)
        ->and($before)->toBe(4);
});

// ---------------------------------------------------------------------------
// D + E. Event / class isolation
// ---------------------------------------------------------------------------

test('auto-seed ignores competitors from another event', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $eventA = cas_event();
    $eventB = cas_event();
    app(\App\Support\ActiveEventContext::class)->set($eventA);
    $this->actingAs($admin);

    $catA = cas_category($eventA);
    $catB = cas_category($eventB);
    $classA = cas_class($eventA, $catA, 'individual_vs_individual');
    $classB = cas_class($eventB, $catB, 'individual_vs_individual');
    cas_register('A1', $eventA, $catA, $classA);
    cas_register('A2', $eventA, $catA, $classA);
    cas_register('B1', $eventB, $catB, $classB);
    cas_register('B2', $eventB, $catB, $classB);

    $bracket = cas_generateBracket($classA, 4);
    [$m1, $m2] = cas_initialMatches($bracket);

    $ids = array_merge(cas_entryIds($m1, 'competition_registration_id'), cas_entryIds($m2, 'competition_registration_id'));
    expect($ids)->toHaveCount(2)
        ->and($ids)->not->toContain(CompetitionRegistration::where('competition_class_id', $classB->id)->first()->id);
});

test('auto-seed ignores competitors from another class in same event', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cas_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cas_category($event);
    $classA = cas_class($event, $category, 'individual_vs_individual');
    $classB = cas_class($event, $category, 'individual_vs_individual');
    cas_register('A1', $event, $category, $classA);
    cas_register('A2', $event, $category, $classA);
    cas_register('B1', $event, $category, $classB);
    cas_register('B2', $event, $category, $classB);

    $bracket = cas_generateBracket($classA, 4);
    [$m1, $m2] = cas_initialMatches($bracket);

    $ids = array_merge(cas_entryIds($m1, 'competition_registration_id'), cas_entryIds($m2, 'competition_registration_id'));
    expect($ids)->toHaveCount(2)
        ->and($ids)->not->toContain(CompetitionRegistration::where('competition_class_id', $classB->id)->first()->id);
});

// ---------------------------------------------------------------------------
// F. Team display (bukan TBD)
// ---------------------------------------------------------------------------

test('bracket display shows seeded team names, not TBD', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cas_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cas_category($event);
    $class = cas_class($event, $category, 'team_vs_team');
    cas_team('Team A', $event, $class);
    cas_team('Team B', $event, $class);
    $bracket = cas_generateBracket($class, 4);

    \Livewire::test(\App\Livewire\Competition\BracketManager::class)
        ->set('selectedBracketId', $bracket->id)
        ->assertSee('Team A')
        ->assertSee('Team B');
});

// ---------------------------------------------------------------------------
// I. Winner advancement after auto-seed
// ---------------------------------------------------------------------------

test('winner advancement works after auto-seed (individual)', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cas_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cas_category($event);
    $class = cas_class($event, $category, 'individual_vs_individual');
    $a = cas_register('Ath A', $event, $category, $class);
    $b = cas_register('Ath B', $event, $category, $class);
    $c = cas_register('Ath C', $event, $category, $class);
    $d = cas_register('Ath D', $event, $category, $class);

    $bracket = cas_generateBracket($class, 4);
    [$m1, $m2] = cas_initialMatches($bracket);
    $final = CompetitionBracketMatch::where('competition_bracket_id', $bracket->id)->where('round', 1)->first();

    $workflow = app(CompetitionWorkflowService::class);
    foreach ([$m1, $m2] as $i => $match) {
        $winner = $i === 0 ? $a->id : $c->id;
        $workflow->prepareMatch($match->schedule);
        $workflow->startMatch($match->schedule);
        $workflow->moveToWaitingResult($match->schedule);
        $workflow->submitResult($match->schedule->fresh(), $winner, 'Normal', null);
    }

    $finalIds = CompetitionScheduleEntry::where('competition_schedule_id', $final->schedule->id)
        ->pluck('competition_registration_id')->sort()->values()->all();
    expect($finalIds)->toBe([$a->id, $c->id]);
});

// ---------------------------------------------------------------------------
// J. Existing manual bracket tidak di-overwrite
// ---------------------------------------------------------------------------

test('auto-seed does not overwrite a bracket that already has entries', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cas_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cas_category($event);
    $class = cas_class($event, $category, 'individual_vs_individual');

    // Bracket dibuat SAAT BELUM ada registrasi → auto-seed no-op (0 entry).
    $bracket = cas_generateBracket($class, 4);
    [$m1, $m2] = cas_initialMatches($bracket);
    expect(CompetitionScheduleEntry::whereIn('competition_schedule_id', [$m1->schedule->id, $m2->schedule->id])->count())->toBe(0);

    // Registrasi dibuat SETELAH generate, lalu M1 di-seed manual (A + B).
    $a = cas_register('Ath A', $event, $category, $class);
    $b = cas_register('Ath B', $event, $category, $class);
    $c = cas_register('Ath C', $event, $category, $class);
    $d = cas_register('Ath D', $event, $category, $class);
    CompetitionScheduleEntry::create(['competition_schedule_id' => $m1->schedule->id, 'competition_registration_id' => $a->id, 'order_number' => 1]);
    CompetitionScheduleEntry::create(['competition_schedule_id' => $m1->schedule->id, 'competition_registration_id' => $b->id, 'order_number' => 2]);

    // Re-seed: M1 (sudah punya entry) TIDAK di-overwrite/ditambah; M2 (kosong) diisi C+D.
    app(CompetitionBracketSeederService::class)->seedInitialRound($event->id, $bracket->id);

    expect(cas_entryIds($m1, 'competition_registration_id'))->toBe([$a->id, $b->id])
        ->and(cas_entryIds($m2, 'competition_registration_id'))->toBe([$c->id, $d->id]);
});

// ---------------------------------------------------------------------------
// R4G — bracket auto-ready → Match Center
// ---------------------------------------------------------------------------

test('K. individual auto-seed promotes full initial matches to Ready (final stays Scheduled)', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cas_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cas_category($event);
    $class = cas_class($event, $category, 'individual_vs_individual');
    cas_register('Ath A', $event, $category, $class);
    cas_register('Ath B', $event, $category, $class);
    cas_register('Ath C', $event, $category, $class);
    cas_register('Ath D', $event, $category, $class);

    $bracket = cas_generateBracket($class, 4);
    [$m1, $m2] = cas_initialMatches($bracket);
    $final = CompetitionBracketMatch::where('competition_bracket_id', $bracket->id)->where('round', 1)->first();

    expect($m1->schedule->refresh()->status)->toBe('Ready')
        ->and($m2->schedule->refresh()->status)->toBe('Ready')
        ->and($final->schedule->refresh()->status)->toBe('Scheduled');
});

test('L. Match Center shows auto-seeded bracket matches as Ready', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cas_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cas_category($event);
    $class = cas_class($event, $category, 'individual_vs_individual');
    cas_register('Ath A', $event, $category, $class);
    cas_register('Ath B', $event, $category, $class);
    cas_register('Ath C', $event, $category, $class);
    cas_register('Ath D', $event, $category, $class);

    cas_generateBracket($class, 4);

    $readyCount = \App\Models\CompetitionSchedule::where('competition_class_id', $class->id)
        ->where('status', 'Ready')->count();
    expect($readyCount)->toBe(2);

    $component = \Livewire::test(\App\Livewire\Competition\MatchCenter::class);
    $component->assertSee('Ath A')
        ->assertSee('Ath B')
        ->assertSee('Ath C')
        ->assertSee('Ath D');
});

test('M. team bracket auto-seed promotes full initial matches to Ready', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cas_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cas_category($event);
    $class = cas_class($event, $category, 'team_vs_team');
    $t1 = cas_team('Team A', $event, $class);
    $t2 = cas_team('Team B', $event, $class);
    $t3 = cas_team('Team C', $event, $class);
    $t4 = cas_team('Team D', $event, $class);

    $bracket = cas_generateBracket($class, 4);
    [$m1, $m2] = cas_initialMatches($bracket);

    expect(cas_entryIds($m1, 'competition_team_id'))->toBe([$t1->id, $t2->id])
        ->and(cas_entryIds($m2, 'competition_team_id'))->toBe([$t3->id, $t4->id])
        ->and($m1->schedule->refresh()->status)->toBe('Ready')
        ->and($m2->schedule->refresh()->status)->toBe('Ready');
});

test('N. incomplete bracket matches stay Scheduled (no forced Ready)', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cas_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cas_category($event);
    $class = cas_class($event, $category, 'individual_vs_individual');
    // hanya 2 dari 4 slot terisi
    cas_register('Ath A', $event, $category, $class);
    cas_register('Ath B', $event, $category, $class);

    $bracket = cas_generateBracket($class, 4);
    [$m1, $m2] = cas_initialMatches($bracket);

    expect($m1->schedule->refresh()->status)->toBe('Ready')
        ->and($m2->schedule->refresh()->status)->toBe('Scheduled')
        ->and($m2->schedule->scheduleEntries()->count())->toBe(0);
});

test('O. re-seed is idempotent and preserves Ready status', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = cas_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = cas_category($event);
    $class = cas_class($event, $category, 'individual_vs_individual');
    cas_register('Ath A', $event, $category, $class);
    cas_register('Ath B', $event, $category, $class);
    cas_register('Ath C', $event, $category, $class);
    cas_register('Ath D', $event, $category, $class);

    $bracket = cas_generateBracket($class, 4);
    [$m1, $m2] = cas_initialMatches($bracket);

    $result = app(CompetitionBracketSeederService::class)->seedInitialRound($event->id, $bracket->id);

    expect($result['seeded'])->toBe(0)
        ->and($m1->schedule->refresh()->status)->toBe('Ready')
        ->and($m1->schedule->scheduleEntries()->count())->toBe(2)
        ->and($m2->schedule->refresh()->status)->toBe('Ready');
});

test('P. auto-ready stays isolated per event (event B untouched)', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $eventA = cas_event();
    $eventB = cas_event();
    app(\App\Support\ActiveEventContext::class)->set($eventA);
    $this->actingAs($admin);

    $catA = cas_category($eventA);
    $classA = cas_class($eventA, $catA, 'individual_vs_individual');
    cas_register('A1', $eventA, $catA, $classA);
    cas_register('A2', $eventA, $catA, $classA);
    cas_register('A3', $eventA, $catA, $classA);
    cas_register('A4', $eventA, $catA, $classA);

    cas_generateBracket($classA, 4);

    $readyInA = \App\Models\CompetitionSchedule::whereIn('competition_class_id',
        \App\Models\CompetitionClass::where('event_id', $eventA->id)->pluck('id'))
        ->where('status', 'Ready')->count();
    $schedulesInB = \App\Models\CompetitionSchedule::whereIn('competition_class_id',
        \App\Models\CompetitionClass::where('event_id', $eventB->id)->pluck('id'))
        ->count();

    expect($readyInA)->toBe(2)
        ->and($schedulesInB)->toBe(0);
});
