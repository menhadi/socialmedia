<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();
            $table->string('provider')->default('facebook');
            $table->string('page_id', 50);
            $table->string('page_name')->nullable();
            $table->text('access_token')->nullable();
            $table->uuid('credential_version');
            $table->timestamp('verified_at')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamps();
            $table->unique(['brand_id', 'provider', 'page_id']);
        });
        Schema::create('publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id')->constrained()->restrictOnDelete();
            $table->foreignId('social_account_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->string('fingerprint', 64);
            $table->string('page_id', 50);
            $table->string('page_name');
            $table->text('message');
            $table->string('link', 2048)->nullable();
            $table->string('status')->default('publishing');
            $table->string('remote_post_id')->nullable();
            $table->string('permalink_url', 2048)->nullable();
            $table->string('error_code')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['post_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publications');
        Schema::dropIfExists('social_accounts');
    }
};
