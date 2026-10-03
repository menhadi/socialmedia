<?php

namespace Tests\Feature;

use App\Models\AiConnection;
use App\Models\Brand;
use App\Models\Post;
use App\Models\TrendRun;
use App\Models\User;
use App\Services\Monitoring\PublicEndpoint;
use App\Services\Trends\DiscoverTrends;
use App\Services\Trends\GenerateTrendDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class TrendWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const WEBSITE = 'https://learning.example.com/papers';

    private const TEXT = 'GATE aerospace previous-year papers and topic-wise practice questions are available here for students preparing for the examination.';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo('2026-10-03 03:00:00');
        $this->partialMock(PublicEndpoint::class, function ($mock): void {
            $mock->shouldReceive('addresses')->andReturn(['8.8.8.8']);
        });
    }

    private function brand(array $settings = []): Brand
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $ai = AiConnection::factory()->enabled()->create(['user_id' => $user->id]);

        return Brand::factory()->create([
            'user_id' => $user->id, 'website' => 'https://learning.example.com', 'ai_connection_id' => $ai->id,
            'trend_settings' => $settings + [
                'enabled' => true, 'version' => (string) Str::uuid(), 'region' => 'IN', 'keywords' => 'GATE, aerospace',
                'timezone' => 'Asia/Kolkata', 'daily_time' => '08:00', 'channel' => 'facebook', 'feed_url' => null, 'landing_pages' => [self::WEBSITE],
            ],
        ]);
    }

    private function package(array $overrides = []): array
    {
        return $overrides + [
            'skip' => false, 'reason' => 'Students can use the existing aerospace papers to explore preparation patterns.',
            'concerns' => [], 'candidate_index' => 1, 'page_index' => 1, 'relevance' => 90,
            'website_quote' => 'GATE aerospace previous-year papers and topic-wise practice questions',
            'caption' => 'Preparing for GATE aerospace? Explore past papers and practice topic by topic. #GATE',
        ];
    }

    private function feed(string $title = 'GATE aerospace preparation', string $date = 'Sat, 03 Oct 2026 02:00:00 +0000'): string
    {
        return '<rss xmlns:ht="https://trends.google.com/trending/rss"><channel><item><title>'.$title.'</title><pubDate>'.$date.'</pubDate><link>https://news.example.com/gate</link><ht:approx_traffic>1000+</ht:approx_traffic></item></channel></rss>';
    }

    private function fakeWorkflow(array $package = [], ?string $feed = null, mixed $aiResponse = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://trends.google.com/trending/rss?geo=IN' => Http::response($feed ?? $this->feed(), 200, ['Content-Type' => 'application/rss+xml']),
            self::WEBSITE => Http::response(self::TEXT, 200, ['Content-Type' => 'text/plain']),
            'https://api.deepseek.com/chat/completions' => $aiResponse ?? Http::response([
                'choices' => [['message' => ['content' => json_encode($this->package($package))], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 100],
            ]),
        ]);
    }

    public function test_daily_check_saves_evidence_and_one_unreviewed_trend_draft(): void
    {
        $brand = $this->brand();
        $this->fakeWorkflow();
        $this->post('/trends/'.$brand->id.'/generate')->assertSessionHasNoErrors()->assertRedirect('/trends?brand='.$brand->id);
        $this->post('/trends/'.$brand->id.'/generate')->assertRedirect();
        $post = Post::firstOrFail();
        $this->assertSame('trend', $post->content_type);
        $this->assertSame('draft', $post->status);
        $this->assertNull($post->reviewed_at);
        $this->assertSame(self::WEBSITE, $post->source_url);
        $this->assertStringEndsWith(self::WEBSITE, $post->body);
        $this->assertSame('review', TrendRun::firstOrFail()->status);
        $this->assertSame('Google search trend', TrendRun::first()->evidence['candidates'][0]['signal']);
        $this->assertDatabaseCount('posts', 1);
        $this->assertDatabaseCount('post_schedules', 0);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'deepseek') && str_contains($request['messages'][0]['content'], 'Do not assert new exam dates'));
        $this->get('/trends?brand='.$brand->id)->assertOk()->assertSee('Review GATE aerospace preparation')->assertSee('1000+');
        $this->get('/posts/'.$post->id.'/edit')->assertOk()->assertSee('Trend-based draft');
    }

    #[TestWith(['GATE aerospace preparation', 'Mon, 28 Sep 2026 02:00:00 +0000'])]
    #[TestWith(['Cricket finals', 'Sat, 03 Oct 2026 02:00:00 +0000'])]
    #[TestWith(['GATE aerospace preparation', 'Sun, 04 Oct 2026 02:00:00 +0000'])]
    public function test_stale_future_and_unrelated_topics_do_not_spend_ai_budget(string $title, string $date): void
    {
        $brand = $this->brand();
        $this->fakeWorkflow([], $this->feed($title, $date));
        $run = app(GenerateTrendDraft::class)->run($brand);
        $this->assertSame('skipped', $run->status);
        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('ai_generations', 0);
        Http::assertSentCount(1);
    }

    #[TestWith(['concerns', ['An exam deadline needs official verification.']])]
    #[TestWith(['website_quote', 'This website offers a guaranteed passing score.'])]
    #[TestWith(['relevance', 40])]
    #[TestWith(['candidate_index', 999])]
    #[TestWith(['caption', ''])]
    #[TestWith(['concerns', 'none'])]
    public function test_unsupported_or_malformed_ai_packages_are_held(string $field, mixed $value): void
    {
        $brand = $this->brand();
        $this->fakeWorkflow([$field => $value]);
        $run = app(GenerateTrendDraft::class)->run($brand);
        $this->assertSame('held', $run->status);
        $this->assertNotNull($run->ai_generation_id);
        $this->assertDatabaseCount('posts', 0);
        Http::assertSentCount(3);
    }

    public function test_ai_can_skip_a_weak_angle_and_the_topic_is_not_repeated_next_day(): void
    {
        $brand = $this->brand();
        $this->fakeWorkflow();
        app(GenerateTrendDraft::class)->run($brand);
        $this->travel(1)->days();
        $this->fakeWorkflow([], $this->feed('GATE aerospace preparation', 'Sun, 04 Oct 2026 02:00:00 +0000'));
        $run = app(GenerateTrendDraft::class)->run($brand);
        $this->assertSame('duplicate', $run->status);
        $this->assertDatabaseCount('posts', 1);
        Http::assertSentCount(3);
    }

    public function test_ai_skip_keeps_its_reason_without_creating_a_post(): void
    {
        $brand = $this->brand();
        $this->fakeWorkflow(['skip' => true, 'reason' => 'This angle is too similar to a recent post.']);
        $run = app(GenerateTrendDraft::class)->run($brand);
        $this->assertSame('skipped', $run->status);
        $this->assertSame('This angle is too similar to a recent post.', $run->reason);
        $this->assertDatabaseCount('posts', 0);
        Http::assertSentCount(3);
    }

    public function test_daily_worker_obeys_local_time_and_does_not_repeat_the_attempt(): void
    {
        $brand = $this->brand();
        $this->fakeWorkflow();
        $this->travelTo('2026-10-03 02:29:00');
        $this->artisan('hub:run-trend-workflow')->assertSuccessful();
        $this->assertDatabaseCount('trend_runs', 0);
        Http::assertNothingSent();
        $this->travelTo('2026-10-03 02:30:00');
        $this->artisan('hub:run-trend-workflow')->assertSuccessful();
        $this->artisan('hub:run-trend-workflow')->assertSuccessful();
        $this->assertDatabaseHas('trend_runs', ['brand_id' => $brand->id, 'run_date' => '2026-10-03', 'status' => 'review']);
        $this->assertNotNull(Cache::get('trend-workflow-heartbeat'));
        Http::assertSentCount(3);
    }

    public function test_failed_provider_has_no_automatic_retry_or_publication(): void
    {
        $brand = $this->brand();
        $this->fakeWorkflow(aiResponse: Http::response([], 500));
        $this->artisan('hub:run-trend-workflow')->assertSuccessful();
        $this->artisan('hub:run-trend-workflow')->assertSuccessful();
        $this->assertSame('held', TrendRun::first()->status);
        $this->assertDatabaseHas('ai_generations', ['status' => 'uncertain']);
        $this->assertDatabaseCount('posts', 0);
        Http::assertSentCount(3);
    }

    public function test_settings_changes_during_ai_generation_discard_the_draft(): void
    {
        $brand = $this->brand();
        $this->fakeWorkflow(aiResponse: function () use ($brand) {
            $brand->forceFill(['trend_settings' => ['enabled' => false]])->save();

            return Http::response(['choices' => [['message' => ['content' => json_encode($this->package())], 'finish_reason' => 'stop']]]);
        });
        $run = app(GenerateTrendDraft::class)->run($brand);
        $this->assertSame('held', $run->status);
        $this->assertStringContainsString('settings changed', $run->reason);
        $this->assertDatabaseCount('posts', 0);
        Http::assertSentCount(3);
    }

    public function test_daily_worker_ignores_disabled_sites_and_recovers_abandoned_runs(): void
    {
        $brand = $this->brand(['enabled' => false]);
        TrendRun::factory()->create(['brand_id' => $brand->id, 'status' => 'running', 'updated_at' => now()->subMinutes(20)]);
        $this->artisan('hub:run-trend-workflow')->assertSuccessful();
        $this->assertSame('held', TrendRun::first()->status);
        Http::assertNothingSent();
    }

    private function settingsPayload(array $overrides = []): array
    {
        return $overrides + [
            'enabled' => 1, 'region' => 'IN', 'keywords' => 'GATE, aerospace', 'channel' => 'facebook',
            'timezone' => 'Asia/Kolkata', 'daily_time' => '08:00', 'landing_pages' => self::WEBSITE,
        ];
    }

    public function test_settings_are_saved_only_for_the_owner_and_enable_daily_drafts(): void
    {
        $brand = $this->brand(['enabled' => false]);
        $this->put('/trends/'.$brand->id, $this->settingsPayload())->assertSessionHasNoErrors()->assertRedirect('/trends?brand='.$brand->id);
        $this->assertTrue($brand->fresh()->trend_settings['enabled']);
        $this->assertSame([self::WEBSITE], $brand->fresh()->trend_settings['landing_pages']);
        $this->actingAs(User::factory()->create());
        $this->get('/trends?brand='.$brand->id)->assertNotFound();
        $this->put('/trends/'.$brand->id, $this->settingsPayload())->assertNotFound();
        $this->post('/trends/'.$brand->id.'/generate')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_unauthenticated_trend_routes_require_login(): void
    {
        $brand = Brand::factory()->create();
        $this->get('/trends')->assertRedirect('/login');
        $this->put('/trends/'.$brand->id, [])->assertRedirect('/login');
        $this->post('/trends/'.$brand->id.'/generate')->assertRedirect('/login');
    }

    #[TestWith(['landing_pages', 'http://127.0.0.1/private'])]
    #[TestWith(['landing_pages', 'https://other.example.com/papers'])]
    #[TestWith(['feed_url', 'http://127.0.0.1/private'])]
    #[TestWith(['timezone', 'Invalid/Zone'])]
    #[TestWith(['keywords', ', ,'])]
    public function test_invalid_settings_leave_existing_configuration_unchanged(string $field, string $value): void
    {
        $brand = $this->brand();
        $before = $brand->trend_settings;
        $this->put('/trends/'.$brand->id, $this->settingsPayload([$field => $value]))->assertSessionHasErrors($field);
        $this->assertSame($before, $brand->fresh()->trend_settings);
        Http::assertNothingSent();
    }

    public function test_previous_year_only_policy_prevents_enabling_trend_posts(): void
    {
        $brand = $this->brand();
        $brand->update(['pyp_only' => true]);
        $this->put('/trends/'.$brand->id, $this->settingsPayload())->assertSessionHasErrors('enabled');
        $run = app(GenerateTrendDraft::class)->run($brand);
        $this->assertSame('held', $run->status);
        Http::assertNothingSent();
    }

    public function test_niche_atom_feed_is_not_misrepresented_as_viral_or_search_popularity(): void
    {
        $brand = $this->brand(['feed_url' => 'https://niche.example.com/feed']);
        $this->fakeWorkflow([], '<rss><channel/></rss>');
        Http::fake(['https://niche.example.com/feed' => Http::response('<feed xmlns="http://www.w3.org/2005/Atom"><entry><title>GATE aerospace study guide</title><published>2026-10-03T02:00:00Z</published><link rel="alternate" href="https://niche.example.com/guide"/><summary>Useful study ideas.</summary></entry></feed>', 200, ['Content-Type' => 'application/atom+xml'])]);
        $run = app(GenerateTrendDraft::class)->run($brand);
        $this->assertSame('review', $run->status);
        $this->assertSame('Recent feed topic (popularity unverified)', $run->package['signal']);
        Http::assertSentCount(4);
    }

    public function test_feed_entities_and_private_dns_are_rejected_without_ai_requests(): void
    {
        $brand = $this->brand();
        $this->fakeWorkflow([], '<!DOCTYPE rss [<!ENTITY secret SYSTEM "file:///private">]><rss><channel/></rss>');
        $result = app(DiscoverTrends::class)->run($brand->trend_settings);
        $this->assertSame([], $result['candidates']);
        $this->assertNotEmpty($result['errors']);
        Http::assertSentCount(1);
    }

    public function test_post_type_filter_and_filtered_archive_leave_standard_posts_active(): void
    {
        $brand = $this->brand();
        $standard = $brand->posts()->create(['title' => 'Existing website content', 'body' => 'Existing body', 'channel' => 'facebook']);
        $this->fakeWorkflow();
        app(GenerateTrendDraft::class)->run($brand);
        $this->get('/posts?content_type=trend')->assertOk()->assertSee('GATE aerospace preparation')->assertDontSee('Existing website content');
        $this->post('/posts/bulk-archive', ['scope' => 'filtered', 'content_type' => 'trend'])->assertSessionHasNoErrors();
        $this->assertNull($standard->fresh()->archived_at);
        $this->assertNotNull(Post::where('content_type', 'trend')->first()->archived_at);
        Http::assertSentCount(3);
    }

    public function test_missing_provider_or_unreadable_website_holds_the_attempt(): void
    {
        $brand = $this->brand();
        $brand->aiConnection->forceFill(['enabled' => false])->save();
        $this->fakeWorkflow();
        $run = app(GenerateTrendDraft::class)->run($brand);
        $this->assertSame('held', $run->status);
        $this->assertDatabaseCount('ai_generations', 0);
        $this->assertDatabaseCount('posts', 0);
        Http::assertSentCount(2);
    }

    public function test_changed_website_hostname_holds_the_attempt_without_reading_old_pages(): void
    {
        $brand = $this->brand();
        $brand->update(['website' => 'https://new-learning.example.com']);
        $this->fakeWorkflow();
        $run = app(GenerateTrendDraft::class)->run($brand);
        $this->assertSame('held', $run->status);
        $this->assertStringContainsString('No configured website page', $run->reason);
        $this->assertDatabaseCount('posts', 0);
        Http::assertSentCount(1);
    }

    public function test_skipped_trend_packages_are_shown_as_evidence_and_cannot_be_saved_as_raw_posts(): void
    {
        $brand = $this->brand();
        $this->fakeWorkflow(['skip' => true]);
        $run = app(GenerateTrendDraft::class)->run($brand);
        $this->get('/ai-assistant/'.$run->ai_generation_id)->assertOk()->assertSee('This trend package was skipped or held.')->assertDontSee('Save as a draft');
        $this->post('/ai-assistant/'.$run->ai_generation_id.'/draft', ['title' => 'Bypass', 'body' => 'Raw JSON'])->assertRedirect('/trends?brand='.$brand->id)->assertSessionHasErrors('body');
        $this->assertDatabaseCount('posts', 0);
        Http::assertSentCount(3);
    }

    public function test_feed_resolving_to_private_ip_is_blocked_before_network_access(): void
    {
        $brand = $this->brand();
        $this->partialMock(PublicEndpoint::class, function ($mock): void {
            $mock->shouldReceive('addresses')->andReturn(['127.0.0.1']);
        });
        $run = app(GenerateTrendDraft::class)->run($brand);
        $this->assertSame('held', $run->status);
        $this->assertDatabaseCount('ai_generations', 0);
        Http::assertNothingSent();
    }

    public function test_untrusted_feed_evidence_is_escaped_in_the_history(): void
    {
        $brand = $this->brand();
        TrendRun::factory()->create(['brand_id' => $brand->id, 'reason' => '<script>alert(1)</script>', 'package' => ['reason' => '<img src=x onerror=alert(1)>']]);
        $this->get('/trends?brand='.$brand->id)->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<img src=x onerror=alert(1)>', false);
    }
}
