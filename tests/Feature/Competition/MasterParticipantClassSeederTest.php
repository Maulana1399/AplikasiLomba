<?php

use App\Models\MasterParticipantClass;
use Database\Seeders\MasterParticipantClassSeeder;

$expected = [
    'PAUD',
    'SD 1',
    'SD 2',
    'SD 3',
    'SD 4',
    'SD 5',
    'SD 6',
    'SMP',
    'SMU',
    'Dewasa',
];

it('seeds 10 default master participant classes', function () use ($expected) {
    $this->seed(MasterParticipantClassSeeder::class);

    expect(MasterParticipantClass::count())->toBe(10);

    $names = MasterParticipantClass::orderBy('sort_order')->pluck('name')->all();
    expect($names)->toBe($expected);
});

it('assigns sort_order 1 through 10 in order', function () {
    $this->seed(MasterParticipantClassSeeder::class);

    $rows = MasterParticipantClass::orderBy('sort_order')->get();

    foreach ($rows as $index => $row) {
        expect($row->sort_order)->toBe($index + 1);
    }
});

it('sets code equal to name for every seeded class', function () {
    $this->seed(MasterParticipantClassSeeder::class);

    MasterParticipantClass::each(function ($row) {
        expect($row->code)->toBe($row->name);
    });
});

it('is idempotent — running twice produces no duplicates', function () {
    $this->seed(MasterParticipantClassSeeder::class);
    $this->seed(MasterParticipantClassSeeder::class);

    expect(MasterParticipantClass::count())->toBe(10);
});

it('preserves operator changes on re-seed', function () {
    $this->seed(MasterParticipantClassSeeder::class);

    $smp = MasterParticipantClass::where('name', 'SMP')->first();
    expect($smp)->not->toBeNull();

    $smp->update(['code' => 'SMP1A', 'sort_order' => 99, 'is_active' => false]);

    $this->seed(MasterParticipantClassSeeder::class);

    expect(MasterParticipantClass::count())->toBe(10);

    $smpAfter = MasterParticipantClass::where('name', 'SMP')->first();
    expect($smpAfter->code)->toBe('SMP1A');
    expect($smpAfter->sort_order)->toBe(99);
    expect($smpAfter->is_active)->toBeFalse();
});

it('all classes are active by default', function () {
    $this->seed(MasterParticipantClassSeeder::class);

    MasterParticipantClass::each(function ($row) {
        expect($row->is_active)->toBeTrue();
    });
});

it('covers all required age brackets', function () use ($expected) {
    $this->seed(MasterParticipantClassSeeder::class);

    $names = MasterParticipantClass::pluck('name')->sort()->values()->all();
    $expectedSorted = collect($expected)->sort()->values()->all();

    expect($names)->toBe($expectedSorted);
});
