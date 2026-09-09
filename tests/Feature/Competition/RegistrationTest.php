<?php

use App\Models\CompetitionCategory;
use App\Models\CompetitionCategoryExclusive;
use App\Models\CompetitionClass;
use App\Models\desa;
use App\Models\Event;
use App\Models\Participation;
use App\Models\Person;
use App\Services\Competition\CompetitionRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeDesa(array $overrides = []): desa
{
    return desa::create(array_merge([
        'desa_asal' => 'Desa Test',
    ], $overrides));
}

function makePerson(array $overrides = []): Person
{
    $desaId = $overrides['desa_id'] ?? null;
    if ($desaId === null) {
        $desa = makeDesa();
        $overrides['desa_id'] = $desa->id;
    }

    return Person::create(array_merge([
        'nama' => 'Budi Test',
        'kelas' => 'SD2',
        'jenis_kelamin' => 'L',
        'tanggal_lahir' => '2015-05-10',
    ], $overrides));
}

function makeEvent(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'Lomba Test',
        'slug' => 'lomba-test-'.str()->random(6),
        'event_type' => 'competition',
        'status' => 'active',
    ], $overrides));
}

function makeCategory(Event $event, array $overrides = []): CompetitionCategory
{
    return CompetitionCategory::create(array_merge([
        'event_id' => $event->id,
        'name' => 'Kategori Test',
        'code' => strtoupper(str()->random(6)),
    ], $overrides));
}

function makeClass(Event $event, CompetitionCategory $category, array $overrides = []): CompetitionClass
{
    return CompetitionClass::create(array_merge([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'SD2',
        'gender' => 'L',
        'code' => strtoupper(str()->random(4)),
    ], $overrides));
}

// ---------------------------------------------------------------------------
// Person.kelas validation
// ---------------------------------------------------------------------------

test('person kelas must match competition class name to register', function () {
    $event = makeEvent();
    $category = makeCategory($event);
    $class = makeClass($event, $category, ['name' => 'SD2']);
    $person = makePerson(['kelas' => 'SD2']);

    $service = app(CompetitionRegistrationService::class);

    $result = $service->registerForPerson($person, $event->id, $category->id, $class->id);

    expect($result['status'])->toBe('registered');
    expect(Participation::where('person_id', $person->id)->exists())->toBeTrue();
});

test('person kelas mismatch blocks registration', function () {
    $event = makeEvent();
    $category = makeCategory($event);
    $class = makeClass($event, $category, ['name' => 'SMP1']);
    $person = makePerson(['kelas' => 'SD2']);

    $service = app(CompetitionRegistrationService::class);

    $service->registerForPerson($person, $event->id, $category->id, $class->id);
})->throws(\Illuminate\Validation\ValidationException::class, 'Kelas peserta (SD2) tidak sesuai dengan kelas lomba (SMP1).');

test('person with null kelas can register at service level (backward-compatible)', function () {
    $event = makeEvent();
    $category = makeCategory($event);
    $class = makeClass($event, $category, ['name' => 'SD2']);
    $person = makePerson(['kelas' => null]);

    $service = app(CompetitionRegistrationService::class);

    $result = $service->registerForPerson($person, $event->id, $category->id, $class->id);

    expect($result['status'])->toBe('registered');
});

// ---------------------------------------------------------------------------
// Exclusivity / Conflict validation
// ---------------------------------------------------------------------------

test('person cannot register in exclusive categories', function () {
    $event = makeEvent();
    $catA = makeCategory($event, ['name' => 'Kategori A']);
    $catB = makeCategory($event, ['name' => 'Kategori B']);
    $classA = makeClass($event, $catA, ['name' => 'SD2']);
    $classB = makeClass($event, $catB, ['name' => 'SD2']);

    CompetitionCategoryExclusive::create([
        'competition_category_id' => $catA->id,
        'exclusive_with_category_id' => $catB->id,
    ]);

    $person = makePerson(['kelas' => 'SD2']);

    $service = app(CompetitionRegistrationService::class);

    $resultA = $service->registerForPerson($person, $event->id, $catA->id, $classA->id);
    expect($resultA['status'])->toBe('registered');

    $service->registerForPerson($person, $event->id, $catB->id, $classB->id);
})->throws(\Illuminate\Validation\ValidationException::class, 'Kategori ini konflik');

test('exclusive check is bidirectional', function () {
    $event = makeEvent();
    $catA = makeCategory($event, ['name' => 'Kategori A']);
    $catB = makeCategory($event, ['name' => 'Kategori B']);
    $classA = makeClass($event, $catA, ['name' => 'SD2']);
    $classB = makeClass($event, $catB, ['name' => 'SD2']);

    CompetitionCategoryExclusive::create([
        'competition_category_id' => $catA->id,
        'exclusive_with_category_id' => $catB->id,
    ]);

    $person = makePerson(['kelas' => 'SD2']);

    $service = app(CompetitionRegistrationService::class);

    $resultB = $service->registerForPerson($person, $event->id, $catB->id, $classB->id);
    expect($resultB['status'])->toBe('registered');

    $service->registerForPerson($person, $event->id, $catA->id, $classA->id);
})->throws(\Illuminate\Validation\ValidationException::class, 'Kategori ini konflik');

test('non-exclusive categories allow multiple registrations', function () {
    $event = makeEvent();
    $catA = makeCategory($event, ['name' => 'Kategori A']);
    $catB = makeCategory($event, ['name' => 'Kategori B']);
    $classA = makeClass($event, $catA, ['name' => 'SD2']);
    $classB = makeClass($event, $catB, ['name' => 'SD2']);

    $person = makePerson(['kelas' => 'SD2']);

    $service = app(CompetitionRegistrationService::class);

    $resultA = $service->registerForPerson($person, $event->id, $catA->id, $classA->id);
    expect($resultA['status'])->toBe('registered');

    $resultB = $service->registerForPerson($person, $event->id, $catB->id, $classB->id);
    expect($resultB['status'])->toBe('registered');

    expect(\App\Models\CompetitionRegistration::where('participation_id', $resultA['participation']->id)->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// CompetitionCategory model exclusivity methods
// ---------------------------------------------------------------------------

test('exclusivity relationship returns correct categories', function () {
    $event = makeEvent();
    $catA = makeCategory($event, ['name' => 'Kategori A']);
    $catB = makeCategory($event, ['name' => 'Kategori B']);
    $catC = makeCategory($event, ['name' => 'Kategori C']);

    CompetitionCategoryExclusive::create([
        'competition_category_id' => $catA->id,
        'exclusive_with_category_id' => $catB->id,
    ]);
    CompetitionCategoryExclusive::create([
        'competition_category_id' => $catA->id,
        'exclusive_with_category_id' => $catC->id,
    ]);

    expect($catA->allExclusiveCategoryIds())->toEqual([$catB->id, $catC->id]);
    expect($catA->isExclusiveWith($catB->id))->toBeTrue();
    expect($catA->isExclusiveWith($catC->id))->toBeTrue();
    expect($catB->isExclusiveWith($catA->id))->toBeTrue(); // bidirectional
    expect($catA->isExclusiveWith($catA->id))->toBeFalse(); // not self-exclusive
});
