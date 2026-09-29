<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->json('card_images')->nullable();
            $table->json('card_sources')->nullable();
            $table->text('card_plan_note')->nullable();
        });
        Schema::table('publications', function (Blueprint $table) {
            $table->json('card_images')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('posts', fn (Blueprint $table) => $table->dropColumn(['card_images', 'card_sources', 'card_plan_note']));
        Schema::table('publications', fn (Blueprint $table) => $table->dropColumn('card_images'));
    }
};
