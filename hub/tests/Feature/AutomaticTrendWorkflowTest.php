<?php

namespace Tests\Feature;

use App\Models\AiConnection;
use App\Models\AutomationRule;
use App\Models\Brand;
use App\Models\MediaConnection;
use App\Models\MediaGeneration;
use App\Models\PostSchedule;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\TrendRun;
use App\Models\User;
use App\Services\Automation\CompleteAutomationMedia;
use App\Services\Monitoring\PublicEndpoint;
use App\Services\Social\PlatformClient;
use App\Services\Social\PublishPost;
use App\Services\Social\SchedulePost;
use App\Services\Trends\GenerateTrendDraft;
use App\Services\Trends\PlatformTrends;
use App\Services\Trends\PublishTrend;
use App\Services\Trends\TrendPostingTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class AutomaticTrendWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://learning.example.com/papers';

    private const QUOTE = 'Explore GATE aerospace papers and topic-wise practice questions.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-03 03:30:00');
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->partialMock(PublicEndpoint::class, fn ($mock) => $mock->shouldReceive('addresses')->andReturn(['8.8.8.8']));
    }

    private function settings(array $overrides = []): array
    {
        return $overrides + ['version' => (string) Str::uuid(), 'timezone' => 'Asia/Kolkata', 'daily_time' => '09:00',
            'window_start' => '09:00', 'window_end' => '21:00', 'preferred_time' => '18:00', 'region' => 'IN',
            'keywords' => 'GATE, aerospace', 'woeid' => 23424848, 'hashtags' => 'gate,aerospace',
            'minimum_views' => 100, 'landing_pages' => [self::URL]];
    }

    private function rule(string $channel = 'x', array $options = [], ?Brand $brand = null): AutomationRule
    {
        if (! $brand) {
            $user = User::factory()->create();
            $this->actingAs($user);
            $ai = AiConnection::factory()->enabled()->create(['user_id' => $user->id]);
            $brand = Brand::factory()->create(['user_id' => $user->id, 'website' => 'https://learning.example.com', 'ai_connection_id' => $ai->id]);
        }
        $account = SocialAccount::factory()->create(['brand_id' => $brand->id, 'provider' => $channel]);

        return AutomationRule::factory()->create(['brand_id' => $brand->id, 'social_account_id' => $account->id,
            'channel' => $channel, 'category' => 'trend', 'enabled' => true,
            'options' => $options + ['workflow' => 'automatic', 'media_kind' => $channel === 'youtube' ? 'video' : 'none', 'trend' => $this->settings(['channel' => $channel])]]);
    }

    private function fakePlatform(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.x.com/2/trends/*' => Http::response(['data' => [['trend_name' => 'GATE aerospace', 'tweet_count' => 2000], ['trend_name' => 'Cricket', 'tweet_count' => 30000]]]),
            'https://graph.facebook.com/*' => Http::response(['data' => [['message' => 'GATE aerospace preparation', 'created_time' => '2026-10-03T02:00:00Z', 'permalink_url' => 'https://www.facebook.com/example/posts/123', 'reactions' => ['summary' => ['total_count' => 10]]]]]),
            'https://www.googleapis.com/youtube/v3/search*' => Http::response(['items' => [['id' => ['videoId' => 'example123']]]]),
            'https://www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [['id' => 'example123', 'snippet' => ['title' => 'GATE aerospace', 'publishedAt' => '2026-10-03T02:00:00Z'], 'statistics' => ['viewCount' => '2000']]]]),
            self::URL => Http::response(self::QUOTE.' More learning material.', 200, ['Content-Type' => 'text/plain']),
            'https://api.deepseek.com/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode(['skip' => false, 'reason' => 'Useful aerospace resources.', 'concerns' => [], 'candidate_index' => 1, 'page_index' => 1, 'relevance' => 90, 'website_quote' => self::QUOTE, 'caption' => 'An AI caption with unsupported extra promises.'])], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 100],
            ]),
        ]);
    }

    public function test_native_topics_without_literal_keywords_reach_ai_selection(): void
    {
        $rule = $this->rule();
        Http::fake(['https://api.x.com/2/trends/*' => Http::response(['data' => [
            ['trend_name' => 'Student protests', 'tweet_count' => 2000],
            ['trend_name' => 'GATE', 'tweet_count' => 1000],
        ]])]);
        $result = app(PlatformTrends::class)->run(SocialAccount::findOrFail($rule->social_account_id), $rule->options['trend']);
        $this->assertSame(['GATE', 'Student protests'], array_column($result['candidates'], 'topic'));
    }

    public function test_manual_discovery_retry_can_recover_but_never_duplicates_a_generated_post(): void
    {
        $rule = $this->rule();
        Http::fake(['https://api.x.com/2/trends/*' => Http::response(['data' => []])]);
        $service = app(GenerateTrendDraft::class);
        $brand = Brand::findOrFail($rule->brand_id);
        $run = $service->run($brand, $rule);
        $this->assertSame('skipped', $run->status);
        $this->fakePlatform();
        $this->travel(2)->minutes();
        $this->assertSame('skipped', $service->run($brand, $rule)->status);
        $retried = $service->run($brand, $rule, retryDiscovery: true);
        $this->assertSame($run->id, $retried->id);
        $this->assertSame('scheduled', $retried->status, $retried->reason);
        $service->run($brand, $rule, retryDiscovery: true);
        $this->assertDatabaseCount('posts', 1);
        $this->assertDatabaseCount('trend_runs', 1);
        Http::assertSentCount(3);
    }

    public function test_useful_indirect_relevance_is_accepted_with_website_evidence(): void
    {
        $rule = $this->rule();
        $this->fakePlatform();
        Http::fake(['https://api.deepseek.com/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => json_encode(['skip' => false, 'reason' => 'Useful audience connection.', 'concerns' => [], 'candidate_index' => 1, 'page_index' => 1, 'relevance' => 65, 'website_quote' => self::QUOTE, 'caption' => 'Useful preparation resources.'])], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 100],
        ])]);
        $run = app(GenerateTrendDraft::class)->run(Brand::findOrFail($rule->brand_id), $rule);
        $this->assertSame('scheduled', $run->status, $run->reason);
    }

    public function test_automatic_post_uses_exact_website_evidence_and_account_window_once(): void
    {
        $rule = $this->rule();
        $this->fakePlatform();
        $run = app(GenerateTrendDraft::class)->run(Brand::findOrFail($rule->brand_id), $rule);
        $this->assertSame('scheduled', $run->status, $run->reason);
        $this->assertSame(self::QUOTE."\n\n".self::URL, $run->post->body);
        $this->assertSame('reviewed', $run->post->status);
        $schedule = PostSchedule::firstOrFail();
        $this->assertSame($rule->social_account_id, $schedule->social_account_id);
        $this->assertSame('2026-10-03 12:30:00', $schedule->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertFalse($schedule->include_link);
        app(GenerateTrendDraft::class)->run(Brand::findOrFail($rule->brand_id), $rule);
        app(PublishTrend::class)->queue($run, $rule);
        $this->assertSame('scheduled', $run->fresh()->status);
        $this->assertDatabaseCount('posts', 1);
        $this->assertDatabaseCount('post_schedules', 1);
        Http::assertSentCount(3);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'trends.google.com'));
    }

    public function test_explicit_pyp_trend_exception_preserves_question_provenance_and_rejects_edited_trends(): void
    {
        $rule = $this->rule();
        $brand = Brand::findOrFail($rule->brand_id);
        $brand->update(['pyp_only' => true, 'trend_posts_allowed' => true]);
        $this->fakePlatform();
        $run = app(GenerateTrendDraft::class)->run($brand, $rule);
        $this->assertSame('scheduled', $run->status, $run->reason);
        $run->post->assertContentPolicy();
        $standard = $brand->posts()->create(['title' => 'Unsourced practice', 'body' => 'An original question without a checked paper.', 'channel' => 'x']);
        foreach ([$standard, $run->post->forceFill(['body' => 'Edited unsupported claims.'])] as $post) {
            try {
                $post->assertContentPolicy();
                $this->fail('The PYP or trend evidence policy must reject this post.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertTrue($brand->fresh()->pyp_only);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'deepseek') && str_contains($request['messages'][0]['content'], 'PYP-only instructions apply to question posts'));
    }

    public function test_revoking_pyp_trend_exception_blocks_already_scheduled_delivery(): void
    {
        $rule = $this->rule();
        $brand = Brand::findOrFail($rule->brand_id);
        $brand->update(['pyp_only' => true, 'trend_posts_allowed' => true]);
        $this->fakePlatform();
        app(GenerateTrendDraft::class)->run($brand, $rule);
        $schedule = PostSchedule::firstOrFail();
        $brand->update(['trend_posts_allowed' => false]);
        $this->travelTo('2026-10-03 12:30:00');
        app(SchedulePost::class)->run($schedule);
        $this->assertSame('blocked', $schedule->fresh()->status);
        $this->assertDatabaseCount('publications', 0);
    }

    public function test_owner_can_save_trend_exception_without_removing_pyp_requirement(): void
    {
        $rule = $this->rule();
        $brand = Brand::findOrFail($rule->brand_id);
        $this->put('/applications/'.$brand->id, ['name' => $brand->name, 'website' => $brand->website,
            'tone' => 'Helpful and clear', 'language' => 'English', 'pyp_only' => 1, 'trend_posts_allowed' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue($brand->fresh()->pyp_only);
        $this->assertTrue($brand->fresh()->trend_posts_allowed);
        $this->get('/applications/'.$brand->id.'/edit')->assertOk()->assertSee('Also allow website-grounded trend posts');
        $this->actingAs(User::factory()->create());
        $this->put('/applications/'.$brand->id, ['trend_posts_allowed' => 0])->assertNotFound();
        $this->assertTrue($brand->fresh()->trend_posts_allowed);
    }

    public function test_worker_attempts_each_platform_independently_and_review_mode_does_not_schedule(): void
    {
        $x = $this->rule(options: ['workflow' => 'review']);
        $this->rule('facebook', ['workflow' => 'review'], Brand::findOrFail($x->brand_id));
        $this->fakePlatform();
        $this->travelTo('2026-10-03 03:29:00');
        $this->artisan('hub:run-trend-workflow')->assertSuccessful();
        $this->assertDatabaseCount('trend_runs', 0);
        $this->travelTo('2026-10-03 03:30:00');
        $this->artisan('hub:run-trend-workflow')->assertSuccessful();
        $this->artisan('hub:run-trend-workflow')->assertSuccessful();
        $this->assertDatabaseCount('trend_runs', 2);
        $this->assertDatabaseCount('posts', 2);
        $this->assertDatabaseCount('post_schedules', 0);
        $this->assertSame(['review'], TrendRun::distinct()->pluck('status')->all());
        Http::assertSentCount(6);
    }

    public function test_platform_failure_holds_without_google_fallback_or_ai_spend(): void
    {
        $rule = $this->rule();
        Http::fake(['https://api.x.com/*' => Http::response(['error' => 'Forbidden'], 403)]);
        $run = app(GenerateTrendDraft::class)->run(Brand::findOrFail($rule->brand_id), $rule);
        $this->assertSame('held', $run->status);
        $this->assertDatabaseCount('ai_generations', 0);
        $this->assertDatabaseCount('posts', 0);
        $this->assertStringContainsString('403', $run->evidence['errors'][0]);
        Http::assertSentCount(1);
    }

    #[TestWith(['expired'])]
    #[TestWith(['window'])]
    #[TestWith(['content'])]
    #[TestWith(['rule'])]
    #[TestWith(['evidence'])]
    #[TestWith(['signal'])]
    public function test_delivery_holds_when_trend_or_approval_no_longer_qualifies(string $condition): void
    {
        $rule = $this->rule();
        $this->fakePlatform();
        $run = app(GenerateTrendDraft::class)->run(Brand::findOrFail($rule->brand_id), $rule);
        $schedule = PostSchedule::firstOrFail();
        $this->travelTo('2026-10-03 12:30:00');
        if ($condition === 'expired') {
            $run->update(['expires_at' => now()->subMinute()]);
        } elseif ($condition === 'window') {
            $this->travelTo('2026-10-03 16:00:00');
        } elseif ($condition === 'content') {
            $run->post->forceFill(['body' => 'Edited after automatic assessment.'])->save();
        } elseif ($condition === 'rule') {
            $rule->update(['version' => $rule->version + 1]);
        } elseif ($condition === 'evidence') {
            Http::swap(new Factory);
            Http::fake([self::URL => Http::response('Website text changed.', 200, ['Content-Type' => 'text/plain'])]);
        } else {
            Http::swap(new Factory);
            Http::fake([self::URL => Http::response(self::QUOTE, 200, ['Content-Type' => 'text/plain']), 'https://api.x.com/*' => Http::response(['data' => []])]);
        }
        $this->mock(PublishPost::class)->shouldNotReceive('run');
        app(SchedulePost::class)->run($schedule);
        $this->assertSame('blocked', $schedule->fresh()->status);
        $this->assertDatabaseCount('publications', 0);
    }

    public function test_passing_delivery_checks_call_publisher_once(): void
    {
        $rule = $this->rule();
        $this->fakePlatform();
        app(GenerateTrendDraft::class)->run(Brand::findOrFail($rule->brand_id), $rule);
        $schedule = PostSchedule::firstOrFail();
        $this->travelTo('2026-10-03 12:30:00');
        $this->mock(PublishPost::class)->shouldReceive('run')->once()->andReturn((new Publication)->forceFill(['status' => 'published']));
        app(SchedulePost::class)->run($schedule);
        app(SchedulePost::class)->run($schedule);
        $this->assertSame('published', $schedule->fresh()->status);
    }

    public function test_timing_waits_until_next_window_and_holds_if_topic_would_expire(): void
    {
        $rule = $this->rule();
        $account = SocialAccount::findOrFail($rule->social_account_id);
        $this->travelTo('2026-10-03 16:00:00');
        $slot = app(TrendPostingTime::class)->choose($account, $rule->options['trend'], CarbonImmutable::now()->addDay());
        $this->assertSame('2026-10-04 12:30:00', $slot['when']->format('Y-m-d H:i:s'));
        $this->expectException(RuntimeException::class);
        app(TrendPostingTime::class)->choose($account, $rule->options['trend'], CarbonImmutable::now()->addHours(2));
    }

    public function test_timing_leaves_one_hour_between_existing_and_new_posts(): void
    {
        $rule = $this->rule();
        $this->fakePlatform();
        app(GenerateTrendDraft::class)->run(Brand::findOrFail($rule->brand_id), $rule);
        PostSchedule::firstOrFail()->post->forceFill(['content_type' => 'standard'])->save();
        $slot = app(TrendPostingTime::class)->choose(SocialAccount::findOrFail($rule->social_account_id), $rule->options['trend'], CarbonImmutable::now()->addDay());
        $this->assertSame('2026-10-03 13:30:00', $slot['when']->format('Y-m-d H:i:s'));
    }

    public function test_settings_require_owner_matching_website_and_valid_window(): void
    {
        $rule = $this->rule();
        $payload = $this->settings() + ['workflow' => 'automatic', 'enabled' => 1, 'media_kind' => 'none'];
        $payload['landing_pages'] = self::URL;
        $url = '/trends/accounts/'.$rule->social_account_id;
        $this->put($url, $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(2, $rule->fresh()->version);
        $this->assertSame('automatic', $rule->fresh()->options['workflow']);
        $this->get('/trends?brand='.$rule->brand_id)->assertOk()->assertSee('Automatically schedule and publish');
        $this->put($url, array_replace($payload, ['window_end' => '08:00']))->assertSessionHasErrors('window_end');
        $this->put($url, array_replace($payload, ['landing_pages' => 'https://other.example.com/page']))->assertSessionHasErrors('landing_pages');
        $this->actingAs(User::factory()->create());
        $this->put($url, $payload)->assertNotFound();
        $this->post('/trends/rules/'.$rule->id.'/generate')->assertNotFound();
    }

    public function test_video_failure_holds_trend_and_does_not_schedule(): void
    {
        $rule = $this->rule('youtube');
        $brand = Brand::findOrFail($rule->brand_id);
        $connection = MediaConnection::factory()->create(['user_id' => $brand->user_id, 'kind' => 'video', 'model' => 'veo-3.1-fast-generate-preview']);
        $brand->update(['video_connection_id' => $connection->id]);
        $this->fakePlatform();
        $run = app(GenerateTrendDraft::class)->run($brand, $rule);
        $this->assertSame('media', $run->status, $run->reason);
        $job = MediaGeneration::firstOrFail();
        $job->update(['status' => 'failed']);
        app(CompleteAutomationMedia::class)->run($job);
        $this->assertSame('held', $run->fresh()->status);
        $this->assertDatabaseCount('post_schedules', 0);
    }

    public function test_instagram_uses_recent_media_without_unsupported_timestamp_field(): void
    {
        $rule = $this->rule('instagram');
        Http::fake([
            'https://graph.facebook.com/*/ig_hashtag_search*' => Http::response(['data' => [['id' => '12345']]]),
            'https://graph.facebook.com/*/12345/recent_media*' => Http::response(['data' => [['id' => '1', 'caption' => 'GATE aerospace practice', 'permalink' => 'https://www.instagram.com/p/example/', 'like_count' => 15, 'comments_count' => 2]]]),
        ]);
        $result = app(PlatformTrends::class)->run(SocialAccount::findOrFail($rule->social_account_id), $rule->options['trend']);
        $this->assertSame([], $result['errors']);
        $this->assertNotEmpty($result['candidates']);
        $this->assertStringContainsString('Conservative', $result['candidates'][0]['time_basis']);
        Http::assertNotSent(fn ($request) => str_contains($request['fields'] ?? '', 'timestamp'));
    }

    public function test_pending_publication_cannot_finish_outside_audience_window(): void
    {
        $rule = $this->rule();
        $this->fakePlatform();
        $run = app(GenerateTrendDraft::class)->run(Brand::findOrFail($rule->brand_id), $rule);
        $schedule = PostSchedule::firstOrFail();
        $run->update(['status' => 'processing']);
        $schedule->update(['status' => 'processing']);
        $this->travelTo('2026-10-03 16:00:00');
        $publication = Publication::factory()->create(['social_account_id' => $rule->social_account_id,
            'post_id' => $run->post_id, 'request_key' => $schedule->request_key,
            'transfer' => ['stage' => 'waiting'], 'next_check_at' => now()->subMinute()]);
        $this->mock(PlatformClient::class)->shouldNotReceive('resume');
        app(PublishPost::class)->resume($publication);
        $this->assertSame('failed', $publication->fresh()->status);
        $this->assertSame('platform_approval', $publication->fresh()->error_code);
        $this->assertSame('failed', $run->fresh()->status);
    }

    public function test_ready_original_video_is_attached_and_scheduled_publicly_once(): void
    {
        $rule = $this->rule('youtube');
        $brand = Brand::findOrFail($rule->brand_id);
        $connection = MediaConnection::factory()->create(['user_id' => $brand->user_id, 'kind' => 'video', 'model' => 'veo-3.1-fast-generate-preview']);
        $brand->update(['video_connection_id' => $connection->id]);
        $this->fakePlatform();
        $run = app(GenerateTrendDraft::class)->run($brand, $rule);
        Storage::disk('local')->put('trend.mp4', 'test-video');
        $job = MediaGeneration::firstOrFail();
        $job->update(['status' => 'completed', 'path' => 'trend.mp4', 'hash' => hash('sha256', 'test-video')]);
        app(CompleteAutomationMedia::class)->run($job);
        app(CompleteAutomationMedia::class)->run($job->fresh());
        $this->assertSame('scheduled', $run->fresh()->status, $run->fresh()->reason);
        $this->assertDatabaseCount('post_schedules', 1);
        $this->assertSame('public', PostSchedule::firstOrFail()->options['privacy']);
        $this->assertSame('trend.mp4', $run->post->video_path);
        $this->assertNull($job->fresh()->automation_context);
    }

    public function test_missing_facebook_dates_and_low_view_youtube_videos_are_skipped(): void
    {
        $facebook = $this->rule('facebook');
        $youtube = $this->rule('youtube', brand: Brand::findOrFail($facebook->brand_id));
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['data' => [['message' => 'GATE aerospace', 'permalink_url' => 'https://www.facebook.com/p/123', 'reactions' => ['summary' => ['total_count' => 10]]]]]),
            'https://www.googleapis.com/youtube/v3/search*' => Http::response(['items' => [['id' => ['videoId' => 'example123']]]]),
            'https://www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [['id' => 'example123', 'snippet' => ['title' => 'GATE aerospace', 'publishedAt' => '2026-10-03T02:00:00Z'], 'statistics' => ['viewCount' => '10']]]]),
        ]);
        foreach ([$facebook, $youtube] as $rule) {
            $result = app(PlatformTrends::class)->run(SocialAccount::findOrFail($rule->social_account_id), $rule->options['trend']);
            $this->assertSame([], $result['candidates']);
        }
    }
}
