<?php

namespace App\Console\Commands;

use App\Models\CompetitionBracket;
use App\Models\CompetitionBracketMatch;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionHeatFormat;
use App\Models\CompetitionHeatResult;
use App\Models\CompetitionMatchOfficial;
use App\Models\CompetitionOutcome;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionSchedule;
use App\Models\CompetitionScheduleEntry;
use App\Models\CompetitionTeam;
use App\Models\CompetitionTeamMember;
use App\Models\CompetitionTeamOutcome;
use App\Models\desa;
use App\Models\Event;
use App\Models\kelompok;
use App\Models\Participation;
use App\Models\Person;
use Database\Seeders\CompetitionUatSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reset dataset UAT Competition KJA Event Manager.
 *
 * Menghapus SEMUA data bermarker UAT yang dibuat oleh `CompetitionUatSeeder`
 * (event "UAT Competition 2026", kategori/kelas/heat/registrasi/peserta team,
 * desa & kelompok "UAT ..."). Tidak pernah menyentuh event lain, master data
 * non-UAT, maupun data legacy.
 *
 * Safety:
 *  - Dry run secara default; `--apply` untuk mengeksekusi.
 *  - Semua hapus dibungkus satu transaksi — gagal = rollback total.
 *  - Hanya hapus Person bermarker UAT yang tidak lagi punya Participation di
 *    event lain; person yang masih terpakai di event lain TIDAK dihapus.
 *  - Desa/kelompok bermarker UAT hanya dihapus bila tidak lagi direferensikan
 *    oleh Person yang tersisa.
 */
class CompetitionUatReset extends Command
{
    protected $signature = 'competition:uat-reset {--apply : Actually delete; default is a dry run}';

    protected $description = 'Reset dataset UAT Competition (event + kategori/kelas/heat/team/peserta/desa/kelompok bermarker UAT)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $events = Event::where('slug', CompetitionUatSeeder::EVENT_SLUG)->get();

        if ($events->isEmpty()) {
            $this->info('Event UAT Competition 2026 tidak ditemukan — tidak ada data untuk di-reset.');

            return Command::SUCCESS;
        }

        $eventIds = $events->pluck('id');
        $classIds = CompetitionClass::whereIn('event_id', $eventIds)->pluck('id');

        $counts = $this->collectCounts($eventIds, $classIds);

        $this->section('IDENTIFICATION');
        $this->line('  Events (UAT Competition 2026): '.$eventIds->count());
        $this->line('  Classes (UAT): '.$classIds->count());

        $this->section('ROWS TO DELETE (UAT scope only)');
        $total = 0;
        foreach ($counts as $table => $count) {
            $total += $count;
            $this->line("  {$table}: {$count} row(s)");
        }
        $this->line('  total UAT rows: '.$total);

        $personIds = $this->uatPersonIds($eventIds);
        $this->line('');
        $this->line('  UAT Persons (no participation in other events): '.count($personIds));

        if (! $apply) {
            $this->line('');
            $this->info('DRY RUN: nothing was deleted. Re-run with --apply to reset the UAT dataset.');

            return Command::SUCCESS;
        }

        DB::transaction(function () use ($eventIds, $classIds, $personIds) {
            CompetitionHeatResult::whereIn('competition_schedule_id', CompetitionSchedule::whereIn('competition_class_id', $classIds)->pluck('id'))->delete();
            CompetitionScheduleEntry::whereIn('competition_schedule_id', CompetitionSchedule::whereIn('competition_class_id', $classIds)->pluck('id'))->delete();
            CompetitionMatchOfficial::whereIn('competition_schedule_id', CompetitionSchedule::whereIn('competition_class_id', $classIds)->pluck('id'))->delete();
            CompetitionBracketMatch::whereIn('competition_bracket_id', CompetitionBracket::whereIn('competition_class_id', $classIds)->pluck('id'))->delete();
            CompetitionOutcome::whereIn('competition_registration_id', CompetitionRegistration::whereIn('competition_class_id', $classIds)->pluck('id'))->delete();
            CompetitionSchedule::whereIn('competition_class_id', $classIds)->delete();
            CompetitionBracket::whereIn('competition_class_id', $classIds)->delete();
            CompetitionTeamMember::whereIn('competition_team_id', CompetitionTeam::whereIn('competition_class_id', $classIds)->pluck('id'))->delete();
            CompetitionTeamOutcome::whereIn('competition_team_id', CompetitionTeam::whereIn('competition_class_id', $classIds)->pluck('id'))->delete();
            CompetitionTeam::whereIn('competition_class_id', $classIds)->delete();
            CompetitionHeatFormat::whereIn('competition_class_id', $classIds)->delete();
            CompetitionRegistration::whereIn('competition_class_id', $classIds)->delete();
            CompetitionClass::whereIn('id', $classIds)->delete();
            CompetitionCategory::whereIn('event_id', $eventIds)->delete();

            $participationIds = Participation::whereIn('event_id', $eventIds)->pluck('id');
            Participation::whereIn('id', $participationIds)->delete();

            Event::whereIn('id', $eventIds)->delete();

            $this->deletePersons($personIds);
            $this->deleteUatMasterData();
        });

        $this->section('VERIFICATION');
        $eventAfter = Event::where('slug', CompetitionUatSeeder::EVENT_SLUG)->count();
        $personAfter = Person::where('nama', 'like', CompetitionUatSeeder::PERSON_PREFIX.'%')->count();
        $this->line('  UAT events remaining: '.$eventAfter.' (expected 0)');
        $this->line('  UAT persons remaining: '.$personAfter.' (expected 0)');

        if ($eventAfter === 0 && $personAfter === 0) {
            $this->info('RESET OK — dataset UAT Competition telah dihapus.');
        } else {
            $this->error('RESET VERIFICATION FAILED — periksa data yang tersisa di atas.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>  $eventIds
     * @param  \Illuminate\Support\Collection<int, int>  $classIds
     * @return array<string, int>
     */
    private function collectCounts($eventIds, $classIds): array
    {
        return [
            'competition_team_outcomes' => CompetitionTeamOutcome::whereIn('competition_team_id', CompetitionTeam::whereIn('competition_class_id', $classIds)->pluck('id'))->count(),
            'competition_heat_results' => CompetitionHeatResult::whereIn('competition_schedule_id', CompetitionSchedule::whereIn('competition_class_id', $classIds)->pluck('id'))->count(),
            'competition_schedule_entries' => CompetitionScheduleEntry::whereIn('competition_schedule_id', CompetitionSchedule::whereIn('competition_class_id', $classIds)->pluck('id'))->count(),
            'competition_match_officials' => CompetitionMatchOfficial::whereIn('competition_schedule_id', CompetitionSchedule::whereIn('competition_class_id', $classIds)->pluck('id'))->count(),
            'competition_bracket_matches' => CompetitionBracketMatch::whereIn('competition_bracket_id', CompetitionBracket::whereIn('competition_class_id', $classIds)->pluck('id'))->count(),
            'competition_outcomes' => CompetitionOutcome::whereIn('competition_registration_id', CompetitionRegistration::whereIn('competition_class_id', $classIds)->pluck('id'))->count(),
            'competition_schedules' => CompetitionSchedule::whereIn('competition_class_id', $classIds)->count(),
            'competition_brackets' => CompetitionBracket::whereIn('competition_class_id', $classIds)->count(),
            'competition_team_members' => CompetitionTeamMember::whereIn('competition_team_id', CompetitionTeam::whereIn('competition_class_id', $classIds)->pluck('id'))->count(),
            'competition_teams' => CompetitionTeam::whereIn('competition_class_id', $classIds)->count(),
            'competition_heat_formats' => CompetitionHeatFormat::whereIn('competition_class_id', $classIds)->count(),
            'competition_registrations' => CompetitionRegistration::whereIn('competition_class_id', $classIds)->count(),
            'competition_classes' => $classIds->count(),
            'competition_categories' => CompetitionCategory::whereIn('event_id', $eventIds)->count(),
            'participations' => Participation::whereIn('event_id', $eventIds)->count(),
            'events' => $eventIds->count(),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>  $eventIds
     * @return list<int>
     */
    private function uatPersonIds($eventIds): array
    {
        $uatParticipationIds = Participation::whereIn('event_id', $eventIds)->pluck('person_id');

        $rows = Person::where('nama', 'like', CompetitionUatSeeder::PERSON_PREFIX.'%')
            ->whereIn('id', $uatParticipationIds)
            ->get();

        $ids = [];

        foreach ($rows as $person) {
            $other = Participation::where('person_id', $person->id)
                ->whereNotIn('event_id', $eventIds)
                ->exists();

            if (! $other) {
                $ids[] = (int) $person->id;
            } else {
                $this->warn("Person #{$person->id} '{$person->nama}' masih punya Participation di event lain — dipertahankan.");
            }
        }

        return $ids;
    }

    /**
     * @param  list<int>  $personIds
     */
    private function deletePersons(array $personIds): void
    {
        if ($personIds === []) {
            return;
        }

        Person::whereIn('id', $personIds)->get()->each(function (Person $person) {
            $person->delete();
        });
    }

    private function deleteUatMasterData(): void
    {
        $desaIds = desa::where('desa_asal', 'like', CompetitionUatSeeder::DESA_PREFIX.'%')->pluck('id');

        $kelompokIds = kelompok::where('kelompok_asal', 'like', CompetitionUatSeeder::TEAM_PREFIX.'%')
            ->orWhere('kelompok_asal', 'like', CompetitionUatSeeder::KELOMPOK_PREFIX.'%')
            ->pluck('id');

        // Person tidak lagi mereferensikan kelompok/desa UAT, dan tidak ada team
        // tersisa, jadi aman untuk dihapus.
        kelompok::whereIn('id', $kelompokIds)->delete();
        desa::whereIn('id', $desaIds)->delete();
    }

    private function section(string $title): void
    {
        $this->line('');
        $this->line(str_repeat('=', 60));
        $this->line($title);
        $this->line(str_repeat('-', 60));
    }
}
