<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Services\Research\ChartVideo;
use App\Services\Research\FetchSource;
use App\Services\Research\PollmediaChart;
use App\Services\Social\ChannelRules;
use App\Services\Social\PublishPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConstituencyVideoTest extends TestCase
{
    use RefreshDatabase;

    private function html(): string
    {
        return '<main><script class="history-chart-data">'.json_encode(['series' => [['key' => 'turnout', 'label' => 'Turnout']],
            'rows' => [['year' => 2019, 'turnout' => 57.13], ['year' => 2024, 'turnout' => 56.59]]]).'</script><table><tr><th>Year</th><th>Winner</th><th>Party</th><th>Votes polled</th><th>Turnout %</th><th>Margin</th></tr>'
            .'<tr><th>2019</th><td>First winner †</td><td>AAA</td><td>1,000</td><td>57.13</td><td>200</td></tr>'
            .'<tr><th>2019</th><td>First winner †</td><td>AAA</td><td>1,000</td><td>57.13</td><td>200</td></tr>'
            .'<tr><th>2024</th><td>Second winner</td><td>BBB</td><td>1,100</td><td>56.59</td><td>100</td></tr></table></main>';
    }

    public function test_history_preserves_review_flags_and_deduplicates_matching_editions(): void
    {
        $chart = app(PollmediaChart::class)->select($this->html(), 'https://pollmedia.org/india/constituency?kind=pc');
        $this->assertCount(2, $chart['visual']['history']);
        $this->assertTrue($chart['visual']['history'][0]['review']);
        $this->assertSame('First winner', $chart['visual']['history'][0]['winner']);
        $this->assertSame(1100, $chart['visual']['history'][1]['polled']);
        $conflict = str_replace('<td>1,000</td>', '<td>999</td>', $this->html());
        $conflict = preg_replace('/<td>999<\/td>/', '<td>998</td>', $conflict, 1);
        $this->assertArrayNotHasKey('history', app(PollmediaChart::class)->select($conflict, 'https://pollmedia.org/india/constituency?kind=pc')['visual']);
    }

    public function test_publishing_command_prepares_both_destinations_and_does_not_repeat_submissions(): void
    {
        Storage::fake('local');
        $brand = Brand::factory()->create(['website' => 'https://pollmedia.org']);
        foreach (['facebook', 'x'] as $provider) {
            SocialAccount::factory()->create(['brand_id' => $brand->id, 'provider' => $provider]);
        }
        $url = 'https://pollmedia.org/india/constituency?kind=pc&state=Uttar+Pradesh&name=Varanasi';
        $this->mock(FetchSource::class, fn ($mock) => $mock->shouldReceive('publicHtml')->once()->with($url)->andReturn($this->html()));
        Storage::disk('local')->put('chart-videos/test.mp4', 'video');
        $this->mock(ChartVideo::class, fn ($mock) => $mock->shouldReceive('create')->once()->andReturn(['video_path' => 'chart-videos/test.mp4', 'video_hash' => hash('sha256', 'video')]));
        $this->mock(PublishPost::class, function ($mock) use ($brand): void {
            $mock->shouldReceive('run')->twice()->andReturnUsing(function ($user, $post, $data) use ($brand) {
                $this->assertSame(2, $brand->posts()->count());
                ChannelRules::validate($post, true, []);
                $post->forceFill(['status' => 'published'])->save();

                return Publication::factory()->create(['post_id' => $post->id, 'social_account_id' => $data['social_account_id'], 'status' => 'published', 'remote_post_id' => '123']);
            });
        });
        $this->artisan('hub:publish-constituency-video', ['brand' => $brand->id, 'url' => $url, '--publish' => true])->assertSuccessful();
        $this->artisan('hub:publish-constituency-video', ['brand' => $brand->id, 'url' => $url, '--publish' => true])->assertSuccessful();
        $this->assertSame(2, Publication::count());
    }

    public function test_external_sources_are_rejected_before_publishing(): void
    {
        $brand = Brand::factory()->create(['website' => 'https://pollmedia.org']);
        $this->mock(FetchSource::class, fn ($mock) => $mock->shouldNotReceive('publicHtml'));
        $this->artisan('hub:publish-constituency-video', ['brand' => $brand->id, 'url' => 'https://example.com/india/constituency', '--publish' => true])->assertFailed();
        $this->assertSame(0, Publication::count());
    }
}
