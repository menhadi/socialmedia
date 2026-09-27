<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publications', function (Blueprint $t) {
            $t->timestamp('remote_deleted_at')->nullable();
            $t->string('deletion_origin', 40)->nullable();
        });
        Schema::create('publication_deletions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('publication_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->uuid('request_key')->unique();
            $t->string('fingerprint', 64);
            $t->string('status', 30)->default('pending');
            $t->text('reason')->nullable();
            $t->string('origin', 40)->default('hub');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_deletions');
        Schema::table('publications', fn (Blueprint $t) => $t->dropColumn(['remote_deleted_at', 'deletion_origin']));
    }
};
