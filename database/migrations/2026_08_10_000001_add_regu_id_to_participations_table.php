<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        if ($isSqlite) {
            DB::statement('PRAGMA foreign_keys = OFF');
        }

        Schema::table('participations', function (Blueprint $table) {
            $table->foreignId('regu_id')
                ->nullable()
                ->constrained('regus')
                ->nullOnDelete();
            $table->index('regu_id');
        });

        if ($isSqlite) {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    public function down(): void
    {
        Schema::table('participations', function (Blueprint $table) {
            $table->dropForeign(['regu_id']);
            $table->dropIndex(['regu_id']);
            $table->dropColumn('regu_id');
        });
    }
};
