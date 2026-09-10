<?php

use App\Models\MasterParticipantClass;
use Database\Seeders\MasterParticipantClassSeeder;

$expected = [
    'TK',
    'SD1',
    'SD2',
    'SD3',
    'SD4',
    'SD5',
    'SD6',
    'SMP1',
    'SMP2',
    'SMP3',
    'Dewasa',
];

it('seeds 11 default master participant classes', function () use ($expected) {
    $this->seed(MasterParticipantClassSeeder::class);

    expect(MasterParticipantClass::count())->toBe(11);

    $names = MasterParticipantClass::orderBy('sort_order')->pluck('name')->all();
    expect($names)->toBe($expected);
});

it('assigns sort_order 1 through 11 in order', function () {
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

    expect(MasterParticipantClass::count())->toBe(11);
});

it('preserves operator changes on re-seed', function () {
    $this->seed(MasterParticipantClassSeeder::class);

    $smp1 = MasterParticipantClass::where('name', 'SMP1')->first();
    expect($smp1)->not->toBeNull();

    $smp1->update(['code' => 'SMP1A', 'sort_order' => 99, 'is_active' => false]);

    $this->seed(MasterParticipantClassSeeder::class);

    expect(MasterParticipantClass::count())->toBe(11);

    $smp1After = MasterParticipantClass::where('name', 'SMP1')->first();
    expect($smp1After->code)->toBe('SMP1A');
    expect($smp1After->sort_order)->toBe(99);
    expect($smp1After->is_active)->toBeFalse();
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
