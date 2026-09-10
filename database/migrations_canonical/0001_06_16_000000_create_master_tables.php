<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desas', function (Blueprint $table) {
            $table->id();
            $table->string('desa_asal');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('kelompoks', function (Blueprint $table) {
            $table->id();
            $table->string('kelompok_asal');
            $table->unsignedBigInteger('desa_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('desa_id')->references('id')->on('desas')->nullOnDelete();
        });

        Schema::create('regus', function (Blueprint $table) {
            $table->id();
            $table->string('regu');
            $table->enum('jenis_kelamin', ['Laki - Laki', 'Perempuan'])->nullable();
            $table->timestamps();
        });

        Schema::create('master_participant_classes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_participant_classes');
        Schema::dropIfExists('regus');
        Schema::dropIfExists('kelompoks');
        Schema::dropIfExists('desas');
    }
};