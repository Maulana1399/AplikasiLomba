<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompetitionClass extends Model
{
    protected $fillable = [
        'event_id',
        'competition_category_id',
        'name',
        'gender',
        'format',
        'status',
        'result_type',
        'code',
        'sort_order',
        'winner_count',
        'honorable_mention_count',
        'team_size',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'winner_count' => 'integer',
            'honorable_mention_count' => 'integer',
            'team_size' => 'integer',
        ];
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function competitionCategory()
    {
        return $this->belongsTo(CompetitionCategory::class);
    }

    public function competitionSchedules()
    {
        return $this->hasMany(CompetitionSchedule::class);
    }

    public function competitionRegistrations()
    {
        return $this->hasMany(CompetitionRegistration::class);
    }

    public function competitionTeams()
    {
        return $this->hasMany(CompetitionTeam::class);
    }

    public function heatFormats()
    {
        return $this->hasMany(CompetitionHeatFormat::class);
    }

    public function isTeamFormat(): bool
    {
        return \App\Support\CompetitionFormat::isTeamFormat($this->format);
    }

    public function resultType(): string
    {
        if (\App\Support\CompetitionResultType::isValid($this->result_type)) {
            return $this->result_type;
        }

        return \App\Support\CompetitionFormat::defaultResultType($this->format);
    }
}
