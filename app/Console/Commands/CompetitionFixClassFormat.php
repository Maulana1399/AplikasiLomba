<?php

namespace App\Console\Commands;

use App\Models\CompetitionClass;
use App\Support\CompetitionFormat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Koreksi Kelas Lomba berformat `individual_heat`.
 *
 * Kontrak AplikasiLomba: seluruh lomba "Heat" adalah LOMBA BEREGU dan
 * disimpan sebagai internal `team_heat`. Data lama (misal kelas "Lomba
 * Migrasi Gelas - SD 4 - 6 - Putri", id 54) pernah tersimpan sebagai
 * `individual_heat` + `team_size=4` — kombinasi yang tidak valid menurut
 * kontrak. Kelas tersebut hanyalah disalah-mapping oleh Setting, tim masih
 * dibentuk via Pembagian Tim, jadi cukup dikoreksi ke `team_heat`.
 *
 * Safety:
 *  - Dry run secara default; `--apply` untuk mengeksekusi.
 *  - Hanya berformat `individual_heat` yang diproses.
 *  - Kelas dengan `team_size` kosong / <= 1 tidak diubah (tidak valid untuk
 *    team_heat) dan hanya dilaporkan.
 *  - Update dibungkus satu transaksi — gagal = rollback total.
 */
class CompetitionFixClassFormat extends Command
{
    protected $signature = 'competition:fix-class-format
        {--id= : Hanya kelas dengan id tertentu (optional); default: semua kelas individual_heat}
        {--apply : Actually update; default is a dry run}';

    protected $description = 'Perbaiki kelas berformat individual_heat menjadi team_heat (Heat = lomba beregu)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $specificId = $this->option('id');

        $query = CompetitionClass::where('format', CompetitionFormat::INDIVIDUAL_HEAT);

        if ($specificId !== null) {
            $query->where('id', (int) $specificId);
        }

        $classes = $query->orderBy('id')->get();

        if ($classes->isEmpty()) {
            $this->info('Tidak ada kelas individual_heat yang ditemukan — tidak ada yang perlu diperbaiki.');

            return Command::SUCCESS;
        }

        $fixable = $classes->filter(fn (CompetitionClass $class) => $class->team_size !== null && $class->team_size > 1);
        $skipped = $classes->filter(fn (CompetitionClass $class) => $class->team_size === null || $class->team_size <= 1);

        $this->section('IDENTIFICATION');
        $this->line('  Kelas individual_heat ditemukan: '.$classes->count());
        $this->line('  Dapat dikoreksi ke team_heat (team_size valid): '.$fixable->count());
        $this->line('  Ditolak (team_size kosong/<=1, tidak valid untuk team_heat): '.$skipped->count());

        foreach ($classes as $class) {
            $flag = $skipped->contains(fn ($c) => $c->id === $class->id) ? 'SKIP' : 'FIX';
            $this->line("  [{$flag}] #{$class->id} '{$class->name}' format={$class->format} team_size=".($class->team_size ?? 'NULL'));
        }

        if ($skipped->isNotEmpty()) {
            $this->warn('Kelas SKIP tidak diubah: team_heat mewajibkan team_size > 1. Perbaiki team_size-nya dulu di Setting.');

            return Command::SUCCESS;
        }

        if ($fixable->isEmpty()) {
            $this->warn('Tidak ada kelas yang dapat dikoreksi.');

            return Command::SUCCESS;
        }

        if (! $apply) {
            $this->line('');
            $this->info('DRY RUN: nothing was updated. Re-run with --apply to fix the class format.');

            return Command::SUCCESS;
        }

        DB::transaction(function () use ($fixable) {
            foreach ($fixable as $class) {
                $class->update(['format' => CompetitionFormat::TEAM_HEAT]);
            }
        });

        $this->section('VERIFICATION');
        $fixedIds = $fixable->pluck('id');
        $stillIndividual = CompetitionClass::whereIn('id', $fixedIds)
            ->where('format', CompetitionFormat::INDIVIDUAL_HEAT)
            ->pluck('id');

        $this->line('  Kelas yang dikoreksi: '.$fixedIds->count());
        $this->line('  Masih individual_heat: '.$stillIndividual->count().' (expected 0)');

        if ($stillIndividual->isEmpty()) {
            $this->info('FIX OK — kelas dikoreksi menjadi team_heat.');
        } else {
            $this->error('VERIFICATION FAILED — kelas individual_heat tersisa: '.$stillIndividual->implode(', '));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function section(string $title): void
    {
        $this->line('');
        $this->line(str_repeat('=', 60));
        $this->line($title);
        $this->line(str_repeat('-', 60));
    }
}
