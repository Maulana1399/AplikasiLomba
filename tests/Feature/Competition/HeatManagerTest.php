<?php

use App\Enums\Role;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionHeatFormat;
use App\Models\CompetitionHeatResult;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionSchedule;
use App\Models\CompetitionScheduleEntry;
use App\Models\CompetitionTeam;
use App\Models\Event;
use App\Models\kelompok;
use App\Models\Person;
use App\Models\User;
use App\Services\Competition\CompetitionHeatManagerService;
use App\Services\Competition\CompetitionMultiRoundHeatService;
use App\Services\Competition\CompetitionRegistrationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function hm_event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'HM Event '.str()->random(6),
        'slug' => 'hm-'.str()->random(6),
        'status' => 'active',
        'event_type' => 'competition',
    ], $overrides));
}

function hm_category(Event $event): CompetitionCategory
{
    $category = CompetitionCategory::create(['event_id' => $event->id, 'name' => 'HM Cat '.str()->random(4)]);

    return $category;
}

function hm_class(Event $event, CompetitionCategory $category, string $format = 'individual_heat', ?string $resultType = null): CompetitionClass
{
    return CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'HM Class '.str()->random(4),
        'gender' => 'M',
        'format' => $format,
        'status' => 'registration_open',
        'result_type' => $resultType,
        'is_active' => true,
    ]);
}

function hm_person(string $nama, ?kelompok $kelompok = null): Person
{
    return Person::create(['nama' => $nama, 'jenis_kelamin' => 'L', 'kelompok_id' => $kelompok?->id]);
}

function hm_register(Person $person, Event $event, CompetitionCategory $category, CompetitionClass $class): CompetitionRegistration
{
    return app(CompetitionRegistrationService::class)->registerForPerson(
        person: $person,
        eventId: $event->id,
        competitionCategoryId: $category->id,
        competitionClassId: $class->id,
    )['competition_registration'];
}

function hm_kelompok(string $name): kelompok
{
    return kelompok::create(['kelompok_asal' => $name]);
}

function hm_team(Event $event, CompetitionClass $class, string $name): CompetitionTeam
{
    return CompetitionTeam::create([
        'event_id' => $event->id,
        'competition_class_id' => $class->id,
        'name' => $name,
        'kelompok_id' => hm_kelompok('K ')->id,
        'is_active' => true,
    ]);
}

function hm_heat_result(CompetitionSchedule $schedule, CompetitionRegistration $registration, ?float $seconds, ?string $status = null): CompetitionHeatResult
{
    return CompetitionHeatResult::updateOrCreate(
        ['competition_schedule_id' => $schedule->id, 'competition_registration_id' => $registration->id],
        ['score' => $seconds, 'status' => $status, 'position' => null],
    );
}

function hm_team_heat_result(CompetitionSchedule $schedule, CompetitionTeam $team, ?float $seconds, ?string $status = null): CompetitionHeatResult
{
    return CompetitionHeatResult::updateOrCreate(
        ['competition_schedule_id' => $schedule->id, 'competition_team_id' => $team->id],
        ['score' => $seconds, 'status' => $status, 'position' => null],
    );
}

function hm_save_format(Event $event, CompetitionClass $class, int $round, int $participants, int $qualifiers): CompetitionHeatFormat
{
    return app(CompetitionHeatManagerService::class)->upsertFormat(
        $event->id,
        $class->id,
        $round,
        $participants,
        $qualifiers,
    );
}

function hm_generate(Event $event, CompetitionClass $class, int $round): array
{
    return app(CompetitionHeatManagerService::class)->generateRound($event->id, $class->id, $round);
}

function hm_register_many(Event $event, CompetitionCategory $category, CompetitionClass $class, int $count): array
{
    $regs = [];
    for ($i = 1; $i <= $count; $i++) {
        $regs[] = hm_register(hm_person('HM Ath '.$i), $event, $category, $class);
    }

    return $regs;
}

// ---------------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------------

test('competition_heat_formats table exists with expected columns', function () {
    expect(Schema::hasTable('competition_heat_formats'))->toBeTrue()
        ->and(Schema::hasColumns('competition_heat_formats', [
            'competition_class_id', 'round', 'participants_per_heat', 'qualifiers_per_heat',
        ]))->toBeTrue()
        ->and(Schema::hasIndex('competition_heat_formats', 'uniq_heat_format_class_round'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Validation (format builder)
// ---------------------------------------------------------------------------

test('format validation rejects participants per heat 0 and qualifiers 0', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category);

    expect(fn () => hm_save_format($event, $class, 1, 0, 3))->toThrow(ValidationException::class);
    expect(fn () => hm_save_format($event, $class, 1, 7, 0))->toThrow(ValidationException::class);
});

test('format validation rejects qualifiers greater than participants', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category);

    expect(fn () => hm_save_format($event, $class, 1, 7, 8))->toThrow(ValidationException::class);
});

test('format validation rejects non-ranked result type (win_loss format class not supported)', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category, 'team_vs_team');

    expect(fn () => hm_save_format($event, $class, 1, 2, 1))->toThrow(ValidationException::class);
});

test('format upsert is idempotent per (class, round)', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category);

    hm_save_format($event, $class, 1, 7, 3);
    hm_save_format($event, $class, 1, 8, 4);

    expect(CompetitionHeatFormat::where('competition_class_id', $class->id)->count())->toBe(1)
        ->and($class->heatFormats()->first()->participants_per_heat)->toBe(8)
        ->and($class->heatFormats()->first()->qualifiers_per_heat)->toBe(4);
});

test('format is event-scoped — cannot be saved for class of another event', function () {
    $eventA = hm_event();
    $eventB = hm_event();
    $category = hm_category($eventA);
    $class = hm_class($eventA, $category);

    expect(fn () => app(CompetitionHeatManagerService::class)->upsertFormat($eventB->id, $class->id, 1, 7, 3))
        ->toThrow(ModelNotFoundException::class);
});

// ---------------------------------------------------------------------------
// B. Auto generate heats — heat count + division
// ---------------------------------------------------------------------------

test('B. 28 participants with 7/heat produces exactly 4 heats of 7', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category);

    hm_register_many($event, $category, $class, 28);
    hm_save_format($event, $class, 1, 7, 3);

    $service = app(CompetitionHeatManagerService::class);

    expect($service->computeHeatCount($class->id, 1))->toBe(4);

    $result = hm_generate($event, $class, 1);

    expect($result['generated'])->toBeTrue()
        ->and($result['heat_count'])->toBe(4)
        ->and($result['competitors_used'])->toBe(28);

    $heats = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1);

    expect($heats)->toHaveCount(4)
        ->and($heats->pluck('required_participants')->unique()->all())->toBe([7])
        ->and($heats->every(fn ($h) => $h->scheduleEntries()->count() === 7))->toBeTrue()
        ->and($heats->pluck('sort_order')->all())->toBe([101, 102, 103, 104]);
});

test('generate round is idempotent — refuses to duplicate round heats', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category);

    hm_register_many($event, $category, $class, 14);
    hm_save_format($event, $class, 1, 7, 3);

    expect(hm_generate($event, $class, 1)['generated'])->toBeTrue();
    $second = hm_generate($event, $class, 1);

    expect($second['generated'])->toBeFalse()
        ->and($second['reason'])->toBe('round_exists')
        ->and(app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1)->count())->toBe(2);
});

test('generate round distributes pool balanced with max difference 1 when not divisible', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category);

    hm_register_many($event, $category, $class, 30);
    hm_save_format($event, $class, 1, 7, 3);

    $result = hm_generate($event, $class, 1);

    expect($result['heat_count'])->toBe(5);

    $counts = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1)
        ->map(fn ($h) => $h->scheduleEntries()->count())->all();

    // 30 / 7 ceil = 5 heats; base=6, extra=0 → [6,6,6,6,6], bukan [7,7,7,7,2].
    expect($counts)->toBe([6, 6, 6, 6, 6])
        ->and(max($counts) - min($counts))->toBeLessThanOrEqual(1);
});

test('team heat generation creates EMPTY heats (no auto-populate, capacity from format)', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category, 'team_heat');

    $teams = [];
    for ($i = 1; $i <= 8; $i++) {
        $teams[] = hm_team($event, $class, 'Tim G '.$i);
    }
    hm_save_format($event, $class, 1, 4, 2);

    $result = hm_generate($event, $class, 1);

    expect($result['heat_count'])->toBe(2)
        ->and($result['competitors_used'])->toBe(0);

    $schedules = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1);

    expect($schedules)->toHaveCount(2)
        ->and($schedules->pluck('required_participants')->all())->toBe([4, 4])
        ->and($schedules->every(fn ($h) => $h->scheduleEntries()->count() === 0))->toBeTrue();
});

test('Team Heat assignment populates competition_team_id entries, not registrations', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category, 'team_heat');

    $teamA = hm_team($event, $class, 'Tim A');
    $teamB = hm_team($event, $class, 'Tim B');
    hm_save_format($event, $class, 1, 4, 2);

    hm_generate($event, $class, 1);

    $service = app(CompetitionHeatManagerService::class);
    $service->assignTeamToHeat($event->id, $class->id, 1, 1, $teamA->id);
    $service->assignTeamToHeat($event->id, $class->id, 1, 1, $teamB->id);

    $scheduleIds = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1)->pluck('id');

    $teamEntries = CompetitionScheduleEntry::whereIn('competition_schedule_id', $scheduleIds)
        ->whereNotNull('competition_team_id')
        ->count();

    $individualEntries = CompetitionScheduleEntry::whereIn('competition_schedule_id', $scheduleIds)
        ->whereNotNull('competition_registration_id')
        ->count();

    expect($teamEntries)->toBe(2)
        ->and($individualEntries)->toBe(0);
});

// ---------------------------------------------------------------------------
// Team Heat — assignment manual (operator memilih Team ke heat)
// ---------------------------------------------------------------------------

test('assignTeamToHeat assigns a team into a heat and auto-readies once min-participants reached', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category, 'team_heat');

    $teamA = hm_team($event, $class, 'Tim Satu');
    $teamB = hm_team($event, $class, 'Tim Dua');
    hm_save_format($event, $class, 1, 4, 2);
    hm_generate($event, $class, 1);

    $service = app(CompetitionHeatManagerService::class);
    $result = $service->assignTeamToHeat($event->id, $class->id, 1, 1, $teamA->id);

    expect($result['assigned'])->toBeTrue()
        ->and($result['heat_index'])->toBe(1);

    $heat = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1)->first();

    expect($heat->scheduleEntries()->first()->competition_team_id)->toBe($teamA->id)
        ->and($heat->status)->toBe('Scheduled');

    // Min start default = 2 -> begitu team kedua masuk, heat otomatis Ready.
    $service->assignTeamToHeat($event->id, $class->id, 1, 1, $teamB->id);

    expect($heat->fresh()->status)->toBe('Ready');
});

test('assignTeamToHeat refuses when heat capacity is full', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category, 'team_heat');

    $teams = [];
    for ($i = 1; $i <= 4; $i++) {
        $teams[] = hm_team($event, $class, 'Tim Ada '.$i);
    }
    $extra = hm_team($event, $class, 'Tim Ekstra');
    hm_save_format($event, $class, 1, 4, 2);
    hm_generate($event, $class, 1);

    $service = app(CompetitionHeatManagerService::class);

    foreach ($teams as $team) {
        $service->assignTeamToHeat($event->id, $class->id, 1, 1, $team->id);
    }

    expect(fn () => $service->assignTeamToHeat($event->id, $class->id, 1, 1, $extra->id))
        ->toThrow(ValidationException::class);
});

test('assignTeamToHeat refuses an inactive team or a team of another class', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category, 'team_heat');
    $otherClass = hm_class($event, $category, 'team_heat');

    $inactive = hm_team($event, $class, 'Tim Nonaktif');
    $inactive->update(['is_active' => false]);

    $foreign = hm_team($event, $otherClass, 'Tim Kelas Lain');

    hm_save_format($event, $class, 1, 4, 2);
    hm_generate($event, $class, 1);

    $service = app(CompetitionHeatManagerService::class);

    expect(fn () => $service->assignTeamToHeat($event->id, $class->id, 1, 1, $inactive->id))
        ->toThrow(ValidationException::class)
        ->and(fn () => $service->assignTeamToHeat($event->id, $class->id, 1, 1, $foreign->id))
        ->toThrow(ValidationException::class);
});

test('assignTeamToHeat refuses a team already placed in another heat of the same round', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category, 'team_heat');

    $team = hm_team($event, $class, 'Tim Satu');
    $other = hm_team($event, $class, 'Tim Lain');
    $fillA = hm_team($event, $class, 'Tim Isi A');
    $fillB = hm_team($event, $class, 'Tim Isi B');

    hm_save_format($event, $class, 1, 2, 1);
    hm_generate($event, $class, 1);

    $service = app(CompetitionHeatManagerService::class);
    $service->assignTeamToHeat($event->id, $class->id, 1, 1, $team->id);

    expect(fn () => $service->assignTeamToHeat($event->id, $class->id, 1, 2, $team->id))
        ->toThrow(ValidationException::class);

    $service->assignTeamToHeat($event->id, $class->id, 1, 2, $other->id);

    $totalEntries = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1)
        ->sum(fn ($h) => $h->scheduleEntries()->count());

    expect($totalEntries)->toBe(2);
});

test('assignTeamToHeat refuses modifications once a heat is locked or has results', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category, 'team_heat');

    $team = hm_team($event, $class, 'Tim Mulai');
    $other = hm_team($event, $class, 'Tim Baru');

    hm_save_format($event, $class, 1, 4, 2);
    hm_generate($event, $class, 1);

    $heat = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1)->first();
    $heat->update(['status' => 'Playing']);

    $service = app(CompetitionHeatManagerService::class);

    expect(fn () => $service->assignTeamToHeat($event->id, $class->id, 1, 1, $team->id))
        ->toThrow(ValidationException::class);

    $heat->update(['status' => 'Scheduled']);
    $service->assignTeamToHeat($event->id, $class->id, 1, 1, $team->id);
    hm_team_heat_result($heat, $team, 12.5, 'Lolos');

    expect(fn () => $service->assignTeamToHeat($event->id, $class->id, 1, 1, $other->id))
        ->toThrow(ValidationException::class);
});

test('assignment methods refuse for non-team-heat formats', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category, 'individual_heat');

    $person = hm_person('Atlet A');
    hm_register($person, $event, $category, $class);

    hm_save_format($event, $class, 1, 4, 2);
    hm_generate($event, $class, 1);

    $service = app(CompetitionHeatManagerService::class);
    $regId = CompetitionRegistration::first()->id;

    expect(fn () => $service->assignTeamToHeat($event->id, $class->id, 1, 1, $regId))
        ->toThrow(ValidationException::class)
        ->and(fn () => $service->autoAssignRound($event->id, $class->id, 1))
        ->toThrow(ValidationException::class);
});

test('removeTeamFromHeat removes a team before start and refuses after', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category, 'team_heat');

    $team = hm_team($event, $class, 'Tim Hapus');

    hm_save_format($event, $class, 1, 4, 2);
    hm_generate($event, $class, 1);

    $service = app(CompetitionHeatManagerService::class);
    $service->assignTeamToHeat($event->id, $class->id, 1, 1, $team->id);

    $heat = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1)->first();
    $heat->update(['status' => 'Playing']);

    expect(fn () => $service->removeTeamFromHeat($event->id, $class->id, 1, 1, $team->id))
        ->toThrow(ValidationException::class);

    $heat->update(['status' => 'Scheduled']);

    $result = $service->removeTeamFromHeat($event->id, $class->id, 1, 1, $team->id);

    expect($result['removed'])->toBeTrue()
        ->and($heat->fresh()->scheduleEntries()->count())->toBe(0);
});

test('moveTeamBetweenHeats moves a team to another heat, honoring capacity', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category, 'team_heat');

    $teams = [];
    for ($i = 1; $i <= 6; $i++) {
        $teams[] = hm_team($event, $class, 'Tim M '.$i);
    }

    hm_save_format($event, $class, 1, 2, 1);
    hm_generate($event, $class, 1);

    $service = app(CompetitionHeatManagerService::class);
    $service->assignTeamToHeat($event->id, $class->id, 1, 1, $teams[0]->id);

    $result = $service->moveTeamBetweenHeats($event->id, $class->id, 1, 1, 3, $teams[0]->id);

    expect($result['moved'])->toBeTrue()
        ->and($result['heat_index'])->toBe(3);

    $schedules = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1);

    expect($schedules->get(0)->scheduleEntries()->count())->toBe(0)
        ->and($schedules->get(2)->scheduleEntries()->first()->competition_team_id)->toBe($teams[0]->id);

    // Isi heat 3 penuh (2/2) -> memindahkan team dari heat 1 harus ditolak.
    $service->assignTeamToHeat($event->id, $class->id, 1, 3, $teams[1]->id);
    $service->assignTeamToHeat($event->id, $class->id, 1, 1, $teams[2]->id);

    expect(fn () => $service->moveTeamBetweenHeats($event->id, $class->id, 1, 1, 3, $teams[2]->id))
        ->toThrow(ValidationException::class);
});

test('autoAssignRound distributes all active teams balanced (max diff 1) and stays editable', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category, 'team_heat');

    $teams = [];
    for ($i = 1; $i <= 10; $i++) {
        $teams[] = hm_team($event, $class, 'Tim A '.$i);
    }

    hm_save_format($event, $class, 1, 4, 2);
    hm_generate($event, $class, 1);

    $service = app(CompetitionHeatManagerService::class);
    $result = $service->autoAssignRound($event->id, $class->id, 1);

    expect($result['assigned'])->toBeTrue()
        ->and($result['teams_assigned'])->toBe(10)
        ->and($result['heat_count'])->toBe(3);

    $schedules = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1);

    $sizes = $schedules->map(fn ($h) => $h->scheduleEntries()->count())->all();

    // 10 team / 3 heat → 4,3,3 (bukan 4,4,2).
    expect($sizes)->toBe([4, 3, 3])
        ->and(max($sizes) - min($sizes))->toBeLessThanOrEqual(1);

    $assignedEver = $schedules->flatMap(fn ($h) => $h->scheduleEntries()->pluck('competition_team_id'))->map(fn ($id) => (int) $id);

    expect($assignedEver->unique()->count())->toBe(10);

    // Hasil distribusi otomatis tetap bisa diubah operator.
    $firstHeat = $schedules->first();
    $movedTeamId = (int) $firstHeat->scheduleEntries()->first()->competition_team_id;

    $service->removeTeamFromHeat($event->id, $class->id, 1, 1, $movedTeamId);
    $service->assignTeamToHeat($event->id, $class->id, 1, 3, $movedTeamId);

    $afterMove = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1);

    expect($afterMove->get(0)->scheduleEntries()->pluck('competition_team_id')->contains($movedTeamId))->toBeFalse()
        ->and($afterMove->get(2)->scheduleEntries()->pluck('competition_team_id')->contains($movedTeamId))->toBeTrue();
});

test('autoAssignRound refuses when round is started or already has results', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category, 'team_heat');

    $team = hm_team($event, $class, 'Tim Satu');

    hm_save_format($event, $class, 1, 4, 2);
    hm_generate($event, $class, 1);

    $service = app(CompetitionHeatManagerService::class);
    $service->assignTeamToHeat($event->id, $class->id, 1, 1, $team->id);

    $heat = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1)->first();
    $heat->update(['status' => 'Playing']);

    expect(fn () => $service->autoAssignRound($event->id, $class->id, 1))
        ->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// A + C + D. Advancement — 4 heats x top 3 -> 12 qualifiers -> 2 next heats
// ---------------------------------------------------------------------------

test('C+D. 4 heats x 3 qualifier -> 12 qualifiers -> 2 next-round heats (6/heat)', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category);

    $regs = hm_register_many($event, $category, $class, 28);
    hm_save_format($event, $class, 1, 7, 3);
    hm_save_format($event, $class, 2, 6, 3);

    hm_generate($event, $class, 1);

    $round1 = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1);

    // Seed per-heat times ascending within each heat (best = smallest).
    foreach ($round1 as $heat) {
        $entries = $heat->scheduleEntries()->pluck('competition_registration_id')->map(fn ($id) => (int) $id)->all();
        $time = 60.0;
        foreach ($entries as $regId) {
            hm_heat_result($heat, CompetitionRegistration::find($regId), $time++, 'Lolos');
        }
    }

    $service = app(CompetitionHeatManagerService::class);
    $result = $service->generateNextRound($event->id, $class->id, 1);

    expect($result['advanced'])->toBeTrue()
        ->and($result['qualifiers'])->toBe(12)
        ->and($result['assigned'])->toBe(12)
        ->and($result['next_round'])->toBe(2)
        ->and($result['heat_count'])->toBe(2);

    $round2 = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 2);

    expect($round2)->toHaveCount(2)
        ->and($round2->pluck('sort_order')->all())->toBe([201, 202])
        ->and($round2->every(fn ($h) => $h->scheduleEntries()->count() === 6))->toBeTrue()
        ->and($round2->pluck('required_participants')->unique()->all())->toBe([6]);
});

test('A. format 7 -> 3: exactly 3 qualifiers advance from a single heat', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category);

    $regs = hm_register_many($event, $category, $class, 7);
    hm_save_format($event, $class, 1, 7, 3);
    hm_save_format($event, $class, 2, 3, 3);

    hm_generate($event, $class, 1);

    $heat = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1)->first();

    $times = [90.0, 95.0, 100.0, 85.0, 80.0, 88.0, 92.0];
    foreach ($regs as $i => $reg) {
        hm_heat_result($heat, $reg, $times[$i], 'Lolos');
    }

    $result = app(CompetitionHeatManagerService::class)->generateNextRound($event->id, $class->id, 1);

    expect($result['advanced'])->toBeTrue()
        ->and($result['qualifiers'])->toBe(3)
        ->and($result['assigned'])->toBe(3);

    $next = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 2)->first();

    expect($next->scheduleEntries()->count())->toBe(3);

    // 3 fastest: 80.0, 85.0, 88.0 -> index 4, 3, 5
    $advancedIds = $next->scheduleEntries()->pluck('competition_registration_id')
        ->map(fn ($id) => (int) $id)->sort()->values()->all();

    expect($advancedIds)->toBe([
        (int) $regs[3]->id, (int) $regs[4]->id, (int) $regs[5]->id,
    ]);
});

// ---------------------------------------------------------------------------
// E. Identity preservation (exact UAT numbers)
// ---------------------------------------------------------------------------

test('E. identity preserved: C01=1:30 C02=1:40 C03=1:20 C04=1:10 -> top 2 = C04 + C03, never C02', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category);

    $C01 = hm_register(hm_person('Competition 01'), $event, $category, $class);
    $C02 = hm_register(hm_person('Competition 02'), $event, $category, $class);
    $C03 = hm_register(hm_person('Competition 03'), $event, $category, $class);
    $C04 = hm_register(hm_person('Competition 04'), $event, $category, $class);

    hm_save_format($event, $class, 1, 7, 2);
    hm_save_format($event, $class, 2, 2, 2);

    hm_generate($event, $class, 1);

    $heat = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1)->first();

    $times = [
        $C01->id => 90.0,  // 1:30
        $C02->id => 100.0, // 1:40
        $C03->id => 80.0,  // 1:20
        $C04->id => 70.0,  // 1:10
    ];
    foreach ([$C01, $C02, $C03, $C04] as $reg) {
        hm_heat_result($heat, $reg, $times[$reg->id], 'Lolos');
    }

    $service = app(CompetitionHeatManagerService::class);
    $result = $service->generateNextRound($event->id, $class->id, 1);

    expect($result['qualifiers'])->toBe(2)
        ->and($result['assigned'])->toBe(2);

    $next = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 2)->first();
    $nextIds = $next->scheduleEntries()->orderBy('id')->pluck('competition_registration_id')
        ->map(fn ($id) => (int) $id)->values()->all();

    expect($nextIds)->toBe([(int) $C04->id, (int) $C03->id])
        ->and($nextIds)->not->toContain((int) $C01->id)
        ->and($nextIds)->not->toContain((int) $C02->id);
});

// ---------------------------------------------------------------------------
// F. Livewire boundary — input survives render, saves to correct registration
// ---------------------------------------------------------------------------

test('F. Livewire boundary: set format+generate via Heat Manager, input results via OutcomeManager across render, no score swap', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = hm_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = hm_category($event);
    $class = hm_class($event, $category);
    $regs = hm_register_many($event, $category, $class, 7);
    hm_save_format($event, $class, 1, 7, 3);
    hm_generate($event, $class, 1);

    $heat = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1)->first();
    expect($heat)->not->toBeNull();

    $component = \Livewire::test(\App\Livewire\Competition\Schedule\OutcomeManager::class, ['schedule' => $heat]);
    $rows = $component->get('heatResults');

    expect($rows)->toHaveCount(7);

    $component->set('heatResults.0.timeText', '1:30.000')
        ->set('heatResults.1.timeText', '1:40.000')
        ->set('heatResults.2.timeText', '1:20.000')
        ->set('heatResults.3.timeText', '1:10.000')
        ->set('heatResults.4.timeText', '1:50.000')
        ->set('heatResults.5.timeText', '1:25.000')
        ->set('heatResults.6.timeText', '1:35.000')
        ->assertSet('heatResults.0.timeText', '1:30.000')
        ->assertSet('heatResults.6.timeText', '1:35.000')
        ->call('saveOutcomes');

    $saved = CompetitionHeatResult::where('competition_schedule_id', $heat->id)->get()->keyBy('competition_registration_id');

    expect($saved)->toHaveCount(7);

    foreach ($regs as $index => $reg) {
        $expected = match ($index) {
            0 => 90.0,
            1 => 100.0,
            2 => 80.0,
            3 => 70.0,
            4 => 110.0,
            5 => 85.0,
            6 => 95.0,
        };
        expect((float) $saved[(int) $reg->id]->score)->toBe($expected);
    }
});

test('F2. Heat Manager page creates format and generates heats via Livewire actions', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = hm_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = hm_category($event);
    $class = hm_class($event, $category);
    hm_register_many($event, $category, $class, 28);

    $component = \Livewire::test(\App\Livewire\Competition\Heat\Index::class);

    $component->set('selectedClassId', (string) $class->id)
        ->set('formatRound', 1)
        ->set('formatParticipants', 7)
        ->set('formatQualifiers', 3)
        ->call('createFormat');

    expect(CompetitionHeatFormat::where('competition_class_id', $class->id)->count())->toBe(1);

    $component->call('generateRound', 1)
        ->assertSet('selectedClassId', (string) $class->id);

    expect(app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1)->count())->toBe(4);

    // Render survives — no inputs to lose on this page.
    $component->assertSee('ROUND 1');
});

test('F4. Team Heat page renders Team Tersedia + assigns a team via Livewire action', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = hm_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = hm_category($event);
    $class = hm_class($event, $category, 'team_heat');

    $teamA = hm_team($event, $class, 'Tim Livewire A');
    hm_team($event, $class, 'Tim Livewire B');

    $component = \Livewire::test(\App\Livewire\Competition\Heat\Index::class);

    $component->set('selectedClassId', (string) $class->id)
        ->set('formatRound', 1)
        ->set('formatParticipants', 4)
        ->set('formatQualifiers', 2)
        ->call('createFormat')
        ->call('generateRound', 1);

    expect(app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1)->count())->toBe(1);

    $component->assertSee('Team Tersedia')
        ->assertSee('Tim Livewire A');

    $component->set('assignTargets', [$teamA->id => 1])
        ->call('assignTeam', 1, $teamA->id);

    $heat = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1)->first();

    expect($heat->scheduleEntries()->first()->competition_team_id)->toBe($teamA->id);
});

// ---------------------------------------------------------------------------
// G. Incomplete heat — no advancement
// ---------------------------------------------------------------------------

test('G. incomplete heat contributes no qualifiers — no advancement', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category);

    $regs = hm_register_many($event, $category, $class, 7);
    hm_save_format($event, $class, 1, 7, 3);
    hm_save_format($event, $class, 2, 6, 3);

    hm_generate($event, $class, 1);

    $heat = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1)->first();

    foreach ($regs as $i => $reg) {
        hm_heat_result($heat, $reg, 90.0 + $i, $i === 3 ? null : 'Lolos');
    }

    $result = app(CompetitionHeatManagerService::class)->generateNextRound($event->id, $class->id, 1);

    expect($result['advanced'])->toBeFalse()
        ->and($result['reason'])->toBe('no_qualifiers');

    expect(app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 2)->isEmpty())->toBeTrue();
});

// ---------------------------------------------------------------------------
// H. Single-round — no fabricated next round without format config
// ---------------------------------------------------------------------------

test('H. single-round competition refuses to fabricate a round without next format', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category);

    $regs = hm_register_many($event, $category, $class, 7);
    hm_save_format($event, $class, 1, 7, 3);

    hm_generate($event, $class, 1);

    $heat = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1)->first();

    foreach ($regs as $reg) {
        hm_heat_result($heat, $reg, 90.0, 'Lolos');
    }

    $schedulesBefore = CompetitionSchedule::count();
    $entriesBefore = CompetitionScheduleEntry::count();

    $result = app(CompetitionHeatManagerService::class)->generateNextRound($event->id, $class->id, 1);

    expect($result['advanced'])->toBeFalse()
        ->and($result['reason'])->toBe('no_next_format')
        ->and(app(CompetitionMultiRoundHeatService::class)->isFinalRound($class->id, 1))->toBeTrue();

    // No fabricated round-2 schedule, no entry changes.
    expect(CompetitionSchedule::count())->toBe($schedulesBefore)
        ->and(CompetitionScheduleEntry::count())->toBe($entriesBefore)
        ->and(app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 2)->isEmpty())->toBeTrue();
});

// ---------------------------------------------------------------------------
// removeRound + team advancement
// ---------------------------------------------------------------------------

test('removeRoundSchedules deletes unstarted round heats but refuses started rounds', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category);

    hm_register_many($event, $category, $class, 14);
    hm_save_format($event, $class, 1, 7, 3);

    hm_generate($event, $class, 1);

    $round1 = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1);
    expect($round1->count())->toBe(2);

    $result = app(CompetitionHeatManagerService::class)->removeRoundSchedules($event->id, $class->id, 1);

    expect($result['deleted'])->toBeTrue()
        ->and(app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1)->count())->toBe(0);

    // Regenerate and start one heat -> refuse deletion.
    hm_generate($event, $class, 1);
    $heat = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1)->first();
    $heat->update(['status' => 'Playing']);

    $result = app(CompetitionHeatManagerService::class)->removeRoundSchedules($event->id, $class->id, 1);

    expect($result['deleted'])->toBeFalse()
        ->and($result['reason'])->toBe('round_started');
});

test('team heat advancement advances teams (not individuals) into next round', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category, 'team_heat');

    $teams = [];
    for ($i = 1; $i <= 8; $i++) {
        $teams[] = hm_team($event, $class, 'Tim A '.$i);
    }
    hm_save_format($event, $class, 1, 4, 2);
    hm_save_format($event, $class, 2, 4, 2);

    hm_generate($event, $class, 1);

    $service = app(CompetitionHeatManagerService::class);

    $schedules = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1);

    foreach ($schedules as $h => $heat) {
        $chunk = array_slice($teams, $h * 4, 4);
        foreach ($chunk as $i => $team) {
            $service->assignTeamToHeat($event->id, $class->id, 1, $h + 1, $team->id);
        }
    }

    $heat = $schedules->first();
    $heat2 = $schedules->last();

    foreach ($heat->scheduleEntries()->pluck('competition_team_id') as $i => $teamId) {
        hm_team_heat_result($heat, CompetitionTeam::find($teamId), 90.0 + $i, 'Lolos');
    }
    foreach ($heat2->scheduleEntries()->pluck('competition_team_id') as $i => $teamId) {
        hm_team_heat_result($heat2, CompetitionTeam::find($teamId), 90.0 + $i, 'Lolos');
    }

    $result = app(CompetitionHeatManagerService::class)->generateNextRound($event->id, $class->id, 1);

    expect($result['advanced'])->toBeTrue()
        ->and($result['qualifiers'])->toBe(4);

    $next = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 2)->first();

    expect($next->scheduleEntries()->whereNotNull('competition_registration_id')->count())->toBe(0)
        ->and($next->scheduleEntries()->count())->toBe(4);
});

// ---------------------------------------------------------------------------
// UAT BUG 2026-08-26 — format (participants_per_heat) HARUS jadi sumber
// kebenaran kapasitas heat. Heat 1v1 (2 peserta/heat) TIDAK boleh muncul untuk
// format 5/2, dan `required_participants` schedule harus = peserta/heat.
// ---------------------------------------------------------------------------

test('UAT Case A: 5 competitors + format 5/2 -> 1 heat capacity 5, 5 entries, top 2 advance', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category);

    hm_register_many($event, $category, $class, 5);
    hm_save_format($event, $class, 1, 5, 2);
    hm_save_format($event, $class, 2, 2, 2);

    hm_generate($event, $class, 1);

    $heats = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1);

    expect($heats)->toHaveCount(1)
        ->and($heats->first()->required_participants)->toBe(5)
        ->and($heats->first()->scheduleEntries()->count())->toBe(5);

    $heat = $heats->first();
    $time = 60.0;
    foreach ($heat->scheduleEntries()->pluck('competition_registration_id') as $regId) {
        hm_heat_result($heat, CompetitionRegistration::find($regId), $time++, 'Lolos');
    }

    $adv = app(CompetitionHeatManagerService::class)->generateNextRound($event->id, $class->id, 1);

    expect($adv['advanced'])->toBeTrue()
        ->and($adv['qualifiers'])->toBe(2)
        ->and($adv['assigned'])->toBe(2);

    $next = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 2)->first();

    expect($next->required_participants)->toBe(2)
        ->and($next->scheduleEntries()->count())->toBe(2);
});

test('UAT Case B: 9 competitors + format 5/2 -> 2 heats capacities [5,5], entries [5,4], each heat top 2', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category);

    hm_register_many($event, $category, $class, 9);
    hm_save_format($event, $class, 1, 5, 2);
    hm_save_format($event, $class, 2, 4, 2);

    $result = hm_generate($event, $class, 1);

    expect($result['heat_count'])->toBe(2);

    $heats = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1);

    expect($heats)->toHaveCount(2)
        ->and($heats->pluck('required_participants')->all())->toBe([5, 5])
        ->and($heats->map(fn ($h) => $h->scheduleEntries()->count())->all())->toBe([5, 4]);

    // Both heats have exactly 2 qualifiers each (top-N = 2).
    foreach ($heats as $heat) {
        $time = 60.0;
        foreach ($heat->scheduleEntries()->pluck('competition_registration_id') as $regId) {
            hm_heat_result($heat, CompetitionRegistration::find($regId), $time++, 'Lolos');
        }
    }

    $adv = app(CompetitionHeatManagerService::class)->generateNextRound($event->id, $class->id, 1);

    expect($adv['qualifiers'])->toBe(4)
        ->and($adv['assigned'])->toBe(4);
});

test('UAT Case C: 10 competitors + format 5/2 -> 2 heats entries [5,5]', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category);

    hm_register_many($event, $category, $class, 10);
    hm_save_format($event, $class, 1, 5, 2);

    $result = hm_generate($event, $class, 1);

    expect($result['heat_count'])->toBe(2);

    $heats = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1);

    expect($heats)->toHaveCount(2)
        ->and($heats->pluck('required_participants')->all())->toBe([5, 5])
        ->and($heats->map(fn ($h) => $h->scheduleEntries()->count())->all())->toBe([5, 5]);
});

test('UAT Case D: 4 competitors + format 5/2 -> 1 heat with 4 entries, never 2 heats of 2+2', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category);

    hm_register_many($event, $category, $class, 4);
    hm_save_format($event, $class, 1, 5, 2);

    $result = hm_generate($event, $class, 1);

    expect($result['heat_count'])->toBe(1);

    $heats = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1);

    expect($heats)->toHaveCount(1)
        ->and($heats->first()->required_participants)->toBe(5)
        ->and($heats->first()->scheduleEntries()->count())->toBe(4);
});

// ---------------------------------------------------------------------------
// Rebuild existing (misconfigured/legacy) round from the format — UAT DATA
// ---------------------------------------------------------------------------

function hm_legacy_round(CompetitionClass $class, array $legacyRegs, int $perHeat = 2): array
{
    $schedules = [];

    foreach (array_chunk($legacyRegs, $perHeat) as $i => $chunk) {
        $schedule = CompetitionSchedule::create([
            'competition_class_id' => $class->id,
            'status' => 'Scheduled',
            'required_participants' => $perHeat,
            'sort_order' => 101 + $i,
        ]);

        foreach ($chunk as $order => $reg) {
            CompetitionScheduleEntry::create([
                'competition_schedule_id' => $schedule->id,
                'competition_registration_id' => $reg->id,
                'order_number' => $order + 1,
            ]);
        }

        $schedules[] = $schedule;
    }

    return $schedules;
}

test('rebuildRound replaces legacy 2-participant heats with format-capacity heats (UAT data repair)', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category);

    $regs = hm_register_many($event, $category, $class, 9);
    hm_save_format($event, $class, 1, 5, 2);

    hm_legacy_round($class, $regs);

    $before = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1);

    expect($before)->toHaveCount(5)
        ->and($before->pluck('required_participants')->unique()->all())->toBe([2]);

    $result = app(CompetitionHeatManagerService::class)->rebuildRound($event->id, $class->id, 1);

    expect($result['rebuilt'])->toBeTrue()
        ->and($result['round'])->toBe(1)
        ->and($result['heat_count'])->toBe(2);

    $heats = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1);

    expect($heats)->toHaveCount(2)
        ->and($heats->pluck('required_participants')->all())->toBe([5, 5])
        ->and($heats->map(fn ($h) => $h->scheduleEntries()->count())->all())->toBe([5, 4]);
});

test('rebuildRound refuses a round whose heat already started and never deletes it', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category);

    $regs = hm_register_many($event, $category, $class, 4);
    hm_save_format($event, $class, 1, 5, 2);

    $legacy = hm_legacy_round($class, $regs);
    $legacy[0]->update(['status' => 'Playing']);

    $result = app(CompetitionHeatManagerService::class)->rebuildRound($event->id, $class->id, 1);

    expect($result['rebuilt'])->toBeFalse()
        ->and($result['reason'])->toBe('round_started')
        ->and(app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1)->count())->toBe(2);
});

test('rebuildRound refuses a round that already has heat results (no data loss)', function () {
    $event = hm_event();
    $category = hm_category($event);
    $class = hm_class($event, $category);

    $regs = hm_register_many($event, $category, $class, 4);
    hm_save_format($event, $class, 1, 5, 2);

    $legacy = hm_legacy_round($class, $regs);
    hm_heat_result($legacy[0], $regs[0], 60.0, 'Lolos');

    $result = app(CompetitionHeatManagerService::class)->rebuildRound($event->id, $class->id, 1);

    expect($result['rebuilt'])->toBeFalse()
        ->and($result['reason'])->toBe('has_results')
        ->and(CompetitionSchedule::count())->toBe(2)
        ->and(CompetitionHeatResult::count())->toBe(1);
});

test('F3. Heat Manager page can rebuild misconfigured round via Livewire action', function () {
    $admin = User::factory()->create(['role' => Role::SuperAdmin]);
    $event = hm_event();
    app(\App\Support\ActiveEventContext::class)->set($event);
    $this->actingAs($admin);

    $category = hm_category($event);
    $class = hm_class($event, $category);
    $regs = hm_register_many($event, $category, $class, 9);
    hm_save_format($event, $class, 1, 5, 2);

    hm_legacy_round($class, $regs);

    $component = \Livewire::test(\App\Livewire\Competition\Heat\Index::class);
    $component->set('selectedClassId', (string) $class->id)
        ->call('rebuildRound', 1)
        ->assertSet('selectedClassId', (string) $class->id);

    $heats = app(CompetitionMultiRoundHeatService::class)->roundSchedules($class->id, 1);

    expect($heats)->toHaveCount(2)
        ->and($heats->pluck('required_participants')->all())->toBe([5, 5]);
});
