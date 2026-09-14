<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competition_classes', function (Blueprint $table) {
            $table->unsignedInteger('honorable_mention_count')->default(0)->after('winner_count');
        });
    }

    public function down(): void
    {
        Schema::table('competition_classes', function (Blueprint $table) {
            $table->dropColumn('honorable_mention_count');
        });
    }
};
