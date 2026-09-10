<?php

use App\Models\Event;
use App\Models\Participation;
use App\Models\Person;
use App\Models\peserta;
use App\Services\Competition\CompetitionRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(Tests\TestCase::class, RefreshDatabase::class);

afterEach(function () {
    Str::createRandomStringsNormally();
});

test('generate attendance code uses the existing KJA format', function () {
    Str::createRandomStringsUsing(fn () => 'abc123xy');

    expect(app(CompetitionRegistrationService::class)->generateAttendanceCode())->toBe('KJA-ABC123XY');
});

test('generate attendance code skips existing codes', function () {
    $event = Event::create(['name' => 'Registration Event Code', 'slug' => 'registration-event-code', 'status' => 'active']);
    $person = Person::create(['nama' => 'Peserta Existing', 'jenis_kelamin' => 'L']);
    Participation::create([
        'person_id' => $person->id,
        'event_id' => $event->id,
        'participant_number' => 'KL001',
        'attendance_code' => 'KJA-AAAAAAAA',
        'jenis_peserta' => peserta::JENIS_WAJIB,
    ]);

    Str::createRandomStringsUsingSequence(['AAAAAAAA', 'BBBBBBBB']);

    expect(app(CompetitionRegistrationService::class)->generateAttendanceCode())->toBe('KJA-BBBBBBBB');
});