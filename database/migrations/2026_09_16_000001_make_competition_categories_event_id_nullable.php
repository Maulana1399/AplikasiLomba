<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competition_categories', function (Blueprint $table) {
            $table->dropUnique(['event_id', 'name']);
            $table->dropUnique(['event_id', 'code']);
        });

        Schema::table('competition_categories', function (Blueprint $table) {
            $table->foreignId('event_id')->nullable()->change();
        });

        Schema::table('competition_categories', function (Blueprint $table) {
            $table->unique('name');
            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::table('competition_categories', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->dropUnique(['code']);
        });

        Schema::table('competition_categories', function (Blueprint $table) {
            $table->foreignId('event_id')->nullable(false)->change();
        });

        Schema::table('competition_categories', function (Blueprint $table) {
            $table->unique(['event_id', 'name']);
            $table->unique(['event_id', 'code']);
        });
    }
};
