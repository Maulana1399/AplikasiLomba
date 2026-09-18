<?php

use App\Enums\Role;
use App\Livewire\Competition\ParticipantList;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionRegistration;
use App\Models\Event;
use App\Models\Participation;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function ple_event(string $name, array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => $name,
        'slug' => 'ple-'.str()->slug($name).'-'.str()->random(4),
        'event_type' => 'competition',
        'status' => 'active',
    ], $overrides));
}

function ple_category(Event $event, string $name, ?string $code = null): CompetitionCategory
{
    $category = CompetitionCategory::create([
        'event_id' => $event->id,
        'name' => $name,
        'code' => $code,
        'is_active' => true,
    ]);


    return $category;
}

function ple_class(Event $event, CompetitionCategory $category, string $name, ?string $code): CompetitionClass
{
    return CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => $name,
        'code' => $code,
        'gender' => 'M',
        'format' => 'individual_mass',
        'status' => 'registration_open',
        'is_active' => true,
    ]);
}

function ple_register(Event $event, CompetitionCategory $category, CompetitionClass $class, string $nama): CompetitionRegistration
{
    $person = Person::create(['nama' => $nama, 'jenis_kelamin' => 'L']);
    $participation = Participation::create([
        'person_id' => $person->id,
        'event_id' => $event->id,
        'participant_number' => 'PLE-'.$event->id.'-'.$person->id,
        'jenis_peserta' => 'Peserta',
    ]);

    return CompetitionRegistration::create([
        'participation_id' => $participation->id,
        'competition_category_id' => $category->id,
        'competition_class_id' => $class->id,
        'registration_type' => 'individual',
    ]);
}

function ple_page(): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::actingAs(User::factory()->create(['role' => Role::Admin]))
        ->test(ParticipantList::class);
}

test('lomba tanpa kode kelas (Mewarnai) muncul di dropdown bersama lomba ber-kode F26', function () {
    $adzan = ple_event('Adzan & Iqomah');
    $catA = ple_category($adzan, 'Dewasa');
    ple_class($adzan, $catA, 'Adzan - Dewasa', 'F26-'.$catA->id.'-L');

    $mewarnai = ple_event('Mewarnai', ['code' => 'lomba-1', 'sort_order' => 1]);
    $catM = ple_category($mewarnai, 'Paud - SD 3');
    ple_class($mewarnai, $catM, 'Mewarnai - Paud - SD 3', null);

    ple_page()
        ->assertSeeHtml('value="'.$adzan->id.'"')
        ->assertSeeHtml('value="'.$mewarnai->id.'"');
});

test('lomba tanpa kode kelas tetap muncul meski belum punya peserta', function () {
    $mewarnai = ple_event('Mewarnai');
    $catM = ple_category($mewarnai, 'Paud - SD 3');
    ple_class($mewarnai, $catM, 'Mewarnai - Paud - SD 3', null);

    expect(CompetitionRegistration::count())->toBe(0);

    ple_page()->assertSeeHtml('value="'.$mewarnai->id.'"');
});

test('event tanpa kelas aktif tidak muncul di dropdown', function () {
    $empty = ple_event('Lomba Kosong');
    $cat = ple_category($empty, 'Dewasa');
    $class = ple_class($empty, $cat, 'Kosong - Dewasa', null);
    $class->update(['is_active' => false]);

    ple_page()->assertDontSeeHtml('value="'.$empty->id.'"');
});

test('event non-competition dan event nonaktif tidak muncul di dropdown', function () {
    $pengajian = ple_event('Pengajian', ['event_type' => 'pengajian']);
    $catP = ple_category($pengajian, 'Dewasa Pengajian');
    ple_class($pengajian, $catP, 'Pengajian - Dewasa', null);

    $inactive = ple_event('Lomba Nonaktif', ['status' => 'inactive']);
    $catI = ple_category($inactive, 'Dewasa Nonaktif');
    ple_class($inactive, $catI, 'Nonaktif - Dewasa', null);

    ple_page()
        ->assertDontSeeHtml('value="'.$pengajian->id.'"')
        ->assertDontSeeHtml('value="'.$inactive->id.'"');
});

test('event berkelas bersandi uji eksplisit tetap dikecualikan', function () {
    $uat = ple_event('Khotbah');
    $catU = ple_category($uat, 'Dewasa');
    ple_class($uat, $catU, 'Khotbah - Dewasa', 'uat-khotbah-dewasa');

    ple_page()->assertDontSeeHtml('value="'.$uat->id.'"');
});

test('memilih Mewarnai menampilkan peserta dan filter kategori-kelas bekerja', function () {
    $mewarnai = ple_event('Mewarnai');
    $catM = ple_category($mewarnai, 'Paud - SD 3');
    $classM = ple_class($mewarnai, $catM, 'Mewarnai - Paud - SD 3', null);
    ple_register($mewarnai, $catM, $classM, 'UAT PAUD 01');

    ple_page()
        ->set('competitionId', (string) $mewarnai->id)
        ->assertSee('Mewarnai')
        ->assertSee('Paud - SD 3')
        ->assertSee('UAT PAUD 01')
        ->set('competitionCategoryId', (string) $catM->id)
        ->assertSee('Mewarnai - Paud - SD 3');
});
