<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competition_heat_qualifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_class_id')->constrained('competition_classes')->cascadeOnDelete();
            $table->unsignedInteger('round')->default(1);
            $table->unsignedInteger('heat_index')->default(1);
            $table->unsignedInteger('qualifiers_per_heat')->default(1);
            $table->timestamps();

            $table->unique(['competition_class_id', 'round', 'heat_index'], 'uniq_heat_qualifier_class_round_heat');
            $table->index(['competition_class_id', 'round']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_heat_qualifiers');
    }
};
