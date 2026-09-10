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
            $table->unsignedInteger('required_participants')->default(1);
            $table->unsignedBigInteger('winner_registration_id')->nullable();
            $table->unsignedBigInteger('winner_team_id')->nullable();
            $table->unsignedBigInteger('winner_score')->nullable();
            $table->string('winning_status')->nullable();
            $table->string('final_position_scope', 20)->nullable()->default('schedule');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('competition_class_id');
            $table->index('venue_id');

            $table->foreign('competition_class_id')->references('id')->on('competition_classes')->restrictOnDelete();
            $table->foreign('venue_id')->references('id')->on('venues')->nullOnDelete();
            $table->foreign('winner_registration_id')->references('id')->on('competition_registrations')->nullOnDelete();
            $table->foreign('winner_team_id')->references('id')->on('competition_teams')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('competition_schedule_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('schedule_id');
            $table->unsignedBigInteger('registration_id')->nullable();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->integer('order_number')->nullable();
            $table->string('lane')->nullable();
            $table->integer('corner')->nullable();
            $table->integer('position')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['schedule_id', 'registration_id'], 'uniq_schedule_registration');
            $table->index('registration_id');
            $table->index('team_id');

            $table->foreign('schedule_id')->references('id')->on('competition_schedules')->cascadeOnDelete();
            $table->foreign('registration_id')->references('id')->on('competition_registrations')->restrictOnDelete();
            $table->foreign('team_id')->references('id')->on('competition_teams')->cascadeOnDelete();
        });

        Schema::create('competition_outcomes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('registration_id');
            $table->integer('position')->nullable();
            $table->string('status')->nullable();
            $table->decimal('score', 10, 2)->nullable();
            $table->string('remarks')->nullable();
            $table->timestamps();

            $table->unique(['registration_id', 'position']);

            $table->foreign('registration_id')->references('id')->on('competition_registrations')->cascadeOnDelete();
        });

        Schema::create('competition_match_officials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('schedule_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role')->nullable();
            $table->timestamps();

            $table->unique(['schedule_id', 'user_id']);

            $table->foreign('schedule_id')->references('id')->on('competition_schedules')->cascadeOnDelete();
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