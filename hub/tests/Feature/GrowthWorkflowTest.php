<?php

namespace Tests\Feature;

use App\Models\AiConnection;
use App\Models\AnalyticsSnapshot;
use App\Models\AutomationRule;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\ContentSource;
use App\Models\Post;
use App\Models\PostSchedule;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Analytics\CollectAnalytics;
use App\Services\Analytics\PerformanceContext;
use App\Services\Automation\RunAutomation;
use App\Services\Research\CreateSourceDraft;
use App\Services\Research\PostImage;
use App\Services\Social\SchedulePost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GrowthWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function setupRule(array $attributes = []): AutomationRule
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $ai = AiConnection::factory()->enabled()->create(['user_id' => $user->id]);
        $brand = Brand::factory()->create(['user_id' => $user->id, 'ai_connection_id' => $ai->id, 'website' => 'https://school.example']);
        $account = SocialAccount::factory()->create(['brand_id' => $brand->id]);

        return AutomationRule::factory()->create($attributes + ['brand_id' => $brand->id, 'social_account_id' => $account->id]);
    }

    private function fakeAi(array $overrides = []): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.deepseek.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode($overrides + ['headline_quote' => 'Study a little every day', 'excerpt_quote' => 'Study a little every day and review your notes.', 'hashtags' => ['#Study'], 'concerns' => []])], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 100]])]);
    }

    public function test_structured_question_creates_card_with_short_caption_and_supplied_hashtags(): void
    {
        $rule = $this->setupRule(['category' => 'question', 'with_image' => false, 'options' => ['workflow' => 'review', 'media_kind' => 'image']]);
        $this->fakeAi();
        $visual = ['type' => 'question', 'question' => 'Study a little every day?', 'options' => ['Yes', 'No'], 'answer' => 1, 'exam' => 'GATE', 'year' => 2024, 'difficulty' => 'hard'];
        $item = ContentItem::factory()->create(['brand_id' => $rule->brand_id, 'category' => 'question', 'approved' => true, 'body' => 'Study a little every day and review your notes.', 'visual' => $visual]);
        $this->mock(PostImage::class)->shouldReceive('create')->once()->withArgs(fn (Post $post) => $post->visual === $visual)->andReturn(['image_path' => 'card.png', 'image_hash' => 'rendered']);

        app(RunAutomation::class)->run($item);

        $post = Post::findOrFail($item->fresh()->post_id);
        $this->assertSame("Try this question. Choose your answer, then open the source link to practise.\n\n#GATE #Exam2024", $post->body);
        $this->assertSame('rendered', $post->image_hash);
        $this->assertSame('review', $item->fresh()->status);
        $this->assertDatabaseCount('media_generations', 0);
        $this->assertDatabaseCount('post_schedules', 0);
    }

    public function test_hard_question_rule_holds_missing_difficulty_before_ai_spend(): void
    {
        $rule = $this->setupRule(['category' => 'question', 'options' => ['question_difficulty' => 'hard']]);
        Http::preventStrayRequests();
        $item = ContentItem::factory()->create(['brand_id' => $rule->brand_id, 'category' => 'question', 'approved' => true]);

        app(RunAutomation::class)->run($item);

        $this->assertSame('held', $item->fresh()->status);
        $this->assertStringContainsString('source-labelled hard', $item->fresh()->reason);
        $this->assertDatabaseCount('ai_generations', 0);
        Http::assertNothingSent();
    }

    public function test_intake_requires_token_and_deduplicates_without_trusting_client_approval(): void
    {
        $brand = Brand::factory()->create(['intake_token_hash' => hash('sha256', str_repeat('a', 64))]);
        $payload = ['external_id' => 'q-1', 'category' => 'question', 'channel' => 'facebook', 'title' => 'Daily question', 'body' => 'What is two plus two? Answer: four.', 'approved' => true, 'brand_id' => 9999];
        $this->postJson('/api/v1/content', $payload)->assertUnauthorized();
        $this->withToken(str_repeat('a', 64))->postJson('/api/v1/content', $payload)->assertCreated();
        $this->postJson('/api/v1/content', $payload)->assertOk();
        $this->postJson('/api/v1/content', array_replace($payload, ['body' => 'Changed text that requires a new revision.']))->assertConflict();
        $this->assertDatabaseCount('content_items', 1);
        $this->assertDatabaseHas('content_items', ['brand_id' => $brand->id, 'approved' => false]);
    }

    public function test_rule_and_token_writes_are_owner_scoped(): void
    {
        $rule = $this->setupRule();
        $brand = Brand::findOrFail($rule->brand_id);
        $this->post('/automation/'.$brand->id.'/token')->assertSessionHas('intake_token');
        $this->assertSame(hash('sha256', session('intake_token')), $brand->fresh()->intake_token_hash);
        $foreign = SocialAccount::factory()->create();
        $this->post('/automation/rules', ['brand_id' => $brand->id, 'channel' => 'facebook', 'category' => 'general', 'social_account_id' => $foreign->id, 'delay_minutes' => 30, 'daily_limit' => 3])->assertSessionHasErrors('social_account_id');
        $this->actingAs(User::factory()->create())->post('/automation/'.$brand->id.'/token')->assertNotFound();
        $this->post('/automation/rules', ['brand_id' => $brand->id])->assertNotFound();
    }

    public function test_approved_content_schedules_once_with_english_and_daily_spacing(): void
    {
        $this->freezeTime();
        $rule = $this->setupRule(['daily_limit' => 1]);
        $this->fakeAi();
        $first = ContentItem::factory()->create(['brand_id' => $rule->brand_id]);
        $second = ContentItem::factory()->create(['brand_id' => $rule->brand_id]);
        app(RunAutomation::class)->run($first);
        app(RunAutomation::class)->run($first->fresh());
        app(RunAutomation::class)->run($second);
        $this->assertSame('scheduled', $first->fresh()->status);
        $this->assertDatabaseCount('post_schedules', 2);
        $entries = PostSchedule::orderBy('id')->get();
        $this->assertTrue($entries[1]->scheduled_at->startOfDay()->gt($entries[0]->scheduled_at->startOfDay()));
        $this->assertDatabaseHas('ai_generations', ['language' => 'English']);
        Http::assertSentCount(2);
    }

    public function test_untrusted_intake_is_held_without_spending(): void
    {
        $rule = $this->setupRule();
        Http::preventStrayRequests();
        $item = ContentItem::factory()->create(['brand_id' => $rule->brand_id, 'approved' => false]);
        app(RunAutomation::class)->run($item);
        $this->assertSame('held', $item->fresh()->status);
        $this->assertDatabaseCount('post_schedules', 0);
        Http::assertNothingSent();
    }

    public function test_flagged_content_keeps_draft_without_schedule(): void
    {
        $rule = $this->setupRule();
        $this->fakeAi(['concerns' => ['Unverified claim']]);
        $item = ContentItem::factory()->create(['brand_id' => $rule->brand_id]);
        app(RunAutomation::class)->run($item);
        $this->assertSame('held', $item->fresh()->status);
        $this->assertNotNull($item->post_id);
        $this->assertDatabaseCount('post_schedules', 0);
        $this->assertSame('draft', Post::first()->status);
    }

    public function test_unmatched_quotes_do_not_create_invented_posts(): void
    {
        $rule = $this->setupRule();
        $this->fakeAi(['excerpt_quote' => 'An invented claim that is not in the evidence.']);
        $item = ContentItem::factory()->create(['brand_id' => $rule->brand_id]);
        app(RunAutomation::class)->run($item);
        $this->assertSame('held', $item->fresh()->status);
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_budget_exhaustion_and_exam_results_are_held(): void
    {
        $rule = $this->setupRule();
        Http::preventStrayRequests();
        $brand = Brand::find($rule->brand_id);
        $brand->aiConnection->update(['daily_budget_micros' => 1]);
        $item = ContentItem::factory()->create(['brand_id' => $rule->brand_id]);
        app(RunAutomation::class)->run($item);
        $official = ContentItem::factory()->create(['brand_id' => $rule->brand_id, 'category' => 'result']);
        app(RunAutomation::class)->run($official);
        $this->assertSame('held', $item->fresh()->status);
        $this->assertSame('held', $official->fresh()->status);
        $this->assertDatabaseCount('post_schedules', 0);
        Http::assertNothingSent();
    }

    public function test_manual_assessment_requires_confirmation_then_schedules_clear_draft(): void
    {
        $rule = $this->setupRule();
        $this->fakeAi();
        $post = Brand::find($rule->brand_id)->posts()->create(['title' => 'Study tip', 'body' => 'Study a little every day and review your notes.', 'channel' => 'facebook']);
        $this->post('/posts/'.$post->id.'/assess', ['category' => 'general'])->assertSessionHasErrors('confirmed');
        $this->post('/posts/'.$post->id.'/assess', ['category' => 'general', 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('post_schedules', ['post_id' => $post->id, 'status' => 'queued']);
    }

    public function test_changed_rule_blocks_delivery(): void
    {
        $this->freezeTime();
        $rule = $this->setupRule();
        $this->fakeAi();
        $item = ContentItem::factory()->create(['brand_id' => $rule->brand_id]);
        app(RunAutomation::class)->run($item);
        $rule->update(['enabled' => false]);
        $this->travel(31)->minutes();
        app(SchedulePost::class)->run(PostSchedule::first());
        $this->assertDatabaseHas('post_schedules', ['status' => 'blocked']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'graph.facebook'));
    }

    public function test_archive_cancels_queue_and_restore_does_not_republish(): void
    {
        $rule = $this->setupRule();
        $this->fakeAi();
        $item = ContentItem::factory()->create(['brand_id' => $rule->brand_id]);
        app(RunAutomation::class)->run($item);
        $post = Post::find($item->post_id);
        $this->post('/posts/'.$post->id.'/archive')->assertSessionHasNoErrors();
        $this->assertNotNull($post->fresh()->archived_at);
        $this->assertDatabaseHas('post_schedules', ['status' => 'cancelled']);
        $this->post('/posts/'.$post->id.'/archive')->assertSessionHasNoErrors();
        $this->assertNull($post->fresh()->archived_at);
        $this->assertDatabaseHas('post_schedules', ['status' => 'cancelled']);
        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');
    }

    public function test_archive_preserves_published_history_and_blocks_uncertain_submission(): void
    {
        Http::preventStrayRequests();
        $rule = $this->setupRule();
        $publication = Publication::factory()->create(['social_account_id' => $rule->social_account_id, 'status' => 'published']);
        $this->post('/posts/'.$publication->post_id.'/archive')->assertRedirect();
        $this->assertModelExists($publication);
        $publication->forceFill(['status' => 'uncertain'])->save();
        $this->post('/posts/'.$publication->post_id.'/archive')->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_metrics_preserve_zero_unknown_and_latest_values_without_summing_snapshots(): void
    {
        $this->freezeTime();
        Http::preventStrayRequests();
        $publication = Publication::factory()->create(['status' => 'published', 'remote_post_id' => '123_456', 'published_at' => now()]);
        Http::fake(['https://graph.facebook.com/*' => Http::response(['id' => '123_456', 'reactions' => ['summary' => ['total_count' => 0]], 'comments' => ['summary' => ['total_count' => 2]]])]);
        app(CollectAnalytics::class)->run($publication);
        $this->travel(31)->minutes();
        app(CollectAnalytics::class)->run($publication);
        $this->assertDatabaseCount('analytics_snapshots', 2);
        $this->assertSame(['reactions' => 0, 'comments' => 2], $publication->fresh()->latestAnalytics->metrics);
        $this->actingAs(User::find($publication->post->brand->user_id))->get('/analytics')->assertOk()->assertSee('Comments');
    }

    public function test_unavailable_remote_post_keeps_history_and_never_republishes(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.facebook.com/*' => Http::response(['error' => ['message' => 'Unavailable']], 404)]);
        $publication = Publication::factory()->create(['status' => 'published', 'remote_post_id' => '123_456', 'published_at' => now()]);
        app(CollectAnalytics::class)->run($publication);
        $this->assertSame('unavailable', $publication->fresh()->latestAnalytics->status);
        $this->assertSame('published', $publication->fresh()->status);
        Http::assertSent(fn ($r) => $r->method() === 'GET');
        $this->assertDatabaseCount('publications', 1);
    }

    public function test_feedback_requires_three_fresh_samples_from_same_application_and_platform(): void
    {
        $this->freezeTime();
        $rule = $this->setupRule();
        $brand = Brand::find($rule->brand_id);
        foreach (range(1, 3) as $i) {
            $p = Publication::factory()->create(['social_account_id' => $rule->social_account_id, 'status' => 'published', 'published_at' => now()->subDays(3)]);
            AnalyticsSnapshot::create(['publication_id' => $p->id, 'status' => 'available', 'metrics' => ['reactions' => $i]]);
        }
        $foreign = Publication::factory()->create(['status' => 'published', 'published_at' => now()->subDays(3)]);
        AnalyticsSnapshot::create(['publication_id' => $foreign->id, 'status' => 'available', 'metrics' => ['reactions' => 9999]]);
        $context = app(PerformanceContext::class)->build($brand, 'facebook');
        $this->assertCount(3, $context['examples']);
        $this->assertNotContains($foreign->id, array_column($context['examples'], 'publication_id'));
        $this->assertSame([], app(PerformanceContext::class)->build($brand, 'instagram')['examples']);
        $this->travel(3)->days();
        $this->assertSame([], app(PerformanceContext::class)->build($brand, 'facebook')['examples']);
    }

    public function test_events_are_idempotent_and_cannot_reference_another_application(): void
    {
        Brand::factory()->create(['intake_token_hash' => hash('sha256', str_repeat('z', 64))]);
        $foreign = Publication::factory()->create(['status' => 'published']);
        $this->withToken(str_repeat('z', 64))->postJson('/api/v1/events', ['external_id' => 'v1', 'name' => 'visit', 'publication_id' => $foreign->id])->assertUnprocessable();
        $this->postJson('/api/v1/events', ['external_id' => 'v1', 'name' => 'visit'])->assertCreated();
        $this->postJson('/api/v1/events', ['external_id' => 'v1', 'name' => 'visit'])->assertOk();
        $this->postJson('/api/v1/events', ['external_id' => 'v1', 'name' => 'registration'])->assertConflict();
        $this->assertDatabaseCount('application_events', 1);
    }

    public function test_pages_escape_content_and_isolate_other_owners(): void
    {
        $rule = $this->setupRule();
        $item = ContentItem::factory()->create(['brand_id' => $rule->brand_id, 'title' => '<script>alert(1)</script>']);
        $this->get('/automation')->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->get('/analytics')->assertOk();
        $post = Brand::find($rule->brand_id)->posts()->create(['title' => 'Private record', 'body' => 'Private body', 'channel' => 'facebook']);
        $this->get('/posts/'.$post->id.'/edit')->assertOk();
        $this->actingAs(User::factory()->create())->get('/automation')->assertDontSee('alert(1)');
        $this->post('/automation/content/'.$item->id.'/approve')->assertNotFound();
        $this->post('/posts/'.$post->id.'/archive')->assertNotFound();
        $this->post('/posts/'.$post->id.'/assess', ['confirmed' => 1, 'category' => 'general'])->assertNotFound();
    }

    public function test_due_automation_publishes_with_application_attribution(): void
    {
        $this->freezeTime();
        $rule = $this->setupRule();
        $this->fakeAi();
        SocialAccount::whereKey($rule->social_account_id)->update(['page_id' => '123']);
        $item = ContentItem::factory()->create(['brand_id' => $rule->brand_id, 'source_url' => 'https://school.example/study']);
        app(RunAutomation::class)->run($item);
        Http::fake(['https://graph.facebook.com/*/feed' => Http::response(['id' => '123_456']), 'https://graph.facebook.com/*/123_456*' => Http::response(['permalink_url' => 'https://www.facebook.com/123/posts/456'])]);
        $this->travel(31)->minutes();
        app(SchedulePost::class)->run(PostSchedule::first());
        $this->assertDatabaseHas('publications', ['status' => 'published']);
        $this->assertDatabaseHas('post_schedules', ['status' => 'published']);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r['link'] ?? '', 'hub_publication=') && str_contains($r['link'] ?? '', 'utm_source=facebook'));
    }

    public function test_instagram_without_media_is_held_and_budgeted_intake_does_not_publish(): void
    {
        $rule = $this->setupRule(['channel' => 'instagram']);
        SocialAccount::whereKey($rule->social_account_id)->update(['provider' => 'instagram']);
        $this->fakeAi();
        $item = ContentItem::factory()->create(['brand_id' => $rule->brand_id, 'channel' => 'instagram']);
        app(RunAutomation::class)->run($item);
        $this->assertSame('held', $item->fresh()->status);
        $this->assertStringContainsString('requires an image', $item->fresh()->reason);
        $this->assertDatabaseCount('post_schedules', 0);
        $this->assertSame('draft', Post::first()->status);
    }

    public function test_approved_official_first_capture_uses_opted_in_rule(): void
    {
        $this->freezeTime();
        $rule = $this->setupRule(['category' => 'official']);
        $this->fakeAi(['headline_quote' => 'New examination results announced', 'excerpt_quote' => 'The examination results are now available on the official results portal.', 'date_text' => now('UTC')->format('j F Y'), 'caption' => 'Read the official notice.']);
        $source = ContentSource::factory()->create(['brand_id' => $rule->brand_id, 'social_account_id' => $rule->social_account_id, 'channel' => 'facebook', 'enabled' => true, 'auto_publish' => true, 'approved_at' => now(), 'with_image' => false]);
        $text = 'New examination results announced The examination results are now available on the official results portal. '.now('UTC')->format('j F Y');
        $snapshot = $source->snapshots()->create(['source_version' => $source->version, 'url' => $source->url, 'text' => $text, 'hash' => hash('sha256', $text), 'status' => 'captured', 'checked_at' => now()]);
        app(CreateSourceDraft::class)->run($snapshot, true);
        $this->assertSame('scheduled', $snapshot->fresh()->status);
        $this->assertDatabaseHas('post_schedules', ['automatic' => true, 'automation_rule_id' => $rule->id]);
    }

    #[DataProvider('platformMetrics')]
    public function test_metrics_are_parsed_for_supported_platforms(string $provider, array $body, array $expected): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://graph.instagram.com/*' => Http::response($body), 'https://graph.facebook.com/*' => Http::response($body), 'https://api.x.com/*' => Http::response($body), 'https://www.googleapis.com/*' => Http::response($body), 'https://api.linkedin.com/*' => Http::response($body)]);
        $account = SocialAccount::factory()->create(['provider' => $provider, 'page_id' => $provider === 'linkedin' ? 'urn:li:organization:123' : '123']);
        $publication = Publication::factory()->create(['social_account_id' => $account->id, 'provider' => $provider, 'status' => 'published', 'remote_post_id' => $provider === 'linkedin' ? 'urn:li:share:123' : '123', 'published_at' => now()]);
        app(CollectAnalytics::class)->run($publication);
        $this->assertSame($expected, $publication->fresh()->latestAnalytics->metrics);
        Http::assertSentCount(1);
    }

    public static function platformMetrics(): array
    {
        return [
            'instagram' => ['instagram', ['id' => '123', 'like_count' => 2, 'comments_count' => 0], ['likes' => 2, 'comments' => 0]],
            'x' => ['x', ['data' => ['id' => '123', 'public_metrics' => ['like_count' => 3, 'reply_count' => 1, 'impression_count' => 40]]], ['likes' => 3, 'comments' => 1, 'impressions' => 40]],
            'youtube' => ['youtube', ['items' => [['statistics' => ['viewCount' => '55', 'likeCount' => '4']]]], ['views' => 55, 'likes' => 4]],
            'linkedin' => ['linkedin', ['elements' => [['totalShareStatistics' => ['impressionCount' => 60, 'clickCount' => 3]]]], ['impressions' => 60, 'clicks' => 3]],
        ];
    }

    public function test_disabled_official_rule_does_not_fall_back_to_source_auto_publish(): void
    {
        $rule = $this->setupRule(['category' => 'official', 'enabled' => false]);
        $this->fakeAi(['headline_quote' => 'New examination results announced', 'excerpt_quote' => 'The examination results are now available on the official results portal.', 'date_text' => now('UTC')->format('j F Y'), 'caption' => 'Read the official notice.']);
        $source = ContentSource::factory()->create(['brand_id' => $rule->brand_id, 'social_account_id' => $rule->social_account_id, 'channel' => 'facebook', 'enabled' => true, 'auto_publish' => true, 'approved_at' => now(), 'with_image' => false]);
        $text = 'New examination results announced The examination results are now available on the official results portal. '.now('UTC')->format('j F Y');
        $snapshot = $source->snapshots()->create(['source_version' => $source->version, 'url' => $source->url, 'text' => $text, 'hash' => hash('sha256', $text), 'status' => 'captured', 'checked_at' => now()]);
        app(CreateSourceDraft::class)->run($snapshot, false);
        $this->assertDatabaseCount('post_schedules', 0);
        $this->assertStringContainsString('disabled', $snapshot->fresh()->reason);
    }

    public function test_scheduler_command_processes_intake_and_collects_eligible_metrics(): void
    {
        $rule = $this->setupRule();
        $this->fakeAi();
        $item = ContentItem::factory()->create(['brand_id' => $rule->brand_id]);
        $publication = Publication::factory()->create(['social_account_id' => $rule->social_account_id, 'status' => 'published', 'remote_post_id' => '123_456', 'published_at' => now()->subDay()]);
        Http::fake(['https://graph.facebook.com/*' => Http::response(['id' => '123_456', 'reactions' => ['summary' => ['total_count' => 3]]])]);

        $this->artisan('hub:run-growth-workflow')->assertSuccessful();

        $this->assertSame('scheduled', $item->fresh()->status);
        $this->assertSame(['reactions' => 3], $publication->fresh()->latestAnalytics->metrics);
    }
}
