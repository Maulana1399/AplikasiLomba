<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompetitionCategoryExclusive extends Model
{
    protected $table = 'competition_category_exclusivities';

    protected $fillable = [
        'competition_category_id',
        'exclusive_with_category_id',
    ];

    public function category()
    {
        return $this->belongsTo(CompetitionCategory::class, 'competition_category_id');
    }

    public function exclusiveWithCategory()
    {
        return $this->belongsTo(CompetitionCategory::class, 'exclusive_with_category_id');
    }
}
