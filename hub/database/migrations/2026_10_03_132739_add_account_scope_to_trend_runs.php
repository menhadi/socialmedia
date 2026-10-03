<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('trend_runs', function (Blueprint $table): void {
            $table->index('brand_id', 'trend_runs_brand_scope_index');
            $table->dropUnique(['brand_id', 'run_date']);
            $table->string('scope_key', 60)->default('website');
            $table->foreignId('social_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('automation_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('automation_version')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unique(['brand_id', 'run_date', 'scope_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('trend_runs')->select('brand_id', 'run_date')->groupBy('brand_id', 'run_date')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot restore one-run-per-website uniqueness while multiple platform runs exist. Preserve trend history and use a forward migration.');
        }
        Schema::table('trend_runs', function (Blueprint $table): void {
            $table->dropUnique(['brand_id', 'run_date', 'scope_key']);
            $table->dropConstrainedForeignId('social_account_id');
            $table->dropConstrainedForeignId('automation_rule_id');
            $table->dropColumn(['scope_key', 'automation_version', 'expires_at']);
            $table->unique(['brand_id', 'run_date']);
            $table->dropIndex('trend_runs_brand_scope_index');
        });
    }
};
