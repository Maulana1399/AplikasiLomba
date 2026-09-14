<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('desas', function (Blueprint $table) {
            $table->unique('desa_asal');
        });

        Schema::table('kelompoks', function (Blueprint $table) {
            $table->unique(['kelompok_asal', 'desa_id']);
        });

        Schema::table('regus', function (Blueprint $table) {
            $table->unique('regu');
        });
    }

    public function down(): void
    {
        Schema::table('desas', function (Blueprint $table) {
            $table->dropUnique(['desa_asal']);
        });

        Schema::table('kelompoks', function (Blueprint $table) {
            $table->dropUnique(['kelompok_asal', 'desa_id']);
        });

        Schema::table('regus', function (Blueprint $table) {
            $table->dropUnique(['regu']);
        });
    }
};
