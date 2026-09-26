<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $t) {
            $t->text('website_context')->nullable();
            $t->timestamp('website_read_at')->nullable();
        });
        Schema::create('content_sources', function (Blueprint $t) {
            $t->id();
            $t->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $t->foreignId('social_account_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name', 150);
            $t->string('topic', 200);
            $t->string('url', 2048);
            $t->string('comparison_url', 2048)->nullable();
            $t->string('element_id', 150)->nullable();
            $t->string('channel')->default('facebook');
            $t->boolean('enabled')->default(false);
            $t->boolean('auto_publish')->default(false);
            $t->boolean('with_image')->default(true);
            $t->timestamp('approved_at')->nullable();
            $t->unsignedInteger('interval_minutes')->default(60);
            $t->unsignedInteger('delay_minutes')->default(60);
            $t->timestamp('next_check_at')->nullable()->index();
            $t->timestamp('checked_at')->nullable();
            $t->string('last_hash', 64)->nullable();
            $t->text('last_error')->nullable();
            $t->uuid('version');
            $t->timestamps();
        });
        Schema::create('source_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('content_source_id')->constrained()->cascadeOnDelete();
            $t->foreignId('post_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('ai_generation_id')->nullable()->constrained()->nullOnDelete();
            $t->uuid('source_version');
            $t->string('url', 2048);
            $t->string('comparison_url', 2048)->nullable();
            $t->longText('text');
            $t->longText('comparison_text')->nullable();
            $t->string('hash', 64);
            $t->string('event_hash', 64)->nullable();
            $t->timestamp('checked_at');
            $t->timestamp('rechecked_at')->nullable();
            $t->string('status')->default('captured');
            $t->text('reason')->nullable();
            $t->json('package')->nullable();
            $t->timestamps();
            $t->unique(['content_source_id', 'source_version', 'hash'], 'snapshot_version_hash');
        });
        Schema::table('posts', function (Blueprint $t) {
            $t->string('image_path')->nullable();
            $t->string('image_hash', 64)->nullable();
        });
        Schema::table('publications', fn (Blueprint $t) => $t->string('image_path')->nullable());
        Schema::create('post_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('post_id')->constrained()->cascadeOnDelete();
            $t->foreignId('social_account_id')->constrained();
            $t->foreignId('source_snapshot_id')->nullable()->constrained()->nullOnDelete();
            $t->uuid('request_key')->unique();
            $t->string('fingerprint', 64);
            $t->uuid('credential_version');
            $t->boolean('automatic')->default(false);
            $t->boolean('include_link')->default(true);
            $t->timestamp('scheduled_at')->index();
            $t->timestamp('started_at')->nullable();
            $t->string('status')->default('queued');
            $t->text('reason')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_schedules');
        Schema::table('publications', fn (Blueprint $t) => $t->dropColumn('image_path'));
        Schema::table('posts', fn (Blueprint $t) => $t->dropColumn(['image_path', 'image_hash']));
        Schema::dropIfExists('source_snapshots');
        Schema::dropIfExists('content_sources');
        Schema::table('brands', fn (Blueprint $t) => $t->dropColumn(['website_context', 'website_read_at']));
    }
};
