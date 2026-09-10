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
            $table->string('event_type', 50)->default('cai');
            $table->text('description')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 50)->default('active');
            $table->timestamps();
        });

        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('jenis_kelamin', 1)->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->string('kelas')->nullable();
            $table->unsignedBigInteger('desa_id')->nullable();
            $table->unsignedBigInteger('kelompok_id')->nullable();
            $table->unsignedBigInteger('nip')->nullable()->unique();
            $table->timestamps();

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
            $table->string('status_registrasi')->nullable();
            $table->unsignedBigInteger('regu_id')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'person_id']);
            $table->unique(['event_id', 'participant_number']);
            $table->index('regu_id');

            $table->foreign('person_id')->references('id')->on('people')->restrictOnDelete();
            $table->foreign('event_id')->references('id')->on('events')->restrictOnDelete();
            $table->foreign('regu_id')->references('id')->on('regus')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('person_id')->nullable()->unique();
            $table->foreign('person_id')->references('id')->on('people')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['person_id']);
            $table->dropColumn('person_id');
        });
        Schema::dropIfExists('participations');
        Schema::dropIfExists('people');
        Schema::dropIfExists('events');
    }
};