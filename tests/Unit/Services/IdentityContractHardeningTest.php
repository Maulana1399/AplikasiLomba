<?php

use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionRegistration;
use App\Models\Event;
use App\Models\Participation;
use App\Models\Person;
use App\Services\Competition\CompetitionRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(Tests\TestCase::class, RefreshDatabase::class);

afterEach(function () {
    Str::createRandomStringsNormally();
});

function s41_makeCompetition(): array
{
    $event = Event::create([
        'name' => 'S41 Event',
        'slug' => 's41-'.str()->random(6),
        'status' => 'active',
    ]);
    $category = CompetitionCategory::create([
        'event_id' => $event->id,
        'name' => 'Kategori S41',
    ]);
    $class = CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'Kelas S41',
        'gender' => 'P',
    ]);

    return [$event, $category, $class];
}

test('canonical registration creates normalized participation identifiers', function () {
    Str::createRandomStringsUsing(fn () => 's41aaaa1');

    [$event, $category, $class] = s41_makeCompetition();

    $person = Person::create(['nama' => 'Fresh Registrant', 'jenis_kelamin' => 'P']);

    $result = app(CompetitionRegistrationService::class)->registerForPerson(
        person: $person,
        eventId: $event->id,
        competitionCategoryId: $category->id,
        competitionClassId: $class->id,
    );

    $participation = $result['participation'];

    expect($participation)->toBeInstanceOf(Participation::class)
        ->and($participation->participant_number)->toBe('KP001')
        ->and($participation->attendance_code)->toBe('KJA-S41AAAA1')
        ->and(Participation::count())->toBe(1)
        ->and(Person::count())->toBe(1)
        ->and(CompetitionRegistration::count())->toBe(1);
});