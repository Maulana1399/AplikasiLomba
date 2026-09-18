<?php

use App\Enums\Role;
use App\Livewire\Competition\Registration;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionRegistration;
use App\Models\desa;
use App\Models\MasterParticipantClass;
use App\Models\Participation;
use App\Models\Person;
use App\Models\User;
use App\Services\Competition\CompetitionRegistrationService;
use App\Support\ActiveEventContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Registrasi Person dengan people.jenis_kelamin NULL.
 *
 * - Gender TIDAK boleh ditebak dari nama.
 * - Wajib dipilih operator; disimpan kanonik L/P.
 * - Class M menerima L & P; class L hanya L.
 * - Registrasi gagal tidak boleh menyimpan gender / meninggalkan data setengah jadi.
 */

function gnEvent(array $overrides = []): \App\Models\Event
{
    return \App\Models\Event::create(array_merge([
        'name' => 'GN Event '.str()->random(6),
        'slug' => 'gn-'.str()->random(6),
        'event_type' => 'competition',
        'status' => 'active',
    ], $overrides));
}

function gnCategory(\App\Models\Event $event, string $code = 'GN'): CompetitionCategory
{
    return CompetitionCategory::create([
        'event_id' => $event->id,
        'name' => 'Kategori '.$code.' '.str()->random(4),
        'code' => $code.str()->random(4),
        'is_active' => true,
    ]);
}

function gnClass(\App\Models\Event $event, CompetitionCategory $category, string $gender): CompetitionClass
{
    return CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'Kelas '.$gender.' '.str()->random(4),
        'gender' => $gender,
        'format' => 'individual_heat',
        'status' => 'registration_open',
        'is_active' => true,
    ]);
}

function gnPerson(?string $gender, string $name = null, ?int $desaId = null): Person
{
    return Person::create([
        'nama' => $name ?? 'Peserta GN '.str()->random(6),
        'jenis_kelamin' => $gender,
        'desa_id' => $desaId,
    ]);
}

function gnDesa(): desa
{
    return desa::create(['desa_asal' => 'GN Desa '.str()->random(4)]);
}

/**
 * @return array{0: ValidationException|null, 1: string}
 */
function gnAttempt(callable $fn): array
{
    try {
        $fn();

        return [null, ''];
    } catch (ValidationException $e) {
        $field = array_key_first($e->errors());

        return [$e, (string) ($e->errors()[$field][0] ?? '')];
    }
}

// ---------------------------------------------------------------------------
// Service-level behavior
// ---------------------------------------------------------------------------

test('1. Person gender NULL + tanpa pilih gender -> ditolak, tidak ada data setengah', function () {
    $event = gnEvent();
    $category = gnCategory($event);
    $class = gnClass($event, $category, 'M');
    $person = gnPerson(null);

    [$e, $message] = gnAttempt(fn () => app(CompetitionRegistrationService::class)
        ->registerForPerson($person, $event->id, $category->id, $class->id));

    expect($e)->not->toBeNull();
    expect($message)->toContain('Jenis kelamin wajib dipilih.');
    expect($person->fresh()->jenis_kelamin)->toBeNull();
    expect(Participation::where('person_id', $person->id)->count())->toBe(0);
    expect(CompetitionRegistration::count())->toBe(0);
});

test('2. Person gender NULL + pilih L + class M -> gender tersimpan L dan registrasi sukses', function () {
    $event = gnEvent();
    $category = gnCategory($event);
    $class = gnClass($event, $category, 'M');
    $person = gnPerson(null);

    $result = app(CompetitionRegistrationService::class)
        ->registerForPerson($person, $event->id, $category->id, $class->id, gender: 'L');

    expect($result['status'])->toBe('registered');
    expect($person->fresh()->jenis_kelamin)->toBe('L');
    expect(CompetitionRegistration::count())->toBe(1);
});

test('3. Person gender NULL + pilih P + class M -> gender tersimpan P dan registrasi sukses', function () {
    $event = gnEvent();
    $category = gnCategory($event);
    $class = gnClass($event, $category, 'M');
    $person = gnPerson(null);

    $result = app(CompetitionRegistrationService::class)
        ->registerForPerson($person, $event->id, $category->id, $class->id, gender: 'P');

    expect($result['status'])->toBe('registered');
    expect($person->fresh()->jenis_kelamin)->toBe('P');
    expect(CompetitionRegistration::count())->toBe(1);
});

test('4. Person gender NULL + pilih L + class L -> sukses', function () {
    $event = gnEvent();
    $category = gnCategory($event);
    $class = gnClass($event, $category, 'L');
    $person = gnPerson(null);

    $result = app(CompetitionRegistrationService::class)
        ->registerForPerson($person, $event->id, $category->id, $class->id, gender: 'L');

    expect($result['status'])->toBe('registered');
    expect($person->fresh()->jenis_kelamin)->toBe('L');
});

test('5. Person gender NULL + pilih P + class L -> ditolak, gender TIDAK tersimpan', function () {
    $event = gnEvent();
    $category = gnCategory($event);
    $class = gnClass($event, $category, 'L');
    $person = gnPerson(null);

    [$e, $message] = gnAttempt(fn () => app(CompetitionRegistrationService::class)
        ->registerForPerson($person, $event->id, $category->id, $class->id, gender: 'P'));

    expect($e)->not->toBeNull();
    expect($message)->toContain('Jenis kelamin peserta tidak sesuai');
    expect($person->fresh()->jenis_kelamin)->toBeNull();
    expect(Participation::where('person_id', $person->id)->count())->toBe(0);
    expect(CompetitionRegistration::count())->toBe(0);
});

test('6. Person sudah L + class M -> tanpa input gender tambahan, sukses', function () {
    $event = gnEvent();
    $category = gnCategory($event);
    $class = gnClass($event, $category, 'M');
    $person = gnPerson('L');

    $result = app(CompetitionRegistrationService::class)
        ->registerForPerson($person, $event->id, $category->id, $class->id);

    expect($result['status'])->toBe('registered');
    expect($person->fresh()->jenis_kelamin)->toBe('L');
});

test('7. Person sudah P + class M -> sukses', function () {
    $event = gnEvent();
    $category = gnCategory($event);
    $class = gnClass($event, $category, 'M');
    $person = gnPerson('P');

    $result = app(CompetitionRegistrationService::class)
        ->registerForPerson($person, $event->id, $category->id, $class->id);

    expect($result['status'])->toBe('registered');
    expect($person->fresh()->jenis_kelamin)->toBe('P');
});

test('8. Person sudah P + class L -> ditolak', function () {
    $event = gnEvent();
    $category = gnCategory($event);
    $class = gnClass($event, $category, 'L');
    $person = gnPerson('P');

    [$e] = gnAttempt(fn () => app(CompetitionRegistrationService::class)
        ->registerForPerson($person, $event->id, $category->id, $class->id));

    expect($e)->not->toBeNull();
    expect(Participation::where('person_id', $person->id)->count())->toBe(0);
});

test('9. gender tidak pernah diinfer dari nama', function () {
    $event = gnEvent();
    $category = gnCategory($event);
    $class = gnClass($event, $category, 'M');
    $service = app(CompetitionRegistrationService::class);

    foreach (['Siti Aminah', 'Dewi Lestari', 'Budi Santoso', 'Ahmad Fauzi'] as $nama) {
        $person = gnPerson(null, $nama);

        [$e, $message] = gnAttempt(fn () => $service
            ->registerForPerson($person, $event->id, $category->id, $class->id));

        expect($e)->not->toBeNull("nama {$nama} seharusnya ditolak tanpa gender");
        expect($message)->toContain('Jenis kelamin wajib dipilih.');
        expect($person->fresh()->jenis_kelamin)->toBeNull();
    }

    expect(CompetitionRegistration::count())->toBe(0);
});

test('10. registrasi gagal tidak meninggalkan Participation/Registration', function () {
    $event = gnEvent();
    $category = gnCategory($event);
    $service = app(CompetitionRegistrationService::class);

    $lClass = gnClass($event, $category, 'L');
    $person = gnPerson(null);

    // Gagal dua kali: tanpa gender, lalu P ke class L.
    gnAttempt(fn () => $service->registerForPerson($person, $event->id, $category->id, $lClass->id));
    gnAttempt(fn () => $service->registerForPerson($person, $event->id, $category->id, $lClass->id, gender: 'P'));

    expect($person->fresh()->jenis_kelamin)->toBeNull();
    expect(Participation::where('person_id', $person->id)->count())->toBe(0);
    expect(CompetitionRegistration::count())->toBe(0);
});

test('register() existing Person NULL tersimpan L/P dan registrasi ke class M', function () {
    $event = gnEvent();
    $category = gnCategory($event);
    $class = gnClass($event, $category, 'M');
    $desa = gnDesa();
    $person = gnPerson(null, 'Peserta Register GN', $desa->id);

    $service = app(CompetitionRegistrationService::class);

    $result = $service->register(
        nama: 'Peserta Register GN',
        jenisKelamin: 'P',
        tanggalLahir: null,
        desaId: $desa->id,
        eventId: $event->id,
        competitionCategoryId: $category->id,
        competitionClassId: $class->id,
    );

    expect($result['status'])->toBe('registered');
    expect($person->fresh()->jenis_kelamin)->toBe('P');
});

test('register() existing Person NULL + P ke class L -> ditolak tanpa menyimpan gender', function () {
    $event = gnEvent();
    $category = gnCategory($event);
    $class = gnClass($event, $category, 'L');
    $desa = gnDesa();
    $person = gnPerson(null, 'Peserta Register Tolak', $desa->id);

    [$e] = gnAttempt(fn () => app(CompetitionRegistrationService::class)->register(
        nama: 'Peserta Register Tolak',
        jenisKelamin: 'P',
        tanggalLahir: null,
        desaId: $desa->id,
        eventId: $event->id,
        competitionCategoryId: $category->id,
        competitionClassId: $class->id,
    ));

    expect($e)->not->toBeNull();
    expect($person->fresh()->jenis_kelamin)->toBeNull();
    expect(Participation::where('person_id', $person->id)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Livewire behavior
// ---------------------------------------------------------------------------

function gnLivewireSetup(): array
{
    $event = gnEvent();
    app(ActiveEventContext::class)->set($event);

    $admin = User::factory()->create(['role' => Role::Admin]);
    $desa = gnDesa();
    $mpc = MasterParticipantClass::firstOrCreate(
        ['name' => 'SMP'],
        ['code' => 'SMP', 'sort_order' => 8, 'is_active' => true]
    );
    $category = gnCategory($event);
    $category->masterParticipantClasses()->sync([$mpc->id]);

    return [$event, $admin, $desa, $mpc, $category];
}

test('Livewire: field gender tampil untuk Person NULL dan wajib diisi', function () {
    [$event, $admin, $desa, $mpc, $category] = gnLivewireSetup();
    $class = gnClass($event, $category, 'M');
    $person = gnPerson(null, 'Peserta GN Live', $desa->id);

    Livewire::actingAs($admin)
        ->test(Registration::class)
        ->set('nama', 'Peserta GN Live')
        ->set('desaId', $desa->id)
        ->set('participantClassId', $mpc->id)
        ->set('competitionId', $event->id)
        ->assertSet('requiresGenderSelection', true)
        ->call('submit')
        ->assertHasErrors(['jenisKelamin']);

    expect($person->fresh()->jenis_kelamin)->toBeNull();
    expect(Participation::count())->toBe(0);
});

test('Livewire: Person NULL + pilih L + class M -> sukses dan gender tersimpan', function () {
    [$event, $admin, $desa, $mpc, $category] = gnLivewireSetup();
    $class = gnClass($event, $category, 'M');
    $person = gnPerson(null, 'Peserta GN L', $desa->id);

    Livewire::actingAs($admin)
        ->test(Registration::class)
        ->set('nama', 'Peserta GN L')
        ->set('desaId', $desa->id)
        ->set('participantClassId', $mpc->id)
        ->set('competitionId', $event->id)
        ->set('jenisKelamin', 'L')
        ->call('submit')
        ->assertHasNoErrors();

    expect($person->fresh()->jenis_kelamin)->toBe('L');
    expect(CompetitionRegistration::count())->toBe(1);
});

test('Livewire: Person sudah L + class M -> field gender tidak diminta', function () {
    [$event, $admin, $desa, $mpc, $category] = gnLivewireSetup();
    $class = gnClass($event, $category, 'M');
    $person = gnPerson('L', 'Peserta GN Known L', $desa->id);

    Livewire::actingAs($admin)
        ->test(Registration::class)
        ->set('nama', 'Peserta GN Known L')
        ->set('desaId', $desa->id)
        ->set('participantClassId', $mpc->id)
        ->set('competitionId', $event->id)
        ->assertSet('requiresGenderSelection', false)
        ->call('submit')
        ->assertHasNoErrors();

    expect($person->fresh()->jenis_kelamin)->toBe('L');
    expect(CompetitionRegistration::count())->toBe(1);
});

test('Livewire: Person NULL + P ke class L -> ditolak, gender tidak tersimpan', function () {
    [$event, $admin, $desa, $mpc, $category] = gnLivewireSetup();
    $class = gnClass($event, $category, 'L');
    $person = gnPerson(null, 'Peserta GN P L', $desa->id);

    Livewire::actingAs($admin)
        ->test(Registration::class)
        ->set('nama', 'Peserta GN P L')
        ->set('desaId', $desa->id)
        ->set('participantClassId', $mpc->id)
        ->set('competitionId', $event->id)
        ->set('jenisKelamin', 'P')
        ->call('submit')
        ->assertHasErrors();

    expect($person->fresh()->jenis_kelamin)->toBeNull();
    expect(Participation::count())->toBe(0);
    expect(CompetitionRegistration::count())->toBe(0);
});
