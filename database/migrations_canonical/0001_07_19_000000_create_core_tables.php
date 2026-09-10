<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->string('event_type')->default('cai');
        });

        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('jenis_kelamin', 1)->nullable();
            $table->unsignedBigInteger('desa_id')->nullable();
            $table->timestamps();
            $table->date('tanggal_lahir')->nullable();
            $table->unsignedBigInteger('kelompok_id')->nullable();
            $table->string('kelas')->nullable();

            $table->foreign('desa_id')->references('id')->on('desas')->nullOnDelete();
            $table->foreign('kelompok_id')->references('id')->on('kelompoks')->nullOnDelete();
        });

        Schema::create('participations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('person_id');
            $table->unsignedBigInteger('event_id');
            $table->string('participant_number')->nullable();
            $table->string('attendance_code')->nullable()->unique();
            $table->string('jenis_peserta')->default('Wajib');
            $table->timestamps();
            $table->unsignedBigInteger('regu_id')->nullable();
            $table->string('status_registrasi')->nullable();

            $table->unique(['event_id', 'person_id'], 'participations_event_person_unique');
            $table->unique(['event_id', 'participant_number'], 'participations_event_participant_number_unique');
            $table->index('regu_id');

            $table->foreign('person_id')->references('id')->on('people')->restrictOnDelete();
            $table->foreign('event_id')->references('id')->on('events')->restrictOnDelete();
            $table->foreign('regu_id')->references('id')->on('regus')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participations');
        Schema::dropIfExists('people');
        Schema::dropIfExists('events');
    }
};