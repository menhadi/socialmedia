<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Services\Research\ContentVisual;
use App\Services\Research\FetchSource;
use App\Services\Research\NativeReportImages;
use App\Services\Research\PostImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class NativeReportImagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_preserves_original_data_and_styles_and_blocks_active_content(): void
    {
        $html = '<html><head><link rel="stylesheet" href="/history.css"><meta http-equiv="refresh" content="0;url=file:///secret"><script>bad()</script></head><body><div class="report-actions">Print</div><main><svg viewBox="0 0 100 100" onload="bad()"><path d="M0 0 L10 20"/><text>67.2%</text><foreignObject><div>Active object</div></foreignObject><image href="https://bad.example/image"/></svg><table><tr><td>2014</td><td>67.2%</td></tr></table><iframe src="file:///secret"></iframe></main></body></html>';
        $result = app(NativeReportImages::class)->document($html, ['@page { size: A4; } table { color: teal; }']);
        $this->assertStringContainsString('M0 0 L10 20', $result);
        $this->assertStringContainsString('<td>67.2%</td>', $result);
        $this->assertStringContainsString('size: A4', $result);
        $this->assertStringContainsString("default-src 'none'", $result);
        foreach (['<script', '<iframe', 'onload=', 'href=', '<link', 'report-actions', 'file:///secret', 'Active object'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $result);
        }
    }

    public function test_report_without_a_head_works_but_empty_report_is_rejected(): void
    {
        $this->assertStringContainsString('Content-Security-Policy', app(NativeReportImages::class)->document('<main><table><tr><td>Original data</td></tr></table></main>'));
        $this->expectException(\RuntimeException::class);
        app(NativeReportImages::class)->document('<main><p>No graphs or tables</p></main>');
    }

    public function test_original_report_requires_attached_pages(): void
    {
        $url = 'https://pollmedia.org/india/state/goa?format=report';
        $visual = app(ContentVisual::class)->validate(['type' => 'source_report', 'report_url' => $url], $url);
        $post = Brand::factory()->create()->posts()->create(['channel' => 'facebook', 'title' => 'Goa', 'body' => 'Original report', 'source_url' => $url, 'visual' => $visual]);
        $this->expectException(ValidationException::class);
        $post->assertContentPolicy();
    }

    public function test_report_on_another_hostname_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(ContentVisual::class)->validate(['type' => 'source_report', 'report_url' => 'https://other.example/report'], 'https://pollmedia.org/india/state/goa');
    }

    #[TestWith(['x'])]
    #[TestWith(['instagram'])]
    public function test_original_report_renders_platform_limited_pages_with_available_binaries(string $channel): void
    {
        if (! is_file(config('research.report_chrome') ?? '') || ! is_file(config('research.pdftoppm') ?? '')) {
            $this->markTestSkipped('Chrome and pdftoppm are needed for the native report integration test.');
        }
        Storage::fake('local');
        $fixture = getenv('HUB_REPORT_TEST_HTML');
        $html = $fixture ? file_get_contents($fixture) : '<html><head><style>@page {size:A4} svg{width:100%}</style></head><body><header>Original election report</header><main><svg viewBox="0 0 400 100"><path d="M10 80L200 50L390 20" stroke="teal" fill="none"/><text x="10" y="20">Recorded turnout 67.2%</text></svg><table><tr><td>2014</td><td>67.2%</td></tr></table></main></body></html>';
        $stylesheet = getenv('HUB_REPORT_TEST_CSS');
        $this->mock(FetchSource::class, function ($mock) use ($html, $stylesheet): void {
            $mock->shouldReceive('publicHtml')->once()->andReturn($html);
            $mock->shouldReceive('publicCss')->andReturn($stylesheet ? file_get_contents($stylesheet) : '');
        });
        $url = 'https://pollmedia.org/india/state/karnataka?format=report';
        $post = Brand::factory()->create()->posts()->create(['channel' => $channel, 'title' => 'Karnataka', 'body' => 'Original report', 'source_url' => $url,
            'visual' => ['type' => 'source_report', 'report_url' => $url]]);
        $images = app(PostImage::class)->create($post);
        $this->assertNotEmpty($images['card_images']);
        $this->assertLessThanOrEqual($channel === 'x' ? 4 : 10, count($images['card_images']));
        foreach ($images['card_images'] as $card) {
            $bytes = Storage::disk('local')->get($card['path']);
            $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $bytes);
            $this->assertSame($card['hash'], hash('sha256', $bytes));
            if ($channel === 'instagram') {
                $size = getimagesizefromstring($bytes);
                $this->assertSame($size[0], $size[1], 'Instagram pages contain the complete report page inside a square image.');
            }
        }
        if ($output = getenv('HUB_REPORT_TEST_OUTPUT')) {
            file_put_contents($channel === 'x' ? $output : preg_replace('/\.png$/', '-instagram.png', $output), Storage::disk('local')->get($images['image_path']));
        }
    }
}
