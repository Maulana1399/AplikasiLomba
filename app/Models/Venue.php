<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Venue extends Model
{
    protected $fillable = [
        'event_id',
        'name',
        'code',
        'location_detail',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function rundownItems()
    {
        return $this->hasMany(RundownItem::class);
    }

    public function committeeAssignments()
    {
        return $this->hasMany(EventCommitteeAssignment::class);
    }

    public function competitionSchedules()
    {
        return $this->hasMany(CompetitionSchedule::class);
    }
}
