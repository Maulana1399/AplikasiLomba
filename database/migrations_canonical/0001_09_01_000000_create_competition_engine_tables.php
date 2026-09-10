<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competition_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('competition_class_id');
            $table->unsignedBigInteger('venue_id')->nullable();
            $table->dateTime('start_at')->nullable();
            $table->dateTime('end_at')->nullable();
            $table->string('status', 20)->default('Scheduled');
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->nullable();
            $table->timestamps();
            $table->unsignedInteger('required_participants')->default(1);
            $table->unsignedBigInteger('winner_registration_id')->nullable();
            $table->string('finish_reason', 50)->nullable();
            $table->text('finish_notes')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->unsignedBigInteger('finished_by')->nullable();
            $table->unsignedBigInteger('winner_team_id')->nullable();

            $table->index('competition_class_id');
            $table->index('venue_id');
            $table->index('status');

            $table->foreign('competition_class_id')->references('id')->on('competition_classes')->restrictOnDelete();
            $table->foreign('venue_id')->references('id')->on('venues')->nullOnDelete();
            $table->foreign('winner_registration_id')->references('id')->on('competition_registrations')->nullOnDelete();
            $table->foreign('winner_team_id')->references('id')->on('competition_teams')->nullOnDelete();
            $table->foreign('finished_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('competition_schedule_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('competition_schedule_id');
            $table->unsignedBigInteger('competition_registration_id')->nullable();
            $table->unsignedInteger('order_number')->nullable();
            $table->string('lane', 50)->nullable();
            $table->string('corner', 50)->nullable();
            $table->unsignedInteger('position')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('competition_team_id')->nullable();

            $table->unique(['competition_schedule_id', 'competition_registration_id'], 'uniq_schedule_registration');
            $table->unique(['competition_schedule_id', 'competition_team_id'], 'uniq_schedule_team');
            $table->index('competition_schedule_id');
            $table->index('competition_registration_id');
            $table->index('competition_team_id');

            $table->foreign('competition_schedule_id')->references('id')->on('competition_schedules')->cascadeOnDelete();
            $table->foreign('competition_registration_id')->references('id')->on('competition_registrations')->restrictOnDelete();
            $table->foreign('competition_team_id')->references('id')->on('competition_teams')->restrictOnDelete();
        });

        Schema::create('competition_outcomes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('competition_registration_id');
            $table->unsignedInteger('position')->nullable();
            $table->string('status', 50)->nullable();
            $table->decimal('score', 10, 2)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index('competition_registration_id');

            $table->foreign('competition_registration_id')->references('id')->on('competition_registrations')->cascadeOnDelete();
        });

        Schema::create('competition_match_officials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('competition_schedule_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role', 50);
            $table->timestamps();

            $table->unique(['competition_schedule_id', 'user_id', 'role'], 'uniq_schedule_user_role');

            $table->foreign('competition_schedule_id')->references('id')->on('competition_schedules')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_match_officials');
        Schema::dropIfExists('competition_outcomes');
        Schema::dropIfExists('competition_schedule_entries');
        Schema::dropIfExists('competition_schedules');
    }
};