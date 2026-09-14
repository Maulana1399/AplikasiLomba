<?php

namespace App\Services\Competition;

use App\Models\CompetitionRegistration;
use App\Models\CompetitionSchedule;
use App\Models\CompetitionTeam;
use App\Models\CompetitionTeamMember;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CompetitionTeamService
{
    public function isInUse(CompetitionTeam $team): bool
    {
        return $team->scheduleEntries()->exists()
            || $team->heatResults()->exists()
            || $team->outcome()->exists()
            || CompetitionSchedule::where('winner_team_id', $team->id)->exists();
    }

    private function guardNotInUse(CompetitionTeam $team, ?CompetitionTeam $otherTeam = null): void
    {
        if ($this->isInUse($team) || ($otherTeam !== null && $this->isInUse($otherTeam))) {
            throw ValidationException::withMessages([
                'team' => 'Team sudah dipakai di jadwal/hasil/heat result — perubahan manual diblokir. Kosongkan jadwal/hasil terkait terlebih dahulu.',
            ]);
        }
    }

    public function addMember(CompetitionTeam $team, int $registrationId, bool $asSubstitute = false): CompetitionTeamMember
    {
        $this->guardNotInUse($team);

        $registration = CompetitionRegistration::with('participation.person')
            ->where('id', $registrationId)
            ->where('competition_class_id', $team->competition_class_id)
            ->first();

        if ($registration === null) {
            throw ValidationException::withMessages([
                'registration' => 'Peserta tidak terdaftar pada lomba/kelas team ini.',
            ]);
        }

        $personKelompokId = $registration->participation?->person?->kelompok_id;

        if ($team->kelompok_id !== null && (int) $personKelompokId !== (int) $team->kelompok_id) {
            throw ValidationException::withMessages([
                'registration' => 'Peserta harus berasal dari kelompok yang sama dengan team.',
            ]);
        }

        if ($team->members()->where('competition_registration_id', $registrationId)->exists()) {
            throw ValidationException::withMessages([
                'registration' => 'Peserta sudah menjadi anggota team ini.',
            ]);
        }

        $alreadyInOtherTeam = CompetitionTeamMember::where('competition_registration_id', $registrationId)
            ->whereHas('team', fn ($query) => $query
                ->where('competition_class_id', $team->competition_class_id)
                ->where('id', '!=', $team->id))
            ->exists();

        if ($alreadyInOtherTeam) {
            throw ValidationException::withMessages([
                'registration' => 'Peserta sudah berada di team lain pada lomba yang sama.',
            ]);
        }

        $maxOrder = $team->members()->max('sort_order') ?? 0;

        return CompetitionTeamMember::create([
            'competition_team_id' => $team->id,
            'competition_registration_id' => $registrationId,
            'is_substitute' => $asSubstitute,
            'sort_order' => $maxOrder + 1,
        ]);
    }

    public function swapMembers(CompetitionTeam $teamA, int $memberAId, CompetitionTeam $teamB, int $memberBId): void
    {
        if ($teamA->competition_class_id !== $teamB->competition_class_id) {
            throw ValidationException::withMessages([
                'swap' => 'Kedua tim harus berada pada lomba (class) yang sama.',
            ]);
        }

        if ($teamA->id === $teamB->id) {
            throw ValidationException::withMessages([
                'swap' => 'Tidak dapat menukar anggota dalam tim yang sama.',
            ]);
        }

        $this->guardNotInUse($teamA, $teamB);

        $memberA = $teamA->members()->findOrFail($memberAId);
        $memberB = $teamB->members()->findOrFail($memberBId);

        $tmpTeam = $memberA->competition_team_id;
        $tmpSubstitute = $memberA->is_substitute;
        $tmpOrder = $memberA->sort_order;

        $memberA->update([
            'competition_team_id' => $memberB->competition_team_id,
            'is_substitute' => $memberB->is_substitute,
            'sort_order' => $memberB->sort_order,
        ]);

        $memberB->update([
            'competition_team_id' => $tmpTeam,
            'is_substitute' => $tmpSubstitute,
            'sort_order' => $tmpOrder,
        ]);
    }

    public function removeMember(CompetitionTeam $team, int $memberId): void
    {
        $this->guardNotInUse($team);

        $member = $team->members()->findOrFail($memberId);
        $member->delete();
    }

    public function setSubstitute(CompetitionTeam $team, int $memberId, bool $asSubstitute): CompetitionTeamMember
    {
        $this->guardNotInUse($team);

        $member = $team->members()->findOrFail($memberId);

        $member->update(['is_substitute' => $asSubstitute]);

        return $member->fresh();
    }

    public function shuffleMembers(CompetitionTeam $team): void
    {
        $this->guardNotInUse($team);

        foreach ([false, true] as $isSubstitute) {
            $members = $team->members()
                ->where('is_substitute', $isSubstitute)
                ->orderBy('sort_order')
                ->get();

            foreach ($members->shuffle()->values() as $index => $member) {
                $member->update(['sort_order' => $index + 1]);
            }
        }
    }

    public function listForClass(int $eventId, int $competitionClassId): Collection
    {
        return CompetitionTeam::where('event_id', $eventId)
            ->where('competition_class_id', $competitionClassId)
            ->with(['kelompok', 'players.competitionRegistration.participation.person', 'substitutes.competitionRegistration.participation.person'])
            ->orderBy('name')
            ->get();
    }
}
