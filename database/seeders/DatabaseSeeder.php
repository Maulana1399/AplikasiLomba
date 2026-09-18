<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SuperUserSeeder::class,
            UserSeeder::class,
            AplikasiLombaSeeder::class,
            Fasda2026Seeder::class,
        ]);
    }
}
