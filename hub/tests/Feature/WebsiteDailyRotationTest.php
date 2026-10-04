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
use App\Services\Research\DailyWebsiteWorkflow;
use App\Services\Research\FetchSource;
use App\Services\Research\PostImage;
use App\Services\Research\WebsiteRotation;
use App\Services\Social\PlatformClient;
use App\Services\Social\SchedulePost;
use App\Services\Trends\PublishTrend;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WebsiteDailyRotationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-04 03:30:00', 'UTC'));
        Storage::fake('local');
    }

    private function settings(): array
    {
        return ['timezone' => 'Asia/Kolkata', 'daily_time' => '09:00', 'window_start' => '09:00', 'window_end' => '21:00', 'preferred_time' => '18:00'];
    }

    public function test_web_retry_defers_rendering_to_scheduled_worker_and_is_consumed_once(): void
    {
        $user = User::factory()->create();
        $brand = Brand::factory()->create(['user_id' => $user->id, 'website' => 'https://examelite.com', 'pyp_only' => true]);
        $rule = $this->rule($brand);
        $this->actingAs($user);
        $this->mock(WebsiteRotation::class, fn ($mock) => $mock->shouldReceive('pyp')->once()->andReturn($this->feed()));
        $this->images();
        $this->post(route('trends.accounts.generate', $rule))->assertRedirect(route('trends', ['brand' => $brand->id]))
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'background worker'));
        $this->assertSame(0, WebsiteDailyBatch::count());
        $this->assertTrue(Cache::has('website-daily-retry:'.$brand->id));
        $lock = Cache::lock('website-daily-brand-'.$brand->id, 900);
        $lock->get();
        app(DailyWebsiteWorkflow::class)->run($brand);
        $this->assertTrue(Cache::has('website-daily-retry:'.$brand->id));
        $lock->release();
        $this->artisan('hub:run-website-daily')->assertSuccessful();
        $this->assertSame(5, PostSchedule::count());
        $this->assertFalse(Cache::has('website-daily-retry:'.$brand->id));
        $this->artisan('hub:run-website-daily')->assertSuccessful();
        $this->assertSame(5, PostSchedule::count());
    }

    private function rule(Brand $brand, string $channel = 'facebook', bool $verified = true): AutomationRule
    {
        $account = SocialAccount::factory()->create(['brand_id' => $brand->id, 'provider' => $channel, 'verified_at' => $verified ? now() : null]);

        return AutomationRule::factory()->create(['brand_id' => $brand->id, 'category' => 'trend', 'channel' => $channel, 'social_account_id' => $account->id,
            'options' => ['workflow' => 'automatic', 'trend' => $this->settings()]]);
    }

    private function feed(): array
    {
        $posts = [];
        for ($i = 1; $i <= 5; $i++) {
            $url = 'https://examelite.com/exam-detail/physics-2024-'.$i;
            $posts[] = ['title' => 'Physics 2024 '.$i, 'body' => 'Try this question '.$i, 'source_url' => $url,
                'visual' => ['type' => 'question', 'question' => 'Which quantity remains constant '.$i.'?', 'options' => ['Energy', 'Mass'], 'exam' => 'Physics', 'year' => 2024,
                    'question_kind' => 'pyp', 'provenance_verified' => true, 'paper_url' => $url]];
        }

        return ['posts' => $posts, 'coverage' => ['question_cycle' => 1]];
    }

    private function images(): void
    {
        $this->mock(PostImage::class, function ($mock): void {
            $mock->shouldReceive('create')->andReturnUsing(function ($post): array {
                $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a5ZkAAAAASUVORK5CYII=');
                $path = 'post-'.$post->id.'.png';
                Storage::disk('local')->put($path, $bytes);

                return ['image_path' => $path, 'image_hash' => hash('sha256', $bytes)];
            });
        });
    }

    public function test_five_questions_are_shared_across_accounts_and_worker_runs_do_not_duplicate_posts(): void
    {
        $brand = Brand::factory()->create(['website' => 'https://examelite.com', 'pyp_only' => true]);
        $fb = $this->rule($brand);
        $this->rule($brand, 'x');
        $this->mock(WebsiteRotation::class, fn ($mock) => $mock->shouldReceive('pyp')->once()->andReturn($this->feed()));
        $this->images();
        $workflow = app(DailyWebsiteWorkflow::class);
        $workflow->run($brand);
        $workflow->run($brand);
        $this->assertSame(1, WebsiteDailyBatch::count());
        $this->assertSame(10, $brand->posts()->count());
        $this->assertSame(10, PostSchedule::count());
        $this->assertSame(['10:00', '12:00', '14:00', '16:00', '18:00'], PostSchedule::where('social_account_id', $fb->social_account_id)->orderBy('scheduled_at')->get()->map(fn ($schedule) => $schedule->scheduled_at->setTimezone('Asia/Kolkata')->format('H:i'))->all());
        $schedule = PostSchedule::first();
        $this->travelTo($schedule->scheduled_at);
        app(PublishTrend::class)->assertSchedule($schedule);
        $this->assertSame('scheduled', $schedule->post->trendRun->status);
    }

    public function test_unverified_accounts_do_not_consume_the_daily_selection(): void
    {
        $brand = Brand::factory()->create(['website' => 'https://examelite.com']);
        $this->rule($brand, verified: false);
        $this->mock(WebsiteRotation::class, fn ($mock) => $mock->shouldNotReceive('pyp'));
        app(DailyWebsiteWorkflow::class)->run($brand);
        $this->assertSame(0, WebsiteDailyBatch::count());
    }

    public function test_image_failure_is_held_once_and_retry_reuses_the_same_draft(): void
    {
        $brand = Brand::factory()->create(['website' => 'https://examelite.com', 'pyp_only' => true]);
        $this->rule($brand);
        $feed = $this->feed();
        $feed['posts'] = array_slice($feed['posts'], 0, 1);
        $this->mock(WebsiteRotation::class, fn ($mock) => $mock->shouldReceive('pyp')->once()->andReturn($feed));
        $this->mock(PostImage::class, fn ($mock) => $mock->shouldReceive('create')->once()->andThrow(new \RuntimeException('Renderer unavailable')));
        app(DailyWebsiteWorkflow::class)->run($brand);
        app(DailyWebsiteWorkflow::class)->run($brand);
        $this->assertSame(1, $brand->posts()->count());
        $this->assertSame('held', TrendRun::first()->status);
        $this->assertSame(0, PostSchedule::count());
        $this->images();
        app(DailyWebsiteWorkflow::class)->run($brand, retry: true);
        $this->assertSame(1, $brand->posts()->count());
        $this->assertSame(1, PostSchedule::count());
    }

    public function test_late_preparation_does_not_spill_into_tomorrow_or_cram_five_posts_into_an_hour(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-04 20:00:00', 'Asia/Kolkata'));
        $slots = app(DailyWebsiteWorkflow::class)->slots($this->settings(), 5);
        $this->assertCount(1, $slots);
        $this->assertSame('2026-10-04 20:05', $slots[0]->setTimezone('Asia/Kolkata')->format('Y-m-d H:i'));
    }

    public function test_pollmedia_finishes_states_then_rotates_constituencies_across_states(): void
    {
        $brand = Brand::factory()->make(['website' => 'https://pollmedia.org']);
        $this->mock(FetchSource::class, function ($mock): void {
            $mock->shouldReceive('publicHtml')->with('https://pollmedia.org')->andReturn('<a href="/india/state/alpha?election=pc">A</a><a href="/india/state/alpha?election=ac">A</a><a href="/india/state/beta">B</a>');
            foreach (['alpha', 'beta'] as $state) {
                $mock->shouldReceive('publicHtml')->with('https://pollmedia.org/india/state/'.$state.'?election=pc')->once()->andReturn('<a href="/india/constituency?kind=pc&amp;state='.$state.'&amp;name=one&amp;edition=old&amp;code=1">One</a><a href="/india/constituency?kind=pc&amp;state='.$state.'&amp;name=two">Two</a>');
            }
        });
        $rotation = app(WebsiteRotation::class);
        [$first, $state] = $rotation->pollmedia($brand, []);
        [$second, $state] = $rotation->pollmedia($brand, $state);
        [$third, $state] = $rotation->pollmedia($brand, $state);
        [$fourth, $state] = $rotation->pollmedia($brand, $state);
        [$fifth] = $rotation->pollmedia($brand, $state);
        $this->assertStringContainsString('/state/alpha?', $first['source_url']);
        $this->assertStringContainsString('/state/beta?', $second['source_url']);
        $this->assertStringContainsString('state=alpha&name=one', $third['source_url']);
        $this->assertStringContainsString('state=beta&name=one', $fourth['source_url']);
        $this->assertStringContainsString('state=alpha&name=two', $fifth['source_url']);
        $this->assertStringNotContainsString('edition=', $third['source_url']);
        $this->assertSame('source_report', $first['visual']['type']);
    }

    public function test_stale_or_practice_feed_cannot_supply_daily_questions(): void
    {
        $brand = Brand::factory()->make(['website' => 'https://examelite.com']);
        $this->mock(FetchSource::class, fn ($mock) => $mock->shouldReceive('publicJson')->andReturn(['version' => 1, 'source' => 'practice', 'date' => '2026-10-03', 'posts' => []]));
        $this->expectException(\RuntimeException::class);
        app(WebsiteRotation::class)->pyp($brand);
    }

    public function test_constituency_rotation_reads_all_directory_pages_before_counting_exhaustion(): void
    {
        $brand = Brand::factory()->make(['website' => 'https://pollmedia.org']);
        $this->mock(FetchSource::class, function ($mock): void {
            $mock->shouldReceive('publicHtml')->with('https://pollmedia.org/india/state/alpha?election=ac')->once()->andReturn('<a href="/india/constituency?kind=ac&amp;state=Alpha&amp;name=one">One</a><a rel="next" href="/india/state/alpha?election=ac&amp;ac_page=2#ac-constituencies">Next</a>');
            $mock->shouldReceive('publicHtml')->with('https://pollmedia.org/india/state/alpha?election=ac&ac_page=2')->once()->andReturn('<a href="/india/constituency?kind=ac&amp;state=Alpha&amp;name=two">Two</a>');
        });
        $roster = app(WebsiteRotation::class)->constituencies($brand, 'https://pollmedia.org/india/state/alpha', 'ac');
        $this->assertCount(2, $roster);
        $this->assertStringContainsString('name=two', $roster[1]);
    }

    public function test_daily_x_delivery_keeps_the_full_tracked_paper_url_and_publishes_once(): void
    {
        $brand = Brand::factory()->create(['website' => 'https://examelite.com', 'pyp_only' => true]);
        $this->rule($brand, 'x');
        $feed = $this->feed();
        $feed['posts'] = array_slice($feed['posts'], 0, 1);
        $feed['posts'][0]['source_url'] = 'https://examelite.com/exam-detail/'.str_repeat('physics-', 25).'2024';
        $feed['posts'][0]['visual']['paper_url'] = $feed['posts'][0]['source_url'];
        $this->mock(WebsiteRotation::class, fn ($mock) => $mock->shouldReceive('pyp')->once()->andReturn($feed));
        $this->images();
        $this->mock(PlatformClient::class, function ($mock) use ($feed): void {
            $mock->shouldReceive('publish')->once()->andReturnUsing(function ($account, $publication) use ($feed): string {
                $this->assertStringStartsWith($feed['posts'][0]['source_url'], $publication->link);
                $this->assertStringContainsString('hub_publication=', $publication->link);
                $this->assertNotNull($publication->image_path);

                return '123456789';
            });
            $mock->shouldReceive('permalink')->once()->andReturn('https://x.com/example/status/123456789');
        });
        app(DailyWebsiteWorkflow::class)->run($brand);
        $schedule = PostSchedule::firstOrFail();
        $this->travelTo($schedule->scheduled_at);
        app(SchedulePost::class)->run($schedule);
        app(SchedulePost::class)->run($schedule);
        $this->assertSame('published', $schedule->fresh()->status);
        $this->assertSame('published', $schedule->post->trendRun->fresh()->status);
        $this->assertSame(1, Publication::count());
    }

    public function test_daily_rotation_screen_displays_the_saved_batch_and_delivery_status(): void
    {
        $brand = Brand::factory()->create(['website' => 'https://examelite.com', 'pyp_only' => true]);
        $this->rule($brand);
        $this->actingAs(User::findOrFail($brand->user_id));
        $this->mock(WebsiteRotation::class, fn ($mock) => $mock->shouldReceive('pyp')->once()->andReturn($this->feed()));
        $this->images();
        app(DailyWebsiteWorkflow::class)->run($brand);
        $this->get(route('trends', ['brand' => $brand->id]))->assertOk()->assertSee('Daily website rotation')->assertSee('5 source posts selected')->assertSee('Open post');
    }
}
