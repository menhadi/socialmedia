<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\Brand;
use App\Models\PostSchedule;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\TrendRun;
use App\Models\User;
use App\Models\WebsiteDailyBatch;
use App\Services\Research\ChartVideo;
use App\Services\Research\DailyWebsiteWorkflow;
use App\Services\Research\FetchSource;
use App\Services\Research\PollmediaChart;
use App\Services\Research\WebsiteRotation;
use App\Services\Social\SchedulePost;
use App\Services\Trends\DiscoverTrends;
use App\Services\Trends\GenerateTrendDraft;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DailyChartVideoTest extends TestCase
{
    use RefreshDatabase;

    private function html(): string
    {
        $html = '<main><section id="pc-history">';
        foreach ([['turnout'], ['fixed0_share', 'fixed1_share', 'fixed2_share'], ['party0_share', 'party1_share', 'others_share'], ['margin'], ['electors', 'polled']] as $keys) {
            $rows = [];
            foreach ([2014, 2019, 2024] as $i => $year) {
                $row = ['year' => $year];
                foreach ($keys as $key) {
                    $row[$key] = $i === 1 ? null : 50 + $i;
                }
                $rows[] = $row;
            }
            $html .= '<script class="history-chart-data" type="application/json">'.json_encode(['rows' => $rows, 'series' => array_map(fn ($key) => ['key' => $key, 'label' => $key], $keys)]).'</script>';
        }

        return $html.'</section><section id="ac-history"><script class="history-chart-data">{"rows":[],"series":[]}</script></section></main>';
    }

    public function test_five_graphs_rotate_with_all_years_and_missing_values_preserved(): void
    {
        $reader = app(PollmediaChart::class);
        foreach (PollmediaChart::GRAPHS as $i => $key) {
            $selected = $reader->select($this->html(), 'https://pollmedia.org/india/state/test?election=pc', $i);
            $this->assertSame($key, $selected['visual']['graph_key']);
            $this->assertSame([2014, 2019, 2024], $selected['visual']['labels']);
            $this->assertSame([50, null, 52], $selected['visual']['series'][0]['values']);
        }
        $this->assertNull($reader->select($this->html(), 'https://pollmedia.org/india/state/test?election=ac'));
        $this->assertSame('turnout', $reader->select($this->html(), 'https://pollmedia.org/india/state/test', 5)['visual']['graph_key']);
    }

    public function test_invalid_graph_is_skipped_without_inventing_values(): void
    {
        $html = str_replace('"turnout":52', '"turnout":152', $this->html());
        $this->assertSame('leaders', app(PollmediaChart::class)->select($html, 'https://pollmedia.org/india/state/test')['visual']['graph_key']);
        $this->assertNull(app(PollmediaChart::class)->select('<main>No charts</main>', 'https://pollmedia.org/india/state/test'));
    }

    private function setupDaily(): Brand
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00', 'Asia/Kolkata'));
        Http::preventStrayRequests();
        Storage::fake('local');
        config(['services.facebook.version' => 'v25.0']);
        $brand = Brand::factory()->create(['website' => 'https://pollmedia.org']);
        foreach (['facebook', 'x'] as $provider) {
            $account = SocialAccount::factory()->create(['brand_id' => $brand->id, 'provider' => $provider, 'page_id' => '12345']);
            AutomationRule::factory()->create(['brand_id' => $brand->id, 'category' => 'trend', 'channel' => $provider, 'social_account_id' => $account->id,
                'options' => ['workflow' => 'automatic', 'trend' => ['timezone' => 'Asia/Kolkata', 'daily_time' => '09:00', 'window_start' => '09:00', 'window_end' => '21:00', 'preferred_time' => '18:00']]]);
        }
        $this->mock(WebsiteRotation::class, fn ($mock) => $mock->shouldReceive('pollmedia')->once()->andReturn([
            ['title' => 'Test state | Original election report', 'body' => 'Original report', 'source_url' => 'https://pollmedia.org/india/state/test?format=report',
                'visual' => ['type' => 'source_report', 'report_url' => 'https://pollmedia.org/india/state/test?format=report']], ['overviews' => ['test']],
        ]));
        $this->mock(FetchSource::class, fn ($mock) => $mock->shouldReceive('publicHtml')->with('https://pollmedia.org/india/state/test?election=pc')->once()->andReturn($this->html()));

        return $brand;
    }

    private function video(): void
    {
        $this->mock(ChartVideo::class, fn ($mock) => $mock->shouldReceive('create')->once()->andReturnUsing(function (): array {
            Storage::disk('local')->put('chart.mp4', 'video-data');

            return ['video_path' => 'chart.mp4', 'video_hash' => hash('sha256', 'video-data')];
        }));
    }

    public function test_one_daily_video_is_shared_and_published_once_to_facebook_and_x(): void
    {
        $brand = $this->setupDaily();
        $this->video();
        Http::fake([
            'https://graph.facebook.com/v25.0/12345/videos' => Http::response(['id' => '777']),
            'https://graph.facebook.com/v25.0/777*' => Http::response(['id' => '777', 'permalink_url' => 'https://www.facebook.com/watch/?v=777']),
            '*/initialize' => Http::response(['data' => ['id' => '555']]),
            '*/append' => Http::response([], 204),
            '*/finalize' => Http::response(['data' => ['processing_info' => ['state' => 'pending']]]),
            '*command=STATUS*' => Http::response(['data' => ['processing_info' => ['state' => 'succeeded']]]),
            'https://api.x.com/2/tweets' => Http::response(['data' => ['id' => '987']]),
        ]);
        app(DailyWebsiteWorkflow::class)->run($brand);
        app(DailyWebsiteWorkflow::class)->run($brand, true);
        $this->assertCount(1, WebsiteDailyBatch::first()->payloads['posts']);
        $this->assertSame(2, PostSchedule::count());
        $this->assertSame(1, $brand->posts()->distinct()->count('video_hash'));
        $this->actingAs(User::findOrFail($brand->user_id));
        $this->get(route('posts.edit', $brand->posts()->first()))->assertOk()->assertSee('Daily historical chart video')->assertSee('Download video');
        $this->travelTo(PostSchedule::first()->scheduled_at);
        foreach (PostSchedule::all() as $schedule) {
            app(SchedulePost::class)->run($schedule);
            app(SchedulePost::class)->run($schedule);
        }
        $this->assertSame('published', Publication::where('provider', 'facebook')->first()->status);
        $this->assertSame('publishing', Publication::where('provider', 'x')->first()->status);
        $this->travel(61)->seconds();
        $this->artisan('hub:complete-publications')->assertSuccessful();
        $this->artisan('hub:complete-publications')->assertSuccessful();
        $this->assertSame(2, Publication::where('status', 'published')->count());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/12345/videos') && str_contains($r->body(), "name=\"is_ai_generated\"\r\n\r\nfalse") && str_contains($r->body(), 'hub_publication='));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/initialize') && $r['media_category'] === 'tweet_video');
        Http::assertSent(fn ($r) => $r->url() === 'https://api.x.com/2/tweets' && $r['media']['media_ids'] === ['555']);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->url() === 'https://api.x.com/2/tweets'));
    }

    public function test_encoder_failure_holds_posts_and_retry_reuses_selection(): void
    {
        $brand = $this->setupDaily();
        $this->mock(ChartVideo::class, fn ($mock) => $mock->shouldReceive('create')->twice()->andThrow(new \RuntimeException('FFmpeg unavailable')));
        app(DailyWebsiteWorkflow::class)->run($brand);
        app(DailyWebsiteWorkflow::class)->run($brand);
        $this->assertSame(0, PostSchedule::count());
        $this->assertSame(2, TrendRun::where('status', 'held')->count());
        $this->video();
        app(DailyWebsiteWorkflow::class)->run($brand, true);
        $this->assertSame(2, PostSchedule::count());
        $this->assertSame(2, $brand->posts()->count());
        Http::assertNothingSent();
    }

    public function test_missing_encoder_has_actionable_error(): void
    {
        config(['research.ffmpeg' => '/does-not-exist']);
        $visual = app(PollmediaChart::class)->select($this->html(), 'https://pollmedia.org/india/state/test')['visual'];
        $this->expectExceptionMessage('Chart video needs FFmpeg');
        app(ChartVideo::class)->create(['visual' => $visual]);
    }

    public function test_pollmedia_video_accounts_do_not_generate_a_second_ai_trend_post(): void
    {
        $brand = Brand::factory()->create(['website' => 'https://pollmedia.org']);
        $this->mock(DiscoverTrends::class, fn ($mock) => $mock->shouldNotReceive('discover'));
        foreach (['facebook', 'x'] as $channel) {
            $rule = AutomationRule::factory()->make(['channel' => $channel, 'options' => ['workflow' => 'automatic']]);
            $this->assertNull(app(GenerateTrendDraft::class)->run($brand, $rule));
        }
        $this->assertSame(0, TrendRun::count());
    }
}
