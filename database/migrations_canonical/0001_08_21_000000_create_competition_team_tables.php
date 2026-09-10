<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competition_teams', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('competition_class_id');
            $table->string('name');
            $table->unsignedBigInteger('kelompok_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['event_id', 'competition_class_id', 'name']);
            $table->index('competition_class_id');

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $table->foreign('competition_class_id')->references('id')->on('competition_classes')->restrictOnDelete();
            $table->foreign('kelompok_id')->references('id')->on('kelompoks')->nullOnDelete();
        });

        Schema::create('competition_team_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('competition_team_id');
            $table->unsignedBigInteger('competition_registration_id');
            $table->boolean('is_substitute')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['competition_team_id', 'competition_registration_id']);
            $table->index('competition_registration_id');

            $table->foreign('competition_team_id')->references('id')->on('competition_teams')->cascadeOnDelete();
            $table->foreign('competition_registration_id')->references('id')->on('competition_registrations')->cascadeOnDelete();
        });

        Schema::create('competition_team_outcomes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('competition_team_id');
            $table->integer('position')->nullable();
            $table->string('status')->nullable();
            $table->decimal('score', 10, 2)->nullable();
            $table->string('remarks')->nullable();
            $table->timestamps();

            $table->unique(['competition_team_id', 'position']);

            $table->foreign('competition_team_id')->references('id')->on('competition_teams')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_team_outcomes');
        Schema::dropIfExists('competition_team_members');
        Schema::dropIfExists('competition_teams');
    }
};