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
        Schema::create('website_daily_rotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('state')->nullable();
            $table->timestamps();
        });
        Schema::create('website_daily_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->date('run_date');
            $table->json('payloads');
            $table->json('posts')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['brand_id', 'run_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('website_daily_batches');
        Schema::dropIfExists('website_daily_rotations');
    }
};
