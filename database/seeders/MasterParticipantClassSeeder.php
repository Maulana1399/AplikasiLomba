<?php

namespace Database\Seeders;

use App\Models\MasterParticipantClass;
use Illuminate\Database\Seeder;

class MasterParticipantClassSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'PAUD',
            'SD 1',
            'SD 2',
            'SD 3',
            'SD 4',
            'SD 5',
            'SD 6',
            'SMP',
            'SMU',
            'Dewasa',
        ];

        foreach (array_values($defaults) as $index => $name) {
            MasterParticipantClass::firstOrCreate(
                ['name' => $name],
                ['code' => $name, 'sort_order' => $index + 1, 'is_active' => true]
            );
        }
    }
}