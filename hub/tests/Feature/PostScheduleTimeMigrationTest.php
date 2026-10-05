<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\PostSchedule;
use App\Models\SocialAccount;
use App\Models\TrendRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PostScheduleTimeMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_restores_only_todays_unstarted_daily_schedules_and_keeps_staggered_times(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 04:55:00', 'UTC'));
        $brand = Brand::factory()->create();
        $account = SocialAccount::factory()->create(['brand_id' => $brand->id]);
        $schedules = [];
        foreach (['10:00', '12:00', '14:00', '16:00', '18:00', '18:00', '18:00'] as $i => $time) {
            $post = $brand->posts()->create(['title' => 'Daily '.$i, 'body' => 'Question', 'channel' => 'facebook']);
            $schedules[$i] = PostSchedule::create(['post_id' => $post->id, 'social_account_id' => $account->id,
                'request_key' => (string) Str::uuid(), 'fingerprint' => str_repeat('a', 64), 'credential_version' => $account->credential_version,
                'scheduled_at' => '2026-10-05 05:30:08', 'status' => $i === 5 ? 'published' : 'queued',
                'started_at' => $i === 6 ? now() : null]);
            TrendRun::factory()->create(['brand_id' => $brand->id, 'post_id' => $post->id, 'social_account_id' => $account->id,
                'scope_key' => 'website-daily:'.$i, 'status' => 'scheduled', 'run_date' => '2026-10-05',
                'reason' => 'Daily website content scheduled for 05 Oct '.$time.' Asia/Kolkata.']);
        }
        $migration = require database_path('migrations/2026_10_05_045418_preserve_explicit_post_schedule_times.php');
        $migration->up();
        foreach (['04:30', '06:30', '08:30', '10:30', '12:30', '05:30', '05:30'] as $i => $expected) {
            $this->assertSame($expected, $schedules[$i]->fresh()->scheduled_at->format('H:i'));
        }
        $schedules[0]->update(['reason' => 'Unrelated update']);
        $this->assertSame('04:30', $schedules[0]->fresh()->scheduled_at->format('H:i'));
        $migration->up();
        $this->assertSame(7, PostSchedule::count());
    }
}
