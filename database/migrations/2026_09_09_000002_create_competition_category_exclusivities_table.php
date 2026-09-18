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

        $table->unsignedBigInteger('competition_category_id');
        $table->unsignedBigInteger('exclusive_with_category_id');

        $table->timestamps();

        $table->foreign(
            'competition_category_id',
            'fk_cc_excl_category'
        )
            ->references('id')
            ->on('competition_categories')
            ->cascadeOnDelete();

        $table->foreign(
            'exclusive_with_category_id',
            'fk_cc_excl_exclusive'
        )
            ->references('id')
            ->on('competition_categories')
            ->cascadeOnDelete();

        $table->unique(
            ['competition_category_id', 'exclusive_with_category_id'],
            'uniq_cc_exclusivity'
        );
    });
}
    public function down(): void
    {
        Schema::dropIfExists('competition_category_exclusivities');
    }
};
