<?php

use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionTeam;
use App\Models\CompetitionTeamMember;
use App\Models\CompetitionTeamOutcome;
use App\Models\Event;
use App\Models\MasterParticipantClass;
use App\Models\Participation;
use App\Models\Person;
use App\Services\Competition\CompetitionRegistrationService;
use App\Services\Placement\PlacementService;
use App\Support\CompetitionBootstrap;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\MasterParticipantClassSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

$requiredTables = [
    'users', 'password_reset_tokens', 'sessions',
    'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
    'desas', 'kelompoks', 'regus', 'master_participant_classes',
    'events', 'people', 'participations',
    'venues', 'competition_categories', 'competition_category_exclusivities',
    'competition_classes', 'competition_registrations',
    'competition_teams', 'competition_team_members', 'competition_team_outcomes',
    'competition_schedules', 'competition_schedule_entries', 'competition_outcomes',
    'competition_match_officials',
    'competition_brackets', 'competition_bracket_matches',
    'competition_heat_results', 'competition_heat_formats',
    'competition_announcements',
];

$excludedLegacyTables = [
    'pesertas', 'absensis', 'sesi_absensis', 'izin_absensis', 'surat_izins',
    'event_attendances', 'cai_participant_replacements', 'desa_access_grants',
    'identity_correction_requests', 'event_roles', 'event_committee_assignments',
    'activity_logs', 'activity_groups', 'activities', 'activity_categories',
    'activity_registrations', 'category_definitions', 'rundowns', 'rundown_items',
    'legacy_peserta_mappings', 'legacy_participation_mappings',
];

/**
 * Canonical registration flow (schema baru AplikasiLomba): langsung membangun
 * Person -> Participation -> CompetitionRegistration.
 *
 * Tidak melalui CompetitionRegistrationService::registerForPerson, karena
 * layanan awal masih menyaring kategori lewat relasi pivot
 * competition_category_event yang tidak ada di schema canonical baru.
 */
$createCanonicalRegistration = function (
    Person $person,
    Event $event,
    CompetitionCategory $category,
    CompetitionClass $class,
    string $registrationType = 'individual',
): array {
    $jenisKelamin = $person->jenis_kelamin === 'P' ? 'Perempuan' : 'Laki - Laki';

    $participation = Participation::create([
        'person_id' => $person->id,
        'event_id' => $event->id,
        'participant_number' => PlacementService::generateParticipantNumber($event->id, $jenisKelamin),
        'attendance_code' => app(CompetitionRegistrationService::class)->generateAttendanceCode(),
        'jenis_peserta' => 'Peserta',
    ]);

    $registration = CompetitionRegistration::create([
        'participation_id' => $participation->id,
        'competition_category_id' => $category->id,
        'competition_class_id' => $class->id,
        'registration_type' => $registrationType,
    ]);

    return [
        'participation' => $participation,
        'competition_registration' => $registration,
    ];
};

beforeEach(function () {
    $this->freshPath = tempnam(sys_get_temp_dir(), 'kja_fresh_').'.sqlite';
    touch($this->freshPath);

    config(['database.connections.sqlite_fresh' => [
        'driver' => 'sqlite',
        'database' => $this->freshPath,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);

    DB::purge('sqlite');
    DB::purge('sqlite_fresh');
    config(['database.default' => 'sqlite_fresh']);

    DB::statement('PRAGMA journal_mode = OFF');
    DB::statement('PRAGMA synchronous = OFF');
    DB::statement('PRAGMA locking_mode = EXCLUSIVE');

        Artisan::call('migrate', [
            '--database' => 'sqlite_fresh',
            '--path' => 'database/migrations',
            '--force' => true,
        ]);
});

afterEach(function () {
    config(['database.default' => 'sqlite']);
    DB::purge('sqlite_fresh');

    if (file_exists($this->freshPath)) {
        unlink($this->freshPath);
    }
});

it('migrates an empty database into every required table', function () use ($requiredTables) {
    foreach ($requiredTables as $table) {
        expect(Schema::hasTable($table), "required table {$table} is missing")->toBeTrue();
    }
});

it('does not create any excluded legacy table', function () use ($excludedLegacyTables) {
    foreach ($excludedLegacyTables as $table) {
        expect(Schema::hasTable($table), "excluded legacy table {$table} was created")->toBeFalse();
    }
});

it('enforces foreign keys on the fresh database', function () {
    $foreignKeys = (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys;
    expect($foreignKeys)->toBe(1);

    $fkTable = fn (string $table) => collect(DB::select("PRAGMA foreign_key_list({$table})"))
        ->map(fn ($row) => $row->table)
        ->all();

    expect($fkTable('participations'))->toContain('people', 'events', 'regus');
    expect($fkTable('competition_registrations'))->toContain('participations', 'competition_categories', 'competition_classes');
    expect($fkTable('competition_schedule_entries'))->toContain('competition_schedules', 'competition_registrations', 'competition_teams');
    expect($fkTable('competition_bracket_matches'))->toContain('competition_brackets', 'competition_schedules');
});

it('seeds the full canonical seeder set', function () {
    $this->seed(DatabaseSeeder::class);

    expect(\App\Models\User::count())->toBe(5);

    $superAdmin = \App\Models\User::where('email', 'admin@kja.local')->first();
    expect($superAdmin)->not->toBeNull();
    expect($superAdmin->role)->toBe(\App\Enums\Role::SuperAdmin);
    expect($superAdmin->is_active)->toBeTrue();

    expect(\App\Models\desa::count())->toBeGreaterThanOrEqual(4);
    expect(\App\Models\kelompok::count())->toBeGreaterThan(0);
    expect(MasterParticipantClass::count())->toBe(10);
});

it('seeds the master participant classes idempotently', function () {
    $expected = ['PAUD', 'SD 1', 'SD 2', 'SD 3', 'SD 4', 'SD 5', 'SD 6', 'SMP', 'SMU', 'Dewasa'];

    $this->seed(MasterParticipantClassSeeder::class);
    $this->seed(MasterParticipantClassSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(MasterParticipantClass::count())->toBe(10);

    $rows = MasterParticipantClass::orderBy('sort_order')->get();
    expect($rows->pluck('name')->all())->toBe($expected);

    foreach ($rows as $index => $row) {
        expect($row->code)->toBe($row->name);
        expect($row->sort_order)->toBe($index + 1);
        expect($row->is_active)->toBeTrue();
    }
});

it('bootstraps the active competition event idempotently', function () {
    $bootstrap = app(CompetitionBootstrap::class);

    $event = $bootstrap->ensureActiveCompetitionEvent();
    $again = $bootstrap->ensureActiveCompetitionEvent();

    expect(Event::count())->toBe(1);
    expect($event->id)->toBe($again->id);
    expect($event->slug)->toBe(CompetitionBootstrap::DEFAULT_SLUG);
    expect($event->event_type)->toBe('competition');
    expect($event->status)->toBe('active');
    expect($bootstrap->hasActiveCompetitionEvent())->toBeTrue();
});

it('bootstraps the competition event from the root route', function () {
    $this->get('/')
        ->assertRedirect(route('competition.dashboard'));

    expect(Event::count())->toBe(1);
});

it('creates catalogue objects (category and class) with defaulted fields', function () {
    $event = app(CompetitionBootstrap::class)->ensureActiveCompetitionEvent();

    $category = CompetitionCategory::create([
        'event_id' => $event->id,
        'name' => 'Lari Sprint',
        'code' => 'SPR',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $class = CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'Putra',
        'gender' => 'M',
        'code' => 'SPR-P',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    expect(CompetitionCategory::find($category->id))->toMatchArray([
        'event_id' => $event->id,
        'name' => 'Lari Sprint',
        'code' => 'SPR',
        'is_active' => 1,
    ]);

    expect(CompetitionClass::find($class->id))->toMatchArray([
        'competition_category_id' => $category->id,
        'event_id' => $event->id,
        'name' => 'Putra',
        'gender' => 'M',
        'format' => 'individual_heat',
        'status' => 'registration_open',
        'is_active' => 1,
        'winner_count' => 3,
    ]);
});

it('registers a participant through the canonical competition flow', function () use ($createCanonicalRegistration) {
    $event = app(CompetitionBootstrap::class)->ensureActiveCompetitionEvent();

    $category = CompetitionCategory::create([
        'event_id' => $event->id,
        'name' => 'Lari Sprint',
        'code' => 'SPR',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $class = CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'Putra',
        'gender' => 'M',
        'format' => 'individual_heat',
        'status' => 'registration_open',
        'code' => 'SPR-P',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $person = Person::create([
        'nama' => 'Budi Santoso',
        'jenis_kelamin' => 'L',
        'kelas' => 'SMP 1',
    ]);

    $created = $createCanonicalRegistration($person, $event, $category, $class, 'individual');

    expect(Participation::count())->toBe(1);
    expect(CompetitionRegistration::count())->toBe(1);

    $participation = $created['participation'];
    expect($participation->participant_number)->toStartWith('KL');
    expect($participation->attendance_code)->toStartWith('KJA-');
    expect($participation->jenis_peserta)->toBe('Peserta');

    $registration = $created['competition_registration'];
    expect($registration->competition_class_id)->toBe($class->id);
    expect($registration->registration_type)->toBe('individual');
});

it('creates a competition team with members and an outcome', function () use ($createCanonicalRegistration) {
    $event = app(CompetitionBootstrap::class)->ensureActiveCompetitionEvent();

    $category = CompetitionCategory::create([
        'event_id' => $event->id,
        'name' => 'Estafet',
        'code' => 'EST',
        'is_active' => true,
    ]);

    $class = CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'Beregu Putra',
        'gender' => 'M',
        'format' => 'team_heat',
        'status' => 'registration_open',
        'team_size' => 3,
        'is_active' => true,
    ]);

    $registrations = collect(['Budi Santoso', 'Andi Wijaya', 'Cici Lestari'])
        ->map(function (string $nama) use ($createCanonicalRegistration, $event, $category, $class) {
            $person = Person::create(['nama' => $nama, 'jenis_kelamin' => 'L', 'kelas' => 'SMP 1']);

            return $createCanonicalRegistration($person, $event, $category, $class)['competition_registration'];
        });

    $team = CompetitionTeam::create([
        'event_id' => $event->id,
        'competition_class_id' => $class->id,
        'name' => 'KJA Putra',
        'kelompok_id' => null,
        'is_active' => true,
    ]);

    foreach ($registrations as $index => $registration) {
        CompetitionTeamMember::create([
            'competition_team_id' => $team->id,
            'competition_registration_id' => $registration->id,
            'is_substitute' => $index === 0,
            'sort_order' => $index,
        ]);
    }

    CompetitionTeamOutcome::create([
        'competition_team_id' => $team->id,
        'position' => 1,
        'status' => 'finished',
        'score' => 42.50,
    ]);

    expect(Participation::count())->toBe(3);
    expect(CompetitionRegistration::count())->toBe(3);
    expect(CompetitionTeam::count())->toBe(1);
    expect(CompetitionTeamMember::count())->toBe(3);
    expect(CompetitionTeamOutcome::count())->toBe(1);

    $outcome = CompetitionTeamOutcome::first();
    expect((float) $outcome->score)->toBe(42.5);
});

it('performs no queries against the legacy pesertas table during registration', function () use ($createCanonicalRegistration) {
    $event = app(CompetitionBootstrap::class)->ensureActiveCompetitionEvent();

    $category = CompetitionCategory::create([
        'event_id' => $event->id,
        'name' => 'Lari Sprint',
        'code' => 'SPR',
        'is_active' => true,
    ]);

    $class = CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'Putra',
        'gender' => 'M',
        'format' => 'individual_heat',
        'status' => 'registration_open',
        'is_active' => true,
    ]);

    $person = Person::create([
        'nama' => 'Sari Puspita',
        'jenis_kelamin' => 'P',
        'kelas' => 'SD 5',
    ]);

    $queries = [];

    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $createCanonicalRegistration($person, $event, $category, $class);

    expect($queries)->not->toBeEmpty();

    foreach ($queries as $sql) {
        expect(str_contains(strtolower($sql), 'pesertas'))->toBeFalse("legacy query leaked: {$sql}");
    }
});
