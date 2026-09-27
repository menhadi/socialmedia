<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $t) {
            $t->string('intake_token_hash', 64)->nullable()->unique();
        });
        Schema::table('posts', function (Blueprint $t) {
            $t->timestamp('archived_at')->nullable();
            $t->text('automation_reason')->nullable();
            $t->text('learning_note')->nullable();
        });
        Schema::create('automation_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $t->string('channel', 30);
            $t->string('category', 30);
            $t->foreignId('social_account_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('enabled')->default(false);
            $t->boolean('trust_intake')->default(false);
            $t->boolean('learn')->default(true);
            $t->boolean('with_image')->default(false);
            $t->unsignedInteger('delay_minutes')->default(30);
            $t->unsignedInteger('daily_limit')->default(3);
            $t->unsignedInteger('version')->default(1);
            $t->text('options')->nullable();
            $t->timestamps();
            $t->unique(['brand_id', 'channel', 'category']);
        });
        Schema::create('content_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $t->string('external_id', 150);
            $t->string('category', 30);
            $t->string('channel', 30);
            $t->string('title', 200);
            $t->text('body');
            $t->text('source_url')->nullable();
            $t->string('fingerprint', 64);
            $t->boolean('approved')->default(false);
            $t->string('status', 30)->default('pending');
            $t->text('reason')->nullable();
            $t->foreignId('post_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['brand_id', 'external_id', 'channel']);
        });
        Schema::table('post_schedules', function (Blueprint $t) {
            $t->foreignId('automation_rule_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedInteger('automation_version')->nullable();
        });
        Schema::create('analytics_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('publication_id')->constrained()->cascadeOnDelete();
            $t->string('status', 30);
            $t->json('metrics')->nullable();
            $t->text('reason')->nullable();
            $t->timestamps();
            $t->index(['publication_id', 'created_at']);
        });
        Schema::create('application_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $t->foreignId('publication_id')->nullable()->constrained()->nullOnDelete();
            $t->string('external_id', 150);
            $t->string('name', 60);
            $t->timestamps();
            $t->unique(['brand_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_events');
        Schema::dropIfExists('analytics_snapshots');
        Schema::table('post_schedules', function (Blueprint $t) {
            $t->dropConstrainedForeignId('automation_rule_id');
            $t->dropColumn('automation_version');
        });
        Schema::dropIfExists('content_items');
        Schema::dropIfExists('automation_rules');
        Schema::table('posts', fn (Blueprint $t) => $t->dropColumn(['archived_at', 'automation_reason', 'learning_note']));
        Schema::table('brands', function (Blueprint $t) {
            $t->dropUnique(['intake_token_hash']);
            $t->dropColumn('intake_token_hash');
        });
    }
};
