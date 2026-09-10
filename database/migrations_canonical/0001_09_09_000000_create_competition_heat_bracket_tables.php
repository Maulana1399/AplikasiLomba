<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competition_brackets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('competition_class_id');
            $table->string('name');
            $table->unsignedInteger('participant_count');
            $table->string('status', 20)->default('draft');
            $table->boolean('third_place_match')->default(false);
            $table->timestamps();

            $table->foreign('competition_class_id')->references('id')->on('competition_classes')->restrictOnDelete();
        });

        Schema::create('competition_bracket_matches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('competition_bracket_id');
            $table->unsignedBigInteger('competition_schedule_id')->nullable();
            $table->unsignedInteger('round');
            $table->unsignedInteger('position');
            $table->boolean('is_third_place')->default(false);
            $table->unsignedBigInteger('source_match_a_id')->nullable();
            $table->unsignedBigInteger('source_match_b_id')->nullable();
            $table->timestamps();

            $table->unique(['competition_bracket_id', 'round', 'position'], 'uniq_bracket_round_pos');

            $table->foreign('competition_bracket_id')->references('id')->on('competition_brackets')->cascadeOnDelete();
            $table->foreign('competition_schedule_id')->references('id')->on('competition_schedules')->cascadeOnDelete();
            $table->foreign('source_match_a_id')->references('id')->on('competition_bracket_matches')->nullOnDelete();
            $table->foreign('source_match_b_id')->references('id')->on('competition_bracket_matches')->nullOnDelete();
        });

        Schema::create('competition_heat_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('competition_schedule_id');
            $table->unsignedBigInteger('competition_registration_id')->nullable();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->decimal('score', 10, 2)->nullable();
            $table->unsignedInteger('position')->nullable();
            $table->string('status', 50)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['competition_schedule_id', 'competition_registration_id'], 'uniq_heat_schedule_registration');
            $table->index('competition_registration_id');
            $table->index('competition_schedule_id');
            $table->index('team_id');

            $table->foreign('competition_schedule_id')->references('id')->on('competition_schedules')->cascadeOnDelete();
            $table->foreign('competition_registration_id')->references('id')->on('competition_registrations')->restrictOnDelete();
            $table->foreign('team_id')->references('id')->on('competition_teams')->cascadeOnDelete();
        });

        Schema::create('competition_heat_formats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('competition_class_id');
            $table->unsignedInteger('round')->default(1);
            $table->unsignedInteger('participants_per_heat')->default(1);
            $table->unsignedInteger('qualifiers_per_heat')->default(1);
            $table->timestamps();

            $table->unique(['competition_class_id', 'round'], 'uniq_heat_format_class_round');

            $table->foreign('competition_class_id')->references('id')->on('competition_classes')->cascadeOnDelete();
        });

        Schema::create('competition_announcements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id');
            $table->text('message');
            $table->boolean('is_active')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'is_active']);

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_announcements');
        Schema::dropIfExists('competition_heat_formats');
        Schema::dropIfExists('competition_heat_results');
        Schema::dropIfExists('competition_bracket_matches');
        Schema::dropIfExists('competition_brackets');
    }
};