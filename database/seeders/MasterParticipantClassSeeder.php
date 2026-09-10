<?php

namespace Database\Seeders;

use App\Models\MasterParticipantClass;
use Illuminate\Database\Seeder;

class MasterParticipantClassSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'TK',
            'SD1',
            'SD2',
            'SD3',
            'SD4',
            'SD5',
            'SD6',
            'SMP1',
            'SMP2',
            'SMP3',
            'Dewasa',
        ];

        foreach (array_values($defaults) as $index => $name) {
            MasterParticipantClass::firstOrCreate(
                ['name' => $name],
                ['code' => $name, 'sort_order' => $index + 1]
            );
        }
    }
}