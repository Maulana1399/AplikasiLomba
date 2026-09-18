<?php

use App\Enums\Role;
use App\Livewire\Competition\Category\Index as CategoryIndex;
use App\Livewire\Competition\Class\Index as ClassIndex;
use App\Livewire\Competition\Registration;
use App\Models\CompetitionCategory;
use App\Models\CompetitionCategoryExclusive;
use App\Models\CompetitionClass;
use App\Models\CompetitionSchedule;
use App\Models\Event;
use App\Models\User;
use App\Support\ActiveEventContext;
use App\Support\CompetitionFormat;
use Livewire\Livewire;

function setting_event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'Event Setting '.str()->random(6),
        'slug' => 'setting-'.str()->random(6),
        'event_type' => 'competition',
        'status' => 'active',
    ], $overrides));
}

function setting_category(Event $event, array $overrides = []): CompetitionCategory
{
    $category = CompetitionCategory::create(array_merge([
        'event_id' => $event->id,
        'name' => 'Kategori Setting '.str()->random(6),
        'code' => strtoupper(str()->random(6)),
    ], $overrides));


    return $category;
}

function setting_class(Event $event, CompetitionCategory $category, array $overrides = []): CompetitionClass
{
    return CompetitionClass::create(array_merge([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => 'SD2',
        'gender' => 'L',
        'format' => CompetitionFormat::INDIVIDUAL_MASS,
        'code' => strtoupper(str()->random(4)),
    ], $overrides));
}

function setting_admin(): User
{
    return User::factory()->create(['role' => Role::Admin]);
}

// ---------------------------------------------------------------------------
// Category settings
// ---------------------------------------------------------------------------

test('category is created via settings and scoped to the active event', function () {
    $event = setting_event();
    app(ActiveEventContext::class)->set($event);
    $user = setting_admin();

    Livewire::actingAs($user)
        ->test(CategoryIndex::class)
        ->set('newName', 'Adzan')
        ->set('newCode', 'ADZ')
        ->set('newSortOrder', '1')
        ->call('create')
        ->assertHasNoErrors();

    $category = CompetitionCategory::where('event_id', $event->id)->first();

    expect($category)->not->toBeNull()
        ->and($category->name)->toBe('Adzan')
        ->and($category->code)->toBe('ADZ')
        ->and($category->is_active)->toBeTrue();
});

test('category can be edited and toggled inactive', function () {
    $event = setting_event();
    app(ActiveEventContext::class)->set($event);
    $user = setting_admin();
    $category = setting_category($event, ['name' => 'Adzan']);

    Livewire::actingAs($user)
        ->test(CategoryIndex::class)
        ->call('edit', $category->id)
        ->set('editName', 'Murotal')
        ->call('update')
        ->assertHasNoErrors();

    expect($category->fresh()->name)->toBe('Murotal');

    Livewire::actingAs($user)
        ->test(CategoryIndex::class)
        ->call('toggleActive', $category->id);

    expect($category->fresh()->is_active)->toBeFalse();
});

test('category name must be unique within the same event', function () {
    $event = setting_event();
    app(ActiveEventContext::class)->set($event);
    $user = setting_admin();
    setting_category($event, ['name' => 'Adzan']);

    Livewire::actingAs($user)
        ->test(CategoryIndex::class)
        ->set('newName', 'Adzan')
        ->call('create')
        ->assertHasErrors(['newName']);

    expect(CompetitionCategory::where('event_id', $event->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Category exclusivity
// ---------------------------------------------------------------------------

test('category exclusivity is saved bidirectionally from settings', function () {
    $event = setting_event();
    app(ActiveEventContext::class)->set($event);
    $user = setting_admin();
    $catA = setting_category($event, ['name' => 'Adzan']);
    $catB = setting_category($event, ['name' => 'Murotal']);

    Livewire::actingAs($user)
        ->test(CategoryIndex::class)
        ->call('edit', $catA->id)
        ->set('editExclusiveIds', [$catB->id])
        ->call('update')
        ->assertHasNoErrors();

    expect(CompetitionCategoryExclusive::count())->toBe(2);
    expect(CompetitionCategoryExclusive::where('competition_category_id', $catA->id)->where('exclusive_with_category_id', $catB->id)->exists())->toBeTrue();
    expect(CompetitionCategoryExclusive::where('competition_category_id', $catB->id)->where('exclusive_with_category_id', $catA->id)->exists())->toBeTrue();
});

test('exclusivity is shown from both sides and not duplicated on re-save', function () {
    $event = setting_event();
    app(ActiveEventContext::class)->set($event);
    $user = setting_admin();
    $catA = setting_category($event, ['name' => 'Adzan']);
    $catB = setting_category($event, ['name' => 'Murotal']);

    Livewire::actingAs($user)
        ->test(CategoryIndex::class)
        ->call('edit', $catA->id)
        ->set('editExclusiveIds', [$catB->id])
        ->call('update');

    // Editing the other side shows the same exclusivity.
    Livewire::actingAs($user)
        ->test(CategoryIndex::class)
        ->call('edit', $catB->id)
        ->assertSet('editExclusiveIds', [$catA->id]);

    // Re-saving does not create duplicate pivot rows.
    Livewire::actingAs($user)
        ->test(CategoryIndex::class)
        ->call('edit', $catA->id)
        ->set('editExclusiveIds', [$catB->id])
        ->call('update')
        ->assertHasNoErrors();

    expect(CompetitionCategoryExclusive::count())->toBe(2);
});

test('category cannot be exclusive with itself', function () {
    $event = setting_event();
    app(ActiveEventContext::class)->set($event);
    $user = setting_admin();
    $catA = setting_category($event, ['name' => 'Adzan']);
    $catB = setting_category($event, ['name' => 'Murotal']);

    Livewire::actingAs($user)
        ->test(CategoryIndex::class)
        ->call('edit', $catA->id)
        ->set('editExclusiveIds', [$catA->id])
        ->call('update')
        ->assertHasErrors(['editExclusiveIds']);

    expect(CompetitionCategoryExclusive::count())->toBe(0);
    expect($catB->fresh())->toBeTruthy();
});

test('category exclusivity is restricted to the same event', function () {
    $event = setting_event();
    $otherEvent = setting_event();
    app(ActiveEventContext::class)->set($event);
    $user = setting_admin();
    $catA = setting_category($event, ['name' => 'Adzan']);
    $foreign = setting_category($otherEvent, ['name' => 'Kategori Asing']);

    Livewire::actingAs($user)
        ->test(CategoryIndex::class)
        ->call('edit', $catA->id)
        ->set('editExclusiveIds', [$foreign->id])
        ->call('update')
        ->assertHasErrors(['editExclusiveIds']);

    expect(CompetitionCategoryExclusive::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Class settings
// ---------------------------------------------------------------------------

test('class is created via settings with format and default winner_count', function () {
    $event = setting_event();
    app(ActiveEventContext::class)->set($event);
    $user = setting_admin();
    $category = setting_category($event);

    Livewire::actingAs($user)
        ->test(ClassIndex::class)
        ->set('newCompetitionCategoryId', (string) $category->id)
        ->set('newName', 'SD2')
        ->set('newGender', 'L')
        ->set('newFormat', CompetitionFormat::INDIVIDUAL_MASS)
        ->call('create')
        ->assertHasNoErrors();

    $class = CompetitionClass::where('event_id', $event->id)->first();

    expect($class)->not->toBeNull()
        ->and($class->competition_category_id)->toBe($category->id)
        ->and($class->gender)->toBe('L')
        ->and($class->format)->toBe(CompetitionFormat::INDIVIDUAL_MASS)
        ->and($class->winner_count)->toBe(3);
});

test('class gender accepts Laki-Laki, Perempuan and Campuran', function () {
    $event = setting_event();
    app(ActiveEventContext::class)->set($event);
    $user = setting_admin();
    $category = setting_category($event);

    foreach (['L', 'P', 'M'] as $index => $gender) {
        Livewire::actingAs($user)
            ->test(ClassIndex::class)
            ->set('newCompetitionCategoryId', (string) $category->id)
            ->set('newName', 'Kelas '.$index)
            ->set('newGender', $gender)
            ->set('newFormat', CompetitionFormat::INDIVIDUAL_MASS)
            ->call('create')
            ->assertHasNoErrors();
    }

    expect(CompetitionClass::where('event_id', $event->id)->pluck('gender')->sort()->values()->all())
        ->toHaveCount(3);
});

test('all five agreed formats can be selected in class settings', function () {
    $event = setting_event();
    app(ActiveEventContext::class)->set($event);
    $user = setting_admin();
    $category = setting_category($event);

    $formats = [
        CompetitionFormat::INDIVIDUAL_MASS,
        CompetitionFormat::INDIVIDUAL_VS_INDIVIDUAL,
        CompetitionFormat::TEAM_VS_TEAM,
        CompetitionFormat::TEAM_HEAT,
        'individual_scoring',
    ];

    foreach ($formats as $index => $format) {
        Livewire::actingAs($user)
            ->test(ClassIndex::class)
            ->set('newCompetitionCategoryId', (string) $category->id)
            ->set('newName', 'Kelas '.$index)
            ->set('newGender', 'M')
            ->set('newFormat', $format)
            ->set('newTeamSize', $format === CompetitionFormat::TEAM_HEAT ? '4' : '')
            ->call('create')
            ->assertHasNoErrors();
    }

    expect(CompetitionClass::where('event_id', $event->id)->count())->toBe(5);
});

test('individual scoring is stored as individual_mass with score result type', function () {
    $event = setting_event();
    app(ActiveEventContext::class)->set($event);
    $user = setting_admin();
    $category = setting_category($event);

    Livewire::actingAs($user)
        ->test(ClassIndex::class)
        ->set('newCompetitionCategoryId', (string) $category->id)
        ->set('newName', 'SD2')
        ->set('newGender', 'M')
        ->set('newFormat', 'individual_scoring')
        ->call('create')
        ->assertHasNoErrors();

    $class = CompetitionClass::where('event_id', $event->id)->first();

    expect($class->format)->toBe(CompetitionFormat::INDIVIDUAL_MASS)
        ->and($class->result_type)->toBe(\App\Support\CompetitionResultType::SCORE)
        ->and($class->resultType())->toBe(\App\Support\CompetitionResultType::SCORE);
});

test('all four result types can be selected in class settings', function () {
    $event = setting_event();
    app(ActiveEventContext::class)->set($event);
    $user = setting_admin();
    $category = setting_category($event);

    $resultTypes = [
        \App\Support\CompetitionResultType::SCORE,
        \App\Support\CompetitionResultType::TIME,
        \App\Support\CompetitionResultType::RANKING,
        \App\Support\CompetitionResultType::WIN_LOSS,
    ];

    foreach ($resultTypes as $index => $resultType) {
        Livewire::actingAs($user)
            ->test(ClassIndex::class)
            ->set('newCompetitionCategoryId', (string) $category->id)
            ->set('newName', 'Kelas '.$index)
            ->set('newGender', 'M')
            ->set('newFormat', CompetitionFormat::TEAM_HEAT)
            ->set('newTeamSize', '4')
            ->set('newResultType', $resultType)
            ->call('create')
            ->assertHasNoErrors();
    }

    expect(CompetitionClass::where('event_id', $event->id)->pluck('result_type')->unique()->values()->all())
        ->toContain(...$resultTypes);
});

test('UI Heat is stored internally as team_heat', function () {
    $event = setting_event();
    app(ActiveEventContext::class)->set($event);
    $user = setting_admin();
    $category = setting_category($event);

    Livewire::actingAs($user)
        ->test(ClassIndex::class)
        ->set('newCompetitionCategoryId', (string) $category->id)
        ->set('newName', 'Kelas Heat')
        ->set('newGender', 'M')
        ->set('newFormat', CompetitionFormat::TEAM_HEAT)
        ->set('newTeamSize', '4')
        ->call('create')
        ->assertHasNoErrors();

    $class = CompetitionClass::where('event_id', $event->id)->first();

    expect($class->format)->toBe(CompetitionFormat::TEAM_HEAT)
        ->and($class->team_size)->toBe(4);
});

test('Heat format requires team_size greater than 1 on create', function () {
    $event = setting_event();
    app(ActiveEventContext::class)->set($event);
    $user = setting_admin();
    $category = setting_category($event);

    foreach (['', '1', '0'] as $teamSize) {
        Livewire::actingAs($user)
            ->test(ClassIndex::class)
            ->set('newCompetitionCategoryId', (string) $category->id)
            ->set('newName', 'Kelas Heat')
            ->set('newGender', 'M')
            ->set('newFormat', CompetitionFormat::TEAM_HEAT)
            ->set('newTeamSize', $teamSize)
            ->call('create')
            ->assertHasErrors(['newTeamSize']);
    }

    expect(CompetitionClass::where('event_id', $event->id)->count())->toBe(0);
});

test('Heat format requires team_size greater than 1 on update', function () {
    $event = setting_event();
    app(ActiveEventContext::class)->set($event);
    $user = setting_admin();
    $category = setting_category($event);
    $class = setting_class($event, $category, ['format' => CompetitionFormat::TEAM_HEAT, 'team_size' => 4]);

    Livewire::actingAs($user)
        ->test(ClassIndex::class)
        ->call('edit', $class->id)
        ->set('editTeamSize', '1')
        ->call('update')
        ->assertHasErrors(['editTeamSize']);

    expect($class->fresh()->team_size)->toBe(4);
});

test('non-heat formats do not require team_size', function () {
    $event = setting_event();
    app(ActiveEventContext::class)->set($event);
    $user = setting_admin();
    $category = setting_category($event);

    Livewire::actingAs($user)
        ->test(ClassIndex::class)
        ->set('newCompetitionCategoryId', (string) $category->id)
        ->set('newName', 'Kelas Massa')
        ->set('newGender', 'M')
        ->set('newFormat', CompetitionFormat::INDIVIDUAL_MASS)
        ->set('newTeamSize', '')
        ->call('create')
        ->assertHasNoErrors();

    $class = CompetitionClass::where('event_id', $event->id)->first();

    expect($class->format)->toBe(CompetitionFormat::INDIVIDUAL_MASS)
        ->and($class->team_size)->toBeNull();
});

test('individual_heat and team_mass are not offered by the class UI', function () {
    $event = setting_event();
    app(ActiveEventContext::class)->set($event);
    $user = setting_admin();

    Livewire::actingAs($user)
        ->test(ClassIndex::class)
        ->assertSet('newFormat', '')
        ->assertDontSee('Individual Heat')
        ->assertDontSee('Team Mass');

    $options = app(ClassIndex::class)->formatOptions();

    expect(array_key_exists(CompetitionFormat::INDIVIDUAL_HEAT, $options))->toBeFalse()
        ->and(array_key_exists(CompetitionFormat::TEAM_MASS, $options))->toBeFalse()
        ->and($options[CompetitionFormat::TEAM_HEAT])->toBe('Heat');
});

test('individual_heat cannot be created via the class UI', function () {
    $event = setting_event();
    app(ActiveEventContext::class)->set($event);
    $user = setting_admin();
    $category = setting_category($event);

    Livewire::actingAs($user)
        ->test(ClassIndex::class)
        ->set('newCompetitionCategoryId', (string) $category->id)
        ->set('newName', 'Kelas Sala')
        ->set('newGender', 'M')
        ->set('newFormat', CompetitionFormat::INDIVIDUAL_HEAT)
        ->set('newTeamSize', '4')
        ->call('create')
        ->assertHasErrors(['newFormat']);

    expect(CompetitionClass::where('event_id', $event->id)->count())->toBe(0);
});

test('class can be edited via settings including winner_count', function () {
    $event = setting_event();
    app(ActiveEventContext::class)->set($event);
    $user = setting_admin();
    $category = setting_category($event);
    $class = setting_class($event, $category, ['name' => 'SD2', 'format' => CompetitionFormat::INDIVIDUAL_MASS]);

    Livewire::actingAs($user)
        ->test(ClassIndex::class)
        ->call('edit', $class->id)
        ->assertSet('editFormat', CompetitionFormat::INDIVIDUAL_MASS)
        ->set('editName', 'SMP1')
        ->set('editWinnerCount', '5')
        ->set('editGender', 'P')
        ->call('update')
        ->assertHasNoErrors();

    expect($class->fresh()->name)->toBe('SMP1')
        ->and($class->fresh()->winner_count)->toBe(5)
        ->and($class->fresh()->gender)->toBe('P');
});

test('class name must be unique within the same category', function () {
    $event = setting_event();
    app(ActiveEventContext::class)->set($event);
    $user = setting_admin();
    $category = setting_category($event);
    setting_class($event, $category, ['name' => 'SD2']);

    Livewire::actingAs($user)
        ->test(ClassIndex::class)
        ->set('newCompetitionCategoryId', (string) $category->id)
        ->set('newName', 'SD2')
        ->set('newGender', 'L')
        ->set('newFormat', CompetitionFormat::INDIVIDUAL_MASS)
        ->call('create')
        ->assertHasErrors(['newName']);

    expect(CompetitionClass::where('event_id', $event->id)->count())->toBe(1);
});

test('class cannot be created under a category from another event', function () {
    $event = setting_event();
    $otherEvent = setting_event();
    app(ActiveEventContext::class)->set($event);
    $user = setting_admin();
    $foreign = setting_category($otherEvent, ['name' => 'Kategori Asing']);

    Livewire::actingAs($user)
        ->test(ClassIndex::class)
        ->set('newCompetitionCategoryId', (string) $foreign->id)
        ->set('newName', 'SD2')
        ->set('newGender', 'L')
        ->set('newFormat', CompetitionFormat::INDIVIDUAL_MASS)
        ->call('create')
        ->assertHasErrors(['newCompetitionCategoryId']);

    expect(CompetitionClass::count())->toBe(0);
});

test('format cannot be changed after a schedule exists', function () {
    $event = setting_event();
    app(ActiveEventContext::class)->set($event);
    $user = setting_admin();
    $category = setting_category($event);
    $class = setting_class($event, $category, ['format' => CompetitionFormat::INDIVIDUAL_MASS]);

    CompetitionSchedule::create(['competition_class_id' => $class->id]);

    Livewire::actingAs($user)
        ->test(ClassIndex::class)
        ->call('edit', $class->id)
        ->set('editFormat', CompetitionFormat::INDIVIDUAL_HEAT)
        ->call('update')
        ->assertHasNoErrors();

    expect($class->fresh()->format)->toBe(CompetitionFormat::INDIVIDUAL_MASS);
});

// ---------------------------------------------------------------------------
// Offering to registration
// ---------------------------------------------------------------------------

test('inactive category is not offered when creating a class', function () {
    $event = setting_event();
    app(ActiveEventContext::class)->set($event);
    $user = setting_admin();

    $active = setting_category($event, ['name' => 'Kategori Aktif Unik', 'is_active' => true]);
    setting_category($event, ['name' => 'Kategori Nonaktif Unik', 'is_active' => false]);

    Livewire::actingAs($user)
        ->test(ClassIndex::class)
        ->call('toggleCreateForm')
        ->assertSee('Kategori Aktif Unik')
        ->assertDontSee('Kategori Nonaktif Unik');

    expect($active->fresh()->is_active)->toBeTrue();
});

test('inactive category and class are not offered in registration', function () {
    $event = setting_event();
    app(ActiveEventContext::class)->set($event);
    $user = setting_admin();

    \App\Models\desa::create(['desa_asal' => 'Desa Registrasi']);

    $activeCat = setting_category($event, ['name' => 'Kategori Aktif Reg', 'is_active' => true]);
    setting_category($event, ['name' => 'Kategori Nonaktif Reg', 'is_active' => false]);
    setting_class($event, $activeCat, ['name' => 'SD7', 'is_active' => true]);
    setting_class($event, $activeCat, ['name' => 'SD99', 'is_active' => false]);

    Livewire::actingAs($user)
        ->test(Registration::class)
        ->assertSee('Kategori Aktif Reg')
        ->assertDontSee('Kategori Nonaktif Reg')
        ->set('competitionCategoryId', (string) $activeCat->id)
        ->assertSee('SD7')
        ->assertDontSee('SD99');
});
