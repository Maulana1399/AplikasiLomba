<?php

use App\Models\Event;
use App\Models\Participation;
use App\Models\Person;
use App\Services\Placement\PlacementService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('generate participant number uses participation records', function () {
    $event = Event::create(['name' => 'Placement Event', 'slug' => 'placement-event', 'status' => 'active']);
    $person = Person::create(['nama' => 'Placement Person', 'nip' => 5001, 'jenis_kelamin' => 'L']);
    Participation::create(['person_id' => $person->id, 'event_id' => $event->id, 'participant_number' => 'KL001', 'attendance_code' => 'KJA-PLAC001', 'jenis_peserta' => 'Wajib']);

    expect(PlacementService::generateParticipantNumber($event->id, 'Laki - Laki'))->toBe('KL002')
        ->and(PlacementService::generateParticipantNumber($event->id, 'Perempuan'))->toBe('KP001');
});

test('generate participant number continues the next sequence for the same gender prefix', function () {
    $eventA = Event::create(['name' => 'Placement Event A', 'slug' => 'placement-event-a', 'status' => 'active']);
    $eventB = Event::create(['name' => 'Placement Event B', 'slug' => 'placement-event-b', 'status' => 'active']);
    $personA = Person::create(['nama' => 'Placement A', 'nip' => 5002, 'jenis_kelamin' => 'L']);
    $personB = Person::create(['nama' => 'Placement B', 'nip' => 6002, 'jenis_kelamin' => 'L']);
    Participation::create(['person_id' => $personA->id, 'event_id' => $eventA->id, 'participant_number' => 'KL001', 'attendance_code' => 'KJA-PLAC-A1', 'jenis_peserta' => 'Wajib']);
    Participation::create(['person_id' => $personB->id, 'event_id' => $eventB->id, 'participant_number' => 'KL001', 'attendance_code' => 'KJA-PLAC-B1', 'jenis_peserta' => 'Wajib']);

    expect(PlacementService::generateParticipantNumber($eventA->id, 'Laki - Laki'))->toBe('KL002')
        ->and(PlacementService::generateParticipantNumber($eventB->id, 'Laki - Laki'))->toBe('KL002')
        ->and(PlacementService::generateParticipantNumber($eventA->id, 'Laki laki'))->toBe('KL002')
        ->and(PlacementService::generateParticipantNumber($eventA->id, 'Laki - Laki '))->toBe('KL002');
});

test('generate participant number is isolated per event', function () {
    $eventA = Event::create(['name' => 'Placement Event A2', 'slug' => 'placement-event-a2', 'status' => 'active']);
    $eventB = Event::create(['name' => 'Placement Event B2', 'slug' => 'placement-event-b2', 'status' => 'active']);
    $personA = Person::create(['nama' => 'Placement A2', 'nip' => 7001, 'jenis_kelamin' => 'L']);
    Participation::create(['person_id' => $personA->id, 'event_id' => $eventA->id, 'participant_number' => 'KL003', 'attendance_code' => 'KJA-PLAC-A2', 'jenis_peserta' => 'Wajib']);

    expect(PlacementService::generateParticipantNumber($eventB->id, 'Laki - Laki'))->toBe('KL001');
});