<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('model');
            $table->text('api_key')->nullable();
            $table->boolean('enabled')->default(false);
            $table->unsignedBigInteger('daily_budget_micros')->default(0);
            $table->unsignedBigInteger('request_cost_micros')->default(0);
            $table->unsignedInteger('daily_request_limit')->default(5);
            $table->timestamps();
            $table->unique(['user_id', 'kind']);
        });
        Schema::table('brands', function (Blueprint $table) {
            $table->foreignId('image_connection_id')->nullable()->constrained('media_connections')->nullOnDelete();
            $table->foreignId('video_connection_id')->nullable()->constrained('media_connections')->nullOnDelete();
        });
        Schema::create('media_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_connection_id')->constrained();
            $table->uuid('request_key')->unique();
            $table->string('fingerprint', 64);
            $table->string('kind');
            $table->string('model');
            $table->text('api_key')->nullable();
            $table->text('prompt');
            $table->string('aspect_ratio');
            $table->string('input_image')->nullable();
            $table->string('status')->default('queued')->index();
            $table->string('operation')->nullable();
            $table->string('path')->nullable();
            $table->string('hash', 64)->nullable();
            $table->unsignedBigInteger('cost_micros');
            $table->string('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
        Schema::table('posts', function (Blueprint $table) {
            $table->string('video_path')->nullable();
            $table->string('video_hash', 64)->nullable();
        });
        Schema::table('publications', fn (Blueprint $table) => $table->string('video_path')->nullable());
        Schema::table('content_sources', fn (Blueprint $table) => $table->string('media_kind')->default('branded'));
    }

    public function down(): void
    {
        Schema::table('content_sources', fn (Blueprint $table) => $table->dropColumn('media_kind'));
        Schema::table('publications', fn (Blueprint $table) => $table->dropColumn('video_path'));
        Schema::table('posts', fn (Blueprint $table) => $table->dropColumn(['video_path', 'video_hash']));
        Schema::dropIfExists('media_generations');
        Schema::table('brands', function (Blueprint $table) {
            $table->dropConstrainedForeignId('image_connection_id');
            $table->dropConstrainedForeignId('video_connection_id');
        });
        Schema::dropIfExists('media_connections');
    }
};
