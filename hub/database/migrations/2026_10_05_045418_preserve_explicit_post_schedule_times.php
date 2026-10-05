<?php

use Carbon\CarbonImmutable;
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
        Schema::table('post_schedules', function (Blueprint $table): void {
            $table->dateTime('scheduled_at')->change();
        });

        $today = CarbonImmutable::now('Asia/Kolkata')->toDateString();
        $runs = DB::table('trend_runs')->where('run_date', $today)
            ->where('status', 'scheduled')->where('scope_key', 'like', 'website-daily:%')->get();
        foreach ($runs as $run) {
            if (! preg_match('/^Daily website content scheduled for (\d{2} [A-Za-z]{3} \d{2}:\d{2}) ([A-Za-z_\/]+)\.$/', $run->reason ?? '', $match)) {
                continue;
            }
            $when = CarbonImmutable::createFromFormat('!Y d M H:i', substr($today, 0, 4).' '.$match[1], $match[2]);
            if ($when->toDateString() !== $today || DB::table('publications')->where('post_id', $run->post_id)->exists()) {
                continue;
            }
            DB::table('post_schedules')->where('post_id', $run->post_id)
                ->where('social_account_id', $run->social_account_id)
                ->where('status', 'queued')->whereNull('started_at')
                ->update(['scheduled_at' => $when->utc()->format('Y-m-d H:i:s')]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Keep explicit dates on rollback: restoring implicit timestamp updates would corrupt schedules again.
    }
};
