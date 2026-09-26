<?php

namespace Tests\Feature;

use App\Models\AiConnection;
use App\Models\Brand;
use App\Models\ContentSource;
use App\Models\MediaConnection;
use App\Models\MediaGeneration;
use App\Models\Post;
use App\Models\PostSchedule;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\SourceSnapshot;
use App\Models\User;
use App\Services\Monitoring\PublicEndpoint;
use App\Services\Research\FetchSource;
use App\Services\Research\PostImage;
use App\Services\Research\ResearchSource;
use App\Services\Social\SchedulePost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResearchWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->travelTo(now('UTC')->startOfSecond());
        config(['services.facebook.version' => 'v25.0']);
        $this->partialMock(PublicEndpoint::class, function ($mock): void {
            $mock->shouldReceive('addresses')->andReturn(['8.8.8.8']);
        });
    }

    private function source(array $attributes = []): ContentSource
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $ai = AiConnection::factory()->enabled()->create(['user_id' => $user->id]);
        $brand = Brand::factory()->create(['user_id' => $user->id, 'ai_connection_id' => $ai->id]);
        $account = SocialAccount::factory()->create(['brand_id' => $brand->id, 'page_id' => '12345']);

        return ContentSource::factory()->create($attributes + ['brand_id' => $brand->id, 'social_account_id' => $account->id]);
    }

    private function package(array $overrides = []): array
    {
        return $overrides + [
            'headline_quote' => 'New examination results announced',
            'excerpt_quote' => 'The examination results are now available on the official results portal.',
            'caption' => 'परीक्षा का परिणाम आधिकारिक वेबसाइट पर उपलब्ध है।',
            'date_text' => now('UTC')->format('j F Y'), 'hashtags' => ['#Education'], 'concerns' => [],
        ];
    }

    private function sourceText(array $package): string
    {
        return $package['headline_quote'].' '.$package['excerpt_quote'].' '.$package['date_text'];
    }

    private function fakeResearch(array $package, ?string $text = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://official.example.com/*' => Http::response('<main>'.e($text ?? $this->sourceText($package)).'</main>', 200, ['Content-Type' => 'text/html']),
            'https://api.deepseek.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode($package, JSON_UNESCAPED_UNICODE)], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 100],
            ]),
            'https://graph.facebook.com/v25.0/12345/feed' => Http::response(['id' => '12345_9876']),
            'https://graph.facebook.com/v25.0/12345/photos' => Http::response(['id' => '6543', 'post_id' => '12345_9876']),
            'https://graph.facebook.com/v25.0/12345_9876*' => Http::response(['id' => '12345_9876', 'permalink_url' => 'https://www.facebook.com/12345/posts/9876']),
        ]);
    }

    private function reviewed(ContentSource $source): Post
    {
        $post = $source->brand->posts()->create(['title' => 'Reviewed announcement', 'body' => 'Approved body', 'channel' => 'facebook', 'source_url' => $source->url]);
        $post->forceFill(['status' => 'reviewed', 'reviewed_at' => now()])->save();

        return $post;
    }

    public function test_source_setup_and_pages_are_owner_scoped_and_do_not_fetch(): void
    {
        $source = $this->source();
        $this->get('/research')->assertOk()->assertSee('Official notices');
        $this->get('/schedules')->assertOk();
        $this->actingAs(User::factory()->create())->get('/research')->assertOk()->assertDontSee('Official notices');
        $this->post("/research/{$source->id}/check")->assertNotFound();
        $this->put("/research/{$source->id}", [])->assertNotFound();
        $this->post("/research/brands/{$source->brand_id}/website")->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_researched_ai_media_is_queued_but_requires_visual_review_before_publishing(): void
    {
        $source = $this->source(['enabled' => true, 'auto_publish' => true, 'approved_at' => now(), 'last_hash' => 'previous', 'media_kind' => 'image']);
        $connection = MediaConnection::factory()->create(['user_id' => $source->brand->user_id]);
        $source->brand->update(['image_connection_id' => $connection->id]);
        $this->fakeResearch($this->package());

        app(ResearchSource::class)->run($source->fresh());

        $this->assertDatabaseCount('media_generations', 1);
        $this->assertSame('queued', MediaGeneration::first()->status);
        $this->assertSame('draft', Post::first()->status);
        $this->assertDatabaseCount('post_schedules', 0);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'generativelanguage') || str_contains($request->url(), 'graph.facebook'));
    }

    public function test_automatic_setup_requires_official_approval_and_same_brand_verified_page(): void
    {
        $source = $this->source();
        $payload = ['brand_id' => $source->brand_id, 'name' => 'Authority', 'topic' => 'Results',
            'url' => $source->url, 'channel' => 'facebook', 'interval_minutes' => 60, 'delay_minutes' => 60,
            'enabled' => 1, 'auto_publish' => 1, 'social_account_id' => $source->social_account_id];
        $this->post('/research', $payload)->assertSessionHasErrors('auto_publish');
        $this->post('/research', $payload + ['official' => 1])->assertSessionHasNoErrors()->assertRedirect('/research');
        $foreign = SocialAccount::factory()->create();
        $this->post('/research', array_replace($payload, ['official' => 1, 'social_account_id' => $foreign->id]))->assertSessionHasErrors('social_account_id');
        Http::assertNothingSent();
    }

    public function test_first_capture_is_review_only_and_unchanged_source_does_not_spend_again(): void
    {
        $source = $this->source(['enabled' => true, 'auto_publish' => true, 'approved_at' => now()]);
        $this->fakeResearch($this->package());
        app(ResearchSource::class)->run($source);
        app(ResearchSource::class)->run($source->fresh());
        $this->assertDatabaseCount('source_snapshots', 1);
        $this->assertDatabaseCount('ai_generations', 1);
        $this->assertDatabaseCount('posts', 1);
        $this->assertDatabaseCount('post_schedules', 0);
        $this->assertSame('draft', Post::first()->status);
        $this->assertStringContainsString('Initial source capture', SourceSnapshot::first()->reason);
        $this->get('/research')->assertOk()->assertSee('Captured source evidence');
        $this->get('/ai-assistant')->assertOk();
    }

    public function test_changed_official_source_schedules_exact_quotes_and_publishes_only_once_when_due(): void
    {
        $source = $this->source(['enabled' => true, 'auto_publish' => true, 'approved_at' => now(), 'last_hash' => 'previous']);
        $this->fakeResearch($this->package());
        app(ResearchSource::class)->run($source);
        $post = Post::firstOrFail();
        $schedule = PostSchedule::firstOrFail();
        $this->assertStringContainsString($this->package()['excerpt_quote'], $post->body);
        $this->assertStringNotContainsString($this->package()['caption'], $post->body);
        app(SchedulePost::class)->run($schedule);
        $this->assertDatabaseCount('publications', 0);
        $this->travel(61)->minutes();
        app(SchedulePost::class)->run($schedule->fresh());
        app(SchedulePost::class)->run($schedule->fresh());
        $this->assertSame('published', $schedule->fresh()->status);
        $this->assertNotNull(SourceSnapshot::first()->rechecked_at);
        $this->assertDatabaseCount('publications', 1);
        Http::assertSentCount(5);
    }

    public function test_invented_quotes_and_unverified_dates_stay_in_review(): void
    {
        $source = $this->source(['enabled' => true, 'auto_publish' => true, 'approved_at' => now(), 'last_hash' => 'previous']);
        $this->fakeResearch($this->package(), 'The official website contains different information and no confirmed result announcement.');
        app(ResearchSource::class)->run($source);
        $this->assertDatabaseCount('post_schedules', 0);
        $this->assertSame('draft', Post::first()->status);
        $this->assertStringContainsString('could not be matched', SourceSnapshot::first()->reason);
    }

    public function test_missing_notice_date_or_comparison_conflict_never_auto_schedules(): void
    {
        $source = $this->source(['enabled' => true, 'auto_publish' => true, 'approved_at' => now(), 'last_hash' => 'previous']);
        $this->fakeResearch($this->package(['date_text' => '']));
        app(ResearchSource::class)->run($source);
        $this->assertStringContainsString('notice date', SourceSnapshot::first()->reason);
        $this->assertDatabaseCount('post_schedules', 0);
    }

    public function test_changed_source_at_publish_time_blocks_submission(): void
    {
        $source = $this->source(['enabled' => true, 'auto_publish' => true, 'approved_at' => now(), 'last_hash' => 'previous']);
        $this->fakeResearch($this->package());
        app(ResearchSource::class)->run($source);
        $this->fakeResearch($this->package(), 'The previous notice was withdrawn and a corrected announcement will be published shortly.');
        $this->travel(61)->minutes();
        app(SchedulePost::class)->run(PostSchedule::first());
        $this->assertSame('blocked', PostSchedule::first()->status);
        $this->assertDatabaseCount('publications', 0);
    }

    public function test_same_announcement_in_a_changed_page_does_not_create_another_post(): void
    {
        $source = $this->source();
        $package = $this->package();
        $this->fakeResearch($package);
        app(ResearchSource::class)->run($source);
        $this->fakeResearch($package, $this->sourceText($package).' Extra page footer update.');
        app(ResearchSource::class)->run($source->fresh());
        $this->assertDatabaseCount('posts', 1);
        $this->assertDatabaseHas('source_snapshots', ['status' => 'duplicate']);
    }

    public function test_manual_schedule_uses_timezone_and_post_edits_cancel_it_and_remove_stale_image(): void
    {
        $source = $this->source();
        $post = $this->reviewed($source);
        Storage::disk('local')->put('post-images/old.png', 'old-image');
        $post->forceFill(['image_path' => 'post-images/old.png', 'image_hash' => str_repeat('a', 64)])->save();
        $when = now()->addHours(3)->setTimezone('Asia/Kolkata');
        $this->post("/posts/{$post->id}/schedule", [
            'social_account_id' => $source->social_account_id, 'fingerprint' => $post->publishingFingerprint(),
            'scheduled_at' => $when->format('Y-m-d\TH:i'), 'timezone' => 'Asia/Kolkata', 'confirm' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect('/schedules');
        $this->assertSame($when->utc()->format('Y-m-d H:i'), PostSchedule::first()->scheduled_at->format('Y-m-d H:i'));
        $this->put("/posts/{$post->id}", ['brand_id' => $source->brand_id, 'title' => 'Changed', 'body' => 'New text', 'channel' => 'facebook'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', PostSchedule::first()->status);
        $this->assertNull($post->fresh()->image_path);
        $this->assertSame('draft', $post->fresh()->status);
    }

    public function test_destination_credential_change_blocks_scheduled_submission(): void
    {
        $source = $this->source();
        $post = $this->reviewed($source);
        $schedule = app(SchedulePost::class)->create($post, $source->social_account_id, now()->subMinute());
        SocialAccount::find($source->social_account_id)->forceFill(['credential_version' => (string) Str::uuid()])->save();
        app(SchedulePost::class)->run($schedule);
        $this->assertSame('blocked', $schedule->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_queued_schedule_blocks_manual_publish_and_cancellation_restores_control(): void
    {
        $source = $this->source();
        $post = $this->reviewed($source);
        $schedule = app(SchedulePost::class)->create($post, $source->social_account_id, now()->addHour());
        $this->post("/posts/{$post->id}/publish", ['social_account_id' => $source->social_account_id, 'request_key' => (string) Str::uuid(), 'fingerprint' => $post->publishingFingerprint(), 'confirm' => 1])->assertSessionHasErrors('post');
        $this->post("/schedules/{$schedule->id}/cancel")->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $schedule->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_photo_publishing_uses_multipart_and_preserves_link_in_caption(): void
    {
        $source = $this->source();
        $post = $this->reviewed($source);
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jZJkAAAAASUVORK5CYII=');
        Storage::disk('local')->put('post-images/test.png', $bytes);
        $post->forceFill(['image_path' => 'post-images/test.png', 'image_hash' => hash('sha256', $bytes)])->save();
        $this->fakeResearch($this->package());
        $schedule = app(SchedulePost::class)->create($post, $source->social_account_id, now()->subMinute());
        app(SchedulePost::class)->run($schedule);
        $this->assertSame('published', $schedule->fresh()->status);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/12345/photos') && $request->hasFile('source'));
        $this->assertSame('post-images/test.png', Publication::first()->image_path);
        $this->get("/posts/{$post->id}/image")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs(User::factory()->create())->get("/posts/{$post->id}/image")->assertNotFound();
    }

    public function test_fetcher_removes_scripts_and_rejects_private_hosts_and_redirects(): void
    {
        Http::fake(['https://official.example.com/*' => Http::response('<main><h1>Official headline</h1><p>Readable content about the examination and its official notification.</p><script>malicious instructions</script></main>', 200, ['Content-Type' => 'text/html'])]);
        $text = app(FetchSource::class)->fetch('https://official.example.com/notices?q=1');
        $this->assertStringNotContainsString('malicious instructions', $text);
        $this->assertStringContainsString('Official headline Readable', $text);
        $this->expectException(\RuntimeException::class);
        app(FetchSource::class)->fetch('http://127.0.0.1/private');
    }

    public function test_redirect_to_private_address_is_not_followed(): void
    {
        Http::fake(['https://official.example.com/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/private'])]);
        try {
            app(FetchSource::class)->fetch('https://official.example.com/notices');
            $this->fail('Redirect was accepted');
        } catch (\RuntimeException) {
            Http::assertSentCount(1);
        }
    }

    public function test_website_context_is_read_for_only_its_brand(): void
    {
        $source = $this->source();
        $source->brand->update(['website' => $source->url]);
        $this->fakeResearch($this->package());
        $this->post("/research/brands/{$source->brand_id}/website")->assertSessionHasNoErrors();
        $this->assertStringContainsString('examination', $source->brand->fresh()->website_context);
        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('ai_generations', 0);
    }

    public function test_worker_records_heartbeat_and_does_not_process_disabled_sources(): void
    {
        $this->source(['enabled' => false]);
        $this->artisan('hub:run-content-workflow')->assertSuccessful();
        $this->assertNotNull(Cache::get('content-workflow-heartbeat'));
        Http::assertNothingSent();
    }

    public function test_image_failure_holds_automatic_schedule_and_keeps_draft(): void
    {
        $source = $this->source(['with_image' => true, 'enabled' => true, 'auto_publish' => true, 'approved_at' => now(), 'last_hash' => 'previous']);
        $this->fakeResearch($this->package());
        $this->mock(PostImage::class)->shouldReceive('create')->andThrow(new \RuntimeException('renderer unavailable'));
        app(ResearchSource::class)->run($source);
        $this->assertDatabaseCount('posts', 1);
        $this->assertDatabaseCount('post_schedules', 0);
        $this->assertStringContainsString('Image creation failed', SourceSnapshot::first()->reason);
    }

    public function test_edit_during_image_generation_cannot_receive_automatic_approval(): void
    {
        $source = $this->source(['with_image' => true, 'enabled' => true, 'auto_publish' => true, 'approved_at' => now(), 'last_hash' => 'previous']);
        $this->fakeResearch($this->package());
        $this->mock(PostImage::class)->shouldReceive('create')->andReturnUsing(function (Post $post): array {
            Post::whereKey($post->id)->update(['body' => 'An edited claim that has not been verified.']);

            return ['image_path' => 'post-images/stale.png', 'image_hash' => str_repeat('a', 64)];
        });
        app(ResearchSource::class)->run($source);
        $this->assertSame('draft', Post::first()->status);
        $this->assertNull(Post::first()->image_path);
        $this->assertDatabaseCount('post_schedules', 0);
        $this->assertStringContainsString('edited during generation', SourceSnapshot::first()->reason);
    }

    public function test_comparison_mismatch_keeps_the_evidence_and_requires_review(): void
    {
        $source = $this->source(['comparison_url' => 'https://comparison.example.com/result', 'enabled' => true, 'auto_publish' => true, 'approved_at' => now(), 'last_hash' => 'previous']);
        $this->fakeResearch($this->package());
        Http::fake(['https://comparison.example.com/*' => Http::response('The exam result has not been announced yet. This conflicts with the primary announcement.', 200, ['Content-Type' => 'text/plain'])]);
        app(ResearchSource::class)->run($source);
        $this->assertDatabaseCount('post_schedules', 0);
        $this->assertStringContainsString('comparison page', SourceSnapshot::first()->reason);
        $this->assertStringContainsString('not been announced', SourceSnapshot::first()->comparison_text);
    }

    public function test_revoking_source_approval_cancels_queued_automatic_posts(): void
    {
        $source = $this->source(['enabled' => true, 'auto_publish' => true, 'approved_at' => now(), 'last_hash' => 'previous']);
        $this->fakeResearch($this->package());
        app(ResearchSource::class)->run($source);
        $this->put('/research/'.$source->id, ['brand_id' => $source->brand_id, 'name' => $source->name, 'topic' => $source->topic,
            'url' => $source->url, 'channel' => 'facebook', 'interval_minutes' => 60, 'delay_minutes' => 60])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', PostSchedule::first()->status);
        $this->assertFalse($source->fresh()->auto_publish);
        $this->assertNull($source->fresh()->approved_at);
    }

    public function test_ambiguous_publication_is_never_retried_by_the_worker(): void
    {
        $source = $this->source();
        $post = $this->reviewed($source);
        $schedule = app(SchedulePost::class)->create($post, $source->social_account_id, now()->subMinute());
        Http::fake(['https://graph.facebook.com/*' => Http::response([], 500)]);
        app(SchedulePost::class)->run($schedule);
        app(SchedulePost::class)->run($schedule->fresh());
        $this->assertSame('uncertain', $schedule->fresh()->status);
        $this->assertSame('uncertain', $post->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_dns_resolving_to_private_address_is_blocked_before_request(): void
    {
        $this->partialMock(PublicEndpoint::class, function ($mock): void {
            $mock->shouldReceive('addresses')->andReturn(['127.0.0.1']);
        });
        try {
            app(FetchSource::class)->fetch('https://official.example.com/notices');
            $this->fail('Private address was accepted');
        } catch (\RuntimeException) {
            Http::assertNothingSent();
        }
    }

    public function test_official_source_can_schedule_linkedin_after_initial_review_baseline(): void
    {
        $source = $this->source(['channel' => 'linkedin', 'enabled' => true, 'auto_publish' => true, 'approved_at' => now()]);
        SocialAccount::findOrFail($source->social_account_id)->forceFill(['provider' => 'linkedin', 'page_id' => 'urn:li:organization:123'])->save();
        $this->fakeResearch($this->package());
        app(ResearchSource::class)->run($source);
        $this->assertDatabaseCount('post_schedules', 0);
        $this->fakeResearch($this->package(['headline_quote' => 'Another examination notice released']));
        app(ResearchSource::class)->run($source->fresh());
        $this->assertDatabaseHas('source_snapshots', ['status' => 'scheduled']);
        $this->assertSame('linkedin', PostSchedule::firstOrFail()->post->channel);
    }
}
