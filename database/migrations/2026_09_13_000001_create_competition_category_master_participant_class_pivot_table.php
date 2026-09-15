<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('competition_category_master_participant_class')) {
            Schema::create('competition_category_master_participant_class', function (Blueprint $table) {
                $table->id();
                $table->foreignId('competition_category_id');
                $table->foreignId('master_participant_class_id');
                $table->timestamps();

                $table->unique(
                    ['competition_category_id', 'master_participant_class_id'],
                    'uniq_category_master_class'
                );

                $table->index(
                    'master_participant_class_id',
                    'cc_mpc_class_idx'
                );

                $table->foreign('competition_category_id', 'cc_mpc_category_fk')
                    ->references('id')
                    ->on('competition_categories')
                    ->cascadeOnDelete();

                $table->foreign('master_participant_class_id', 'cc_mpc_class_fk')
                    ->references('id')
                    ->on('master_participant_classes')
                    ->cascadeOnDelete();
            });

            return;
        }

        // Tabel sudah terlanjur dibuat oleh percobaan migration sebelumnya.
        // Unique index juga sudah ada, jadi hanya tambahkan index + FK.
        Schema::table('competition_category_master_participant_class', function (Blueprint $table) {
            $table->index(
                'master_participant_class_id',
                'cc_mpc_class_idx'
            );

            $table->foreign('competition_category_id', 'cc_mpc_category_fk')
                ->references('id')
                ->on('competition_categories')
                ->cascadeOnDelete();

            $table->foreign('master_participant_class_id', 'cc_mpc_class_fk')
                ->references('id')
                ->on('master_participant_classes')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_category_master_participant_class');
    }
};