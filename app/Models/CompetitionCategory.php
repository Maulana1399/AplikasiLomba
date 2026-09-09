<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompetitionCategory extends Model
{
    protected $fillable = [
        'event_id',
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

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function competitionClasses()
    {
        return $this->hasMany(CompetitionClass::class);
    }

    /**
     * Categories that this category is exclusive with (outgoing).
     */
    public function exclusiveWith()
    {
        return $this->belongsToMany(
            CompetitionCategory::class,
            'competition_category_exclusivities',
            'competition_category_id',
            'exclusive_with_category_id'
        );
    }

    /**
     * Categories that have this category as exclusive (incoming).
     */
    public function exclusiveFrom()
    {
        return $this->belongsToMany(
            CompetitionCategory::class,
            'competition_category_exclusivities',
            'exclusive_with_category_id',
            'competition_category_id'
        );
    }

    /**
     * Check if this category conflicts with another category (bidirectional).
     */
    public function isExclusiveWith(int $otherCategoryId): bool
    {
        if ($this->id === $otherCategoryId) {
            return false;
        }

        return $this->exclusiveWith()->where('competition_categories.id', $otherCategoryId)->exists()
            || $this->exclusiveFrom()->where('competition_categories.id', $otherCategoryId)->exists();
    }

    /**
     * Get all category IDs that conflict with this category (bidirectional).
     */
    public function allExclusiveCategoryIds(): array
    {
        $outgoing = $this->exclusiveWith->pluck('id')->toArray();
        $incoming = $this->exclusiveFrom->pluck('id')->toArray();

        return array_unique(array_merge($outgoing, $incoming));
    }
}
