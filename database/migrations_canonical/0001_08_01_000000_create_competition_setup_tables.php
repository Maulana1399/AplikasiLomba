<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('location_detail')->nullable();
            $table->integer('sort_order')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'name']);
            $table->unique(['event_id', 'code']);
            $table->index('event_id');

            $table->foreign('event_id')->references('id')->on('events')->restrictOnDelete();
        });

        Schema::create('competition_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->integer('sort_order')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['event_id', 'name']);
            $table->unique(['event_id', 'code']);
            $table->index('event_id');
            $table->index('is_active');

            $table->foreign('event_id')->references('id')->on('events')->restrictOnDelete();
        });

        Schema::create('competition_category_exclusivities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('competition_category_id');
            $table->unsignedBigInteger('exclusive_with_category_id');
            $table->timestamps();

            $table->unique(['competition_category_id', 'exclusive_with_category_id']);

            $table->foreign('competition_category_id')->references('id')->on('competition_categories')->cascadeOnDelete();
            $table->foreign('exclusive_with_category_id')->references('id')->on('competition_categories')->cascadeOnDelete();
        });

        Schema::create('competition_classes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('competition_category_id');
            $table->unsignedBigInteger('event_id');
            $table->string('name');
            $table->string('gender', 1)->nullable();
            $table->string('format', 40)->nullable()->default('individual_heat');
            $table->string('status', 30)->nullable()->default('registration_open');
            $table->string('result_type', 20)->nullable();
            $table->string('code')->nullable();
            $table->integer('sort_order')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('winner_count')->default(3);
            $table->unsignedInteger('team_size')->nullable();
            $table->timestamps();

            $table->unique(['competition_category_id', 'name']);
            $table->unique(['event_id', 'code']);
            $table->index('event_id');
            $table->index(['event_id', 'status']);
            $table->index('is_active');

            $table->foreign('competition_category_id')->references('id')->on('competition_categories')->restrictOnDelete();
            $table->foreign('event_id')->references('id')->on('events')->restrictOnDelete();
        });

        Schema::create('competition_registrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('participation_id');
            $table->unsignedBigInteger('competition_category_id');
            $table->unsignedBigInteger('competition_class_id');
            $table->string('registration_type')->default('individual');
            $table->timestamps();

            $table->unique(['participation_id', 'competition_class_id']);
            $table->index('competition_category_id');
            $table->index('competition_class_id');

            $table->foreign('participation_id')->references('id')->on('participations')->restrictOnDelete();
            $table->foreign('competition_category_id')->references('id')->on('competition_categories')->restrictOnDelete();
            $table->foreign('competition_class_id')->references('id')->on('competition_classes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_registrations');
        Schema::dropIfExists('competition_classes');
        Schema::dropIfExists('competition_category_exclusivities');
        Schema::dropIfExists('competition_categories');
        Schema::dropIfExists('venues');
    }
};