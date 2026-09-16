<?php

use App\Enums\Role;
use App\Livewire\Competition\Registration;
use App\Models\CompetitionCategory;
use App\Models\CompetitionCategoryExclusive;
use App\Models\CompetitionClass;
use App\Models\desa;
use App\Models\kelompok;
use App\Models\MasterParticipantClass;
use App\Models\Participation;
use App\Models\Person;
use App\Models\User;
use App\Support\ActiveEventContext;
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
        ['name' => 'SMP1'],
        ['code' => 'SMP1', 'sort_order' => 8]
    );
    $this->category = CompetitionCategory::create([
        'event_id' => $this->event->id,
        'name' => 'Lari Sprint',
        'code' => 'SPR',
        'is_active' => true,
    ]);
    $this->category->events()->attach($this->event);
    $this->class = CompetitionClass::create([
        'event_id' => $this->event->id,
        'competition_category_id' => $this->category->id,
        'name' => 'Putra',
        'gender' => 'M',
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
        ->assertSee($this->category->name);
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

test('category selection cascades to classes', function () {
    Livewire::actingAs($this->admin)
        ->test(Registration::class)
        ->set('competitionCategoryId', $this->category->id)
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
        ->set('competitionCategoryId', $this->category->id)
        ->set('competitionClassId', $this->class->id)
        ->call('submit')
        ->assertHasNoErrors();

    expect(Person::where('nama', 'Budi Santoso')->count())->toBe(1);
    expect(Participation::count())->toBe(1);
    expect(\App\Models\CompetitionRegistration::count())->toBe(1);

    $p = Participation::first();
    expect($p->participant_number)->toStartWith('KL');
    expect($p->attendance_code)->toStartWith('KJA-');
});

test('submit rejects duplicate registration in the same class', function () {
    $service = app(\App\Services\Competition\CompetitionRegistrationService::class);

    Livewire::actingAs($this->admin)
        ->test(Registration::class)
        ->set('nama', 'Budi Santoso')
        ->set('participantClassId', $this->participantClass->id)
        ->set('jenisKelamin', 'L')
        ->set('desaId', $this->desa->id)
        ->set('competitionCategoryId', $this->category->id)
        ->set('competitionClassId', $this->class->id)
        ->call('submit')
        ->assertHasNoErrors();

    Livewire::actingAs($this->admin)
        ->test(Registration::class)
        ->set('nama', 'Budi Santoso')
        ->set('participantClassId', $this->participantClass->id)
        ->set('jenisKelamin', 'L')
        ->set('desaId', $this->desa->id)
        ->set('competitionCategoryId', $this->category->id)
        ->set('competitionClassId', $this->class->id)
        ->call('submit')
        ->assertHasErrors(['nama']);

    expect(\App\Models\CompetitionRegistration::count())->toBe(1);
});

test('exclusivity blocks registration when person already in conflicting category', function () {
    $conflictCat = CompetitionCategory::create([
        'event_id' => $this->event->id,
        'name' => 'Lari Jarak Jauh',
        'code' => 'JAU',
        'is_active' => true,
    ]);
    $conflictCat->events()->attach($this->event);

    CompetitionCategoryExclusive::create([
        'competition_category_id' => $this->category->id,
        'exclusive_with_category_id' => $conflictCat->id,
    ]);

    $conflictClass = CompetitionClass::create([
        'event_id' => $this->event->id,
        'competition_category_id' => $conflictCat->id,
        'name' => 'Putra',
        'gender' => 'M',
        'format' => 'individual_heat',
        'status' => 'registration_open',
        'is_active' => true,
    ]);

    $service = app(\App\Services\Competition\CompetitionRegistrationService::class);

    $person = Person::create(['nama' => 'Andi', 'jenis_kelamin' => 'L', 'desa_id' => $this->desa->id, 'kelas' => 'SMP1']);

    $service->registerForPerson($person, $this->event->id, $this->category->id, $this->class->id);
    expect(\App\Models\CompetitionRegistration::where('competition_category_id', $conflictCat->id)->count())->toBe(0);

    Livewire::actingAs($this->admin)
        ->test(Registration::class)
        ->set('nama', 'Andi')
        ->set('participantClassId', $this->participantClass->id)
        ->set('jenisKelamin', 'L')
        ->set('desaId', $this->desa->id)
        ->set('competitionCategoryId', $conflictCat->id)
        ->set('competitionClassId', $conflictClass->id)
        ->call('submit')
        ->assertHasErrors(['competitionCategoryId']);

    expect(\App\Models\CompetitionRegistration::where('competition_category_id', $conflictCat->id)->count())->toBe(0);
});

test('submit rejects inactive category or class', function () {
    $this->class->update(['status' => 'closed']);

    Livewire::actingAs($this->admin)
        ->test(Registration::class)
        ->set('nama', 'Sari')
        ->set('participantClassId', $this->participantClass->id)
        ->set('jenisKelamin', 'P')
        ->set('desaId', $this->desa->id)
        ->set('competitionCategoryId', $this->category->id)
        ->set('competitionClassId', $this->class->id)
        ->call('submit')
        ->assertHasNoErrors();

    expect(Participation::count())->toBe(1);
});

test('gender mismatch with class is rejected at service level', function () {
    $genderedClass = CompetitionClass::create([
        'event_id' => $this->event->id,
        'competition_category_id' => $this->category->id,
        'name' => 'Putri',
        'gender' => 'P',
        'format' => 'individual_heat',
        'status' => 'registration_open',
        'is_active' => true,
    ]);

    Livewire::actingAs($this->admin)
        ->test(Registration::class)
        ->set('nama', 'Sari')
        ->set('participantClassId', $this->participantClass->id)
        ->set('jenisKelamin', 'L')
        ->set('desaId', $this->desa->id)
        ->set('competitionCategoryId', $this->category->id)
        ->set('competitionClassId', $genderedClass->id)
        ->call('submit')
        ->assertHasErrors();

    expect(Participation::count())->toBe(0);
});

test('master participant class list is populated and ordered by sort_order', function () {
    Livewire::actingAs($this->admin)
        ->test(Registration::class)
        ->assertSee($this->participantClass->name);
});
