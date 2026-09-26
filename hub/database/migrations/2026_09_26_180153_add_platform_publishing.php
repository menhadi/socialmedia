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
        Schema::table('publications', function (Blueprint $table) {
            $table->string('provider')->default('facebook');
            $table->string('title_snapshot')->nullable();
            $table->text('options')->nullable();
            $table->text('transfer')->nullable();
            $table->string('credential_version')->nullable();
            $table->string('asset_path')->nullable();
            $table->timestamp('next_check_at')->nullable()->index();
        });
        Schema::table('post_schedules', fn (Blueprint $table) => $table->text('options')->nullable());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('post_schedules', fn (Blueprint $table) => $table->dropColumn('options'));
        Schema::table('publications', fn (Blueprint $table) => $table->dropColumn(['provider', 'title_snapshot', 'options', 'transfer', 'credential_version', 'asset_path', 'next_check_at']));
    }
};
