<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\Post;
use App\Models\User;
use App\Services\Research\ContentVisual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentVisualTest extends TestCase
{
    use RefreshDatabase;

    private function question(): array
    {
        return ['type' => 'question', 'question' => 'Which option solves the equation?', 'options' => ['First option', 'Second option'], 'answer' => 2, 'group' => 'GATE', 'exam' => 'GATE', 'year' => 2024, 'topic' => 'Linear Algebra', 'difficulty' => 'hard'];
    }

    public function test_question_saves_metadata_and_requires_image_before_review(): void
    {
        $user = User::factory()->create();
        $brand = Brand::factory()->create(['user_id' => $user->id]);
        $data = ['brand_id' => $brand->id, 'channel' => 'facebook', 'title' => 'Try this question', 'body' => 'Choose an option and open the source.', 'source_url' => 'https://example.com/questions/42', 'visual' => $this->question()];
        $this->actingAs($user)->post('/posts', $data)->assertSessionHasNoErrors();
        $post = Post::firstOrFail();
        $this->assertSame($data['visual'], $post->visual);
        $this->assertSame("Choose an option and open the source.\n\n#GATE #Exam2024 #LinearAlgebra", $post->body);
        $this->post('/posts/'.$post->id.'/review')->assertSessionHasErrors('visual');
        $this->assertSame('draft', $post->fresh()->status);
        $this->get('/posts/'.$post->id.'/edit')->assertSee('Question with options')->assertSee('Linear Algebra');
    }

    public function test_visual_edits_invalidate_media_and_foreign_user_is_denied(): void
    {
        $brand = Brand::factory()->create();
        $post = $brand->posts()->create(['title' => 'Question', 'body' => 'Try this', 'channel' => 'facebook', 'source_url' => 'https://example.com/q', 'visual' => $this->question()]);
        $post->forceFill(['image_hash' => 'old-hash', 'image_path' => 'old.png', 'status' => 'reviewed', 'reviewed_at' => now()])->save();
        $before = $post->publishingFingerprint();
        $data = ['brand_id' => $brand->id, 'title' => $post->title, 'body' => $post->body, 'channel' => 'facebook', 'source_url' => $post->source_url, 'visual' => array_replace($this->question(), ['question' => '<script>alert(1)</script>'])];
        $this->actingAs(User::factory()->create())->put('/posts/'.$post->id, $data)->assertNotFound();
        $this->actingAs(User::findOrFail($brand->user_id))->put('/posts/'.$post->id, $data)->assertSessionHasNoErrors();
        $this->assertNull($post->fresh()->image_hash);
        $this->assertNotSame($before, $post->fresh()->publishingFingerprint());
        $this->get('/posts/'.$post->id.'/edit')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_api_preserves_chart_data_and_rejects_changed_revision(): void
    {
        $token = str_repeat('v', 64);
        Brand::factory()->create(['intake_token_hash' => hash('sha256', $token)]);
        $visual = ['type' => 'chart', 'heading' => 'Turnout', 'unit' => '%', 'labels' => ['2019', '2024'], 'values' => [68.81, 70.90], 'note' => 'Available tables only'];
        $data = ['external_id' => 'chart-1', 'category' => 'general', 'channel' => 'facebook', 'title' => 'Turnout comparison', 'body' => 'Turnout comparison from available source tables.', 'source_url' => 'https://example.com/results', 'visual' => $visual];
        $this->postJson('/api/v1/content', $data)->assertUnauthorized();
        $this->withToken($token)->postJson('/api/v1/content', $data)->assertCreated();
        $this->assertSame($visual, ContentItem::firstOrFail()->visual);
        $data['visual']['values'][1] = 71;
        $this->postJson('/api/v1/content', $data)->assertConflict();
        $this->assertDatabaseCount('content_items', 1);
    }

    public function test_invalid_visuals_do_not_save(): void
    {
        $brand = Brand::factory()->create();
        $this->actingAs(User::findOrFail($brand->user_id));
        $base = ['brand_id' => $brand->id, 'channel' => 'facebook', 'title' => 'Card', 'body' => 'Short caption', 'source_url' => 'https://example.com/q'];
        foreach ([
            array_replace($this->question(), ['answer' => 3]),
            array_replace($this->question(), ['options' => 'invalid']),
            array_replace($this->question(), ['options' => ['one', '', 'three']]),
            ['type' => 'chart', 'heading' => 'Chart', 'unit' => '%', 'labels' => ['a', 'b'], 'values' => [20, 101], 'note' => 'Source note'],
            ['type' => 'chart', 'heading' => 'Chart', 'unit' => 'votes', 'labels' => ['a', 'b'], 'values' => [20], 'note' => 'Source note'],
            ['type' => 'chart', 'heading' => 'Chart', 'unit' => 'votes', 'labels' => ['a', 'b'], 'values' => [-1, 20], 'note' => 'Source note'],
        ] as $visual) {
            $this->post('/posts', $base + ['visual' => $visual])->assertSessionHasErrors();
        }
        $this->post('/posts', array_replace($base, ['source_url' => null, 'visual' => $this->question()]))->assertSessionHasErrors('source_url');
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_layout_keeps_answer_private_and_uses_zero_to_hundred_percent_scale(): void
    {
        $service = new ContentVisual;
        $question = $this->question();
        $first = $service->layout($question, 'School', 'https://example.com/q');
        $question['answer'] = 1;
        $this->assertSame($first, $service->layout($question, 'School', 'https://example.com/q'));
        $this->assertContains('First option', array_column($first['layers'], 6));
        $this->assertContains('Second option', array_column($first['layers'], 6));
        $this->assertContains('EXAM: GATE · 2024', array_column($first['layers'], 6));
        $chart = $service->layout(['type' => 'chart', 'heading' => 'Comparison', 'labels' => ['A', 'B'], 'values' => [0, 50], 'unit' => '%', 'note' => 'Sample'], 'Brand', 'https://example.com/data');
        $this->assertSame([[310, 415, 635, 470]], $chart['bars']);
        $this->assertContains('50 %', array_column($chart['layers'], 6));
    }

    public function test_question_options_use_two_columns_and_provenance_is_not_invented(): void
    {
        $service = new ContentVisual;
        $question = $this->question();
        $question['options'] = ['First', 'Second', 'Third', 'Fourth'];
        unset($question['exam'], $question['year']);
        $layout = $service->layout($question, 'School', 'https://example.com/q');
        $this->assertLessThan(900, $layout['height']);
        $this->assertSame($layout['panels'][0][1], $layout['panels'][1][1]);
        $this->assertSame($layout['panels'][2][1], $layout['panels'][3][1]);
        $this->assertGreaterThan($layout['panels'][0][2], $layout['panels'][1][0]);
        $this->assertContains('PRACTICE QUESTION · Exam not supplied', array_column($layout['layers'], 6));
        $question['exam'] = 'GATE';
        $this->assertContains('EXAM: GATE', array_column($service->layout($question, 'School', 'https://example.com/q')['layers'], 6));
    }

    public function test_long_question_and_six_bar_chart_keep_all_content_inside_canvas(): void
    {
        $service = new ContentVisual;
        $question = array_replace($this->question(), ['question' => str_repeat('Question ', 72), 'options' => array_fill(0, 6, str_repeat('Option ', 21))]);
        $chart = ['type' => 'chart', 'heading' => 'Comparison', 'labels' => ['A', 'B', 'C', 'D', 'E', 'F'], 'values' => [0, 20, 40, 60, 80, 100], 'unit' => '%', 'note' => str_repeat('Source note ', 15)];
        foreach ([$question, $chart] as $visual) {
            $layout = $service->layout($visual, 'Brand', 'https://example.com/data');
            foreach ($layout['layers'] as [$x, $y, $width, $height]) {
                $this->assertGreaterThanOrEqual(0, $x);
                $this->assertGreaterThanOrEqual(0, $y);
                $this->assertLessThanOrEqual($layout['width'], $x + $width);
                $this->assertLessThanOrEqual($layout['height'], $y + $height);
            }
            foreach (array_merge($layout['panels'], $layout['bars']) as [$left, $top, $right, $bottom]) {
                $this->assertGreaterThanOrEqual(0, $left);
                $this->assertGreaterThanOrEqual(0, $top);
                $this->assertLessThanOrEqual($layout['width'], $right);
                $this->assertLessThan($layout['height'] - 65, $bottom);
            }
        }
    }
}
