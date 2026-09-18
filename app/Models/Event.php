<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Event extends Model
{
    /** @use HasFactory<\Database\Factories\EventFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'code',
        'sort_order',
        'event_type',
        'description',
        'start_date',
        'end_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    public function isCai(): bool
    {
        return $this->event_type === 'cai';
    }

    public function isPengajian(): bool
    {
        return $this->event_type === 'pengajian';
    }

    public function isCompetition(): bool
    {
        return $this->event_type === 'competition';
    }

    public function typeLabel(): string
    {
        return match ($this->event_type) {
            'cai' => 'CAI',
            'pengajian' => 'Pengajian',
            'competition' => 'Competition',
            default => ucfirst($this->event_type),
        };
    }

    /**
     * Landing route untuk event ini. Satu-satunya sumber kebenaran untuk
     * redirect setelah memilih/membuat event.
     */
    public function dashboardRoute(): string
    {
        return match (true) {
            $this->isPengajian() => route('pengajian.report', ['event' => $this], absolute: false),
            $this->isCompetition() => route('competition.dashboard', absolute: false),
            default => route('events.dashboard', ['event' => $this], absolute: false),
        };
    }

    public function quickAccessRoute(string $target): string
    {
        return match ($target) {
            'scan' => $this->isPengajian()
                ? route('pengajian.report', ['event' => $this], absolute: false)
                : ($this->isCompetition()
                    ? route('competition.dashboard', absolute: false)
                    : route('absensi', ['event' => $this], absolute: false)),
            'registrasi' => $this->isPengajian()
                ? route('pengajian.admin.manual-entry', ['event' => $this], absolute: false)
                : ($this->isCompetition()
                    ? route('competition.registration', absolute: false)
                    : route('registrasi.peserta', ['event' => $this], absolute: false)),
            'cari' => $this->isPengajian()
                ? route('pengajian.report', ['event' => $this], absolute: false)
                : ($this->isCompetition()
                    ? route('competition.participants', absolute: false)
                    : route('database', ['event' => $this], absolute: false)),
            default => abort(404),
        };
    }

    public function scopeCai($query)
    {
        return $query->where('event_type', 'cai');
    }

    public function scopePengajian($query)
    {
        return $query->where('event_type', 'pengajian');
    }

    public function scopeCompetition($query)
    {
        return $query->where('event_type', 'competition');
    }

    public function participations()
    {
        return $this->hasMany(Participation::class);
    }

    public function activityGroups()
    {
        return $this->hasMany(ActivityGroup::class);
    }

    public function activities()
    {
        return $this->hasMany(Activity::class);
    }

    public function activityRegistrations()
    {
        return $this->hasMany(ActivityRegistration::class);
    }

    public function sesiAbsensis()
    {
        return $this->hasMany(SesiAbsensi::class);
    }

    public function people()
    {
        return $this->belongsToMany(Person::class, 'participations');
    }

    public function eventRoles()
    {
        return $this->hasMany(EventRole::class);
    }

    public function committeeAssignments()
    {
        return $this->hasMany(EventCommitteeAssignment::class);
    }

    public function desaAccessGrants()
    {
        return $this->hasMany(DesaAccessGrant::class);
    }

    public function eventAttendances()
    {
        return $this->hasMany(EventAttendance::class);
    }

    public function competitionCategories()
    {
        return $this->hasMany(CompetitionCategory::class);
    }

    public function competitionClasses()
    {
        return $this->hasMany(CompetitionClass::class);
    }

    public function competitionAnnouncements()
    {
        return $this->hasMany(CompetitionAnnouncement::class);
    }

    public function venues()
    {
        return $this->hasMany(Venue::class);
    }

    public function hasRuntimeDependencies(): bool
    {
        return $this->participations()->exists()
            || $this->sesiAbsensis()->exists()
            || $this->eventAttendances()->exists()
            || $this->eventRoles()->exists()
            || $this->committeeAssignments()->exists()
            || $this->desaAccessGrants()->exists();
    }

    protected static function booted(): void
    {
        static::creating(function (Event $event) {
            if (blank($event->slug)) {
                $event->slug = Str::slug($event->name);
            }
        });
    }
}
