<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\Participation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Renumber participant_number secara deterministic untuk data existing.
 *
 * Aturan:
 * - Per event/lomba, peserta diurutkan berdasarkan NAMA A-Z.
 * - Nomor berurutan mulai 001, dengan prefix kontrak aplikasi:
 *     L -> KL, P -> KP (gender lain/empty -> KL, mengikuti PlacementService).
 * - Class M tetap memakai aturan yang sama: peserta L -> KL, P -> KP.
 * - Tidak memakai database ID sebagai urutan (ID hanya tie-breaker nama sama).
 *
 * Hanya meng-UPDATE participations.participant_number. Person, Participation
 * (baris), dan Registration tidak dibuat/dihapus/diubah.
 *
 * Default DRY-RUN. Tambahkan --force untuk menerapkan.
 */
class RenumberParticipantNumbers extends Command
{
    protected $signature = 'competition:renumber-participant-numbers
        {--event= : Batasi ke satu event id}
        {--examples=20 : Jumlah contoh baris yang ditampilkan}
        {--force : Terapkan perubahan (tanpa flag ini hanya dry-run)}';

    protected $description = 'Renumber participant_number per event, urut nama A-Z, prefix KL/KP.';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $exampleLimit = max(1, (int) $this->option('examples'));

        $eventsQuery = Event::where('event_type', 'competition')->orderBy('id');

        if ($this->option('event')) {
            $eventsQuery->where('id', (int) $this->option('event'));
        }

        $events = $eventsQuery->get();

        if ($events->isEmpty()) {
            $this->warn('Tidak ada event competition yang cocok.');

            return self::SUCCESS;
        }

        $this->info($force
            ? 'MODE: APPLY (participant_number akan diperbarui)'
            : 'MODE: DRY-RUN (tidak ada perubahan; gunakan --force untuk menerapkan)');

        $totalParticipations = 0;
        $totalChanged = 0;
        $examples = [];

        foreach ($events as $event) {
            $participations = Participation::where('event_id', $event->id)
                ->with('person:id,nama,jenis_kelamin,tanggal_lahir')
                ->get();

            if ($participations->isEmpty()) {
                continue;
            }

            $totalParticipations += $participations->count();

            $grouped = ['KL' => [], 'KP' => []];

            foreach ($participations as $participation) {
                $grouped[$this->prefixFor($participation->person?->jenis_kelamin)][] = $participation;
            }

            $assignments = [];

            foreach ($grouped as $prefix => $items) {
                usort($items, function (Participation $a, Participation $b): int {
                    $nameA = mb_strtolower((string) ($a->person?->nama ?? ''));
                    $nameB = mb_strtolower((string) ($b->person?->nama ?? ''));

                    if ($nameA !== $nameB) {
                        return $nameA <=> $nameB;
                    }

                    $dobA = (string) ($a->person?->tanggal_lahir ?? '');
                    $dobB = (string) ($b->person?->tanggal_lahir ?? '');

                    if ($dobA !== $dobB) {
                        return $dobA <=> $dobB;
                    }

                    return $a->id <=> $b->id;
                });

                foreach ($items as $index => $participation) {
                    $new = $prefix . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
                    $old = (string) $participation->participant_number;

                    if ($old !== $new) {
                        $totalChanged++;
                    }

                    if (count($examples) < $exampleLimit) {
                        $examples[] = [
                            'event' => $event->name,
                            'name' => (string) ($participation->person?->nama ?? ''),
                            'gender' => (string) ($participation->person?->jenis_kelamin ?? ''),
                            'old' => $old,
                            'new' => $new,
                            'participation_id' => $participation->id,
                        ];
                    }

                    $assignments[$participation->id] = $new;
                }
            }

            if ($force) {
                DB::transaction(function () use ($event, $assignments): void {
                    // Kosongkan dulu agar tidak bentrok unique (event_id, participant_number).
                    Participation::where('event_id', $event->id)->update(['participant_number' => null]);

                    foreach ($assignments as $participationId => $number) {
                        Participation::where('id', $participationId)->update(['participant_number' => $number]);
                    }
                });
            }
        }

        $this->newLine();
        $this->info('Contoh (event | nama | gender | lama -> baru):');
        $this->table(
            ['event', 'nama', 'gender', 'lama', 'baru', 'participation_id'],
            array_map(fn ($e) => [$e['event'], $e['name'], $e['gender'], $e['old'], $e['new'], $e['participation_id']], $examples)
        );

        $this->newLine();
        $this->info('Ringkasan:');
        $this->line("  event diproses              : {$events->count()}");
        $this->line("  participation diperiksa     : {$totalParticipations}");
        $this->line('  participant_number berubah  : ' . ($force ? $totalChanged : "{$totalChanged} (dry-run, belum diterapkan)"));

        if (! $force) {
            $this->newLine();
            $this->comment('Jalankan ulang dengan --force untuk menerapkan.');
        }

        return self::SUCCESS;
    }

    private function prefixFor(?string $gender): string
    {
        return mb_strtoupper((string) $gender) === 'P' ? 'KP' : 'KL';
    }
}
