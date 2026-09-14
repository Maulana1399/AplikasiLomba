<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterParticipantClass extends Model
{

    protected $fillable = [
        'name',
        'code',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function competitionCategories()
    {
        return $this->belongsToMany(CompetitionCategory::class, 'competition_category_master_participant_class', 'master_participant_class_id', 'competition_category_id');
    }
}