<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Services\Research\ContentVisual;
use App\Services\Research\DailyWebsiteContent;
use App\Services\Research\FetchSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyWebsiteContentTest extends TestCase
{
    use RefreshDatabase;

    private function paper(): string
    {
        return '<article data-pyp-question data-multiple="0"><div class="pyp-preview-paper">GATE Physics 2024</div><div class="pyp-preview-body">Which quantity stays constant?</div><div class="pyp-preview-option-content">Energy</div><div class="pyp-preview-option-content">Momentum</div></article>';
    }

    public function test_daily_reader_fetches_an_unused_sourced_question_without_inventing_an_answer(): void
    {
        $brand = Brand::factory()->create(['website' => 'https://exam.example', 'pyp_only' => true]);
        $this->mock(FetchSource::class, function ($mock): void {
            $mock->shouldReceive('publicHtml')->with('https://exam.example')->andReturn('<a href="/course-detail/physics">Physics</a>');
            $mock->shouldReceive('publicHtml')->with('https://exam.example/course-detail/physics')->andReturn('<a href="/exam-detail/physics-2024">Paper</a>');
            $mock->shouldReceive('publicHtml')->with('https://exam.example/exam-detail/physics-2024')->andReturn($this->paper());
        });
        $post = app(DailyWebsiteContent::class)->create($brand, 'facebook');
        $this->assertSame('reviewed', $post->status);
        $this->assertSame('Which quantity stays constant?', $post->visual['question']);
        $this->assertArrayNotHasKey('answer', $post->visual);
        app(ContentVisual::class)->assertPreviousYearQuestion($post->visual, $post->source_url);
        $this->expectException(\RuntimeException::class);
        app(DailyWebsiteContent::class)->create($brand, 'facebook');
    }

    public function test_question_reader_skips_diagrams_and_unrenderable_math(): void
    {
        $reader = app(DailyWebsiteContent::class);
        $this->assertSame([], $reader->questions(str_replace('Which quantity stays constant?', '<img src="diagram.png">', $this->paper()), 'https://exam.example/paper'));
        $this->assertSame([], $reader->questions(str_replace('Which quantity stays constant?', '\\frac{a}{b}', $this->paper()), 'https://exam.example/paper'));
    }

    public function test_state_report_creates_graphs_with_literal_values_and_preserves_missing_records(): void
    {
        $html = '<h1>Karnataka</h1><script type="application/json" class="history-chart-data">'.json_encode(['rows' => [
            ['year' => 2014, 'turnout' => 67.2, 'electors' => 100, 'polled' => 67],
            ['year' => 2019, 'turnout' => null, 'electors' => 110, 'polled' => 74],
            ['year' => 2024, 'turnout' => 70.9, 'electors' => 120, 'polled' => 85],
        ]]).'</script>';
        $package = app(DailyWebsiteContent::class)->charts($html, 'https://data.example/state/karnataka');
        $visual = app(ContentVisual::class)->validate($package['visual'], $package['source_url']);
        $this->assertCount(3, $visual['cards']);
        $this->assertSame([67.2, null, 70.9], $visual['cards'][0]['visual']['values']);
        $this->assertStringContainsString('boundaries', $visual['cards'][0]['visual']['note']);
        $this->assertSame('https://data.example/state/karnataka', $package['source_url']);
    }
}
