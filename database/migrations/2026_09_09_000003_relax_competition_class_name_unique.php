<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competition_classes', function (Blueprint $table) {
            $table->dropUnique('competition_classes_event_id_name_unique');
            $table->unique(['competition_category_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('competition_classes', function (Blueprint $table) {
            $table->dropUnique('competition_classes_competition_category_id_name_unique');
            $table->unique(['event_id', 'name']);
        });
    }
};
