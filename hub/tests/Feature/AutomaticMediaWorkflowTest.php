<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\Brand;
use App\Models\MediaConnection;
use App\Models\MediaGeneration;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Automation\CompleteAutomationMedia;
use App\Services\Automation\RunAutomation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AutomaticMediaWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function workflow(string $mode = 'automatic'): array
    {
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->freezeTime();
        $user = User::factory()->create();
        $connection = MediaConnection::factory()->create(['user_id' => $user->id, 'kind' => 'video']);
        $brand = Brand::factory()->create(['user_id' => $user->id, 'video_connection_id' => $connection->id]);
        $post = $brand->posts()->create(['title' => 'Private test', 'body' => 'A short illustrative test video.', 'channel' => 'youtube']);
        $account = SocialAccount::factory()->create(['brand_id' => $brand->id, 'provider' => 'youtube']);
        $rule = AutomationRule::factory()->create(['brand_id' => $brand->id, 'channel' => 'youtube', 'social_account_id' => $account->id,
            'options' => ['workflow' => $mode, 'media_kind' => 'video', 'aspect_ratio' => '16:9', 'privacy' => 'private', 'made_for_kids' => false]]);
        $this->actingAs($user);

        return [$post, $rule->fresh()];
    }

    private function finishMedia(): MediaGeneration
    {
        $job = MediaGeneration::firstOrFail();
        Storage::disk('local')->put('test.mp4', 'test-video');
        $job->update(['status' => 'completed', 'path' => 'test.mp4', 'hash' => hash('sha256', 'test-video'), 'api_key' => null]);

        return $job;
    }

    public function test_automatic_media_is_attached_and_scheduled_once_as_private(): void
    {
        [$post, $rule] = $this->workflow();
        $this->assertFalse(app(RunAutomation::class)->queue($post, $rule));
        $this->assertDatabaseCount('post_schedules', 0);
        $this->finishMedia();

        $this->artisan('hub:generate-media')->assertExitCode(0);
        $this->artisan('hub:generate-media')->assertExitCode(0);

        $this->assertDatabaseCount('post_schedules', 1);
        $schedule = $post->schedules()->firstOrFail();
        $this->assertSame('private', $schedule->options['privacy']);
        $this->assertSame($rule->id, $schedule->automation_rule_id);
        $this->assertSame('reviewed', $post->fresh()->status);
        $this->assertSame('test.mp4', $post->fresh()->video_path);
        Http::assertNothingSent();
    }

    public function test_normal_review_does_not_attach_or_schedule_generated_media(): void
    {
        [$post, $rule] = $this->workflow('review');
        app(RunAutomation::class)->queue($post, $rule);
        $job = $this->finishMedia();

        $this->artisan('hub:generate-media')->assertExitCode(0);

        $this->assertDatabaseCount('post_schedules', 0);
        $this->assertNull($post->fresh()->video_path);
        $this->assertSame('completed', $job->fresh()->status);
        $this->get(route('media', $post))->assertSee('Use this video');
        $this->post(route('media.attach', $job), ['confirm' => 1])->assertRedirect();
        $this->assertSame('draft', $post->fresh()->status);
        $this->assertDatabaseCount('post_schedules', 0);
        Http::assertNothingSent();
    }

    public static function holds(): array
    {
        return [['edit'], ['archive'], ['rule'], ['failed'], ['file'], ['account']];
    }

    #[DataProvider('holds')]
    public function test_changed_or_failed_media_is_held_for_manual_review(string $change): void
    {
        [$post, $rule] = $this->workflow();
        app(RunAutomation::class)->queue($post, $rule);
        $job = $this->finishMedia();
        match ($change) {
            'edit' => $post->update(['body' => 'Changed after generation was requested']),
            'archive' => $post->forceFill(['archived_at' => now()])->save(),
            'rule' => $rule->increment('version'),
            'failed' => $job->update(['status' => 'failed']),
            'file' => Storage::disk('local')->put('test.mp4', 'changed'),
            'account' => SocialAccount::whereKey($rule->social_account_id)->update(['verified_at' => null]),
        };

        app(CompleteAutomationMedia::class)->run($job);

        $this->assertDatabaseCount('post_schedules', 0);
        $this->assertNull($post->fresh()->video_path);
        $this->assertNull($job->fresh()->automation_context);
        $this->assertStringContainsString('held', $post->fresh()->automation_reason);
        Http::assertNothingSent();
    }

    public function test_rule_form_saves_review_mode_and_rejects_square_video(): void
    {
        [$post, $rule] = $this->workflow();
        $data = ['brand_id' => $post->brand_id, 'channel' => 'youtube', 'category' => 'general',
            'social_account_id' => $rule->social_account_id, 'daily_limit' => 3, 'delay_minutes' => 30,
            'enabled' => 1, 'workflow' => 'review', 'media_kind' => 'video', 'aspect_ratio' => '16:9',
            'options' => ['privacy' => 'private', 'made_for_kids' => 0]];

        $this->post(route('automation.save'), $data)->assertSessionHasNoErrors();
        $this->assertSame('review', $rule->fresh()->options['workflow']);
        $this->get(route('automation', ['brand' => $post->brand_id, 'channel' => 'youtube']))->assertSee('Normal admin review before publishing');
        $this->post(route('automation.save'), array_replace($data, ['aspect_ratio' => '1:1']))->assertSessionHasErrors('aspect_ratio');
        Http::assertNothingSent();
    }
}
