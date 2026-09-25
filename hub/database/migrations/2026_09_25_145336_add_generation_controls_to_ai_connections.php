<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_connections', function (Blueprint $table) {
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('daily_request_limit')->default(10);
            $table->unsignedInteger('max_output_tokens')->default(1200);
            $table->unsignedBigInteger('daily_budget_micros')->default(0);
            $table->decimal('input_rate', 12, 4)->nullable();
            $table->decimal('output_rate', 12, 4)->nullable();
        });
        Schema::create('ai_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_connection_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('fingerprint', 64);
            $table->string('provider');
            $table->string('model');
            $table->string('task');
            $table->string('title', 200);
            $table->string('channel');
            $table->string('language', 100);
            $table->string('source_url', 2048)->nullable();
            $table->string('status')->default('pending');
            $table->text('result')->nullable();
            $table->string('error_code')->nullable();
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('cost_micros');
            $table->decimal('input_rate', 12, 4);
            $table->decimal('output_rate', 12, 4);
            $table->boolean('usage_reported')->default(false);
            $table->foreignId('post_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'request_key']);
            $table->index(['ai_connection_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generations');
        Schema::table('ai_connections', fn (Blueprint $table) => $table->dropColumn(['enabled', 'daily_request_limit', 'max_output_tokens', 'daily_budget_micros', 'input_rate', 'output_rate']));
    }
};
