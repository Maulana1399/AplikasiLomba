<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competition_category_master_participant_class', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_category_id')->constrained('competition_categories')->cascadeOnDelete();
            $table->foreignId('master_participant_class_id')->constrained('master_participant_classes')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['competition_category_id', 'master_participant_class_id'], 'uniq_category_master_class');
            $table->index('competition_category_id');
            $table->index('master_participant_class_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_category_master_participant_class');
    }
};
