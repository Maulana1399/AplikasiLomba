<?php

use App\Enums\Role;
use App\Livewire\Competition\ParticipantList;
use App\Livewire\Competition\Reregistration;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionOutcome;
use App\Models\CompetitionRegistration;
use App\Models\desa;
use App\Models\Event;
use App\Models\kelompok;
use App\Models\Participation;
use App\Models\Person;
use App\Models\User;
use App\Support\ActiveEventContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: Event, 1: CompetitionCategory, 2: CompetitionClass}
 */
function rr_lomba(string $name, array $eventOverrides = []): array
{
    $suffix = str()->random(6);

    $event = Event::create(array_merge([
        'name' => $name,
        'slug' => 'rr-'.str()->slug($name).'-'.$suffix,
        'event_type' => 'competition',
        'status' => 'active',
    ], $eventOverrides));

    $category = CompetitionCategory::create([
        'event_id' => $event->id,
        'name' => 'Kategori '.$name.' '.$suffix,
        'is_active' => true,
    ]);
    $category->events()->attach($event);

    $class = CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => $name.' - Paud',
        'code' => null,
        'gender' => 'M',
        'format' => 'individual_mass',
        'status' => 'registration_open',
        'is_active' => true,
    ]);

    return [$event, $category, $class];
}

function rr_person(string $nama, array $overrides = []): Person
{
    return Person::create(array_merge([
        'nama' => $nama,
        'jenis_kelamin' => 'L',
        'kelas' => 'PAUD',
    ], $overrides));
}

/**
 * @param  array{0: Event, 1: CompetitionCategory, 2: CompetitionClass}  $lomba
 */
function rr_enroll(Person $person, array $lomba, string $number): Participation
{
    [$event, $category, $class] = $lomba;

    $participation = Participation::create([
        'person_id' => $person->id,
        'event_id' => $event->id,
        'participant_number' => $number,
        'jenis_peserta' => 'Peserta',
    ]);

    CompetitionRegistration::create([
        'participation_id' => $participation->id,
        'competition_category_id' => $category->id,
        'competition_class_id' => $class->id,
        'registration_type' => 'individual',
    ]);

    return $participation;
}

function rr_page(): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::actingAs(User::factory()->create(['role' => Role::Admin]))
        ->test(Reregistration::class);
}

beforeEach(function () {
    [$this->mewarnai, $this->catM, $this->clsM] = rr_lomba('Mewarnai');
    [$this->cerdas, $this->catC, $this->clsC] = rr_lomba('Cerdas Cermat');
    [$this->dakwah, $this->catD, $this->clsD] = rr_lomba('Dakwah');

    // Bug ticket: event aktif = Mewarnai (event pertama), hence scoping to it hid
    // participants dari lomba lain.
    app(ActiveEventContext::class)->set($this->mewarnai);

    $this->desa = desa::create(['desa_asal' => 'Batam', 'sort_order' => 1]);
    $this->otherDesa = desa::create(['desa_asal' => 'Sepinggan', 'sort_order' => 2]);
    $this->kelompok = kelompok::create(['kelompok_asal' => 'Km 7', 'desa_id' => $this->desa->id, 'sort_order' => 1]);
});

test('peserta yang hanya punya registrasi Mewarnai muncul', function () {
    $person = rr_person('UAT Mewarnai Satu');
    rr_enroll($person, [$this->mewarnai, $this->catM, $this->clsM], 'KL001');

    rr_page()
        ->set('search', 'UAT Mewarnai')
        ->assertSee('UAT Mewarnai Satu')
        ->assertSee('Mewarnai - Paud');
});

test('peserta yang hanya punya registrasi lomba lain muncul', function () {
    $person = rr_person('UAT Cerdas Satu');
    rr_enroll($person, [$this->cerdas, $this->catC, $this->clsC], 'KL001');

    rr_page()
        ->set('search', 'UAT Cerdas')
        ->assertSee('UAT Cerdas Satu')
        ->assertSee('Cerdas Cermat - Paud');
});

test('peserta dengan beberapa lomba muncul satu card dan menampilkan semua lomba', function () {
    $person = rr_person('UAT Multi Satu');
    rr_enroll($person, [$this->mewarnai, $this->catM, $this->clsM], 'KL001');
    rr_enroll($person, [$this->cerdas, $this->catC, $this->clsC], 'KL002');
    rr_enroll($person, [$this->dakwah, $this->catD, $this->clsD], 'KL003');

    $html = rr_page()->set('search', 'UAT Multi')->html();

    expect(substr_count($html, 'result-'.$person->id))->toBe(1)
        ->and(substr_count($html, 'UAT Multi Satu'))->toBe(1);

    $page = rr_page()->set('search', 'UAT Multi');
    $page->assertSee('Mewarnai - Paud')
        ->assertSee('Cerdas Cermat - Paud')
        ->assertSee('Dakwah - Paud');
});

test('search bersifat case-insensitive', function () {
    $person = rr_person('UAT Case Sensitive');
    rr_enroll($person, [$this->cerdas, $this->catC, $this->clsC], 'KL001');

    rr_page()
        ->set('search', 'uat CASE sensitive')
        ->assertSee('UAT Case Sensitive');
});

test('peserta dari event non-competition atau event nonaktif tidak muncul', function () {
    [$pengajian, $catP, $clsP] = rr_lomba('Pengajian', ['event_type' => 'pengajian']);
    $personPengajian = rr_person('UAT Pengajian Satu');
    rr_enroll($personPengajian, [$pengajian, $catP, $clsP], 'KL001');

    [$nonaktif, $catN, $clsN] = rr_lomba('Lomba Nonaktif', ['status' => 'inactive']);
    $personNonaktif = rr_person('UAT Nonaktif Satu');
    rr_enroll($personNonaktif, [$nonaktif, $catN, $clsN], 'KL001');

    rr_page()
        ->set('search', 'UAT')
        ->assertDontSee('UAT Pengajian Satu')
        ->assertDontSee('UAT Nonaktif Satu');
});

test('status daftar ulang disimpan per Participation dan tetap muncul setelah daftar ulang', function () {
    $person = rr_person('UAT Status Satu');
    $mewarnaiParticipation = rr_enroll($person, [$this->mewarnai, $this->catM, $this->clsM], 'KL001');
    $cerdasParticipation = rr_enroll($person, [$this->cerdas, $this->catC, $this->clsC], 'KL002');

    rr_page()
        ->call('select', $person->id)
        ->call('markPresent')
        ->assertSee('Sudah Daftar Ulang');

    expect($mewarnaiParticipation->fresh()->status_registrasi)
        ->toBe(Participation::STATUS_SUDAH_DAFTAR_ULANG)
        ->and($cerdasParticipation->fresh()->status_registrasi)
        ->toBe(Participation::STATUS_SUDAH_DAFTAR_ULANG);

    rr_page()
        ->set('search', 'UAT Status')
        ->assertSee('UAT Status Satu')
        ->assertSee('Sudah Daftar Ulang');
});

test('menandai hadir dua kali tidak menduplikasi dan tidak menyentuh hasil lomba', function () {
    $person = rr_person('UAT Idempotent Satu');
    rr_enroll($person, [$this->mewarnai, $this->catM, $this->clsM], 'KL001');

    $page = rr_page()->call('select', $person->id);
    $page->call('markPresent');
    $page->call('markPresent');

    expect(Participation::count())->toBe(1)
        ->and(CompetitionRegistration::count())->toBe(1)
        ->and(CompetitionOutcome::count())->toBe(0);
});

test('peserta yang sudah daftar ulang sebelumnya tetap muncul', function () {
    $person = rr_person('UAT Sudah Satu');
    $participation = rr_enroll($person, [$this->dakwah, $this->catD, $this->clsD], 'KL001');
    $participation->update(['status_registrasi' => Participation::STATUS_SUDAH_DAFTAR_ULANG]);

    rr_page()
        ->set('search', 'UAT Sudah')
        ->assertSee('UAT Sudah Satu')
        ->assertSee('Sudah Daftar Ulang');
});

test('edit mengubah data peserta, mempertahankan status dan nomor peserta', function () {
    $person = rr_person('UAT Edit Satu', [
        'desa_id' => $this->desa->id,
        'kelompok_id' => $this->kelompok->id,
    ]);
    $participation = rr_enroll($person, [$this->mewarnai, $this->catM, $this->clsM], 'KL001');
    $participation->update(['status_registrasi' => Participation::STATUS_SUDAH_DAFTAR_ULANG]);
    $otherKelompok = kelompok::create(['kelompok_asal' => 'Km 8', 'desa_id' => $this->otherDesa->id, 'sort_order' => 2]);

    rr_page()
        ->call('select', $person->id)
        ->call('startEdit')
        ->set('editNama', 'UAT Edit Baru')
        ->set('editKelas', 'SMP')
        ->set('editJenisKelamin', 'P')
        ->set('editDesaId', (string) $this->otherDesa->id)
        ->set('editKelompokId', (string) $otherKelompok->id)
        ->call('saveEdit')
        ->assertHasNoErrors()
        ->assertSee('UAT Edit Baru');

    $fresh = $participation->fresh();

    expect($person->fresh()->nama)->toBe('UAT Edit Baru')
        ->and($person->fresh()->kelas)->toBe('SMP')
        ->and($person->fresh()->desa_id)->toBe($this->otherDesa->id)
        ->and($fresh->status_registrasi)->toBe(Participation::STATUS_SUDAH_DAFTAR_ULANG)
        ->and($fresh->participant_number)->toBe('KL001');
});

test('select menolak person yang tidak punya participation pada lomba aktif', function () {
    [$pengajian, $catP, $clsP] = rr_lomba('Pengajian Dua', ['event_type' => 'pengajian']);
    $person = rr_person('UAT Luar Satu');
    rr_enroll($person, [$pengajian, $catP, $clsP], 'KL001');

    rr_page()
        ->call('select', $person->id)
        ->assertSet('selectedPersonId', null);
});

test('pencarian tidak menimbulkan N+1 saat jumlah peserta bertambah', function () {
    rr_enroll(rr_person('N1 Peserta Satu'), [$this->mewarnai, $this->catM, $this->clsM], 'KL001');

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $before = $queries;
    rr_page()->set('search', 'N1 Peserta');
    $onePerson = $queries - $before;

    foreach (range(2, 6) as $i) {
        rr_enroll(rr_person('N1 Peserta '.$i), [$this->cerdas, $this->catC, $this->clsC], 'KL10'.$i);
    }

    $before = $queries;
    rr_page()->set('search', 'N1 Peserta');
    $manyPersons = $queries - $before;

    expect($manyPersons)->toBe($onePerson);
});

test('halaman registrasi ulang dapat diakses dan tampil di sidebar', function () {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));

    $this->get(route('competition.reregistration'))
        ->assertOk()
        ->assertSee('Cari nama peserta')
        ->assertSee('Registrasi Ulang');
});

test('daftar peserta tetap menampilkan status daftar ulang dari data tersimpan', function () {
    $reregistered = rr_person('UAT Terdaftar Satu');
    $participation = rr_enroll($reregistered, [$this->mewarnai, $this->catM, $this->clsM], 'KL001');
    $participation->update(['status_registrasi' => Participation::STATUS_SUDAH_DAFTAR_ULANG]);

    Livewire::actingAs(User::factory()->create(['role' => Role::Admin]))
        ->test(ParticipantList::class)
        ->set('competitionId', (string) $this->mewarnai->id)
        ->set('competitionCategoryId', 'all')
        ->assertSee('UAT Terdaftar Satu')
        ->assertSee('Sudah Daftar Ulang');
});
