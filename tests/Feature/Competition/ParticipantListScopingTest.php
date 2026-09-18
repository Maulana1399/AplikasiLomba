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

/*
 * Event FASDA: kelas-nya memakai code berawalan "F26-" (persis seperti
 * yang ditulis Fasda2026Seeder), sehingga MASUK dropdown Lomba.
 */
function pl_fasda_event(string $name): Event
{
    return Event::create([
        'name' => $name,
        'slug' => 'pl-'.str()->slug($name).'-fasda-2026',
        'event_type' => 'competition',
        'status' => 'active',
    ]);
}

/*
 * Event NON-FASDA (UAT/dummy): kelas tanpa code "F26-" — TIDAK boleh
 * muncul di dropdown Lomba walaupun namanya sama dengan lomba FASDA.
 */
function pl_uat_event(string $name): Event
{
    return Event::create([
        'name' => $name,
        'slug' => 'pl-uat-'.str()->slug($name),
        'event_type' => 'competition',
        'status' => 'active',
    ]);
}

function pl_category(Event $event, string $name): CompetitionCategory
{
    $category = CompetitionCategory::create([
        'event_id' => $event->id,
        'name' => $name,
        'is_active' => true,
    ]);


    return $category;
}

function pl_class(Event $event, CompetitionCategory $category, string $name, ?string $code = null): CompetitionClass
{
    return CompetitionClass::create([
        'event_id' => $event->id,
        'competition_category_id' => $category->id,
        'name' => $name,
        'code' => $code ?? 'F26-'.$category->id.'-L',
        'gender' => 'L',
        'format' => 'individual_heat',
        'status' => 'registration_open',
        'is_active' => true,
    ]);
}

function pl_participant(Event $event, string $nama): Participation
{
    $person = Person::create(['nama' => $nama, 'jenis_kelamin' => 'L']);

    return Participation::create([
        'person_id' => $person->id,
        'event_id' => $event->id,
        'participant_number' => 'PL-'.$event->id.'-'.$person->id,
        'jenis_peserta' => 'Peserta',
    ]);
}

function pl_register(Event $event, CompetitionCategory $category, CompetitionClass $class, Participation $participation): CompetitionRegistration
{
    return CompetitionRegistration::create([
        'participation_id' => $participation->id,
        'competition_category_id' => $category->id,
        'competition_class_id' => $class->id,
        'registration_type' => 'individual',
    ]);
}

/*
 * Dua event SAMA NAMA "Khotbah":
 * - FASDA (F26 class) = lomba asli, HARUS terpilih di dropdown.
 * - UAT (class tanpa F26) = tidak boleh muncul di dropdown / dipilih.
 */
function pl_fasda_uat_khotbah_world(): array
{
    $fasdaKhotbah = pl_fasda_event('Khotbah');
    $uatKhotbah = pl_uat_event('Khotbah');

    $catF = pl_category($fasdaKhotbah, 'Kategori Khotbah Fasda');
    $catU = pl_category($uatKhotbah, 'Kategori Khotbah UAT');

    $classF = pl_class($fasdaKhotbah, $catF, 'Khotbah - Dewasa');
    $classU = pl_class($uatKhotbah, $catU, 'Khotbah - Dewasa', 'uat-khotbah-dewasa');

    return [$fasdaKhotbah, $uatKhotbah, $catF, $catU, $classF, $classU];
}

function pl_page(): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::actingAs(User::factory()->create(['role' => Role::SuperAdmin]))
        ->test(ParticipantList::class);
}

test('dropdown Lomba hanya memuat event FASDA; event UAT bernama sama TIDAK ikut', function () {
    [$fasdaKhotbah, $uatKhotbah] = pl_fasda_uat_khotbah_world();

    pl_page()
        ->assertSeeHtml('value="'.$fasdaKhotbah->id.'"')
        ->assertDontSeeHtml('value="'.$uatKhotbah->id.'"');
});

test('selecting "Khotbah" memilih event FASDA (F26), bukan event UAT bernama sama', function () {
    [$fasdaKhotbah, $uatKhotbah, $catF, , $classF] = pl_fasda_uat_khotbah_world();

    pl_register($fasdaKhotbah, $catF, $classF, pl_participant($fasdaKhotbah, 'Peserta Khotbah Fasda'));

    pl_page()
        ->set('competitionId', (string) $fasdaKhotbah->id)
        ->assertSet('competitionId', (string) $fasdaKhotbah->id)
        ->assertSee('Peserta Khotbah Fasda');
});

test('mode all: hanya peserta dari event FASDA, event UAT bernama sama dikecualikan', function () {
    $khotbah = pl_fasda_event('Khotbah');
    $bacaan = pl_fasda_event('Bacaan / Tilawah');
    $uatKhotbah = pl_uat_event('Khotbah');

    $catK = pl_category($khotbah, 'Kategori Khotbah');
    $catB = pl_category($bacaan, 'Kategori Bacaan');
    $catU = pl_category($uatKhotbah, 'Kategori UAT');

    $classK = pl_class($khotbah, $catK, 'Khotbah - Dewasa');
    $classB = pl_class($bacaan, $catB, 'Bacaan - Dewasa');
    $classU = pl_class($uatKhotbah, $catU, 'Khotbah - Dewasa', 'uat-khotbah-dewasa');

    pl_register($khotbah, $catK, $classK, pl_participant($khotbah, 'Peserta Khotbah Fasda'));
    pl_register($bacaan, $catB, $classB, pl_participant($bacaan, 'Peserta Bacaan Fasda'));
    pl_register($uatKhotbah, $catU, $classU, pl_participant($uatKhotbah, 'Peserta UAT Khotbah'));

    pl_page()
        ->set('competitionId', 'all')
        ->assertSee('Peserta Khotbah Fasda')
        ->assertSee('Peserta Bacaan Fasda')
        ->assertDontSee('Peserta UAT Khotbah');
});

test('single lomba, all categories: hanya registrasi event FASDA terpilih', function () {
    [$fasdaKhotbah, $uatKhotbah, $catF, $catU, $classF, $classU] = pl_fasda_uat_khotbah_world();

    pl_register($fasdaKhotbah, $catF, $classF, pl_participant($fasdaKhotbah, 'Peserta Khotbah Fasda'));
    pl_register($uatKhotbah, $catU, $classU, pl_participant($uatKhotbah, 'Peserta UAT Khotbah'));

    pl_page()
        ->set('competitionId', (string) $fasdaKhotbah->id)
        ->assertSee('Peserta Khotbah Fasda')
        ->assertDontSee('Peserta UAT Khotbah');
});

test('single lomba returns nothing when it has no registrations (no fallback to other event)', function () {
    $adzan = pl_fasda_event('Adzan & Iqomah');
    [$fasdaKhotbah, $uatKhotbah, $catF, $catU, $classF, $classU] = pl_fasda_uat_khotbah_world();

    $catA = pl_category($adzan, 'Kategori Adzan');
    $classA = pl_class($adzan, $catA, 'Adzan - Dewasa');

    pl_register($fasdaKhotbah, $catF, $classF, pl_participant($fasdaKhotbah, 'Peserta Khotbah Fasda'));
    pl_register($uatKhotbah, $catU, $classU, pl_participant($uatKhotbah, 'Peserta UAT Khotbah'));

    pl_page()
        ->set('competitionId', (string) $adzan->id)
        ->assertDontSee('Peserta Khotbah Fasda')
        ->assertDontSee('Peserta UAT Khotbah')
        ->assertSee('Belum ada peserta');
});

test('lomba + kategori + kelas: validates event, category AND class via competition_classes', function () {
    [$fasdaKhotbah, $uatKhotbah, $catF, $catU, $classF, $classU] = pl_fasda_uat_khotbah_world();

    pl_register($fasdaKhotbah, $catF, $classF, pl_participant($fasdaKhotbah, 'Peserta Khotbah Fasda'));
    pl_register($uatKhotbah, $catU, $classU, pl_participant($uatKhotbah, 'Peserta UAT Khotbah'));

    pl_page()
        ->set('competitionId', (string) $fasdaKhotbah->id)
        ->set('competitionCategoryId', (string) $catF->id)
        ->set('competitionClassId', (string) $classF->id)
        ->assertSee('Peserta Khotbah Fasda')
        ->assertDontSee('Peserta UAT Khotbah');
});

test('registration bocor lintas event: class milik Khotbah + participation event UAT tidak tampil di Khotbah', function () {
    [$fasdaKhotbah, $uatKhotbah, $catF, , $classF] = pl_fasda_uat_khotbah_world();

    pl_register($fasdaKhotbah, $catF, $classF, pl_participant($fasdaKhotbah, 'Peserta Khotbah Fasda'));

    // Reproduksi laporan bug: "UAT Dewasa 01" participation di event UAT,
    // tapi registrasinya menunjuk class "Khotbah - Dewasa" milik event FASDA.
    $uatParticipation = pl_participant($uatKhotbah, 'UAT Dewasa 01');
    pl_register($uatKhotbah, $catF, $classF, $uatParticipation);

    pl_page()
        ->set('competitionId', (string) $fasdaKhotbah->id)
        ->assertSee('Peserta Khotbah Fasda')
        ->assertDontSee('UAT Dewasa 01');
});

test('dropdown categories only contain categories of the selected lomba', function () {
    $fasdaKhotbah = pl_fasda_event('Khotbah');
    $uatKhotbah = pl_uat_event('Khotbah');

    pl_category($fasdaKhotbah, 'Kategori Khotbah Fasda');
    pl_category($uatKhotbah, 'Kategori Khotbah UAT');

    pl_page()
        ->set('competitionId', (string) $fasdaKhotbah->id)
        ->assertSee('Kategori Khotbah Fasda')
        ->assertDontSee('Kategori Khotbah UAT');
});
