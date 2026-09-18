<?php

use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\Event;
use App\Models\Person;
use App\Services\Competition\CompetitionRegistrationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * Aturan gender CompetitionClass:
 * - Lomba campuran -> satu class gender M (wildcard L & P).
 * - Lomba male-only -> class gender L saja.
 */

function gcEvent(): Event
{
    return Event::create([
        'name' => 'Lomba Gender Test',
        'slug' => 'lomba-gender-'.str()->random(6),
        'event_type' => 'competition',
        'status' => 'active',
    ]);
}

function gcCategory(Event $event): CompetitionCategory
{
    return CompetitionCategory::create([
        'event_id' => $event->id,
        'name' => 'SD 2',
        'code' => strtoupper(str()->random(6)),
    ]);
}

function gcClass(Event $event, CompetitionCategory $category, string $gender): CompetitionClass
{
    return CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'Kelas '.$gender,
        'gender' => $gender,
        'code' => strtoupper(str()->random(6)),
    ]);
}

function gcPerson(string $gender): Person
{
    return Person::create([
        'nama' => 'Peserta '.str()->random(6),
        'jenis_kelamin' => $gender,
    ]);
}

test('class M menerima peserta L dan P (wildcard mixed)', function () {
    $event = gcEvent();
    $category = gcCategory($event);
    $class = gcClass($event, $category, 'M');

    $service = app(CompetitionRegistrationService::class);

    $l = $service->registerForPerson(gcPerson('L'), $event->id, $category->id, $class->id);
    $p = $service->registerForPerson(gcPerson('P'), $event->id, $category->id, $class->id);

    expect($l['status'])->toBe('registered');
    expect($p['status'])->toBe('registered');
});

test('class L menolak peserta P dan menerima peserta L', function () {
    $event = gcEvent();
    $category = gcCategory($event);
    $class = gcClass($event, $category, 'L');

    $service = app(CompetitionRegistrationService::class);

    expect(fn () => $service->registerForPerson(gcPerson('P'), $event->id, $category->id, $class->id))
        ->toThrow(ValidationException::class);

    $ok = $service->registerForPerson(gcPerson('L'), $event->id, $category->id, $class->id);
    expect($ok['status'])->toBe('registered');
});

test('seeder canonical: male-only -> L, lomba lain -> M', function () {
    $this->seed(DatabaseSeeder::class);

    $maleOnly = [
        'Adzan & Qomat',
        'Kaifiyatussholah',
        'Aplikasi Penerapan 29 Karakter Luhur Jamaah',
        'Khotbah',
    ];

    $events = Event::where('event_type', 'competition')->get();

    expect($events)->toHaveCount(14);

    foreach ($events as $event) {
        $genders = CompetitionClass::where('event_id', $event->id)
            ->pluck('gender')->unique()->sort()->values()->all();

        if (in_array($event->name, $maleOnly, true)) {
            expect($genders)->toBe(['L'], "male-only {$event->name}");
        } else {
            expect($genders)->toBe(['M'], "mixed {$event->name}");
        }
    }

    $eventsWithL = CompetitionClass::where('gender', 'L')
        ->join('events', 'events.id', '=', 'competition_classes.event_id')
        ->distinct()->pluck('events.name')->sort()->values()->all();

    expect($eventsWithL)->toBe(collect($maleOnly)->sort()->values()->all());
});

test('tidak ada class gender P pada data canonical', function () {
    $this->seed(DatabaseSeeder::class);

    expect(CompetitionClass::where('gender', 'P')->count())->toBe(0);
});
