<?php

namespace App\Console\Commands;

use App\Models\CompetitionClass;
use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normalisasi gender CompetitionClass untuk data existing:
 * - Lomba campuran (semua lomba FASDA kecuali 4 male-only) memakai SATU
 *   class gender M (mixed/wildcard), bukan class L dan P terpisah.
 * - Lomba male-only tetap memakai class L.
 *
 * Tidak menyentuh Person maupun Participation.
 * Registration dipindahkan (UPDATE competition_class_id), bukan dibuat ulang,
 * dan hanya class L/P campuran yang sudah kosong yang dihapus.
 *
 * Default: DRY-RUN. Tambahkan --force untuk menerapkan.
 */
class NormalizeGenderClasses extends Command
{
    protected $signature = 'competition:normalize-gender-classes
        {--force : Terapkan perubahan (tanpa flag ini hanya dry-run)}';

    protected $description = 'Gabungkan class L/P lomba campuran menjadi satu class M.';

    /**
     * Lomba khusus laki-laki.
     */
    private const MALE_ONLY = [
        'Adzan & Qomat',
        'Kaifiyatussholah',
        'Aplikasi Penerapan 29 Karakter Luhur Jamaah',
        'Khotbah',
    ];

    /**
     * Tabel/kolom yang menahan penghapusan class selain registrations.
     * Registrations dipindahkan, sehingga tidak termasuk blocker.
     */
    private const DEPENDENTS = [
        'competition_teams' => 'competition_class_id',
        'competition_schedules' => 'competition_class_id',
        'competition_heat_formats' => 'competition_class_id',
        'competition_heat_qualifiers' => 'competition_class_id',
        'competition_brackets' => 'competition_class_id',
    ];

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->info($force
            ? 'MODE: APPLY (perubahan akan disimpan)'
            : 'MODE: DRY-RUN (tidak ada perubahan; gunakan --force untuk menerapkan)');

        $events = Event::where('event_type', 'competition')->orderBy('id')->get();

        $groups = 0;
        $created = 0;
        $moved = 0;
        $deleted = 0;
        $blocked = 0;
        $maleOnlyBad = 0;

        foreach ($events as $event) {
            if (in_array($event->name, self::MALE_ONLY, true)) {
                $nonL = CompetitionClass::where('event_id', $event->id)
                    ->where('gender', '!=', 'L')
                    ->count();

                if ($nonL > 0) {
                    $maleOnlyBad += $nonL;
                    $this->warn("  [male-only] {$event->name}: {$nonL} class non-L (tidak diubah otomatis).");
                }

                continue;
            }

            $byCategory = CompetitionClass::where('event_id', $event->id)
                ->orderBy('id')
                ->get()
                ->groupBy('competition_category_id');

            foreach ($byCategory as $categoryId => $classes) {
                $hasSplit = $classes->contains(fn ($c) => in_array($c->gender, ['L', 'P'], true));

                if (! $hasSplit) {
                    continue;
                }

                $groups++;

                $existingM = $classes->firstWhere('gender', 'M');
                $target = $existingM ?? $classes->first();
                $others = $classes->reject(fn ($c) => $c->id === $target->id);

                $blockers = $this->dependentCounts($others->pluck('id')->all());

                if ($blockers !== []) {
                    $blocked++;
                    $this->warn("  [skip] {$event->name} / kategori #{$categoryId}: ada dependent " . json_encode($blockers));

                    continue;
                }

                $label = $classes->map(fn ($c) => "#{$c->id}:{$c->gender}")->implode(', ');
                $this->line("  {$event->name} / kategori #{$categoryId}: {$label} -> #{$target->id} M");

                if (! $force) {
                    continue;
                }

                DB::transaction(function () use ($event, $target, $others, $existingM, &$created, &$moved, &$deleted) {
                    $categoryName = $target->competitionCategory?->name
                        ?? $target->competitionCategory()->value('name')
                        ?? '';

                    if (! $existingM) {
                        $target->update([
                            'gender' => 'M',
                            'name' => "{$event->name} - {$categoryName} - Campuran",
                        ]);
                        $created++;
                    }

                    $otherIds = $others->pluck('id')->all();

                    foreach (DB::table('competition_registrations')->whereIn('competition_class_id', $otherIds)->get() as $reg) {
                        $duplicate = DB::table('competition_registrations')
                            ->where('participation_id', $reg->participation_id)
                            ->where('competition_class_id', $target->id)
                            ->exists();

                        if ($duplicate) {
                            DB::table('competition_registrations')->where('id', $reg->id)->delete();
                        } else {
                            DB::table('competition_registrations')->where('id', $reg->id)
                                ->update(['competition_class_id' => $target->id]);
                            $moved++;
                        }
                    }

                    CompetitionClass::whereIn('id', $otherIds)->delete();
                    $deleted += count($otherIds);
                });
            }
        }

        $this->newLine();
        $this->info('Ringkasan:');
        $this->line("  grup L/P ditemukan      : {$groups}");
        $this->line('  class diubah jadi M     : ' . ($force ? $created : '(dry-run)'));
        $this->line('  registration dipindah   : ' . ($force ? $moved : '(dry-run)'));
        $this->line('  class L/P dihapus       : ' . ($force ? $deleted : '(dry-run)'));
        $this->line("  grup di-skip (dependent): {$blocked}");
        $this->line("  class non-L male-only   : {$maleOnlyBad}");

        if (! $force) {
            $this->newLine();
            $this->comment('Jalankan ulang dengan --force untuk menerapkan.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, int>  $classIds
     * @return array<string, int>
     */
    private function dependentCounts(array $classIds): array
    {
        if ($classIds === []) {
            return [];
        }

        $out = [];

        foreach (self::DEPENDENTS as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $count = (int) DB::table($table)->whereIn($column, $classIds)->count();

            if ($count > 0) {
                $out[$table] = $count;
            }
        }

        return $out;
    }
}
