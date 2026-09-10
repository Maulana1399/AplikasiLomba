<?php

namespace App\Services\Competition;

use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionRegistration;
use App\Models\Event;
use App\Models\Participation;
use App\Models\Person;
use App\Services\Placement\PlacementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompetitionRegistrationService
{
    public function register(
        string $nama,
        string $jenisKelamin,
        ?string $tanggalLahir,
        int $desaId,
        int $eventId,
        int $competitionCategoryId,
        int $competitionClassId,
        ?int $kelompokId = null,
        ?string $kelas = null,
    ): array {
        return DB::transaction(function () use (
            $nama, $jenisKelamin, $tanggalLahir, $desaId,
            $eventId, $competitionCategoryId, $competitionClassId, $kelompokId, $kelas
        ) {
            $event = Event::lockForUpdate()->findOrFail($eventId);

            $person = Person::where('nama', $nama)
                ->where('desa_id', $desaId)
                ->first();

            if (! $person) {
                $person = Person::create([
                    'nama' => $nama,
                    'jenis_kelamin' => $jenisKelamin === 'Perempuan' ? 'P' : 'L',
                    'tanggal_lahir' => $tanggalLahir,
                    'desa_id' => $desaId,
                    'kelompok_id' => $kelompokId,
                    'kelas' => $kelas,
                ]);
            }

            return $this->registerForPerson(
                person: $person,
                eventId: $event->id,
                competitionCategoryId: $competitionCategoryId,
                competitionClassId: $competitionClassId,
            );
        });
    }

    public function registerForPerson(
        Person $person,
        int $eventId,
        int $competitionCategoryId,
        int $competitionClassId,
        string $registrationType = 'individual',
    ): array {
        return DB::transaction(function () use ($person, $eventId, $competitionCategoryId, $competitionClassId, $registrationType) {
            $event = Event::lockForUpdate()->findOrFail($eventId);

            $category = CompetitionCategory::where('id', $competitionCategoryId)
                ->where('event_id', $event->id)->firstOrFail();

            $class = CompetitionClass::where('id', $competitionClassId)
                ->where('competition_category_id', $category->id)->firstOrFail();

            // 1. Gender validation
            if ($class->gender !== 'M' && $class->gender !== $person->jenis_kelamin) {
                throw ValidationException::withMessages([
                    'competitionClassId' => 'Jenis kelamin peserta tidak sesuai dengan kelas ini.',
                ]);
            }

            $existingParticipation = Participation::where('person_id', $person->id)
                ->where('event_id', $event->id)
                ->first();

            if ($existingParticipation) {
                // 2. Duplicate registration check
                $existingReg = CompetitionRegistration::where('participation_id', $existingParticipation->id)
                    ->where('competition_class_id', $class->id)
                    ->first();

                if ($existingReg) {
                    throw ValidationException::withMessages([
                        'nama' => 'Peserta sudah terdaftar di kelas ini.',
                    ]);
                }

                // 3. Conflict/exclusivity check (bidirectional)
                $conflictCategoryIds = $category->allExclusiveCategoryIds();

                if (! empty($conflictCategoryIds)) {
                    $hasConflict = CompetitionRegistration::where('participation_id', $existingParticipation->id)
                        ->whereIn('competition_category_id', $conflictCategoryIds)
                        ->exists();

                    if ($hasConflict) {
                        $conflictNames = CompetitionCategory::whereIn('id', $conflictCategoryIds)
                            ->pluck('name')
                            ->implode(', ');

                        throw ValidationException::withMessages([
                            'competitionCategoryId' => "Kategori ini konflik dengan kategori yang sudah diikuti peserta: {$conflictNames}.",
                        ]);
                    }
                }

                $participation = $existingParticipation;
            } else {
                $jenisKelamin = $person->jenis_kelamin === 'P' ? 'Perempuan' : 'Laki - Laki';
                $participation = $this->createParticipation($person, $event, $jenisKelamin);
            }

            $registration = $this->createCompetitionRegistration($participation, $category, $class, $registrationType);

            return [
                'status' => 'registered',
                'person' => $person,
                'participation' => $participation,
                'competition_registration' => $registration,
            ];
        });
    }

    private function createParticipation(Person $person, Event $event, string $jenisKelamin): Participation
    {
        $participantNumber = PlacementService::generateParticipantNumber($event->id, $jenisKelamin);
        $attendanceCode = $this->generateAttendanceCode();

        return Participation::create([
            'person_id' => $person->id,
            'event_id' => $event->id,
            'participant_number' => $participantNumber,
            'attendance_code' => $attendanceCode,
            'jenis_peserta' => 'Peserta',
        ]);
    }

    public function generateAttendanceCode(): string
    {
        do {
            $code = 'KJA-'.Str::upper(Str::random(8));
        } while (Participation::where('attendance_code', $code)->exists());

        return $code;
    }

    private function createCompetitionRegistration(
        Participation $participation,
        CompetitionCategory $category,
        CompetitionClass $class,
        string $registrationType = 'individual',
    ): CompetitionRegistration {
        return CompetitionRegistration::create([
            'participation_id' => $participation->id,
            'competition_category_id' => $category->id,
            'competition_class_id' => $class->id,
            'registration_type' => $registrationType,
        ]);
    }
}
