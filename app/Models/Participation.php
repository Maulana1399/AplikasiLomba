<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Participation extends Model
{
    public const STATUS_BELUM_DAFTAR_ULANG = 'Belum Daftar Ulang';

    public const STATUS_SUDAH_DAFTAR_ULANG = 'Sudah Daftar Ulang';

    private const SUDAH_DAFTAR_ULANG_VALUES = [
        self::STATUS_SUDAH_DAFTAR_ULANG,
        'checked_in',
    ];

    protected $fillable = [
        'person_id',
        'event_id',
        'participant_number',
        'attendance_code',
        'jenis_peserta',
        'status_registrasi',
        'regu_id',
    ];

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function regu()
    {
        return $this->belongsTo(regu::class);
    }

    public function legacyParticipationMapping()
    {
        return $this->hasOne(LegacyParticipationMapping::class, 'participation_id');
    }

    public function activityRegistrations()
    {
        return $this->hasMany(ActivityRegistration::class);
    }

    public function committeeAssignments()
    {
        return $this->hasMany(EventCommitteeAssignment::class);
    }

    public function eventAttendances()
    {
        return $this->hasMany(EventAttendance::class);
    }

    public function competitionRegistrations()
    {
        return $this->hasMany(CompetitionRegistration::class);
    }

    public function isReregistered(): bool
    {
        return in_array($this->status_registrasi, self::SUDAH_DAFTAR_ULANG_VALUES, true);
    }

    public function reregistrationStatusLabel(): string
    {
        return $this->isReregistered()
            ? self::STATUS_SUDAH_DAFTAR_ULANG
            : self::STATUS_BELUM_DAFTAR_ULANG;
    }
}
