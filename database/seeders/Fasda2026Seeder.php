<?php

namespace Database\Seeders;

use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionRegistration;
use App\Models\Event;
use App\Models\MasterParticipantClass;
use App\Models\Participation;
use App\Models\Person;
use App\Models\desa;
use App\Models\kelompok;
use App\Services\Placement\PlacementService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Fasda2026Seeder extends Seeder
{
    /**
     * Lomba khusus Putra (gender L saja). Gender dari CSV tetap dipaksa L.
     */
    private const PUTRA_ONLY = [
        'Adzan & Qomat',
        'Aplikasi Penerapan 29 Karakter Luhur Jamaah',
        'Khotbah',
        'Kaifiyatussholah',
    ];

    /**
     * Import data peserta FASDA 2026 (data lomba nyata).
     *
     * Sumber:
     * database/seeders/data/fasda2026_final.csv
     *
     * Data yang diimport:
     * - Person
     * - Gender
     * - Tanggal lahir
     * - Desa
     * - Kelompok
     * - Master Kelas Peserta
     * - Lomba / Event
     * - Kategori
     * - Kelas Lomba
     * - Competition Registration
     *
     * Tidak membuat:
     * - Team
     * - Schedule
     * - Heat
     * - Bracket
     * - Outcome
     *
     * Seeder bersifat idempotent dan non-destructive.
     */
    public function run(): void
    {
        $path = database_path(
            'seeders/data/fasda2026_final.csv'
        );

        if (! is_file($path)) {
            $this->command?->error(
                "File data tidak ditemukan: {$path}"
            );

            return;
        }

        $rows = $this->readCsv($path);

        $this->command?->info(
            'FASDA 2026: '.count($rows).' baris data ditemukan.'
        );

        /*
         * Urutkan deterministik: per lomba, lalu nama A-Z.
         *
         * Ini membuat participant_number (yang diberikan berurutan per
         * lomba + gender) konsisten alfabetis, bukan mengikuti urutan CSV
         * atau id database.
         */
        usort($rows, function (array $a, array $b): int {
            $eventA = mb_strtolower($this->canonicalBranch((string) ($a['cabang_perlombaan'] ?? '')));
            $eventB = mb_strtolower($this->canonicalBranch((string) ($b['cabang_perlombaan'] ?? '')));

            if ($eventA !== $eventB) {
                return $eventA <=> $eventB;
            }

            return mb_strtolower((string) ($a['nama'] ?? '')) <=> mb_strtolower((string) ($b['nama'] ?? ''));
        });

        DB::transaction(function () use ($rows): void {
            /*
             * -------------------------------------------------------------
             * 1. MASTER KELAS PESERTA
             * -------------------------------------------------------------
             */
            $masterClasses =
                $this->ensureMasterClasses($rows);

            /*
             * -------------------------------------------------------------
             * 2. LOMBA / EVENT
             * -------------------------------------------------------------
             */
            $events =
                $this->ensureEvents($rows);

            /*
             * -------------------------------------------------------------
             * 3. DESA / KELOMPOK
             * -------------------------------------------------------------
             */
            $desaCache = [];
            $kelompokCache = [];

            /*
             * -------------------------------------------------------------
             * COUNTER
             * -------------------------------------------------------------
             */
            $createdPeople = 0;
            $reusedPeople = 0;
            $updatedPeople = 0;

            $createdParticipations = 0;
            $reusedParticipations = 0;

            $createdCategories = 0;
            $reusedCategories = 0;

            $createdClasses = 0;
            $reusedClasses = 0;

            $createdRegistrations = 0;
            $reusedRegistrations = 0;

            $genderL = 0;
            $genderP = 0;
            $genderEmpty = 0;

            /*
             * Untuk memastikan kategori dan kelas yang sama
             * tidak dibuat berulang selama satu proses import.
             */
            $categoryCache = [];
            $classCache = [];

            /*
             * -------------------------------------------------------------
             * 4. PROCESS SETIAP BARIS PESERTA
             * -------------------------------------------------------------
             */
            foreach ($rows as $row) {
                /*
                 * Nama.
                 *
                 * Baris dengan nama "-" dianggap invalid.
                 */
                $name = $this->nullable(
                    $row['nama'] ?? null
                );

                if (! $name) {
                    continue;
                }

                /*
                 * ---------------------------------------------------------
                 * GENDER
                 * ---------------------------------------------------------
                 *
                 * CSV baru menggunakan:
                 * jenis_kelamin_inferred
                 */
                $branch =
                    $this->canonicalBranch(
                        (string) ($row['cabang_perlombaan'] ?? '')
                    );

                $gender = $this->normalizeGender(
                    $row['jenis_kelamin_inferred']
                        ?? $row['gender']
                        ?? $row['jenis_kelamin']
                        ?? null
                );

                /*
                 * Lomba khusus Putra: paksa gender L apa pun isi CSV.
                 */
                if (in_array($branch, self::PUTRA_ONLY, true)) {
                    $gender = 'L';
                }

                if ($gender === 'L') {
                    $genderL++;
                } elseif ($gender === 'P') {
                    $genderP++;
                } else {
                    $genderEmpty++;
                }

                /*
                 * ---------------------------------------------------------
                 * DESA
                 * ---------------------------------------------------------
                 */
                $desaName = $this->normalizeDesaName(
                    $this->nullable(
                        $row['desa'] ?? null
                    )
                );

                $desaModel = $desaName
                    ? $this->ensureDesa(
                        $desaName,
                        $desaCache
                    )
                    : null;

                /*
                 * ---------------------------------------------------------
                 * KELOMPOK
                 * ---------------------------------------------------------
                 */
                $kelompokName = $this->normalizeKelompokName(
                    $this->nullable(
                        $row['kelompok'] ?? null
                    )
                );

                $kelompokModel = $kelompokName
                    ? $this->ensureKelompok(
                        $kelompokName,
                        $desaModel?->id,
                        $kelompokCache
                    )
                    : null;

                /*
                 * ---------------------------------------------------------
                 * MASTER KELAS PESERTA
                 * ---------------------------------------------------------
                 */
                $mpcName = $this->nullable(
                    $row['mpc_candidate'] ?? null
                );

                if (
                    $mpcName &&
                    ! isset($masterClasses[$mpcName])
                ) {
                    $masterClasses[$mpcName] =
                        $this->ensureMasterParticipantClass(
                            $mpcName
                        );
                }

                /*
                 * ---------------------------------------------------------
                 * TANGGAL LAHIR
                 * ---------------------------------------------------------
                 */
                $birthDate =
                    $this->parseBirthDate(
                        $row['tempat_tanggal_lahir']
                            ?? null
                    );

                /*
                 * ---------------------------------------------------------
                 * PERSON
                 * ---------------------------------------------------------
                 */
                $person = $this->findPerson(
                    name: $name,
                    birthDate: $birthDate,
                    desaId: $desaModel?->id,
                );

                if (! $person) {
                    $person = Person::create([
                        'nama' => $name,
                        'jenis_kelamin' => $gender,
                        'tanggal_lahir' => $birthDate,
                        'desa_id' => $desaModel?->id,
                        'kelompok_id' => $kelompokModel?->id,
                    ]);

                    $createdPeople++;
                } else {
                    $reusedPeople++;

                    $updates = [];

                    /*
                     * Jangan menimpa data existing dengan NULL.
                     */
                    if (
                        ! $person->tanggal_lahir &&
                        $birthDate
                    ) {
                        $updates['tanggal_lahir'] =
                            $birthDate;
                    }

                    if (
                        ! $person->desa_id &&
                        $desaModel
                    ) {
                        $updates['desa_id'] =
                            $desaModel->id;
                    }

                    if (
                        ! $person->kelompok_id &&
                        $kelompokModel
                    ) {
                        $updates['kelompok_id'] =
                            $kelompokModel->id;
                    }

                    /*
                     * Gender dari CSV hanya mengisi
                     * jika gender existing masih kosong.
                     */
                    if (
                        ! $person->jenis_kelamin &&
                        $gender
                    ) {
                        $updates['jenis_kelamin'] =
                            $gender;
                    }

                    if ($updates) {
                        $person->update($updates);
                        $updatedPeople++;
                    }
                }

                /*
                 * ---------------------------------------------------------
                 * LOMBA / EVENT
                 * ---------------------------------------------------------
                 */
                $event =
                    $events[$branch] ?? null;

                if (! $event) {
                    continue;
                }

                /*
                 * ---------------------------------------------------------
                 * PARTICIPATION
                 * ---------------------------------------------------------
                 *
                 * Satu Person = satu Participation per Lomba/Event.
                 */
                $participation =
                    Participation::where(
                        'person_id',
                        $person->id
                    )
                    ->where(
                        'event_id',
                        $event->id
                    )
                    ->first();

                if ($participation) {
                    $reusedParticipations++;
                } else {
                    $participation =
                        Participation::create([
                            'person_id' => $person->id,
                            'event_id' => $event->id,
                            'participant_number' =>
                                PlacementService::generateParticipantNumber(
                                    $event->id,
                                    PlacementService::normalizePersonGender(
                                        (string) $gender
                                    )
                                ),
                            'attendance_code' =>
                                $this->uniqueAttendanceCode(),
                            'jenis_peserta' => 'Peserta',
                        ]);

                    $createdParticipations++;
                }

                /*
                 * ---------------------------------------------------------
                 * KATEGORI
                 * ---------------------------------------------------------
                 *
                 * Kategori = jenjang peserta (individual), diambil dari
                 * mpc_candidate. BUKAN gabungan rentang seperti
                 * "Paud - SD 3" / "SD 4 - 6".
                 */
                $categoryName =
                    $this->nullable(
                        $row['mpc_candidate']
                            ?? null
                    );

                if (! $categoryName) {
                    continue;
                }

                /*
                 * Kategori di schema aktual aplikasi:
                 *
                 * - competition_categories.event_id NOT NULL (production)
                 * - satu kategori dimiliki SATU event (one-to-many), BUKAN
                 *   kategori global. Tidak ada pivot competition_category_event.
                 *
                 * Karena itu kategori di-reuse berdasarkan (event_id, name),
                 * bukan berdasarkan name global.
                 */
                $categoryKey =
                    $event->id.'|name:'.mb_strtolower(
                        $categoryName
                    );

                if (
                    isset(
                        $categoryCache[$categoryKey]
                    )
                ) {
                    $category =
                        $categoryCache[$categoryKey];

                    $reusedCategories++;
                } else {
                    $category =
                        CompetitionCategory::where(
                            'event_id',
                            $event->id
                        )
                        ->whereRaw(
                            'LOWER(name) = ?',
                            [
                                mb_strtolower(
                                    $categoryName
                                ),
                            ]
                        )
                        ->first();

                    if ($category) {
                        $reusedCategories++;
                    } else {
                        $category =
                            CompetitionCategory::create([
                                'event_id' => $event->id,
                                'name' => $categoryName,
                                'code' =>
                                    $this->categoryCode(
                                        $categoryName
                                    ),
                                'sort_order' =>
                                    $this->categorySortOrder(
                                        $event,
                                        $categoryName
                                    ),
                                'is_active' => true,
                            ]);

                        $createdCategories++;
                    }

                    $categoryCache[$categoryKey] =
                        $category;
                }

                /*
                 * ---------------------------------------------------------
                 * KATEGORI ? MASTER KELAS PESERTA
                 * ---------------------------------------------------------
                 *
                 * Ambil mpc_candidate dari baris ini.
                 *
                 * Dengan begitu:
                 *
                 * PAUD - SD 3
                 *   -> PAUD
                 *   -> SD 1
                 *   -> SD 2
                 *   -> SD 3
                 *
                 * SD 4 - 6
                 *   -> SD 4
                 *   -> SD 5
                 *   -> SD 6
                 */
                if (
                    $mpcName &&
                    isset($masterClasses[$mpcName])
                ) {
                    $mpc =
                        $masterClasses[$mpcName];

                    DB::table(
                        'competition_category_master_participant_class'
                    )->updateOrInsert(
                        [
                            'competition_category_id' =>
                                $category->id,
                            'master_participant_class_id' =>
                                $mpc->id,
                        ],
                        [
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }

                /*
                 * ---------------------------------------------------------
                 * KELAS LOMBA
                 * ---------------------------------------------------------
                 *
                 * Dibuat berdasarkan:
                 *
                 * Event
                 * + Category
                 * + Gender yang tersedia di data
                 *
                 * Contoh:
                 *
                 * PAUD - SD 3 - Putra
                 * PAUD - SD 3 - Putri
                 *
                 * Tidak membuat kelas gender yang tidak ada
                 * datanya.
                 */
                /*
                 * Gender class: male-only -> L; semua lomba FASDA lain -> M
                 * (mixed/campuran). Peserta L maupun P masuk ke class M.
                 */
                $classGender =
                    in_array($branch, self::PUTRA_ONLY, true) ? 'L' : 'M';

                $classKey =
                    $event->id.
                    '|'.
                    $category->id.
                    '|'.
                    $classGender;

                if (
                    isset(
                        $classCache[$classKey]
                    )
                ) {
                    $competitionClass =
                        $classCache[$classKey];

                    $reusedClasses++;
                } else {
                    $competitionClass =
                        CompetitionClass::where(
                            'event_id',
                            $event->id
                        )
                        ->where(
                            'competition_category_id',
                            $category->id
                        )
                        ->where(
                            'gender',
                            $classGender
                        )
                        ->first();

                    if ($competitionClass) {
                        $reusedClasses++;
                    } else {
                        $competitionClass =
                            CompetitionClass::create([
                                'event_id' =>
                                    $event->id,

                                'competition_category_id' =>
                                    $category->id,

                                /*
                                 * Nama kelas di-scope ke nama event.
                                 *
                                 * Kategori kini di-reuse global (satu
                                 * competition_category_id dipakai banyak
                                 * event), sedangkan competition_classes
                                 * punya UNIQUE (competition_category_id,
                                 * name). Tanpa prefix event, dua event
                                 * yang memakai kategori sama akan membuat
                                 * nama kelas yang TIDAK unik.
                                 */
                                'name' =>
                                    $event->name.
                                    ' - '.
                                    $categoryName.
                                    ' - '.
                                    $this->genderLabel(
                                        $classGender
                                    ),

                                'gender' =>
                                    $classGender,

                                /*
                                 * Default awal kelas lomba.
                                 *
                                 * Nanti dapat diubah melalui
                                 * Setting Kelas Lomba.
                                 */
                                'format' =>
                                    'individual_mass',

                                'status' =>
                                    'registration_open',

                                'result_type' =>
                                    'score',

                                'winner_count' =>
                                    4,

                                'code' =>
                                    $this->classCode(
                                        $event,
                                        $category,
                                        $classGender
                                    ),

                                'sort_order' =>
                                    $this->classSortOrder(
                                        $event,
                                        $category,
                                        $classGender
                                    ),

                                'is_active' => true,
                            ]);

                        $createdClasses++;
                    }

                    $classCache[$classKey] =
                        $competitionClass;
                }

                /*
                 * ---------------------------------------------------------
                 * COMPETITION REGISTRATION
                 * ---------------------------------------------------------
                 *
                 * Participation yang sudah dibuat di atas
                 * langsung didaftarkan ke Kelas Lomba.
                 */
                $existingRegistration =
                    CompetitionRegistration::where(
                        'participation_id',
                        $participation->id
                    )
                    ->where(
                        'competition_class_id',
                        $competitionClass->id
                    )
                    ->first();

                if ($existingRegistration) {
                    $reusedRegistrations++;

                    continue;
                }

                /*
                 * Gunakan category dari class.
                 *
                 * Ini memastikan:
                 * registration.category_id
                 * =
                 * class.category_id
                 */
                CompetitionRegistration::create([
                    'participation_id' =>
                        $participation->id,

                    'competition_category_id' =>
                        $category->id,

                    'competition_class_id' =>
                        $competitionClass->id,

                    'registration_type' =>
                        'individual',
                ]);

                $createdRegistrations++;
            }

            /*
             * -------------------------------------------------------------
             * OUTPUT
             * -------------------------------------------------------------
             */
            $this->command?->newLine();

            $this->command?->info(
                "Person baru             : {$createdPeople}"
            );

            $this->command?->info(
                "Person dipakai          : {$reusedPeople}"
            );

            $this->command?->info(
                "Person diperbarui       : {$updatedPeople}"
            );

            $this->command?->info(
                "Participation baru      : {$createdParticipations}"
            );

            $this->command?->info(
                "Participation sudah     : {$reusedParticipations}"
            );

            $this->command?->info(
                "Kategori baru           : {$createdCategories}"
            );

            $this->command?->info(
                "Kategori sudah          : {$reusedCategories}"
            );

            $this->command?->info(
                "Kelas Lomba baru        : {$createdClasses}"
            );

            $this->command?->info(
                "Kelas Lomba sudah       : {$reusedClasses}"
            );

            $this->command?->info(
                "CompetitionRegistration : {$createdRegistrations}"
            );

            $this->command?->info(
                "Registration sudah      : {$reusedRegistrations}"
            );

            $this->command?->newLine();

            $this->command?->info(
                "Gender L                 : {$genderL}"
            );

            $this->command?->info(
                "Gender P                 : {$genderP}"
            );

            $this->command?->info(
                "Gender kosong            : {$genderEmpty}"
            );

            $this->command?->info(
                "Lomba/Event              : ".count($events)
            );

            $this->command?->info(
                "Master kelas peserta     : ".count($masterClasses)
            );
        });

        $this->command?->newLine();

        $this->command?->info(
            'Fasda2026Seeder: DONE'
        );

        $this->command?->warn(
            'Data peserta FASDA langsung diregistrasikan ke Kelas Lomba.'
        );

        $this->command?->warn(
            'Format awal Kelas Lomba = individual_mass, result_type = score.'
        );
    }

    /*
     * =====================================================================
     * CSV
     * =====================================================================
     */

    private function readCsv(
        string $path
    ): array {
        $handle = fopen(
            $path,
            'rb'
        );

        if (! $handle) {
            throw new \RuntimeException(
                "Tidak dapat membaca {$path}"
            );
        }

        $header =
            fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            return [];
        }

        $header =
            array_map(
                fn ($value) =>
                    trim((string) $value),
                $header
            );

        $rows = [];

        while (
            ($values = fgetcsv($handle))
            !== false
        ) {
            /*
             * Lewati baris kosong.
             */
            if (
                count(
                    array_filter(
                        $values,
                        fn ($value) =>
                            trim(
                                (string) $value
                            ) !== ''
                    )
                ) === 0
            ) {
                continue;
            }

            /*
             * Pastikan jumlah kolom sama.
             */
            $values =
                array_pad(
                    $values,
                    count($header),
                    null
                );

            $values =
                array_slice(
                    $values,
                    0,
                    count($header)
                );

            $row =
                array_combine(
                    $header,
                    $values
                );

            if (! $row) {
                continue;
            }

            $row =
                array_map(
                    fn ($value) =>
                        is_string($value)
                            ? trim($value)
                            : $value,
                    $row
                );

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /*
     * =====================================================================
     * MASTER KELAS PESERTA
     * =====================================================================
     */

    private function ensureMasterClasses(
        array $rows
    ): array {
        $names =
            collect($rows)
                ->pluck('mpc_candidate')
                ->map(
                    fn ($name) =>
                        $this->nullable($name)
                )
                ->filter()
                ->unique()
                ->values();

        $result = [];

        foreach (
            $names as $index => $name
        ) {
            $result[$name] =
                $this->ensureMasterParticipantClass(
                    $name,
                    $index + 1
                );
        }

        return $result;
    }

    private function ensureMasterParticipantClass(
        string $name,
        ?int $sortOrder = null
    ): MasterParticipantClass {
        $model =
            MasterParticipantClass::whereRaw(
                'LOWER(name) = ?',
                [
                    mb_strtolower($name),
                ]
            )->first();

        if ($model) {
            return $model;
        }

        return MasterParticipantClass::create([
            'name' => $name,
            'code' => Str::slug($name),
            'sort_order' =>
                $sortOrder ?? 0,
            'is_active' => true,
        ]);
    }

    /*
     * =====================================================================
     * EVENT / LOMBA
     * =====================================================================
     */

    private function ensureEvents(
        array $rows
    ): array {
        $names =
            collect($rows)
                ->pluck('cabang_perlombaan')
                ->map(
                    fn ($name) =>
                        $this->canonicalBranch(
                            (string) $name
                        )
                )
                ->filter()
                ->unique()
                ->values();

        $events = [];

        foreach ($names as $name) {
            $event =
                Event::whereRaw(
                    'LOWER(name) = ?',
                    [
                        mb_strtolower($name),
                    ]
                )
                ->where(
                    'event_type',
                    'competition'
                )
                ->first();

            if (! $event) {
                $event =
                    Event::create([
                        'name' => $name,
                        'slug' =>
                            $this->uniqueEventSlug(
                                $name
                            ),
                        'event_type' =>
                            'competition',
                        'status' =>
                            'active',
                        'start_date' =>
                            '2026-01-01',
                        'end_date' =>
                            '2026-12-31',
                    ]);
            }

            $events[$name] =
                $event;
        }

        return $events;
    }

    private function uniqueEventSlug(
        string $name
    ): string {
        $base =
            Str::slug($name).
            '-fasda-2026';

        $slug = $base;
        $counter = 2;

        while (
            Event::where(
                'slug',
                $slug
            )->exists()
        ) {
            $slug =
                $base.
                '-'.
                $counter;

            $counter++;
        }

        return $slug;
    }

    /*
     * =====================================================================
     * DESA
     * =====================================================================
     */

    private function normalizeDesaName(
        ?string $name
    ): ?string {
        if (! $name) {
            return null;
        }

        $name = preg_replace(
            '/\s+/',
            ' ',
            trim($name)
        );

        // FASDA menulis desa ini sebagai "RING ROAD"; master aplikasi
        // memakai "Ringroad". Samakan agar tidak menjadi dua record.
        return match (mb_strtolower($name)) {
            'ring road' => 'Ringroad',

            default => $name,
        };
    }

    private function normalizeKelompokName(
        ?string $name
    ): ?string {
        if (! $name) {
            return null;
        }

        $name = preg_replace(
            '/\s+/',
            ' ',
            trim($name)
        );

        // "KM. 7", "KM.7", "KM 7" -> "KM 7"
        $name = preg_replace(
            '/^KM\s*\.?\s*/i',
            'KM ',
            $name
        );

        // Mapping varian penulisan ke nilai canonical master aplikasi.
        // - Lamaru/Sosial Lamaru (FASDA) -> Lemaru/Lemaru Sosial (master).
        // - Melatih -> Melati.
        // - Bandara Lama -> Sepinggan 2.
        return match (mb_strtolower($name)) {
            'sumber rejo' => 'Sumber Rejo',
            'sumberejo' => 'Sumber Rejo',
            'lamaru' => 'Lemaru',
            'sosial lamaru' => 'Lemaru Sosial',
            'melatih' => 'Melati',
            'bandara lama' => 'Sepinggan 2',

            default => $name,
        };
    }

    private function ensureDesa(
        string $name,
        array &$cache
    ): desa {
        $key =
            mb_strtolower(
                trim($name)
            );

        if (
            isset($cache[$key])
        ) {
            return $cache[$key];
        }

        $model =
            desa::whereRaw(
                'LOWER(desa_asal) = ?',
                [$key]
            )->first();

        if (! $model) {
            /*
             * Schema desa saat ini tidak mempunyai is_active.
             */
            $model =
                desa::create([
                    'desa_asal' => $name,
                ]);
        }

        $cache[$key] =
            $model;

        return $model;
    }

    /*
     * =====================================================================
     * KELOMPOK
     * =====================================================================
     */

    private function ensureKelompok(
        string $name,
        ?int $desaId,
        array &$cache
    ): kelompok {
        $normalizedName =
            mb_strtolower(
                trim($name)
            );

        $key =
            ($desaId ?? 0).
            '|'.
            $normalizedName;

        if (
            isset($cache[$key])
        ) {
            return $cache[$key];
        }

        $query =
            kelompok::whereRaw(
                'LOWER(kelompok_asal) = ?',
                [$normalizedName]
            );

        if ($desaId) {
            $query->where(
                'desa_id',
                $desaId
            );
        } else {
            $query->whereNull(
                'desa_id'
            );
        }

        $model =
            $query->first();

        if (! $model) {
            /*
             * Schema kelompok saat ini tidak mempunyai is_active.
             */
            $model =
                kelompok::create([
                    'kelompok_asal' =>
                        $name,
                    'desa_id' =>
                        $desaId,
                ]);
        }

        $cache[$key] =
            $model;

        return $model;
    }

    /*
     * =====================================================================
     * PERSON
     * =====================================================================
     */

    private function findPerson(
        string $name,
        ?string $birthDate,
        ?int $desaId
    ): ?Person {
        $query =
            Person::whereRaw(
                'LOWER(nama) = ?',
                [
                    mb_strtolower($name),
                ]
            );

        if ($birthDate) {
            $query->whereDate(
                'tanggal_lahir',
                $birthDate
            );
        } elseif ($desaId) {
            $query->where(
                'desa_id',
                $desaId
            );
        }

        return $query
            ->orderBy('id')
            ->first();
    }

    /*
     * =====================================================================
     * GENDER
     * =====================================================================
     */

    private function normalizeGender(
        ?string $gender
    ): ?string {
        $gender =
            strtoupper(
                trim(
                    (string) $gender
                )
            );

        return match ($gender) {
            'L',
            'LAKI',
            'LAKI-LAKI',
            'LAKI LAKI',
            'M',
            'MALE'
                => 'L',

            'P',
            'PEREMPUAN',
            'WANITA',
            'F',
            'FEMALE'
                => 'P',

            default => null,
        };
    }

    private function genderLabel(
        string $gender
    ): string {
        return match ($gender) {
            'L' => 'Putra',
            'P' => 'Putri',
            default => 'Campuran',
        };
    }

    /*
     * =====================================================================
     * DATE
     * =====================================================================
     */

    private function parseBirthDate(
        ?string $raw
    ): ?string {
        $raw =
            $this->nullable($raw);

        if (! $raw) {
            return null;
        }

        /*
         * Format:
         * Tempat, tanggal
         */
        $parts =
            preg_split(
                '/\s*,\s*/',
                $raw,
                2
            );

        $dateText =
            trim(
                $parts[1] ?? $raw
            );

        /*
         * Bahasa Indonesia -> English.
         */
        $months = [
            'januari' => 'January',
            'februari' => 'February',
            'maret' => 'March',
            'april' => 'April',
            'mei' => 'May',
            'juni' => 'June',
            'juli' => 'July',
            'agustus' => 'August',
            'september' => 'September',
            'oktober' => 'October',
            'november' => 'November',
            'desember' => 'December',
        ];

        $normalized =
            str_ireplace(
                array_keys($months),
                array_values($months),
                $dateText
            );

        foreach ([
            'd-m-Y',
            'd/m/Y',
            'd.m.Y',
            'j F Y',
            'd F Y',
            'j-M-Y',
            'd-M-Y',
        ] as $format) {
            try {
                return Carbon::createFromFormat(
                    $format,
                    $normalized
                )->format('Y-m-d');
            } catch (\Throwable) {
                // Coba format berikutnya.
            }
        }

        try {
            return Carbon::parse(
                $normalized
            )->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /*
     * =====================================================================
     * CATEGORY
     * =====================================================================
     */

    private function categoryCode(
        string $name
    ): string {
        $base =
            'F26-'.
            strtoupper(
                Str::slug(
                    $name,
                    '-'
                )
            );

        $base =
            substr(
                $base,
                0,
                40
            );

        $code = $base;
        $counter = 2;

        while (
            CompetitionCategory::where(
                'code',
                $code
            )
            ->exists()
        ) {
            $code =
                substr(
                    $base,
                    0,
                    36
                ).
                '-'.
                $counter;

            $counter++;
        }

        return $code;
    }

    private function categorySortOrder(
        Event $event,
        string $name
    ): int {
        $existing =
            CompetitionCategory::where(
                'event_id',
                $event->id
            )->max('sort_order');

        return ((int) $existing) + 1;
    }

    /*
     * =====================================================================
     * COMPETITION CLASS
     * =====================================================================
     */

    private function classCode(
        Event $event,
        CompetitionCategory $category,
        string $gender
    ): string {
        $base =
            'F26-'.
            $category->id.
            '-'.
            $gender;

        $code = $base;
        $counter = 2;

        while (
            CompetitionClass::where(
                'event_id',
                $event->id
            )
            ->where(
                'code',
                $code
            )
            ->exists()
        ) {
            $code =
                $base.
                '-'.
                $counter;

            $counter++;
        }

        return $code;
    }

    private function classSortOrder(
        Event $event,
        CompetitionCategory $category,
        string $gender
    ): int {
        $existing =
            CompetitionClass::where(
                'event_id',
                $event->id
            )->max('sort_order');

        return ((int) $existing) + 1;
    }

    /*
     * =====================================================================
     * NORMALIZER
     * =====================================================================
     */

    private function canonicalBranch(
        string $name
    ): string {
        $name =
            trim(
                preg_replace(
                    '/\s+/',
                    ' ',
                    $name
                )
            );

        return match (
            mb_strtolower($name)
        ) {
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

    private function nullable(
        ?string $value
    ): ?string {
        $value =
            trim(
                (string) $value
            );

        if (
            $value === '' ||
            mb_strtolower($value) === 'null' ||
            $value === '-'
        ) {
            return null;
        }

        return $value;
    }

    /*
     * =====================================================================
     * UNIQUE PARTICIPANT NUMBER
     * =====================================================================
     */

    /*
     * =====================================================================
     * UNIQUE ATTENDANCE CODE
     * =====================================================================
     */

    private function uniqueAttendanceCode(): string
    {
        do {
            $code =
                'F26-'.
                strtoupper(
                    Str::random(8)
                );
        } while (
            Participation::where(
                'attendance_code',
                $code
            )->exists()
        );

        return $code;
    }
}