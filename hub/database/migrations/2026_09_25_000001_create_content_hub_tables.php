<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_connections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('provider');
            $t->string('model')->nullable();
            $t->text('api_key')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'provider']);
        });
        Schema::create('brands', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('website', 2048)->nullable();
            $t->text('description')->nullable();
            $t->text('audience')->nullable();
            $t->string('tone')->default('Helpful and clear');
            $t->string('language')->default('English');
            $t->text('instructions')->nullable();
            $t->foreignId('ai_connection_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('posts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->string('channel');
            $t->text('body');
            $t->string('source_url', 2048)->nullable();
            $t->string('status')->default('draft');
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('ai_connections');
    }
};
