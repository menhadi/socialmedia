<?php

namespace Tests\Feature;

use App\Models\AiConnection;
use App\Models\AutomationRule;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\Post;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Ai\CardPlanner;
use App\Services\Automation\RunAutomation;
use App\Services\Research\ContentVisual;
use App\Services\Research\PostImage;
use App\Services\Social\ChannelRules;
use App\Services\Social\PublishPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MultiCardTest extends TestCase
{
    use RefreshDatabase;

    private function sources(): array
    {
        return [
            ['source_url' => 'https://example.org/data', 'visual' => ['type' => 'chart', 'heading' => 'Enrolment', 'unit' => '%', 'labels' => ['2020', '2024'], 'values' => [40, 55], 'note' => 'Survey sample only']],
            ['source_url' => 'https://example.org/report', 'visual' => ['type' => 'facts', 'heading' => 'Report findings', 'rows' => ['The survey covers two reporting years.', 'Coverage varies by year.'], 'note' => 'Published survey']],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['services.facebook.version' => 'v25.0']);
    }

    private function setupPost(string $channel = 'facebook'): array
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $ai = AiConnection::factory()->enabled()->create(['user_id' => $user->id]);
        $brand = Brand::factory()->create(['user_id' => $user->id, 'ai_connection_id' => $ai->id]);
        $account = SocialAccount::factory()->create(['brand_id' => $brand->id, 'provider' => $channel, 'page_id' => $channel === 'linkedin' ? 'urn:li:person:abc' : '12345']);
        $post = $brand->posts()->create(['channel' => $channel, 'title' => 'Education survey', 'body' => 'The survey covers two reporting years. Coverage varies by year.', 'source_url' => 'https://example.org/report', 'visual' => ['type' => 'collection', 'cards' => $this->sources()]]);
        $image = imagecreatetruecolor(100, 100);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        $cards = [];
        foreach ($this->sources() as $i => $source) {
            Storage::disk('local')->put('card-'.$i.'.png', $bytes);
            $cards[] = ['path' => 'card-'.$i.'.png', 'hash' => hash('sha256', $bytes), 'source_url' => $source['source_url']];
        }
        $post->forceFill(['card_sources' => $this->sources(), 'card_images' => $cards, 'image_path' => $cards[0]['path'], 'image_hash' => $cards[0]['hash'], 'status' => 'reviewed', 'reviewed_at' => now()])->save();

        return [$account, $post];
    }

    private function plan(array $changes = []): array
    {
        return $changes + ['card_indices' => [2, 1], 'reason' => 'Explain coverage before showing the trend.', 'concerns' => [], 'headline_quote' => 'The survey', 'excerpt_quote' => 'The survey covers two reporting years.', 'hashtags' => ['#Education', '#Survey']];
    }

    private function fakeAi(array $plan): void
    {
        Http::fake(['api.deepseek.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode($plan)], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 100]])]);
    }

    public function test_caption_accepts_exact_selected_card_evidence_but_rejects_changed_facts(): void
    {
        $planner = new CardPlanner;
        $cards = [['visual' => ['note' => 'Coverage differs between reporting years.']]];
        $plan = $this->plan(['excerpt_quote' => 'Coverage differs between reporting years.']);
        $this->assertStringContainsString($plan['excerpt_quote'], $planner->caption('The survey', $plan, $cards));
        $this->expectException(ValidationException::class);
        $planner->caption('The survey', $this->plan(['excerpt_quote' => 'Coverage is identical between reporting years.']), $cards);
    }

    public function test_ai_selects_without_changing_source_values_and_rejects_bad_plans(): void
    {
        $planner = new CardPlanner;
        $selected = $planner->select($this->sources(), $this->plan(), 'facebook');
        $this->assertEquals([$this->sources()[1], $this->sources()[0]], $selected['cards']);
        foreach ([['card_indices' => [1, 1]], ['card_indices' => [3]], ['card_indices' => []], ['concerns' => ['Contradiction']], ['concerns' => 'Coverage differs between years']] as $change) {
            try {
                $planner->select($this->sources(), $this->plan($change), 'facebook');
                $this->fail('Invalid plan accepted');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->expectException(ValidationException::class);
        $planner->select($this->sources(), $this->plan(), 'x', 1);
    }

    public function test_ai_button_replans_invalidates_media_and_preserves_source_library(): void
    {
        [$account, $post] = $this->setupPost();
        $this->fakeAi($this->plan());
        $this->post(route('posts.cards.plan', $post), ['request_key' => (string) Str::uuid(), 'fingerprint' => $post->publishingFingerprint()])->assertSessionHasNoErrors();
        $post->refresh();
        $this->assertSame('facts', $post->visual['cards'][0]['visual']['type']);
        $this->assertSame($this->sources(), $post->card_sources);
        $this->assertNull($post->card_images);
        $this->assertSame('draft', $post->status);
        $this->assertStringContainsString('#Education', $post->body);
        $this->get(route('posts.edit', $post))->assertOk()->assertSee('Let AI choose cards and caption');
        $this->post(route('posts.review', $post))->assertSessionHasErrors('visual');
        $this->actingAs(User::factory()->create())->post(route('posts.cards.plan', $post))->assertNotFound();
    }

    public function test_flagged_plan_preserves_the_reviewed_post(): void
    {
        [$account, $post] = $this->setupPost();
        $fingerprint = $post->publishingFingerprint();
        $this->fakeAi($this->plan(['concerns' => ['Conflicting figures']]));
        $this->post(route('posts.cards.plan', $post), ['request_key' => (string) Str::uuid(), 'fingerprint' => $fingerprint])->assertSessionHasErrors('cards');
        $this->assertSame($fingerprint, $post->fresh()->publishingFingerprint());
        $this->assertSame('reviewed', $post->fresh()->status);
    }

    public function test_disclosed_caveats_allow_card_planning_without_erasing_source_notes(): void
    {
        [$account, $post] = $this->setupPost();
        $sources = $post->card_sources;
        $sources[0]['visual'] = ['type' => 'chart', 'chart_style' => 'line', 'heading' => 'Recorded turnout', 'unit' => '%', 'labels' => ['2004', '2009', '2014'], 'values' => [60, null, 65], 'note' => '2009 unavailable. Coverage varies; these are not comparable statewide totals.'];
        $post->forceFill(['card_sources' => $sources])->save();
        $this->fakeAi($this->plan(['caveats' => ['Missing year and varying coverage are disclosed.']]));
        $this->post(route('posts.cards.plan', $post), ['request_key' => (string) Str::uuid(), 'fingerprint' => $post->publishingFingerprint()])->assertSessionHasNoErrors();
        $post->refresh();
        $this->assertNull($post->visual['cards'][1]['visual']['values'][1]);
        $this->assertSame($sources[0]['visual']['note'], $post->visual['cards'][1]['visual']['note']);
        $this->assertSame('draft', $post->status);
        Http::assertSent(fn ($request) => str_contains($request['messages'][0]['content'], 'caveats alone do not require manual review') && str_contains($request['messages'][0]['content'], 'Still block invented or filled-in values'));
    }

    public function test_difficult_question_rule_checks_the_whole_collection(): void
    {
        $visuals = new ContentVisual;
        $hard = ['visual' => ['type' => 'question', 'difficulty' => 'hard']];
        $this->assertTrue($visuals->isHardQuestion(['type' => 'collection', 'cards' => [$hard, $hard]]));
        $this->assertFalse($visuals->isHardQuestion(['type' => 'collection', 'cards' => [$hard, ['visual' => ['type' => 'question', 'difficulty' => 'easy']]]]));
        $this->assertFalse($visuals->isHardQuestion(['type' => 'collection', 'cards' => []]));
    }

    public function test_failed_photo_upload_does_not_publish_a_partial_facebook_post(): void
    {
        [$account, $post] = $this->setupPost();
        Http::fake(['*/12345/photos' => Http::sequence()->push(['id' => '101'])->push(['error' => ['code' => 100]], 400)]);
        $publication = $this->publish($account, $post);
        $this->assertNotSame('published', $publication->status);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/feed'));
    }

    public function test_automation_chooses_cards_renders_and_obeys_review_mode(): void
    {
        [$account, $post] = $this->setupPost();
        AutomationRule::factory()->create(['brand_id' => $account->brand_id, 'social_account_id' => $account->id, 'category' => 'general', 'enabled' => true, 'options' => ['workflow' => 'review', 'max_cards' => 2]]);
        $this->fakeAi($this->plan());
        $item = ContentItem::factory()->create(['brand_id' => $account->brand_id, 'channel' => 'facebook', 'category' => 'general', 'approved' => true, 'body' => $post->body, 'visual' => $post->visual]);
        $this->mock(PostImage::class)->shouldReceive('create')->once()->withArgs(fn ($draft) => $draft->visual['cards'][0]['visual']['type'] === 'facts')->andReturn(['image_path' => $post->image_path, 'image_hash' => $post->image_hash, 'card_images' => $post->card_images]);
        app(RunAutomation::class)->run($item);
        $this->assertSame('review', $item->fresh()->status);
        $this->assertDatabaseCount('post_schedules', 0);
        $this->assertCount(2, Post::findOrFail($item->fresh()->post_id)->card_images);
    }

    public function test_each_card_is_protected_and_changes_affect_fingerprint(): void
    {
        [$account, $post] = $this->setupPost();
        $this->get(route('posts.image', ['post' => $post, 'card' => 1]))->assertOk();
        $this->get(route('posts.image', ['post' => $post, 'card' => 9]))->assertNotFound();
        $fingerprint = $post->publishingFingerprint();
        $post->card_images = array_reverse($post->card_images);
        $this->assertNotSame($fingerprint, $post->publishingFingerprint());
        $this->actingAs(User::factory()->create())->get(route('posts.image', ['post' => $post, 'card' => 1]))->assertNotFound();
    }

    public function test_automatic_mode_schedules_the_complete_set_with_its_fingerprint(): void
    {
        [$account, $post] = $this->setupPost();
        $rule = AutomationRule::factory()->create(['brand_id' => $account->brand_id, 'social_account_id' => $account->id, 'options' => ['workflow' => 'automatic']]);
        $this->assertTrue(app(RunAutomation::class)->queue($post, $rule->fresh()));
        $schedule = $post->schedules()->sole();
        $this->assertSame('queued', $schedule->status);
        $this->assertSame($post->fresh()->publishingFingerprint(), $schedule->fingerprint);
        $this->assertSame($rule->id, $schedule->automation_rule_id);
        Http::assertNothingSent();
    }

    public function test_channels_without_image_sets_reject_instead_of_publishing_only_the_cover(): void
    {
        [$account, $post] = $this->setupPost('youtube');
        $this->expectException(ValidationException::class);
        ChannelRules::validate($post, true, []);
    }

    private function publish(SocialAccount $account, Post $post): Publication
    {
        return app(PublishPost::class)->run(User::findOrFail($account->brand->user_id), $post, ['request_key' => (string) Str::uuid(), 'fingerprint' => $post->publishingFingerprint(), 'social_account_id' => $account->id, 'include_link' => true]);
    }

    public function test_facebook_uploads_unpublished_images_and_sends_one_ordered_post(): void
    {
        [$account, $post] = $this->setupPost();
        Http::fake(['*/12345/photos' => Http::sequence()->push(['id' => '101'])->push(['id' => '102']), '*/12345/feed' => Http::response(['id' => '12345_999']), '*/12345_999*' => Http::response(['id' => '12345_999'])]);
        $publication = $this->publish($account, $post);
        $this->assertSame('published', $publication->status);
        $this->assertSame($post->card_images, $publication->card_images);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/feed') && $request['attached_media'] === [['media_fbid' => '101'], ['media_fbid' => '102']] && str_contains($request['message'], $post->source_url));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/photos') && preg_match('/name="published"\r\n\r\nfalse\r\n/', $request->body()));
    }

    public function test_changed_second_card_blocks_all_uploads(): void
    {
        [$account, $post] = $this->setupPost();
        Storage::disk('local')->put($post->card_images[1]['path'], 'tampered');
        try {
            $this->publish($account, $post);
            $this->fail('Changed media published');
        } catch (ValidationException) {
            Http::assertNothingSent();
            $this->assertDatabaseCount('publications', 0);
        }
    }

    public function test_pyp_policy_checks_every_card_not_just_the_cover(): void
    {
        $question = ['source_url' => 'https://example.org/paper', 'visual' => ['type' => 'question', 'question' => 'Question?', 'options' => ['A', 'B'], 'answer' => 1, 'exam' => 'Exam', 'year' => 2024, 'question_kind' => 'pyp', 'paper_url' => 'https://example.org/paper', 'provenance_verified' => true]];
        $this->expectException(ValidationException::class);
        app(ContentVisual::class)->assertPreviousYearQuestion(['type' => 'collection', 'cards' => [$question, $this->sources()[0]]], 'https://example.org');
    }

    public function test_instagram_waits_for_children_then_parent_before_publishing_once(): void
    {
        [$account, $post] = $this->setupPost('instagram');
        URL::forceScheme('https');
        Http::fake(['*/12345/media' => Http::sequence()->push(['id' => '101'])->push(['id' => '102'])->push(['id' => '103']), '*/101?*' => Http::response(['status_code' => 'FINISHED']), '*/102?*' => Http::response(['status_code' => 'FINISHED']), '*/103?*' => Http::response(['status_code' => 'FINISHED']), '*/12345/media_publish' => Http::response(['id' => '999']), '*/999?*' => Http::response([])]);
        $publication = $this->publish($account, $post);
        $this->assertSame('publishing', $publication->status);
        $this->travel(61)->seconds();
        app(PublishPost::class)->resume($publication);
        $this->assertSame('103', $publication->fresh()->transfer['asset']);
        $this->travel(61)->seconds();
        app(PublishPost::class)->resume($publication->fresh());
        $this->assertSame('published', $publication->fresh()->status);
        Http::assertSent(fn ($r) => ($r['media_type'] ?? '') === 'CAROUSEL' && $r['children'] === '101,102');
        $this->assertCount(1, Http::recorded(fn ($r) => str_ends_with($r->url(), '/media_publish')));
    }

    public function test_linkedin_waits_for_all_images_and_preserves_order(): void
    {
        [$account, $post] = $this->setupPost('linkedin');
        Http::fake(['*images?action=initializeUpload' => Http::sequence()->push(['value' => ['image' => 'urn:li:image:a', 'uploadUrl' => 'https://www.linkedin.com/dms-uploads/a']])->push(['value' => ['image' => 'urn:li:image:b', 'uploadUrl' => 'https://www.linkedin.com/dms-uploads/b']]), 'https://www.linkedin.com/dms-uploads/*' => Http::response('', 201), '*rest/images/urn*' => Http::response(['status' => 'AVAILABLE']), '*rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:123'])]);
        $publication = $this->publish($account, $post);
        $this->travel(61)->seconds();
        app(PublishPost::class)->resume($publication);
        $this->assertSame('published', $publication->fresh()->status);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/rest/posts') && $r['content']['multiImage']['images'] === [['id' => 'urn:li:image:a'], ['id' => 'urn:li:image:b']]);
    }

    public function test_x_attaches_all_images_to_one_post(): void
    {
        [$account, $post] = $this->setupPost('x');
        Http::fake(['*upload/initialize' => Http::sequence()->push(['data' => ['id' => '101']])->push(['data' => ['id' => '102']]), '*append' => Http::response([]), '*finalize' => Http::response(['data' => []]), '*2/tweets' => Http::response(['data' => ['id' => '999']])]);
        $this->assertSame('published', $this->publish($account, $post)->status);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/2/tweets') && $r['media']['media_ids'] === ['101', '102']);
    }
}
