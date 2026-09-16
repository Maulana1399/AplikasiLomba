<?php

use App\Enums\Role;
use App\Livewire\Competition\Desa\Index as DesaIndex;
use App\Livewire\Competition\Kelompok\Index as KelompokIndex;
use App\Livewire\Competition\ParticipantClass\Index as ParticipantClassIndex;
use App\Models\desa;
use App\Models\kelompok;
use App\Models\MasterParticipantClass;
use App\Models\User;
use Livewire\Livewire;

function dd_admin(): User
{
    return User::factory()->create(['role' => Role::Admin]);
}

// ---------------------------------------------------------------------------
// MasterParticipantClass CRUD
// ---------------------------------------------------------------------------

test('create participant class via settings', function () {
    Livewire::actingAs(dd_admin())
        ->test(ParticipantClassIndex::class)
        ->set('newName', 'SMP1')
        ->set('newCode', 'SMP1')
        ->set('newSortOrder', '8')
        ->call('create')
        ->assertHasNoErrors();

    expect(MasterParticipantClass::where('name', 'SMP1')->exists())->toBeTrue();

    $row = MasterParticipantClass::where('name', 'SMP1')->first();
    expect($row->code)->toBe('SMP1');
    expect($row->sort_order)->toBe(8);
    expect($row->is_active)->toBeTrue();
});

test('edit participant class via settings', function () {
    $cls = MasterParticipantClass::create(['name' => 'TK', 'code' => 'TK', 'sort_order' => 1]);

    Livewire::actingAs(dd_admin())
        ->test(ParticipantClassIndex::class)
        ->call('edit', $cls->id)
        ->set('editName', 'TK Modif')
        ->call('update')
        ->assertHasNoErrors();

    expect($cls->fresh()->name)->toBe('TK Modif');
});

test('toggle participant class inactive', function () {
    $cls = MasterParticipantClass::create(['name' => 'SD1', 'code' => 'SD1', 'sort_order' => 2]);

    Livewire::actingAs(dd_admin())
        ->test(ParticipantClassIndex::class)
        ->call('toggleActive', $cls->id);

    expect($cls->fresh()->is_active)->toBeFalse();
});

test('participant class name must be unique', function () {
    MasterParticipantClass::create(['name' => 'SD2', 'code' => 'SD2', 'sort_order' => 3]);

    Livewire::actingAs(dd_admin())
        ->test(ParticipantClassIndex::class)
        ->set('newName', 'SD2')
        ->call('create')
        ->assertHasErrors(['newName']);
});

// ---------------------------------------------------------------------------
// Desa CRUD
// ---------------------------------------------------------------------------

test('create desa via settings', function () {
    Livewire::actingAs(dd_admin())
        ->test(DesaIndex::class)
        ->set('newName', 'Batam')
        ->set('newSortOrder', '1')
        ->call('create')
        ->assertHasNoErrors();

    expect(desa::where('desa_asal', 'Batam')->exists())->toBeTrue();
});

test('edit desa via settings', function () {
    $d = desa::create(['desa_asal' => 'Ringroad', 'sort_order' => 1]);

    Livewire::actingAs(dd_admin())
        ->test(DesaIndex::class)
        ->call('edit', $d->id)
        ->set('editName', 'Ringroad Baru')
        ->call('update')
        ->assertHasNoErrors();

    expect($d->fresh()->desa_asal)->toBe('Ringroad Baru');
});

test('toggle desa inactive', function () {
    $d = desa::create(['desa_asal' => 'Sepinggan', 'sort_order' => 2]);

    Livewire::actingAs(dd_admin())
        ->test(DesaIndex::class)
        ->call('toggleActive', $d->id);

    expect($d->fresh()->is_active)->toBeFalse();
});

test('desa name must be unique', function () {
    desa::create(['desa_asal' => 'Batam']);

    Livewire::actingAs(dd_admin())
        ->test(DesaIndex::class)
        ->set('newName', 'Batam')
        ->call('create')
        ->assertHasErrors(['newName']);
});

// ---------------------------------------------------------------------------
// Kelompok CRUD
// ---------------------------------------------------------------------------

test('create kelompok scoped to desa', function () {
    $d = desa::create(['desa_asal' => 'Batam']);

    Livewire::actingAs(dd_admin())
        ->test(KelompokIndex::class)
        ->set('newName', 'Kel-A')
        ->set('newDesaId', (string) $d->id)
        ->set('newSortOrder', '1')
        ->call('create')
        ->assertHasNoErrors();

    $k = kelompok::where('kelompok_asal', 'Kel-A')->first();
    expect($k)->not->toBeNull();
    expect($k->desa_id)->toBe($d->id);
    expect($k->is_active)->toBeTrue();
});

test('edit kelompok via settings', function () {
    $d = desa::create(['desa_asal' => 'Batam']);
    $k = kelompok::create(['kelompok_asal' => 'Kel-B', 'desa_id' => $d->id, 'sort_order' => 2]);

    Livewire::actingAs(dd_admin())
        ->test(KelompokIndex::class)
        ->call('edit', $k->id)
        ->set('editName', 'Kel-B Modif')
        ->call('update')
        ->assertHasNoErrors();

    expect($k->fresh()->kelompok_asal)->toBe('Kel-B Modif');
});

test('toggle kelompok inactive', function () {
    $d = desa::create(['desa_asal' => 'Batam']);
    $k = kelompok::create(['kelompok_asal' => 'Kel-C', 'desa_id' => $d->id]);

    Livewire::actingAs(dd_admin())
        ->test(KelompokIndex::class)
        ->call('toggleActive', $k->id);

    expect($k->fresh()->is_active)->toBeFalse();
});

test('kelompok name must be unique per desa', function () {
    $d = desa::create(['desa_asal' => 'Batam']);
    kelompok::create(['kelompok_asal' => 'Kel-D', 'desa_id' => $d->id]);

    Livewire::actingAs(dd_admin())
        ->test(KelompokIndex::class)
        ->set('newName', 'Kel-D')
        ->set('newDesaId', (string) $d->id)
        ->call('create')
        ->assertHasErrors(['newName']);

    $other = desa::create(['desa_asal' => 'Ringroad']);

    Livewire::actingAs(dd_admin())
        ->test(KelompokIndex::class)
        ->set('newName', 'Kel-D')
        ->set('newDesaId', (string) $other->id)
        ->call('create')
        ->assertHasNoErrors();

    expect(kelompok::where('kelompok_asal', 'Kel-D')->count())->toBe(2);
});

test('sort order is persisted for all master data', function () {
    Livewire::actingAs(dd_admin())
        ->test(ParticipantClassIndex::class)
        ->set('newName', 'TK')
        ->set('newSortOrder', '1')
        ->call('create')
        ->assertHasNoErrors();

    expect(MasterParticipantClass::where('name', 'TK')->first()->sort_order)->toBe(1);

    $d = desa::create(['desa_asal' => 'Test Desa', 'sort_order' => 0]);

    Livewire::actingAs(dd_admin())
        ->test(DesaIndex::class)
        ->call('edit', $d->id)
        ->set('editSortOrder', '5')
        ->call('update')
        ->assertHasNoErrors();

    expect($d->fresh()->sort_order)->toBe(5);
});
