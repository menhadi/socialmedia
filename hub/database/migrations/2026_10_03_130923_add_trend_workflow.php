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
        Schema::table('brands', function (Blueprint $table): void {
            $table->json('trend_settings')->nullable();
        });
        Schema::table('posts', function (Blueprint $table): void {
            $table->string('content_type', 20)->default('standard')->index();
        });
        Schema::create('trend_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_generation_id')->nullable()->constrained()->nullOnDelete();
            $table->date('run_date');
            $table->string('settings_version', 36);
            $table->string('status', 20)->default('running');
            $table->string('topic_hash', 64)->nullable()->index();
            $table->text('reason')->nullable();
            $table->json('evidence')->nullable();
            $table->json('package')->nullable();
            $table->timestamps();
            $table->unique(['brand_id', 'run_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trend_runs');
        Schema::table('posts', fn (Blueprint $table) => $table->dropColumn('content_type'));
        Schema::table('brands', fn (Blueprint $table) => $table->dropColumn('trend_settings'));
    }
};
