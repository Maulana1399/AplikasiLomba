<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class desa extends Model
{
    protected $table = 'desas'; // <-- WAJIB

    protected $fillable = ['desa_asal', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function kelompok()
    {
        return $this->hasMany(kelompok::class);
    }
}
