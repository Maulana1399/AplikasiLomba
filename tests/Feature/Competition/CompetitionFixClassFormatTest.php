<?php

use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\Event;
use App\Support\CompetitionFormat;

function fix_class_seed(?string $format, ?int $teamSize): CompetitionClass
{
    $event = Event::create([
        'name' => 'Event Fix '.str()->random(6),
        'slug' => 'fix-'.str()->random(6),
        'event_type' => 'competition',
        'status' => 'active',
    ]);

    $category = CompetitionCategory::create([
        'event_id' => $event->id,
        'name' => 'Kategori Fix '.str()->random(6),
        'code' => strtoupper(str()->random(6)),
    ]);


    return CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'Kelas Fix '.str()->random(6),
        'gender' => 'L',
        'format' => $format,
        'team_size' => $teamSize,
    ]);
}

test('fix class format dry run reports but does not modify individual_heat', function () {
    $class = fix_class_seed(CompetitionFormat::INDIVIDUAL_HEAT, 4);

    $this->artisan('competition:fix-class-format', ['--id' => $class->id])
        ->expectsOutputToContain('DRY RUN')
        ->assertExitCode(0);

    expect($class->fresh()->format)->toBe(CompetitionFormat::INDIVIDUAL_HEAT);
});

test('fix class format apply converts individual_heat to team_heat', function () {
    $class = fix_class_seed(CompetitionFormat::INDIVIDUAL_HEAT, 4);

    $this->artisan('competition:fix-class-format', ['--id' => $class->id, '--apply' => true])
        ->expectsOutputToContain('FIX OK')
        ->assertExitCode(0);

    expect($class->fresh()->format)->toBe(CompetitionFormat::TEAM_HEAT)
        ->and($class->fresh()->team_size)->toBe(4);
});

test('fix class format skips classes without a valid team_size', function () {
    $class = fix_class_seed(CompetitionFormat::INDIVIDUAL_HEAT, null);

    $this->artisan('competition:fix-class-format', ['--id' => $class->id, '--apply' => true])
        ->expectsOutputToContain('SKIP')
        ->assertExitCode(0);

    expect($class->fresh()->format)->toBe(CompetitionFormat::INDIVIDUAL_HEAT);
});

test('fix class format leaves non-individual_heat classes untouched', function () {
    $class = fix_class_seed(CompetitionFormat::INDIVIDUAL_MASS, null);

    $this->artisan('competition:fix-class-format', ['--apply' => true])
        ->assertExitCode(0);

    expect($class->fresh()->format)->toBe(CompetitionFormat::INDIVIDUAL_MASS);
});
