<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompetitionHeatQualifier extends Model
{
    protected $fillable = [
        'competition_class_id',
        'round',
        'heat_index',
        'qualifiers_per_heat',
    ];

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'heat_index' => 'integer',
            'qualifiers_per_heat' => 'integer',
        ];
    }

    public function competitionClass()
    {
        return $this->belongsTo(CompetitionClass::class);
    }
}
