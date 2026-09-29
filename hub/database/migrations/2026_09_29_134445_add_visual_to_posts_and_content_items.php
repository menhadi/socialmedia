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
        foreach (['posts', 'content_items'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->json('visual')->nullable());
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['posts', 'content_items'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('visual'));
        }
    }
};
