<?php

namespace Database\Seeders;

use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\desa;
use App\Models\Event;
use App\Models\kelompok;
use App\Models\MasterParticipantClass;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeder master data lomba NYATA (bukan UAT/dummy).
 *
 * Membuat data master saja:
 * - MasterParticipantClass
 * - Event (lomba)
 * - CompetitionCategory (event-scoped, SATU jenjang per kategori)
 * - CompetitionClass (event + kategori + gender)
 * - desa / kelompok
 *
 * Skema yang dipakai mengikuti schema canonical aplikasi:
 * - `competition_categories.event_id` NOT NULL -> satu kategori dimiliki satu
 *   event (one-to-many). TIDAK ada pivot `competition_category_event`.
 * - Relasi kategori -> master kelas peserta memakai pivot
 *   `competition_category_master_participant_class`.
 *
 * Kategori mengikuti JENJANG peserta (PAUD, SD 1..SD 6, SMP, SMU, Dewasa),
 * bukan gabungan rentang. Jenjang diambil dari data peserta (mpc_candidate)
 * pada `database/seeders/data/fasda2026_final.csv`.
 *
 * TIDAK membuat Person / Participation / CompetitionRegistration
 * (data peserta FASDA diimpor oleh Fasda2026Seeder secara terpisah).
 *
 * Idempotent: aman dijalankan berulang kali tanpa membuat duplikat.
 */
class AplikasiLombaSeeder extends Seeder
{
    /**
     * Nama lomba standar (canonical) -> code event yang STABIL.
     */
    private const STANDARD_EVENTS = [
        'Bacaan / Murotal' => 'lomba-bacaan-murotal',
        'Adzan & Qomat' => 'lomba-adzan-qomat',
        'Hafalan / Tahfidz' => 'lomba-hafalan-tahfidz',
        'Dakwah' => 'lomba-dakwah',
        'Cerdas Cermat' => 'lomba-cerdas-cermat',
        'Kaifiyatussholah' => 'lomba-kaifiyatussholah',
        'Aplikasi Penerapan 29 Karakter Luhur Jamaah' => 'lomba-29-karakter',
        'Kreativitas Maket Masjid Al Ajwah, dengan bahan Kardus' => 'lomba-maket-masjid',
        'Khotbah' => 'lomba-khotbah',
        'Kreativitas Barang Bekas' => 'lomba-barang-bekas',
    ];

    /**
     * Lomba team di luar dataset FASDA 2026 (lomba Kemerdekaan).
     */
    private const EXTRA_TEAM_EVENTS = [
        'Pancing Kerupuk' => 'lomba-pancing-kerupuk',
        'Dragon Ball' => 'lomba-dragon-ball',
        'Tarik Tambang' => 'lomba-tarik-tambang',
        'Sedotan Hidung' => 'lomba-sedotan-hidung',
    ];

    /**
     * Alias nama lama -> canonical. Dipakai saat lookup event agar lomba yang
     * sama tidak dibuat jadi dua event.
     */
    private const NAME_ALIASES = [
        'Bacaan / Tilawah' => 'Bacaan / Murotal',
        'Bacaan / Murottal' => 'Bacaan / Murotal',
        'Adzan & Iqomah' => 'Adzan & Qomat',
        'khotbah' => 'Khotbah',
        'Kaifiyatussholah (Imam)' => 'Kaifiyatussholah',
        "Kaifiyatussholah (Ma'mum)" => 'Kaifiyatussholah',
    ];

    /**
     * Lomba khusus Putra (gender L saja).
     */
    private const PUTRA_ONLY = [
        'Adzan & Qomat',
        'Aplikasi Penerapan 29 Karakter Luhur Jamaah',
        'Khotbah',
        'Kaifiyatussholah',
    ];

    private const MASTER_CLASSES = [
        'PAUD', 'SD 1', 'SD 2', 'SD 3', 'SD 4', 'SD 5', 'SD 6', 'SMP', 'SMU', 'Dewasa',
    ];

    /**
     * Lomba yang diformat sebagai team competition (spec #7).
     */
    private const TEAM_EVENTS = [
        'Cerdas Cermat',
        'Aplikasi Penerapan 29 Karakter Luhur Jamaah',
        'Kreativitas Maket Masjid Al Ajwah, dengan bahan Kardus',
        'Kreativitas Barang Bekas',
        'Pancing Kerupuk',
        'Dragon Ball',
        'Tarik Tambang',
        'Sedotan Hidung',
    ];

    /**
     * team_size yang sudah ditentukan. Lomba team lain memakai konfigurasi
     * aplikasi (dibiarkan null agar dihitung service saat pembentukan team).
     */
    private const TEAM_SIZE_MAP = [
        'Pancing Kerupuk' => 4,
        'Dragon Ball' => 5,
        'Tarik Tambang' => 5,
        'Sedotan Hidung' => 3,
    ];

    /**
     * Jenjang untuk lomba team di luar dataset FASDA.
     */
    private const EXTRA_TEAM_JENJANG = [
        'Pancing Kerupuk' => ['PAUD', 'SD 1', 'SD 2', 'SD 3', 'SD 4', 'SD 5', 'SD 6'],
        'Dragon Ball' => ['SMP', 'SMU'],
        'Tarik Tambang' => ['SMP', 'SMU', 'Dewasa'],
        'Sedotan Hidung' => ['SMP', 'SMU', 'Dewasa'],
    ];

    /**
     * Master desa/kelompok canonical.
     */
    private const DESA_KELOMPOK = [
        'Batam' => ['KM 7', 'KM 10', 'Perumnas', 'Kariangau', 'Soekarno Hatta', 'Somber'],
        'Ringroad' => ['Gunung Samarinda', 'Sumber Rejo', 'Bandara Utara'],
        'Sepinggan' => ['Sepinggan 1', 'Sepinggan 2', 'Sepinggan 3', 'Sepinggan 4', 'Bandara Baru', 'Melati'],
        'Timur Raya' => ['Lemaru', 'Batakan', 'Lemaru Sosial', 'Manggar'],
    ];

    private const CSV = 'seeders/data/fasda2026_final.csv';

    public function run(): void
    {
        $mpcs = $this->ensureMasterParticipantClasses();
        $this->ensureDesaAndKelompok();

        $needed = $this->collectNeededCombinations();

        $events = $this->ensureEvents($needed);
        $this->ensureCategoriesAndClasses($events, $mpcs, $needed);

        $this->command?->info('AplikasiLombaSeeder: DONE');
    }

    /**
     * Kombinasi (event, jenjang, gender) yang memang dibutuhkan data peserta.
     *
     * @return array<string, array<string, array<string, bool>>>
     */
    private function collectNeededCombinations(): array
    {
        $needed = [];

        $path = database_path(self::CSV);

        if (is_file($path)) {
            $handle = fopen($path, 'rb');
            $header = fgetcsv($handle);

            if ($header !== false) {
                $header = array_map(
                    fn ($value) => trim((string) $value, " \t\n\r\0\x0B\xEF\xBB\xBF"),
                    $header
                );

                while (($values = fgetcsv($handle)) !== false) {
                    if (count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) {
                        continue;
                    }

                    $values = array_pad($values, count($header), null);
                    $values = array_slice($values, 0, count($header));
                    $row = array_combine($header, $values);

                    if ($row === false) {
                        continue;
                    }

                    $eventName = $this->canonicalBranch((string) ($row['cabang_perlombaan'] ?? ''));

                    if ($eventName === '') {
                        continue;
                    }

                    $jenjang = trim((string) ($row['mpc_candidate'] ?? ''));

                    if ($jenjang === '') {
                        continue;
                    }

                    /*
                     * Gender class: male-only -> L; semua lomba FASDA lain
                     * -> M (mixed/campuran, wildcard untuk peserta L dan P).
                     * Gender peserta tidak lagi menentukan pemisahan class.
                     */
                    $classGender = in_array($eventName, self::PUTRA_ONLY, true) ? 'L' : 'M';

                    $needed[$eventName][$jenjang][$classGender] = true;
                }
            }

            fclose($handle);
        }

        // Lomba team di luar dataset: konfigurasi master (bukan data peserta).
        foreach (self::EXTRA_TEAM_EVENTS as $eventName => $code) {
            foreach (self::EXTRA_TEAM_JENJANG[$eventName] as $jenjang) {
                $needed[$eventName][$jenjang]['M'] = true;
            }
        }

        return $needed;
    }

    private function ensureMasterParticipantClasses(): array
    {
        $mpcs = [];

        foreach (array_values(self::MASTER_CLASSES) as $index => $name) {
            $mpc = MasterParticipantClass::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

            if (! $mpc) {
                $mpc = MasterParticipantClass::create([
                    'name' => $name,
                    'code' => $name,
                    'sort_order' => $index + 1,
                    'is_active' => true,
                ]);
            }

            $mpcs[$name] = $mpc;
        }

        $this->command?->info('  Master Kelas Peserta: '.count($mpcs));

        return $mpcs;
    }

    private function ensureDesaAndKelompok(): void
    {
        $desaCount = 0;
        $kelompokCount = 0;

        foreach (self::DESA_KELOMPOK as $desaName => $kelompokNames) {
            $desa = desa::whereRaw('LOWER(desa_asal) = ?', [mb_strtolower($desaName)])->first();

            if (! $desa) {
                $desa = desa::create([
                    'desa_asal' => $desaName,
                    'is_active' => true,
                ]);
            }
            $desaCount++;

            foreach ($kelompokNames as $kelompokName) {
                $kelompok = kelompok::whereRaw('LOWER(kelompok_asal) = ?', [mb_strtolower($kelompokName)])
                    ->where('desa_id', $desa->id)
                    ->first();

                if (! $kelompok) {
                    kelompok::create([
                        'kelompok_asal' => $kelompokName,
                        'desa_id' => $desa->id,
                        'is_active' => true,
                    ]);
                }
                $kelompokCount++;
            }
        }

        $this->command?->info("  Desa: {$desaCount}, Kelompok: {$kelompokCount}");
    }

    private function ensureEvents(array $needed): array
    {
        $events = [];
        $sortOrder = 0;

        $all = self::STANDARD_EVENTS + self::EXTRA_TEAM_EVENTS;

        foreach ($all as $name => $code) {
            $sortOrder++;
            $events[$name] = $this->ensureEvent($name, $code, $sortOrder);
        }

        foreach (array_keys($needed) as $name) {
            if (isset($events[$name])) {
                continue;
            }

            $sortOrder++;
            $events[$name] = $this->ensureEvent($name, Str::slug($name), $sortOrder);
        }

        $this->command?->info('  Lomba: '.count($events));

        return $events;
    }

    private function ensureEvent(string $name, string $code, int $sortOrder): Event
    {
        $lookupNames = $this->eventLookupNames($name);

        $event = Event::where('event_type', 'competition')
            ->whereIn(DB::raw('LOWER(name)'), $lookupNames)
            ->orderBy('id')
            ->first();

        if ($event) {
            $event->name = $name;
            $event->code = $event->code ?: $code;
            $event->slug = $event->slug ?: $this->uniqueSlug($name);
            $event->event_type = 'competition';
            $event->status = $event->status ?: 'active';
            $event->start_date = $event->start_date ?? now()->toDateString();
            $event->end_date = $event->end_date ?? now()->addDays(30)->toDateString();
            $event->sort_order = $event->sort_order ?? $sortOrder;
            $event->save();

            return $event;
        }

        return Event::create([
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
            'code' => $code,
            'event_type' => 'competition',
            'status' => 'active',
            'sort_order' => $sortOrder,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
        ]);
    }

    private function eventLookupNames(string $canonical): array
    {
        $names = [mb_strtolower($canonical)];

        foreach (self::NAME_ALIASES as $alias => $target) {
            if ($target === $canonical) {
                $names[] = mb_strtolower($alias);
            }
        }

        return array_values(array_unique($names));
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $counter = 2;

        while (Event::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Kategori bersifat event-scoped dan mengikuti jenjang peserta.
     * Setiap kategori ditautkan ke master kelas peserta jenjang tersebut.
     *
     * @param  array<string, array<string, array<string, bool>>>  $needed
     */
    private function ensureCategoriesAndClasses(array $events, array $mpcs, array $needed): void
    {
        $categoryCount = 0;
        $classCount = 0;

        foreach ($needed as $eventName => $jenjangMap) {
            $event = $events[$eventName] ?? null;

            if (! $event) {
                continue;
            }

            $categorySortOrder = 0;

            foreach ($jenjangMap as $jenjang => $genders) {
                $categorySortOrder++;

                $category = $this->ensureCategory($event, $jenjang, $categorySortOrder);

                $mpc = $mpcs[$jenjang] ?? null;

                if ($mpc) {
                    $category->masterParticipantClasses()->sync([$mpc->id]);
                }

                $categoryCount++;

                $classSortOrder = 0;

                foreach (array_keys($genders) as $gender) {
                    $classSortOrder++;
                    $this->ensureClass($event, $category, $gender, $classSortOrder);
                    $classCount++;
                }
            }
        }

        $this->command?->info("  Kategori: {$categoryCount}, Kelas Lomba: {$classCount}");
    }

    private function ensureCategory(Event $event, string $categoryName, int $sortOrder): CompetitionCategory
    {
        $category = CompetitionCategory::where('event_id', $event->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($categoryName)])
            ->first();

        if ($category) {
            return $category;
        }

        return CompetitionCategory::create([
            'event_id' => $event->id,
            'name' => $categoryName,
            'code' => 'F26-'.strtoupper(Str::slug($categoryName, '-')),
            'sort_order' => $sortOrder,
            'is_active' => true,
        ]);
    }

    private function ensureClass(
        Event $event,
        CompetitionCategory $category,
        string $gender,
        int $sortOrder
    ): void {
        $isTeam = in_array($event->name, self::TEAM_EVENTS, true);

        $genderLabel = match ($gender) {
            'L' => 'Putra',
            'P' => 'Putri',
            'M' => 'Campuran',
            default => $gender,
        };

        CompetitionClass::updateOrCreate(
            [
                'event_id' => $event->id,
                'competition_category_id' => $category->id,
                'gender' => $gender,
            ],
            [
                'name' => "{$event->name} - {$category->name} - {$genderLabel}",
                'code' => 'F26-'.strtoupper(Str::slug($category->name.' '.$gender, '-')),
                'format' => $isTeam ? 'team_vs_team' : 'individual_mass',
                'status' => 'registration_open',
                'result_type' => $isTeam ? 'win_loss' : 'score',
                'team_size' => $isTeam ? (self::TEAM_SIZE_MAP[$event->name] ?? null) : null,
                'is_active' => true,
                'winner_count' => 4,
                'honorable_mention_count' => 0,
                'sort_order' => $sortOrder,
            ],
        );
    }

    private function canonicalBranch(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));

        return match (mb_strtolower($name)) {
            'bacaan / tilawah' => 'Bacaan / Murotal',
            'bacaan / murottal' => 'Bacaan / Murotal',
            'adzan & iqomah' => 'Adzan & Qomat',
            'kaifiyatussholah (imam)' => 'Kaifiyatussholah',
            "kaifiyatussholah (ma'mum)" => 'Kaifiyatussholah',
            'khotbah' => 'Khotbah',
            'pildacil' => 'Dakwah',
            default => $name,
        };
    }

}
