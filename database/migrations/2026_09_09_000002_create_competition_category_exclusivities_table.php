<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competition_category_exclusivities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exclusive_with_category_id')->constrained('competition_categories')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['competition_category_id', 'exclusive_with_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_category_exclusivities');
    }
};
