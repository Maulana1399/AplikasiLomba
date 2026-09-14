<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competition_category_event', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('competition_category_id')->constrained('competition_categories')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['event_id', 'competition_category_id'], 'uniq_event_category');
            $table->index('event_id');
            $table->index('competition_category_id');
        });

        if (Schema::hasTable('competition_categories') && Schema::hasColumn('competition_categories', 'event_id')) {
            $categories = DB::table('competition_categories')->whereNotNull('event_id')->get(['id', 'event_id']);
            foreach ($categories as $cat) {
                $exists = DB::table('competition_category_event')
                    ->where('event_id', $cat->event_id)
                    ->where('competition_category_id', $cat->id)
                    ->exists();
                if (! $exists) {
                    DB::table('competition_category_event')->insert([
                        'event_id' => $cat->event_id,
                        'competition_category_id' => $cat->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_category_event');
    }
};
