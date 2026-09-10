<?php

namespace App\Services\Placement;

use App\Models\Participation;

class PlacementService
{
    public static function generateParticipantNumber(int $eventId, ?string $jenisKelamin = null): string
    {
        $prefix = self::genderPrefix($jenisKelamin);
        $last = Participation::query()
            ->where('event_id', $eventId)
            ->whereNotNull('participant_number')
            ->where('participant_number', 'like', $prefix.'%')
            ->max('participant_number');

        $nextNumber = $last
            ? ((int) substr($last, 2) + 1)
            : 1;

        return $prefix.str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
    }

    private static function genderPrefix(?string $jenisKelamin = null): string
    {
        $jk = strtolower(
            str_replace([' ', '-'], '', $jenisKelamin ?? '')
        );

        return match ($jk) {
            'lakilaki' => 'KL',
            'perempuan' => 'KP',
            default => 'KL',
        };
    }

    public static function normalizePersonGender(string $jenisKelamin): string
    {
        return match ($jenisKelamin) {
            'L' => 'Laki - Laki',
            'P' => 'Perempuan',
            default => $jenisKelamin,
        };
    }
}
