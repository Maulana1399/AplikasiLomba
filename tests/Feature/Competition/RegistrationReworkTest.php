<?php

use App\Enums\Role;
use App\Livewire\Competition\Registration;
use App\Models\CompetitionCategory;
use App\Models\CompetitionCategoryExclusive;
use App\Models\CompetitionClass;
use App\Models\CompetitionRegistration;
use App\Models\desa;
use App\Models\kelompok;
use App\Models\MasterParticipantClass;
use App\Models\Participation;
use App\Models\Person;
use App\Models\User;
use App\Services\Competition\CompetitionRegistrationService;
use App\Support\ActiveEventContext;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function reg_event(array $overrides = []): \App\Models\Event
{
    return \App\Models\Event::create(array_merge([
        'name' => 'Event Reg '.str()->random(6),
        'slug' => 'reg-'.str()->random(6),
        'event_type' => 'competition',
        'status' => 'active',
    ], $overrides));
}

function reg_admin(): User
{
    return User::factory()->create(['role' => Role::Admin]);
}

beforeEach(function () {
    $this->event = reg_event();
    app(ActiveEventContext::class)->set($this->event);
    $this->admin = reg_admin();
    $this->desa = desa::create(['desa_asal' => 'Batam', 'sort_order' => 1]);
    $this->kelompok = kelompok::create(['kelompok_asal' => 'Kel-A', 'desa_id' => $this->desa->id, 'sort_order' => 1]);
    $this->participantClass = MasterParticipantClass::firstOrCreate(
        ['name' => 'SMP'],
        ['code' => 'SMP', 'sort_order' => 8, 'is_active' => true]
    );
    $this->category = CompetitionCategory::create([
        'event_id' => $this->event->id,
        'name' => 'Kategori Reg',
        'code' => 'REG',
        'is_active' => true,
    ]);
    $this->category->masterParticipantClasses()->sync([$this->participantClass->id]);
    $this->class = CompetitionClass::create([
        'event_id' => $this->event->id,
        'competition_category_id' => $this->category->id,
        'name' => 'Kelas Putra',
        'gender' => 'L',
        'format' => 'individual_heat',
        'status' => 'registration_open',
        'is_active' => true,
    ]);
});

test('registration form loads with master data dropdowns', function () {
    Livewire::actingAs($this->admin)
        ->test(Registration::class)
        ->assertOk()
        ->assertSee($this->desa->desa_asal)
        ->assertSee($this->participantClass->name)
        ->assertSee($this->event->name);
});

test('desa selection cascades to kelompok', function () {
    Livewire::actingAs($this->admin)
        ->test(Registration::class)
        ->set('desaId', $this->desa->id)
        ->assertSee($this->kelompok->kelompok_asal);
});

test('empty desa yields no kelompok', function () {
    Livewire::actingAs($this->admin)
        ->test(Registration::class)
        ->set('desaId', '')
        ->assertDontSee($this->kelompok->kelompok_asal);
});

test('competition, participant class and gender resolve the candidate class', function () {
    Livewire::actingAs($this->admin)
        ->test(Registration::class)
        ->set('competitionId', $this->event->id)
        ->set('participantClassId', $this->participantClass->id)
        ->set('jenisKelamin', 'L')
        ->assertSee($this->class->name);
});

test('submit creates person, participation, and competition registration', function () {
    Livewire::actingAs($this->admin)
        ->test(Registration::class)
        ->set('nama', 'Budi Santoso')
        ->set('participantClassId', $this->participantClass->id)
        ->set('jenisKelamin', 'L')
        ->set('desaId', $this->desa->id)
        ->set('kelompokId', $this->kelompok->id)
        ->set('competitionId', $this->event->id)
        ->call('submit')
        ->assertHasNoErrors();

    expect(Person::where('nama', 'Budi Santoso')->count())->toBe(1);
    expect(Participation::count())->toBe(1);
    expect(CompetitionRegistration::count())->toBe(1);

    $p = Participation::first();
    expect($p->participant_number)->toStartWith('KL');
    expect($p->attendance_code)->toStartWith('KJA-');
});

test('submit rejects duplicate registration in the same class', function () {
    Livewire::actingAs($this->admin)
        ->test(Registration::class)
        ->set('nama', 'Budi Santoso')
        ->set('participantClassId', $this->participantClass->id)
        ->set('jenisKelamin', 'L')
        ->set('desaId', $this->desa->id)
        ->set('competitionId', $this->event->id)
        ->call('submit')
        ->assertHasNoErrors();

    Livewire::actingAs($this->admin)
        ->test(Registration::class)
        ->set('nama', 'Budi Santoso')
        ->set('participantClassId', $this->participantClass->id)
        ->set('jenisKelamin', 'L')
        ->set('desaId', $this->desa->id)
        ->set('competitionId', $this->event->id)
        ->call('submit')
        ->assertHasErrors(['competitionId']);

    expect(CompetitionRegistration::count())->toBe(1);
});

test('exclusivity blocks registration when person already in conflicting category', function () {
    $conflictCat = CompetitionCategory::create([
        'event_id' => $this->event->id,
        'name' => 'Kategori Konflik',
        'code' => 'KONF',
        'is_active' => true,
    ]);
    $conflictCat->masterParticipantClasses()->sync([$this->participantClass->id]);

    CompetitionCategoryExclusive::create([
        'competition_category_id' => $this->category->id,
        'exclusive_with_category_id' => $conflictCat->id,
    ]);

    $conflictClass = CompetitionClass::create([
        'event_id' => $this->event->id,
        'competition_category_id' => $conflictCat->id,
        'name' => 'Kelas Putra Konflik',
        'gender' => 'L',
        'format' => 'individual_heat',
        'status' => 'registration_open',
        'is_active' => true,
    ]);

    $service = app(CompetitionRegistrationService::class);
    $person = Person::create(['nama' => 'Andi', 'jenis_kelamin' => 'L', 'desa_id' => $this->desa->id]);
    $service->registerForPerson($person, $this->event->id, $this->category->id, $this->class->id);

    expect(CompetitionRegistration::where('competition_category_id', $conflictCat->id)->count())->toBe(0);

    Livewire::actingAs($this->admin)
        ->test(Registration::class)
        ->set('nama', 'Andi')
        ->set('participantClassId', $this->participantClass->id)
        ->set('jenisKelamin', 'L')
        ->set('desaId', $this->desa->id)
        ->set('competitionId', $this->event->id)
        ->set('selectedClassId', $conflictClass->id)
        ->call('submit')
        ->assertHasErrors(['competitionId']);

    expect(CompetitionRegistration::where('competition_category_id', $conflictCat->id)->count())->toBe(0);
});

test('submit rejects inactive class', function () {
    $this->class->update(['is_active' => false]);

    Livewire::actingAs($this->admin)
        ->test(Registration::class)
        ->set('nama', 'Sari')
        ->set('participantClassId', $this->participantClass->id)
        ->set('jenisKelamin', 'L')
        ->set('desaId', $this->desa->id)
        ->set('competitionId', $this->event->id)
        ->call('submit')
        ->assertHasErrors(['competitionId']);

    expect(Participation::count())->toBe(0);
});

test('submit rejects inactive category', function () {
    $this->category->update(['is_active' => false]);

    Livewire::actingAs($this->admin)
        ->test(Registration::class)
        ->set('nama', 'Sari')
        ->set('participantClassId', $this->participantClass->id)
        ->set('jenisKelamin', 'L')
        ->set('desaId', $this->desa->id)
        ->set('competitionId', $this->event->id)
        ->call('submit')
        ->assertHasErrors(['competitionId']);

    expect(Participation::count())->toBe(0);
});

test('gender mismatch yields no candidate class', function () {
    Livewire::actingAs($this->admin)
        ->test(Registration::class)
        ->set('nama', 'Sari')
        ->set('participantClassId', $this->participantClass->id)
        ->set('jenisKelamin', 'P')
        ->set('desaId', $this->desa->id)
        ->set('competitionId', $this->event->id)
        ->call('submit')
        ->assertHasErrors(['competitionId']);

    expect(Participation::count())->toBe(0);
});

test('gender mismatch is rejected at service level', function () {
    $genderedClass = CompetitionClass::create([
        'event_id' => $this->event->id,
        'competition_category_id' => $this->category->id,
        'name' => 'Kelas Putri',
        'gender' => 'P',
        'format' => 'individual_heat',
        'status' => 'registration_open',
        'is_active' => true,
    ]);

    $service = app(CompetitionRegistrationService::class);
    $person = Person::create(['nama' => 'Sari', 'jenis_kelamin' => 'L', 'desa_id' => $this->desa->id]);

    expect(fn () => $service->registerForPerson($person, $this->event->id, $this->category->id, $genderedClass->id))
        ->toThrow(ValidationException::class);

    expect(Participation::count())->toBe(0);
});

test('master participant class list is populated and ordered by sort_order', function () {
    Livewire::actingAs($this->admin)
        ->test(Registration::class)
        ->assertSee($this->participantClass->name);
});
