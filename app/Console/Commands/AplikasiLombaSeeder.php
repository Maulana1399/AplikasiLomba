<?php

namespace App\Console\Commands;

use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionRegistration;
use App\Models\desa;
use App\Models\Event;
use App\Models\kelompok;
use App\Models\MasterParticipantClass;
use App\Models\Participation;
use App\Models\Person;
use App\Services\Competition\CompetitionRegistrationService;
use Illuminate\Console\Command;

class AplikasiLombaSeeder extends Command
{
    protected $signature = 'app:aplikasi-lomba-seeder';

    protected $description = 'Seed AplikasiLomba dummy master data (idempotent)';

    public function handle(): int
    {
        $mpcs = $this->ensureMasterParticipantClasses();
        $categories = $this->ensureCategories($mpcs);
        $this->ensureDesaAndKelompok();
        $events = $this->ensureEvents();
        $this->attachCategoriesToEvents($events, $categories);
        $this->ensureClasses($events, $categories);
        $this->ensureDummyRegistrations($events, $categories);
        $this->ensureTeamUatRegistrations($events, $categories);

        $this->reportSummary();

        $this->info('AplikasiLombaSeeder: DONE');

        return self::SUCCESS;
    }

    private function ensureMasterParticipantClasses(): array
    {
        $names = [
            'PAUD', 'SD 1', 'SD 2', 'SD 3', 'SD 4',
            'SD 5', 'SD 6', 'SMP', 'SMU', 'Dewasa',
        ];

        $mpcs = [];
        foreach (array_values($names) as $index => $name) {
            $mpcs[$name] = MasterParticipantClass::firstOrCreate(
                ['name' => $name],
                ['code' => $name, 'sort_order' => $index + 1, 'is_active' => true],
            );
        }

        $this->info('  Master Kelas Peserta: '.count($mpcs));

        return $mpcs;
    }

    private function ensureCategories(array $mpcs): array
    {
        $specs = [
            'Paud - SD 3' => ['PAUD', 'SD 1', 'SD 2', 'SD 3'],
            'SD 4 - 6' => ['SD 4', 'SD 5', 'SD 6'],
            'Remaja' => ['SMP', 'SMU'],
            'Dewasa' => ['Dewasa'],
        ];

        $categories = [];
        $sortOrder = 0;
        foreach ($specs as $catName => $mpcNames) {
            $sortOrder++;
            $category = CompetitionCategory::firstOrCreate(
                ['name' => $catName],
                ['code' => $catName, 'sort_order' => $sortOrder, 'is_active' => true],
            );

            $mpcIds = collect($mpcNames)
                ->filter(fn ($n) => isset($mpcs[$n]))
                ->map(fn ($n) => $mpcs[$n]->id)
                ->values()
                ->all();
            $category->masterParticipantClasses()->sync($mpcIds);

            $categories[$catName] = $category;
        }

        $this->info('  Kategori: '.count($categories));

        return $categories;
    }

    private function ensureDesaAndKelompok(): void
    {
        $kelompokByDesa = [
            'Batam' => ['Km 7', 'Km 10', 'Kariangau', 'Perumnas', 'Somber', 'Soekarno hatta'],
            'Ringroad' => ['Gunung Samarinda', 'Sumberjo', 'Bandara utara'],
            'Sepinggan' => ['Sepinggan 1', 'Sepinggan 2', 'Sepinggan 3', 'Sepinggan 4', 'Bandara baru', 'Melati'],
            'Timur Raya' => ['Batakan', 'Lemaru', 'Manggar'],
        ];

        $desaCount = 0;
        $kelompokCount = 0;

        foreach ($kelompokByDesa as $desaName => $kelompokNames) {
            $d = desa::firstOrCreate(
                ['desa_asal' => $desaName],
                ['is_active' => true],
            );
            $desaCount++;

            foreach ($kelompokNames as $kName) {
                kelompok::firstOrCreate(
                    ['kelompok_asal' => $kName],
                    ['desa_id' => $d->id, 'is_active' => true],
                );
                $kelompokCount++;
            }
        }

        $this->info("  Desa: {$desaCount}, Kelompok: {$kelompokCount}");
    }

    private function ensureEvents(): array
    {
        $lombaNames = [
            'Mewarnai',
            'Bacaan / Tilawah',
            'Adzan & Qomat',
            'Hafalan / Tahfidz',
            'Dakwah',
            'Cerdas Cermat',
            'Menggambar & Mewarnai',
            'Kaifiyatussholah',
            'Aplikasi Penerapan 29 Karakter Luhur Jamaah',
            'Kreativitas Maket Masjid Al Ajwah, dengan bahan Kardus',
            'Khotbah',
            'Kreativitas Barang Bekas',

            // Lomba Kemerdekaan
            'Lomba Pancing Kerupuk',
            'Lomba Migrasi Gelas',
            'Lomba Dragon Ball',
            'Lomba Palu-Palu',
            'Lomba Tarik Tambang',
            'Migrasi Gelas',
            'Sedotan Hidung',
        ];

        $events = [];
        foreach (array_values($lombaNames) as $index => $name) {
            $code = 'lomba-'.($index + 1);
            $events[$name] = Event::updateOrCreate(
                ['name' => $name],
                [
                    'slug' => \Illuminate\Support\Str::slug($name).'-'.($index + 1),
                    'code' => $code,
                    'event_type' => 'competition',
                    'status' => 'active',
                    'sort_order' => $index + 1,
                    'start_date' => now()->toDateString(),
                    'end_date' => now()->addDays(30)->toDateString(),
                ],
            );
        }

        $this->info('  Lomba: '.count($events));

        return $events;
    }

    private function attachCategoriesToEvents(array $events, array $categories): void
    {
        // Lomba lama tetap memakai seluruh kategori umum.
        $eventCategoryMap = [
            'Mewarnai' => ['Paud - SD 3', 'SD 4 - 6', 'Remaja', 'Dewasa'],
            'Bacaan / Tilawah' => ['Paud - SD 3', 'SD 4 - 6', 'Remaja', 'Dewasa'],
            'Adzan & Qomat' => ['Paud - SD 3', 'SD 4 - 6', 'Remaja', 'Dewasa'],
            'Hafalan / Tahfidz' => ['Paud - SD 3', 'SD 4 - 6', 'Remaja', 'Dewasa'],
            'Dakwah' => ['Paud - SD 3', 'SD 4 - 6', 'Remaja', 'Dewasa'],
            'Cerdas Cermat' => ['Paud - SD 3', 'SD 4 - 6', 'Remaja', 'Dewasa'],
            'Menggambar & Mewarnai' => ['Paud - SD 3', 'SD 4 - 6', 'Remaja', 'Dewasa'],
            'Kaifiyatussholah' => ['Paud - SD 3', 'SD 4 - 6', 'Remaja', 'Dewasa'],
            'Aplikasi Penerapan 29 Karakter Luhur Jamaah' => ['Paud - SD 3', 'SD 4 - 6', 'Remaja', 'Dewasa'],
            'Kreativitas Maket Masjid Al Ajwah, dengan bahan Kardus' => ['Paud - SD 3', 'SD 4 - 6', 'Remaja', 'Dewasa'],
            'Khotbah' => ['Paud - SD 3', 'SD 4 - 6', 'Remaja', 'Dewasa'],
            'Kreativitas Barang Bekas' => ['Paud - SD 3', 'SD 4 - 6', 'Remaja', 'Dewasa'],

            // Lomba Kemerdekaan � persis sesuai daftar yang diberikan.
            'Lomba Pancing Kerupuk' => ['Paud - SD 3', 'SD 4 - 6'],
            'Lomba Migrasi Gelas' => ['Paud - SD 3', 'SD 4 - 6'],
            'Lomba Dragon Ball' => ['Remaja'],
            'Lomba Palu-Palu' => ['Dewasa'],
            'Lomba Tarik Tambang' => ['Remaja', 'Dewasa'],
            'Migrasi Gelas' => ['Remaja', 'Dewasa'],
            'Sedotan Hidung' => ['Remaja', 'Dewasa'],
        ];

        $count = 0;

        foreach ($eventCategoryMap as $eventName => $categoryNames) {
            $event = $events[$eventName] ?? null;
            if (! $event) {
                continue;
            }

            $catIds = collect($categoryNames)
                ->map(fn ($name) => $categories[$name]->id ?? null)
                ->filter()
                ->values()
                ->all();

            $event->competitionCategories()->syncWithoutDetaching($catIds);
            $count += count($catIds);
        }

        $this->info("  Pivot Lomba ? Kategori: {$count}");
    }

    private function ensureClasses(array $events, array $categories): void
    {
        $putraLomba = ['Adzan & Qomat', 'Khotbah', 'Aplikasi Penerapan 29 Karakter Luhur Jamaah'];

        // Lomba lama: pertahankan satu kelas per kategori seperti sebelumnya.
        $legacyEvents = [
            'Mewarnai',
            'Bacaan / Tilawah',
            'Adzan & Qomat',
            'Hafalan / Tahfidz',
            'Dakwah',
            'Cerdas Cermat',
            'Menggambar & Mewarnai',
            'Kaifiyatussholah',
            'Aplikasi Penerapan 29 Karakter Luhur Jamaah',
            'Kreativitas Maket Masjid Al Ajwah, dengan bahan Kardus',
            'Khotbah',
            'Kreativitas Barang Bekas',
        ];

        $count = 0;
        $sortOrder = 0;

        foreach ($legacyEvents as $eventName) {
            $event = $events[$eventName] ?? null;
            if (! $event) {
                continue;
            }

            foreach (['Paud - SD 3', 'SD 4 - 6', 'Remaja', 'Dewasa'] as $catName) {
                $category = $categories[$catName] ?? null;
                if (! $category) {
                    continue;
                }

                $sortOrder++;
                $className = "{$eventName} - {$catName}";
                $gender = in_array($eventName, $putraLomba, true) ? 'L' : 'M';

                CompetitionClass::updateOrCreate(
                    ['event_id' => $event->id, 'competition_category_id' => $category->id, 'gender' => $gender],
                    [
                        'name' => $className,
                        'format' => 'individual_mass',
                        'result_type' => 'score',
                        'is_active' => true,
                        'winner_count' => 1,
                        'honorable_mention_count' => 0,
                        'sort_order' => $sortOrder,
                    ],
                );
                $count++;
            }
        }

        // Lomba Kemerdekaan.
        // Paud-SD3: HANYA Campuran.
        // SD4-6 / Remaja / Dewasa: dipisahkan Putra dan Putri,
        // kecuali lomba khusus perempuan yang hanya membuat kelas Putri.
        $independence = [
            'Lomba Pancing Kerupuk' => [
                'Paud - SD 3' => ['M'],
                'SD 4 - 6' => ['L', 'P'],
            ],
            'Lomba Migrasi Gelas' => [
                'Paud - SD 3' => ['M'],
                'SD 4 - 6' => ['L', 'P'],
            ],
            'Lomba Dragon Ball' => [
                'Remaja' => ['L', 'P'],
            ],
            'Lomba Palu-Palu' => [
                'Dewasa' => ['L', 'P'],
            ],
            'Lomba Tarik Tambang' => [
                'Remaja' => ['L', 'P'],
                'Dewasa' => ['L', 'P'],
            ],
            // Pemudi = kategori Remaja + gender Perempuan.
            'Migrasi Gelas' => [
                'Remaja' => ['P'],
                'Dewasa' => ['P'],
            ],
            // Ibu-Ibu = kategori Dewasa + gender Perempuan.
            'Sedotan Hidung' => [
                'Remaja' => ['P'],
                'Dewasa' => ['P'],
            ],
        ];

        $teamEvents = [
            'Lomba Pancing Kerupuk',
            'Lomba Migrasi Gelas',
            'Lomba Dragon Ball',
            'Lomba Palu-Palu',
            'Lomba Tarik Tambang',
            'Migrasi Gelas',
            'Sedotan Hidung',
        ];

        $teamSizeMap = [
            'Lomba Pancing Kerupuk' => 4,
            'Lomba Migrasi Gelas' => 4,
            'Lomba Dragon Ball' => 5,
            'Lomba Palu-Palu' => 4,
            'Lomba Tarik Tambang' => 5,
            'Migrasi Gelas' => 3,
            'Sedotan Hidung' => 3,
        ];

        foreach ($independence as $eventName => $categoryGenders) {
            $event = $events[$eventName] ?? null;
            if (! $event) {
                continue;
            }

            foreach ($categoryGenders as $catName => $genders) {
                $category = $categories[$catName] ?? null;
                if (! $category) {
                    continue;
                }

                foreach ($genders as $gender) {
                    $sortOrder++;
                    $genderLabel = match ($gender) {
                        'L' => 'Putra',
                        'P' => 'Putri',
                        'M' => 'Campuran',
                        default => $gender,
                    };
                    $className = "{$eventName} - {$catName} - {$genderLabel}";
                    $isTeam = in_array($eventName, $teamEvents, true);

                    $teamSize = $isTeam ? ($teamSizeMap[$eventName] ?? 4) : null;

                    CompetitionClass::updateOrCreate(
                        [
                            'event_id' => $event->id,
                            'competition_category_id' => $category->id,
                            'gender' => $gender,
                        ],
                        [
                            'name' => $className,
                            'format' => $isTeam ? 'team_vs_team' : 'individual_mass',
                            'result_type' => $isTeam ? 'win_loss' : 'score',
                            'team_size' => $teamSize,
                            'is_active' => true,
                            'winner_count' => 1,
                            'honorable_mention_count' => 0,
                            'sort_order' => $sortOrder,
                        ],
                    );
                    $count++;
                }
            }
        }

        $this->info("  Kelas Lomba: {$count}");
    }

    private function ensureDummyRegistrations(array $events, array $categories): void
    {
        $service = app(CompetitionRegistrationService::class);

        $defs = [
            ['nama' => 'UAT PAUD 01', 'mpc' => 'PAUD', 'jk' => 'L', 'desa' => 'Batam', 'kelompok' => 'Km 7'],
            ['nama' => 'UAT PAUD 02', 'mpc' => 'PAUD', 'jk' => 'P', 'desa' => 'Ringroad', 'kelompok' => 'Gunung Samarinda'],
            ['nama' => 'UAT SD1 01', 'mpc' => 'SD 1', 'jk' => 'L', 'desa' => 'Sepinggan', 'kelompok' => 'Sepinggan 1'],
            ['nama' => 'UAT SD1 02', 'mpc' => 'SD 1', 'jk' => 'P', 'desa' => 'Timur Raya', 'kelompok' => 'Batakan'],
            ['nama' => 'UAT SD3 01', 'mpc' => 'SD 3', 'jk' => 'P', 'desa' => 'Batam', 'kelompok' => 'Perumnas'],
            ['nama' => 'UAT SD3 02', 'mpc' => 'SD 3', 'jk' => 'L', 'desa' => 'Ringroad', 'kelompok' => 'Sumberjo'],
            ['nama' => 'UAT SD4 01', 'mpc' => 'SD 4', 'jk' => 'L', 'desa' => 'Sepinggan', 'kelompok' => 'Sepinggan 2'],
            ['nama' => 'UAT SD4 02', 'mpc' => 'SD 4', 'jk' => 'P', 'desa' => 'Batam', 'kelompok' => 'Km 10'],
            ['nama' => 'UAT SD6 01', 'mpc' => 'SD 6', 'jk' => 'P', 'desa' => 'Timur Raya', 'kelompok' => 'Lemaru'],
            ['nama' => 'UAT SD6 02', 'mpc' => 'SD 6', 'jk' => 'L', 'desa' => 'Sepinggan', 'kelompok' => 'Sepinggan 3'],
            ['nama' => 'UAT SMP 01', 'mpc' => 'SMP', 'jk' => 'L', 'desa' => 'Batam', 'kelompok' => 'Kariangau'],
            ['nama' => 'UAT SMP 02', 'mpc' => 'SMP', 'jk' => 'P', 'desa' => 'Ringroad', 'kelompok' => 'Bandara utara'],
            ['nama' => 'UAT SMU 01', 'mpc' => 'SMU', 'jk' => 'L', 'desa' => 'Sepinggan', 'kelompok' => 'Bandara baru'],
            ['nama' => 'UAT SMU 02', 'mpc' => 'SMU', 'jk' => 'P', 'desa' => 'Timur Raya', 'kelompok' => 'Manggar'],
            ['nama' => 'UAT Dewasa 01', 'mpc' => 'Dewasa', 'jk' => 'L', 'desa' => 'Batam', 'kelompok' => 'Somber'],
            ['nama' => 'UAT Dewasa 02', 'mpc' => 'Dewasa', 'jk' => 'P', 'desa' => 'Ringroad', 'kelompok' => 'Gunung Samarinda'],
            ['nama' => 'UAT Dewasa 03', 'mpc' => 'Dewasa', 'jk' => 'L', 'desa' => 'Sepinggan', 'kelompok' => 'Melati'],
            ['nama' => 'UAT Dewasa 04', 'mpc' => 'Dewasa', 'jk' => 'P', 'desa' => 'Timur Raya', 'kelompok' => 'Batakan'],
            ['nama' => 'UAT SD2 01', 'mpc' => 'SD 2', 'jk' => 'L', 'desa' => 'Batam', 'kelompok' => 'Soekarno hatta'],
            ['nama' => 'UAT SD5 01', 'mpc' => 'SD 5', 'jk' => 'P', 'desa' => 'Sepinggan', 'kelompok' => 'Sepinggan 4'],
            ['nama' => 'UAT SD3 03', 'mpc' => 'SD 3', 'jk' => 'L', 'desa' => 'Timur Raya', 'kelompok' => 'Lemaru'],
            ['nama' => 'UAT SMP 03', 'mpc' => 'SMP', 'jk' => 'L', 'desa' => 'Batam', 'kelompok' => 'Km 7'],
        ];

        $plan = [
            'UAT PAUD 01' => ['Mewarnai'],
            'UAT PAUD 02' => ['Hafalan / Tahfidz'],
            'UAT SD1 01' => ['Mewarnai', 'Cerdas Cermat'],
            'UAT SD1 02' => ['Dakwah'],
            'UAT SD3 01' => ['Mewarnai', 'Hafalan / Tahfidz'],
            'UAT SD3 02' => ['Menggambar & Mewarnai'],
            'UAT SD4 01' => ['Mewarnai', 'Kaifiyatussholah'],
            'UAT SD4 02' => ['Kreativitas Barang Bekas'],
            'UAT SD6 01' => ['Mewarnai', 'Cerdas Cermat'],
            'UAT SD6 02' => ['Dakwah'],
            'UAT SMP 01' => ['Bacaan / Tilawah', 'Dakwah'],
            'UAT SMP 02' => ['Cerdas Cermat'],
            'UAT SMU 01' => ['Hafalan / Tahfidz', 'Kreativitas Barang Bekas'],
            'UAT SMU 02' => ['Bacaan / Tilawah'],
            'UAT Dewasa 01' => ['Adzan & Qomat', 'Bacaan / Tilawah', 'Khotbah'],
            'UAT Dewasa 02' => ['Bacaan / Tilawah', 'Dakwah'],
            'UAT Dewasa 03' => ['Aplikasi Penerapan 29 Karakter Luhur Jamaah'],
            'UAT Dewasa 04' => ['Kreativitas Maket Masjid Al Ajwah, dengan bahan Kardus'],
            'UAT SD2 01' => ['Mewarnai', 'Hafalan / Tahfidz'],
            'UAT SD5 01' => ['Menggambar & Mewarnai', 'Kreativitas Barang Bekas'],
            'UAT SD3 03' => ['Mewarnai', 'Hafalan / Tahfidz', 'Cerdas Cermat'],
            'UAT SMP 03' => ['Adzan & Qomat'],
        ];

        $defByName = collect($defs)->keyBy('nama');
        $createdRegs = 0;
        $skipped = 0;

        foreach ($plan as $nama => $lombaNames) {
            $def = $defByName->get($nama);
            if (! $def) {
                continue;
            }

            $mpc = MasterParticipantClass::where('name', $def['mpc'])->first();
            $desaRec = desa::where('desa_asal', $def['desa'])->first();
            $kelRec = kelompok::where('kelompok_asal', $def['kelompok'])->first();
            if (! $mpc || ! $desaRec || ! $kelRec) {
                $this->warn("  Skip {$nama}: master data missing");
                continue;
            }

            $jenisKelamin = $def['jk'] === 'P' ? 'Perempuan' : 'Laki - Laki';
            $personGender = $def['jk'] === 'P' ? 'P' : 'L';

            $category = CompetitionCategory::whereHas('masterParticipantClasses', fn ($q) => $q->where('master_participant_classes.id', $mpc->id))->first();
            if (! $category) {
                $this->warn("  Skip {$nama}: category not found for {$def['mpc']}");
                continue;
            }

            foreach ($lombaNames as $lombaName) {
                $event = $events[$lombaName] ?? Event::where('name', $lombaName)->first();
                if (! $event) {
                    $this->warn("  Skip {$nama} -> {$lombaName}: event missing");
                    continue;
                }

                $class = CompetitionClass::where('event_id', $event->id)
                    ->where('competition_category_id', $category->id)
                    ->where('is_active', true)
                    ->first();

                if (! $class) {
                    $this->warn("  Skip {$nama} -> {$lombaName}: class missing");
                    continue;
                }

                if ($class->gender !== 'M' && $class->gender !== $personGender) {
                    $this->warn("  Skip {$nama} -> {$lombaName}: gender mismatch class={$class->gender} person={$personGender}");
                    continue;
                }

                try {
                    $service->register(
                        nama: $nama,
                        jenisKelamin: $jenisKelamin,
                        tanggalLahir: null,
                        desaId: $desaRec->id,
                        eventId: $event->id,
                        competitionCategoryId: $category->id,
                        competitionClassId: $class->id,
                        kelompokId: $kelRec->id,
                        kelas: $mpc->name,
                    );
                    $createdRegs++;
                } catch (\Illuminate\Validation\ValidationException $e) {
                    $skipped++;
                } catch (\Throwable $e) {
                    $this->warn("  Error {$nama} -> {$lombaName}: ".$e->getMessage());
                    $skipped++;
                }
            }
        }

        $this->info("  Dummy Registrations: created {$createdRegs}, skipped {$skipped} (already exists)");
    }

    private function ensureTeamUatRegistrations(array $events, array $categories): void
    {
        $service = app(CompetitionRegistrationService::class);

        $kelompokPool = ['Km 7', 'Km 10', 'Kariangau', 'Perumnas', 'Gunung Samarinda', 'Sumberjo', 'Sepinggan 1', 'Sepinggan 2', 'Sepinggan 3', 'Batakan'];

        $specs = [
            ['event' => 'Lomba Pancing Kerupuk', 'cat' => 'Paud - SD 3', 'gender' => 'M', 'mpcs' => ['PAUD', 'SD 1', 'SD 2', 'SD 3'], 'n' => 8],
            ['event' => 'Lomba Pancing Kerupuk', 'cat' => 'SD 4 - 6', 'gender' => 'L', 'mpcs' => ['SD 4', 'SD 5', 'SD 6'], 'n' => 9],
            ['event' => 'Lomba Pancing Kerupuk', 'cat' => 'SD 4 - 6', 'gender' => 'P', 'mpcs' => ['SD 4', 'SD 5', 'SD 6'], 'n' => 10],
            ['event' => 'Lomba Migrasi Gelas', 'cat' => 'Paud - SD 3', 'gender' => 'M', 'mpcs' => ['PAUD', 'SD 1', 'SD 2', 'SD 3'], 'n' => 12],
            ['event' => 'Lomba Migrasi Gelas', 'cat' => 'SD 4 - 6', 'gender' => 'L', 'mpcs' => ['SD 4', 'SD 5', 'SD 6'], 'n' => 13],
            ['event' => 'Lomba Migrasi Gelas', 'cat' => 'SD 4 - 6', 'gender' => 'P', 'mpcs' => ['SD 4', 'SD 5', 'SD 6'], 'n' => 10],
            ['event' => 'Lomba Dragon Ball', 'cat' => 'Remaja', 'gender' => 'L', 'mpcs' => ['SMP', 'SMU'], 'n' => 15],
            ['event' => 'Lomba Dragon Ball', 'cat' => 'Remaja', 'gender' => 'P', 'mpcs' => ['SMP', 'SMU'], 'n' => 16],
            ['event' => 'Lomba Palu-Palu', 'cat' => 'Dewasa', 'gender' => 'L', 'mpcs' => ['Dewasa'], 'n' => 16],
            ['event' => 'Lomba Palu-Palu', 'cat' => 'Dewasa', 'gender' => 'P', 'mpcs' => ['Dewasa'], 'n' => 8],
            ['event' => 'Lomba Tarik Tambang', 'cat' => 'Remaja', 'gender' => 'L', 'mpcs' => ['SMP', 'SMU'], 'n' => 20],
            ['event' => 'Lomba Tarik Tambang', 'cat' => 'Dewasa', 'gender' => 'L', 'mpcs' => ['Dewasa'], 'n' => 15],
            ['event' => 'Migrasi Gelas', 'cat' => 'Remaja', 'gender' => 'P', 'mpcs' => ['SMP', 'SMU'], 'n' => 9],
            ['event' => 'Migrasi Gelas', 'cat' => 'Dewasa', 'gender' => 'P', 'mpcs' => ['Dewasa'], 'n' => 14],
            ['event' => 'Sedotan Hidung', 'cat' => 'Remaja', 'gender' => 'P', 'mpcs' => ['SMP', 'SMU'], 'n' => 12],
            ['event' => 'Sedotan Hidung', 'cat' => 'Dewasa', 'gender' => 'P', 'mpcs' => ['Dewasa'], 'n' => 11],
        ];

        $created = 0;
        $skipped = 0;
        $seq = 1;

        foreach ($specs as $specIdx => $spec) {
            $event = $events[$spec['event']] ?? Event::where('name', $spec['event'])->first();
            $category = $categories[$spec['cat']] ?? CompetitionCategory::where('name', $spec['cat'])->first();
            if (! $event || ! $category) {
                $this->warn("  Skip TEAM UAT {$spec['event']} {$spec['cat']}: event/category missing");
                continue;
            }

            $class = CompetitionClass::where('event_id', $event->id)
                ->where('competition_category_id', $category->id)
                ->where('gender', $spec['gender'])
                ->where('is_active', true)
                ->first();
            if (! $class || $class->format !== 'team_vs_team') {
                $this->warn("  Skip TEAM UAT {$spec['event']} {$spec['cat']} {$spec['gender']}: class not team_vs_team");
                continue;
            }

            $teamSize = (int) ($class->team_size ?? 4);
            $n = (int) $spec['n'];
            $numGroups = min(5, max(4, (int) ceil($n / max(1, $teamSize))));
            $numGroups = min($numGroups, count($kelompokPool), $n);
            $base = intdiv($n, $numGroups);
            $rem = $n % $numGroups;
            $counts = array_fill(0, $numGroups, $base);
            for ($r = 0; $r < $rem; $r++) $counts[$r]++;
            $maxAttempts = $numGroups * 3;
            $attempt = 0;
            while ($attempt++ < $maxAttempts && max($counts) <= $teamSize && $n >= $teamSize + $numGroups) {
                $donor = null;
                $minVal = PHP_INT_MAX;
                foreach ($counts as $idx => $c) {
                    if ($idx === array_search(max($counts), $counts)) continue;
                    if ($c > 1 && $c < $minVal) { $minVal = $c; $donor = $idx; }
                }
                if ($donor === null) break;
                $receiver = array_search(max($counts), $counts);
                $counts[$receiver]++;
                $counts[$donor]--;
            }
            $offset = $specIdx % max(1, count($kelompokPool) - $numGroups + 1);
            $kelNames = array_slice($kelompokPool, $offset, $numGroups);
            if (count($kelNames) < $numGroups) {
                $kelNames = array_merge($kelNames, array_slice($kelompokPool, 0, $numGroups - count($kelNames)));
            }
            $kelCounts = array_combine($kelNames, $counts);

            $globalIdx = 0;
            foreach ($kelCounts as $kName => $cnt) {
                $kelRec = kelompok::where('kelompok_asal', $kName)->first();
                if (! $kelRec) continue;
                $desaRec = $kelRec->desa ?? desa::where('id', $kelRec->desa_id)->first() ?? desa::first();
                if (! $desaRec) continue;

                for ($j = 0; $j < $cnt; $j++) {
                    $jk = 'L';
                    if ($spec['gender'] === 'P') $jk = 'P';
                    elseif ($spec['gender'] === 'M') $jk = ($globalIdx % 2 === 0) ? 'L' : 'P';
                    $mpcName = $spec['mpcs'][$globalIdx % count($spec['mpcs'])];
                    $globalIdx++;
                    $mpc = MasterParticipantClass::where('name', $mpcName)->first();
                    if (! $mpc) continue;
                    $jenisKelamin = $jk === 'P' ? 'Perempuan' : 'Laki - Laki';
                    $nama = sprintf('TEAM UAT %04d %s-%s-%s', $seq++, $spec['event'], $spec['cat'], $jk);
                    $nama = mb_substr($nama, 0, 80);
                    try {
                        $service->register(
                            nama: $nama,
                            jenisKelamin: $jenisKelamin,
                            tanggalLahir: null,
                            desaId: $desaRec->id,
                            eventId: $event->id,
                            competitionCategoryId: $category->id,
                            competitionClassId: $class->id,
                            kelompokId: $kelRec->id,
                            kelas: $mpc->name,
                        );
                        $created++;
                    } catch (\Illuminate\Validation\ValidationException $e) {
                        $skipped++;
                    } catch (\Throwable $e) {
                        $this->warn('  Error TEAM UAT '.$nama.': '.$e->getMessage());
                        $skipped++;
                    }
                }
            }
        }

        $this->info("  TEAM UAT Registrations: created {$created}, skipped {$skipped} (already exists)");

        $teamUatRegs = CompetitionRegistration::whereHas('participation.person', fn ($q) => $q->where('nama', 'like', 'TEAM UAT%'))->count();
        $teamUatPersons = Person::where('nama', 'like', 'TEAM UAT%')->count();
        $teamUatParts = Participation::whereHas('person', fn ($q) => $q->where('nama', 'like', 'TEAM UAT%'))->count();
        $this->info("  TEAM UAT Summary: persons={$teamUatPersons}, participations={$teamUatParts}, registrations={$teamUatRegs}");

        $byClass = CompetitionRegistration::with(['competitionClass.event', 'competitionCategory'])
            ->whereHas('participation.person', fn ($q) => $q->where('nama', 'like', 'TEAM UAT%'))
            ->get()
            ->groupBy(fn ($r) => ($r->competitionClass?->event?->name ?? '?').' | '.$r->competitionCategory?->name.' | '.$r->competitionClass?->gender.' | team_size='.$r->competitionClass?->team_size)
            ->map(fn ($g) => $g->count())
            ->sortKeys();
        foreach ($byClass as $key => $cnt) {
            $teamSize = CompetitionClass::whereHas('event', fn ($q) => $q->where('name', explode(' | ', $key)[0]))->where('gender', explode(' | ', $key)[2])->first()?->team_size ?? '?';
            $rem = is_numeric($cnt) && is_numeric($teamSize) ? $cnt % $teamSize : '?';
            $this->line("    - {$key}: {$cnt} (sisa ". $rem .")");
        }
        $byKelompok = CompetitionRegistration::with(['participation.person.kelompok'])
            ->whereHas('participation.person', fn ($q) => $q->where('nama', 'like', 'TEAM UAT%'))
            ->get()
            ->groupBy(fn ($r) => $r->participation?->person?->kelompok?->kelompok_asal ?? '?')
            ->map(fn ($g) => $g->count())
            ->sortKeys();
        $this->info('  TEAM UAT per Kelompok: '. $byKelompok->map(fn ($c,$k) => "{$k}={$c}")->implode(', '));
    }

    private function reportSummary(): void
    {
        $counts = [
            'Person' => Person::where('nama', 'like', 'UAT %')->count(),
            'Participation (UAT)' => Participation::whereHas('person', fn ($q) => $q->where('nama', 'like', 'UAT %'))->count(),
            'CompetitionRegistration (UAT)' => CompetitionRegistration::whereHas('participation.person', fn ($q) => $q->where('nama', 'like', 'UAT %'))->count(),
            'Event (competition active)' => Event::where('event_type', 'competition')->where('status', 'active')->count(),
            'CompetitionCategory' => CompetitionCategory::count(),
            'CompetitionClass' => CompetitionClass::count(),
        ];

        foreach ($counts as $label => $cnt) {
            $this->info("  {$label}: {$cnt}");
        }

        $samples = CompetitionRegistration::with(['participation.person', 'participation.event', 'competitionCategory', 'competitionClass'])
            ->whereHas('participation.person', fn ($q) => $q->where('nama', 'like', 'UAT %'))
            ->orderBy('id')
            ->limit(10)
            ->get();

        if ($samples->isNotEmpty()) {
            $this->info('  Samples:');
            foreach ($samples as $r) {
                $p = $r->participation?->person;
                $ev = $r->participation?->event;
                $cat = $r->competitionCategory;
                $cls = $r->competitionClass;
                $this->line("    - {$p?->nama} | mpc={$p?->kelas} gender={$p?->jenis_kelamin} | event={$ev?->name} | cat={$cat?->name} | class={$cls?->name} | class_gender={$cls?->gender}");
            }
        }

        $cross = CompetitionRegistration::with(['participation', 'competitionClass'])
            ->whereHas('participation.person', fn ($q) => $q->where('nama', 'like', 'UAT %'))
            ->get()
            ->filter(fn ($r) => $r->participation?->event_id !== $r->competitionClass?->event_id)
            ->count();

        $this->info("  Cross-event UAT: {$cross} (expected 0)");

        $dups = CompetitionRegistration::selectRaw('participation_id, competition_class_id, COUNT(*) as c')
            ->whereHas('participation.person', fn ($q) => $q->where('nama', 'like', 'UAT %'))
            ->groupBy('participation_id', 'competition_class_id')
            ->having('c', '>', 1)
            ->count();

        $this->info("  Duplicate UAT: {$dups} (expected 0)");
    }
}
