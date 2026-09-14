<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (! Schema::hasColumn('events', 'code')) {
                $table->string('code', 50)->nullable()->after('slug');
            }
            if (! Schema::hasColumn('events', 'sort_order')) {
                $table->unsignedInteger('sort_order')->nullable()->after('code');
            }
        });

        if (Schema::hasColumn('events', 'code')) {
            try {
                Schema::table('events', function (Blueprint $table) {
                    $table->unique('code', 'uniq_events_code');
                });
            } catch (\Throwable $e) {
            }
        }
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            try {
                $table->dropUnique('uniq_events_code');
            } catch (\Throwable $e) {
            }
            if (Schema::hasColumn('events', 'sort_order')) {
                $table->dropColumn('sort_order');
            }
            if (Schema::hasColumn('events', 'code')) {
                $table->dropColumn('code');
            }
        });
    }
};
