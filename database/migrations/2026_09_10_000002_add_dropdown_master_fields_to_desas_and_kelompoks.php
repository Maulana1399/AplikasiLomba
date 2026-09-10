<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('desas', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('desa_asal');
            $table->unsignedInteger('sort_order')->default(0)->after('is_active');
        });

        Schema::table('kelompoks', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('desa_id');
            $table->unsignedInteger('sort_order')->default(0)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('desas', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'sort_order']);
        });

        Schema::table('kelompoks', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'sort_order']);
        });
    }
};