<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_monitors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->unsignedSmallInteger('expected_status')->default(200);
            $table->unsignedSmallInteger('interval_minutes')->default(5);
            $table->boolean('enabled')->default(false);
            $table->uuid('configuration_version');
            $table->string('last_status')->default('unknown');
            $table->timestamp('last_checked_at')->nullable();
            $table->unsignedInteger('last_response_ms')->nullable();
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('next_check_at')->nullable()->index();
            $table->uuid('running_key')->nullable();
            $table->timestamp('running_since')->nullable();
            $table->timestamps();
        });
        Schema::create('health_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('health_monitor_id')->constrained()->cascadeOnDelete();
            $table->uuid('request_key')->unique();
            $table->uuid('configuration_version');
            $table->string('url', 2048);
            $table->string('status')->default('running');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('response_ms')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamp('checked_at')->index();
            $table->timestamp('completed_at')->nullable();
            $table->index(['health_monitor_id', 'checked_at']);
        });
        Schema::create('health_incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('health_monitor_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason')->nullable();
            $table->index(['health_monitor_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_incidents');
        Schema::dropIfExists('health_checks');
        Schema::dropIfExists('health_monitors');
    }
};
