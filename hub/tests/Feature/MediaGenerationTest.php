<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\MediaConnection;
use App\Models\MediaGeneration;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Media\GenerateMedia;
use App\Services\Social\SchedulePost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MediaGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->travelTo(now('UTC')->startOfSecond());
    }

    private function postFor(string $kind = 'image', array $settings = []): Post
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $connection = MediaConnection::factory()->create($settings + ['user_id' => $user->id, 'kind' => $kind,
            'model' => $kind === 'image' ? 'gemini-3.1-flash-image' : 'veo-3.1-fast-generate-preview']);
        $brand = Brand::factory()->create(['user_id' => $user->id, $kind.'_connection_id' => $connection->id]);

        return $brand->posts()->create(['title' => 'Build strong concepts', 'body' => 'Entrance exam preparation.', 'channel' => 'facebook']);
    }

    private function payload(Post $post, string $kind = 'image'): array
    {
        return ['kind' => $kind, 'request_key' => (string) Str::uuid(), 'fingerprint' => $post->publishingFingerprint(),
            'prompt' => 'An illustrated learning scene', 'aspect_ratio' => '16:9', 'confirm' => 1];
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(12, 12);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function imageResponse(): array
    {
        return ['steps' => [['type' => 'model_output', 'content' => [['type' => 'image', 'mime_type' => 'image/png', 'data' => base64_encode($this->png())]]]]];
    }

    public function test_image_is_queued_once_generated_privately_and_only_attached_after_review(): void
    {
        $post = $this->postFor();
        $payload = $this->payload($post);
        Http::fake(['*/interactions' => Http::response($this->imageResponse())]);
        $this->post(route('media.store', $post), $payload)->assertSessionHasNoErrors();
        $this->post(route('media.store', $post), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('media_generations', 1);
        Http::assertNothingSent();
        $job = MediaGeneration::firstOrFail();
        $this->assertNotSame('fake-media-key', DB::table('media_generations')->value('api_key'));
        $this->artisan('hub:generate-media')->assertExitCode(0);
        $job->refresh();
        $this->assertSame('completed', $job->status);
        $this->assertNull($job->api_key);
        $this->assertNull($post->fresh()->image_path);
        Storage::disk('local')->assertExists($job->path);
        $this->get(route('media', $post))->assertSee('Use this image')->assertDontSee('fake-media-key');
        $this->post(route('media.attach', $job), ['confirm' => 1])->assertRedirect(route('posts.edit', $post));
        $this->assertSame($job->path, $post->fresh()->image_path);
        $this->assertSame('draft', $post->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_video_is_submitted_once_then_polled_and_downloaded(): void
    {
        $post = $this->postFor('video');
        Storage::disk('local')->put('input.png', $this->png());
        $post->forceFill(['image_path' => 'input.png', 'image_hash' => hash('sha256', $this->png())])->save();
        $uri = 'https://generativelanguage.googleapis.com/v1beta/files/video:download?alt=media';
        Http::fake([
            '*:predictLongRunning' => Http::response(['name' => 'models/veo-3.1-fast-generate-preview/operations/job1']),
            '*/operations/job1' => Http::sequence()->push(['done' => false])->push(['done' => true, 'response' => ['generateVideoResponse' => ['generatedSamples' => [['video' => ['uri' => $uri]]]]]]),
            $uri => Http::response("\x00\x00\x00\x18ftypisom".str_repeat('a', 32), 200, ['Content-Type' => 'video/mp4']),
        ]);
        $this->post(route('media.store', $post), $this->payload($post, 'video') + ['use_image' => 1])->assertSessionHasNoErrors();
        $job = MediaGeneration::firstOrFail();
        $service = app(GenerateMedia::class);
        $service->run($job);
        $this->assertSame('processing', $job->fresh()->status);
        $service->run($job);
        $service->run($job);
        $service->run($job);
        $this->assertSame('completed', $job->fresh()->status);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), ':predictLongRunning') && $request['instances'][0]['image']['inlineData']['data'] === base64_encode($this->png()) && $request['parameters']['durationSeconds'] === 8);
        Http::assertSentCount(4);
        $this->post(route('media.attach', $job), ['confirm' => 1])->assertSessionHasNoErrors();
        $this->assertNotNull($post->fresh()->video_path);
        $this->assertNull($post->fresh()->image_path);
    }

    public function test_budget_and_request_limits_are_shared_across_brands(): void
    {
        $post = $this->postFor('image', ['daily_budget_micros' => 50000]);
        $this->post(route('media.store', $post), $this->payload($post))->assertSessionHasNoErrors();
        $secondBrand = Brand::factory()->create(['user_id' => $post->brand->user_id, 'image_connection_id' => $post->brand->image_connection_id]);
        $secondPost = $secondBrand->posts()->create(['title' => 'Second', 'body' => 'Facts', 'channel' => 'facebook']);
        $this->post(route('media.store', $secondPost), $this->payload($secondPost))->assertSessionHasErrors('media');
        $this->assertDatabaseCount('media_generations', 1);
        Http::assertNothingSent();
    }

    public function test_failed_request_retains_reservation_and_is_not_retried(): void
    {
        $post = $this->postFor();
        Http::fake(['*/interactions' => Http::response(['error' => 'secret-provider-detail'], 503)]);
        $this->post(route('media.store', $post), $this->payload($post));
        $job = MediaGeneration::firstOrFail();
        app(GenerateMedia::class)->run($job);
        app(GenerateMedia::class)->run($job);
        $this->assertSame('uncertain', $job->fresh()->status);
        $this->assertSame(50000, $job->fresh()->cost_micros);
        $this->get(route('media', $post))->assertDontSee('secret-provider-detail');
        Http::assertSentCount(1);
    }

    public function test_changed_post_cannot_receive_stale_media(): void
    {
        $post = $this->postFor();
        Http::fake(['*/interactions' => Http::response($this->imageResponse())]);
        $this->post(route('media.store', $post), $this->payload($post));
        $job = MediaGeneration::firstOrFail();
        app(GenerateMedia::class)->run($job);
        $post->update(['body' => 'Changed facts']);
        $this->post(route('media.attach', $job), ['confirm' => 1])->assertSessionHasErrors('media');
        $this->assertNull($post->fresh()->image_path);
    }

    public function test_cross_tenant_media_and_provider_selection_are_rejected(): void
    {
        $post = $this->postFor();
        $this->post(route('media.store', $post), $this->payload($post));
        $job = MediaGeneration::firstOrFail();
        $this->actingAs(User::factory()->create());
        $this->get(route('media', $post))->assertNotFound();
        $this->get(route('media.file', $job))->assertNotFound();
        $this->post(route('media.attach', $job), ['confirm' => 1])->assertNotFound();
        $this->post(route('media.store', $post), $this->payload($post))->assertNotFound();
        $this->post('/applications', ['name' => 'Other', 'tone' => 'Helpful', 'language' => 'English', 'image_connection_id' => $post->brand->image_connection_id])->assertSessionHasErrors('image_connection_id');
        Http::assertNothingSent();
    }

    public function test_settings_encrypt_keys_and_do_not_expose_or_submit_them(): void
    {
        $this->actingAs(User::factory()->create())->put('/media-providers/image', ['model' => 'gemini-3.1-flash-image', 'api_key' => 'private-key', 'daily_budget' => 1, 'request_cost' => 0.1, 'daily_request_limit' => 2, 'enabled' => 1])->assertSessionHasNoErrors();
        $this->assertNotSame('private-key', DB::table('media_connections')->value('api_key'));
        $this->get('/media-providers')->assertSee('Generation enabled')->assertDontSee('private-key');
        Http::assertNothingSent();
    }

    public function test_unsafe_download_is_rejected_without_sending_key(): void
    {
        $post = $this->postFor('video');
        Http::fake([
            '*:predictLongRunning' => Http::response(['name' => 'models/veo/operations/job']),
            '*/operations/job' => Http::response(['done' => true, 'response' => ['generateVideoResponse' => ['generatedSamples' => [['video' => ['uri' => 'https://evil.example/steal']]]]]]),
        ]);
        $this->post(route('media.store', $post), $this->payload($post, 'video'));
        $job = MediaGeneration::firstOrFail();
        app(GenerateMedia::class)->run($job);
        app(GenerateMedia::class)->run($job);
        $this->assertSame('failed', $job->fresh()->status);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'evil.example'));
    }

    public function test_disabled_or_expired_queued_request_is_cancelled_without_charge(): void
    {
        $post = $this->postFor();
        $this->post(route('media.store', $post), $this->payload($post));
        $job = MediaGeneration::firstOrFail();
        $this->travel(1)->days();
        app(GenerateMedia::class)->run($job);
        $this->assertSame('cancelled', $job->fresh()->status);
        $this->assertSame(0, $job->fresh()->cost_micros);
        Http::assertNothingSent();
    }

    public function test_login_required(): void
    {
        $this->get('/media-providers')->assertRedirect('/login');
        $this->post('/posts/1/media')->assertRedirect('/login');
        $this->get('/media/1/file')->assertRedirect('/login');
    }

    public function test_attaching_media_cancels_schedule_and_removes_previous_review(): void
    {
        $post = $this->postFor();
        $post->forceFill(['status' => 'reviewed', 'reviewed_at' => now()])->save();
        $account = SocialAccount::factory()->create(['brand_id' => $post->brand_id]);
        $schedule = app(SchedulePost::class)->create($post, $account->id, now()->addHour());
        Http::fake(['*/interactions' => Http::response($this->imageResponse())]);
        $this->post(route('media.store', $post), $this->payload($post));
        $job = MediaGeneration::firstOrFail();
        app(GenerateMedia::class)->run($job);

        $this->post(route('media.attach', $job), ['confirm' => 1])->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $schedule->fresh()->status);
        $this->assertNull($post->fresh()->reviewed_at);
        $this->assertSame('draft', $post->fresh()->status);
    }

    public function test_missing_provider_or_square_video_cannot_queue_paid_requests(): void
    {
        $post = $this->postFor('video', ['enabled' => false]);
        $this->post(route('media.store', $post), $this->payload($post, 'video'))->assertSessionHasErrors('media');
        $this->post(route('media.store', $post), array_replace($this->payload($post, 'video'), ['aspect_ratio' => '1:1']))->assertSessionHasErrors('aspect_ratio');
        $this->assertDatabaseCount('media_generations', 0);
        Http::assertNothingSent();
    }

    public function test_invalid_image_does_not_become_a_downloadable_attachment(): void
    {
        $post = $this->postFor();
        Http::fake(['*/interactions' => Http::response(['steps' => [['type' => 'model_output', 'content' => [['type' => 'image', 'data' => base64_encode('<script>unsafe</script>')]]]]])]);
        $this->post(route('media.store', $post), $this->payload($post));
        $job = MediaGeneration::firstOrFail();

        app(GenerateMedia::class)->run($job);

        $this->assertSame('failed', $job->fresh()->status);
        $this->assertNull($job->fresh()->path);
        $this->get(route('media.file', $job))->assertNotFound();
    }

    public function test_video_publication_uses_video_endpoint_and_prevents_duplicates(): void
    {
        $post = $this->postFor('video');
        $bytes = "\x00\x00\x00\x18ftypisom".str_repeat('a', 32);
        Storage::disk('local')->put('post.mp4', $bytes);
        $post->forceFill(['video_path' => 'post.mp4', 'video_hash' => hash('sha256', $bytes), 'status' => 'reviewed', 'reviewed_at' => now()])->save();
        $account = SocialAccount::factory()->create(['brand_id' => $post->brand_id, 'page_id' => '12345']);
        Http::fake(['*/12345/videos' => Http::response(['id' => '9876']), '*/9876*' => Http::response(['id' => '9876', 'permalink_url' => 'https://www.facebook.com/watch/?v=9876'])]);
        $payload = ['social_account_id' => $account->id, 'request_key' => (string) Str::uuid(), 'fingerprint' => $post->publishingFingerprint(), 'confirm' => 1];
        $this->post(route('posts.publish.store', $post), $payload)->assertSessionHasNoErrors();
        $this->post(route('posts.publish.store', $post), $payload)->assertSessionHasNoErrors();
        $this->assertSame('published', $post->fresh()->status);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/12345/videos') && str_contains($r->body(), 'post.mp4') && str_contains($r->body(), 'is_ai_generated'));
    }
}
