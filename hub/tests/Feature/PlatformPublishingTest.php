<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\ContentSource;
use App\Models\Post;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\SourceSnapshot;
use App\Models\User;
use App\Services\Social\SchedulePost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlatformPublishingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        config(['services.facebook.version' => 'v25.0']);
    }

    private function setupPost(string $provider, ?string $media = null): array
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $brand = Brand::factory()->create(['user_id' => $user->id]);
        $id = match ($provider) {
            'linkedin' => 'urn:li:person:abc', 'youtube' => 'UCabcdefghijklmnopqrstuv', default => '12345'
        };
        $account = SocialAccount::factory()->create(['brand_id' => $brand->id, 'provider' => $provider, 'page_id' => $id]);
        $post = $brand->posts()->create(['title' => 'A real title', 'body' => 'A useful update', 'channel' => $provider]);
        $post->forceFill(['status' => 'reviewed', 'reviewed_at' => now()]);
        if ($media) {
            if ($media === 'image') {
                $image = imagecreatetruecolor(100, 100);
                ob_start();
                imagepng($image);
                $bytes = ob_get_clean();
                imagedestroy($image);
            } else {
                $bytes = str_repeat('video-data', 8000);
            }
            $path = 'test/'.$post->id.($media === 'image' ? '.png' : '.mp4');
            Storage::disk('local')->put($path, $bytes);
            $post->forceFill([$media.'_path' => $path, $media.'_hash' => hash('sha256', $bytes)]);
        }
        $post->save();

        return [$account, $post];
    }

    private function submit(SocialAccount $account, Post $post, array $options = []): Publication
    {
        $this->post(route('posts.publish.store', $post), ['social_account_id' => $account->id,
            'request_key' => (string) Str::uuid(), 'fingerprint' => $post->publishingFingerprint(),
            'confirm' => 1, 'options' => $options])->assertSessionHasNoErrors();

        return $post->publications()->latest()->firstOrFail();
    }

    public static function identities(): array
    {
        return [
            ['instagram', 'https://graph.facebook.com/v25.0/12345*', ['id' => '12345', 'username' => 'academy']],
            ['x', 'https://api.x.com/2/users/me', ['data' => ['id' => '12345', 'username' => 'academy']]],
            ['linkedin', 'https://api.linkedin.com/v2/userinfo', ['sub' => 'abc', 'name' => 'Academy']],
            ['youtube', 'https://www.googleapis.com/youtube/v3/channels*', ['items' => [['id' => 'UCabcdefghijklmnopqrstuv', 'snippet' => ['title' => 'Academy']]]]],
            ['whatsapp', 'https://graph.facebook.com/v25.0/12345*', ['id' => '12345', 'verified_name' => 'Academy']],
        ];
    }

    #[DataProvider('identities')]
    public function test_each_platform_verifies_identity_without_posting(string $provider, string $url, array $result): void
    {
        [$account] = $this->setupPost($provider);
        $account->forceFill(['verified_at' => null])->save();
        Http::fake([$url => Http::response($result)]);
        $this->post(route('social.verify', $account))->assertSessionHasNoErrors();
        $this->assertNotNull($account->fresh()->verified_at);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->hasHeader('Authorization', 'Bearer test-page-token'));
        Http::assertSentCount(1);
    }

    public function test_identity_mismatch_and_missing_organization_role_do_not_verify(): void
    {
        [$account] = $this->setupPost('x');
        Http::fake(['*' => Http::response(['data' => ['id' => '999', 'username' => 'wrong']])]);
        $this->post(route('social.verify', $account))->assertSessionHasErrors('connection');
        $this->assertNull($account->fresh()->verified_at);
        $account->forceFill(['provider' => 'linkedin', 'page_id' => 'urn:li:organization:123'])->save();
        Http::fake(['*' => Http::response(['elements' => []])]);
        $this->post(route('social.verify', $account))->assertSessionHasErrors('connection');
        $this->assertNull($account->fresh()->verified_at);
    }

    public function test_instagram_media_is_signed_private_and_published_only_after_processing(): void
    {
        URL::forceScheme('https');
        [$account, $post] = $this->setupPost('instagram', 'image');
        $url = null;
        Http::fake([
            '*/12345/media' => function ($request) use (&$url) {
                $url = $request['image_url'];

                return Http::response(['id' => '999']);
            },
            '*/999?*' => Http::response(['status_code' => 'FINISHED']),
            '*/12345/media_publish' => Http::response(['id' => '888']),
            '*/888?*' => Http::response(['permalink' => 'https://www.instagram.com/p/abc/']),
        ]);
        $publication = $this->submit($account, $post);
        $this->assertSame('publishing', $publication->status);
        Http::assertSentCount(1);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get(route('publishing.asset', $publication))->assertForbidden();
        $this->travel(61)->seconds();
        $this->artisan('hub:complete-publications')->assertSuccessful();
        $this->assertSame('published', $publication->fresh()->status);
        $this->assertSame('888', $publication->fresh()->remote_post_id);
        $this->assertSame('published', $post->fresh()->status);
        $this->artisan('hub:complete-publications')->assertSuccessful();
        Http::assertSentCount(4);
        $this->travel(2)->hours();
        $this->get($url)->assertForbidden();
    }

    public static function linkedinMedia(): array
    {
        return [['image'], ['video']];
    }

    #[DataProvider('linkedinMedia')]
    public function test_linkedin_upload_processing_and_final_post(string $media): void
    {
        [$account, $post] = $this->setupPost('linkedin', $media);
        $asset = 'urn:li:'.$media.':abc';
        $value = [$media => $asset, 'uploadUrl' => 'https://www.linkedin.com/dms-uploads/abc', 'uploadToken' => '',
            'uploadInstructions' => [['uploadUrl' => 'https://www.linkedin.com/dms-uploads/abc', 'firstByte' => 0, 'lastByte' => 79999]]];
        Http::fake([
            '*action=initializeUpload' => Http::response(['value' => $value]),
            'https://www.linkedin.com/dms-uploads/abc' => Http::response('', 201, ['ETag' => 'part1']),
            '*action=finalizeUpload' => Http::response([], 200),
            'https://api.linkedin.com/rest/'.$media.'s/urn*' => Http::response(['status' => 'AVAILABLE']),
            'https://api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:456']),
        ]);
        $publication = $this->submit($account, $post);
        $this->assertSame('publishing', $publication->status);
        Http::assertNotSent(fn ($r) => $r->url() === 'https://api.linkedin.com/rest/posts');
        $this->travel(61)->seconds();
        $this->artisan('hub:complete-publications')->assertSuccessful();
        $this->assertSame('published', $publication->fresh()->status);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.linkedin.com/rest/posts' && $r['author'] === $account->page_id && $r['content']['media']['id'] === $asset);
    }

    public function test_x_video_waits_and_schedule_tracks_completion(): void
    {
        [$account, $post] = $this->setupPost('x', 'video');
        Http::fake([
            '*/initialize' => Http::response(['data' => ['id' => '555']]),
            '*/append' => Http::response([], 204),
            '*/finalize' => Http::response(['data' => ['processing_info' => ['state' => 'pending']]]),
            '*command=STATUS*' => Http::response(['data' => ['processing_info' => ['state' => 'succeeded']]]),
            'https://api.x.com/2/tweets' => Http::response(['data' => ['id' => '987']]),
        ]);
        $schedule = app(SchedulePost::class)->create($post, $account->id, now()->addMinute());
        $this->travel(61)->seconds();
        app(SchedulePost::class)->run($schedule);
        $this->assertSame('processing', $schedule->fresh()->status);
        $this->travel(61)->seconds();
        $this->artisan('hub:complete-publications')->assertSuccessful();
        $this->assertSame('published', $schedule->fresh()->status);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.x.com/2/tweets' && $r['media']['media_ids'] === ['555']);
    }

    public function test_youtube_resumable_upload_preserves_title_privacy_and_audience(): void
    {
        [$account, $post] = $this->setupPost('youtube', 'video');
        Http::fake([
            '*uploadType=resumable*' => Http::response([], 200, ['Location' => 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=abc']),
            '*upload_id=abc' => Http::response(['id' => 'abcDEF123_-'], 201),
        ]);
        $publication = $this->submit($account, $post, ['privacy' => 'private', 'made_for_kids' => '0']);
        $this->assertSame('published', $publication->status);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['snippet']['title'] === 'A real title' && $r['status']['privacyStatus'] === 'private' && $r['status']['selfDeclaredMadeForKids'] === false);
        Http::assertSentCount(2);
    }

    public function test_untrusted_upload_destinations_never_receive_credentials_or_media(): void
    {
        [$account, $post] = $this->setupPost('youtube', 'video');
        Http::fake(['*uploadType=resumable*' => Http::response([], 200, ['Location' => 'https://www.googleapis.com.attacker.test/steal'])]);
        $publication = $this->submit($account, $post, ['privacy' => 'private', 'made_for_kids' => 0]);
        $this->assertSame('failed', $publication->status);
        Http::assertSentCount(1);
    }

    public function test_whatsapp_session_message_sends_only_to_approved_recipient_and_encrypts_options(): void
    {
        [$account, $post] = $this->setupPost('whatsapp', 'image');
        Http::fake(['*/media' => Http::response(['id' => '555']), '*/messages' => Http::response(['messages' => [['id' => 'wamid.abc123']]])]);
        $options = ['recipient' => '919876543210', 'consent' => 1, 'mode' => 'session', 'last_inbound_at' => now()->subHour()->toISOString()];
        $publication = $this->submit($account, $post, $options);
        $this->assertSame('published', $publication->status);
        $this->assertNull($publication->permalink_url);
        $this->assertStringNotContainsString($options['recipient'], DB::table('publications')->value('options'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/messages') && $r['to'] === $options['recipient'] && $r['type'] === 'image' && $r['image']['id'] === '555');
    }

    public function test_whatsapp_template_uses_selected_template_and_explicit_parameters(): void
    {
        [$account, $post] = $this->setupPost('whatsapp');
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.abc123']]])]);
        $publication = $this->submit($account, $post, ['recipient' => '919876543210', 'consent' => 1, 'mode' => 'template',
            'template_name' => 'exam_notice', 'template_language' => 'en_US', 'template_values' => "Exam A\nTomorrow", 'template_confirm' => 1]);
        $this->assertSame('published', $publication->status);
        Http::assertSent(fn ($r) => $r['type'] === 'template' && $r['template']['name'] === 'exam_notice' && $r['template']['components'][0]['parameters'][1]['text'] === 'Tomorrow' && ! isset($r['text']));
    }

    public function test_expired_whatsapp_window_and_missing_video_are_blocked_before_network(): void
    {
        [$account, $post] = $this->setupPost('whatsapp');
        $this->post(route('posts.publish.store', $post), ['social_account_id' => $account->id, 'request_key' => (string) Str::uuid(), 'fingerprint' => $post->publishingFingerprint(), 'confirm' => 1,
            'options' => ['recipient' => '919876543210', 'consent' => 1, 'mode' => 'session', 'last_inbound_at' => now()->subDays(2)->toISOString()]])->assertSessionHasErrors('post');
        [$account, $post] = $this->setupPost('youtube');
        $this->post(route('posts.publish.store', $post), ['social_account_id' => $account->id, 'request_key' => (string) Str::uuid(), 'fingerprint' => $post->publishingFingerprint(), 'confirm' => 1,
            'options' => ['privacy' => 'private', 'made_for_kids' => 0]])->assertSessionHasErrors('post');
        Http::assertNothingSent();
        $this->assertDatabaseCount('publications', 0);
    }

    public function test_uncertain_write_never_retries_even_after_worker_runs(): void
    {
        [$account, $post] = $this->setupPost('x');
        Http::fake(['https://api.x.com/2/tweets' => Http::failedConnection()]);
        $publication = $this->submit($account, $post);
        $this->assertSame('uncertain', $publication->status);
        $this->travel(20)->minutes();
        $this->artisan('hub:complete-publications')->assertSuccessful();
        $this->assertSame('uncertain', $publication->fresh()->status);
        $this->assertDatabaseCount('publications', 1);
    }

    public function test_credential_change_during_processing_stops_final_publication(): void
    {
        [$account, $post] = $this->setupPost('x', 'image');
        Http::fake(['*/initialize' => Http::response(['data' => ['id' => '555']]), '*/append' => Http::response([], 204),
            '*/finalize' => Http::response(['data' => ['processing_info' => ['state' => 'pending']]])]);
        $publication = $this->submit($account, $post);
        $account->forceFill(['credential_version' => (string) Str::uuid()])->save();
        $this->travel(61)->seconds();
        $this->artisan('hub:complete-publications')->assertSuccessful();
        $this->assertSame('failed', $publication->fresh()->status);
        Http::assertSentCount(3);
    }

    public function test_instagram_login_uses_instagram_host_and_reels_use_video_url(): void
    {
        URL::forceScheme('https');
        [$account, $post] = $this->setupPost('instagram', 'video');
        $account->forceFill(['settings' => ['login_method' => 'instagram']])->save();
        Http::fake(['https://graph.instagram.com/v25.0/12345/media' => Http::response(['id' => '123'])]);
        $publication = $this->submit($account, $post);
        $this->assertSame('publishing', $publication->status);
        Http::assertSent(fn ($r) => $r['media_type'] === 'REELS' && str_contains($r['video_url'], '/publishing-assets/') && ! isset($r['image_url']));
    }

    public function test_options_are_bound_to_idempotency_key_and_same_request_sends_once(): void
    {
        [$account, $post] = $this->setupPost('whatsapp');
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.abc123']]])]);
        $data = ['social_account_id' => $account->id, 'request_key' => (string) Str::uuid(), 'fingerprint' => $post->publishingFingerprint(), 'confirm' => 1,
            'options' => ['recipient' => '919876543210', 'consent' => 1, 'mode' => 'session', 'last_inbound_at' => now()->subHour()->toISOString()]];
        $this->post(route('posts.publish.store', $post), $data)->assertSessionHasNoErrors();
        $this->post(route('posts.publish.store', $post), $data)->assertSessionHasNoErrors();
        $data['options']['recipient'] = '919876543211';
        $this->post(route('posts.publish.store', $post), $data)->assertSessionHasErrors('post');
        Http::assertSentCount(1);
        $this->assertDatabaseCount('publications', 1);
    }

    public function test_queued_whatsapp_message_rechecks_service_window_when_worker_is_late(): void
    {
        [$account, $post] = $this->setupPost('whatsapp');
        $options = ['recipient' => '919876543210', 'consent' => 1, 'mode' => 'session', 'last_inbound_at' => now()->subHours(23)->toISOString()];
        $schedule = app(SchedulePost::class)->create($post, $account->id, now()->addMinute(), options: $options);
        $this->assertStringNotContainsString($options['recipient'], DB::table('post_schedules')->value('options'));
        $this->travel(2)->hours();
        app(SchedulePost::class)->run($schedule);
        $this->assertSame('blocked', $schedule->fresh()->status);
        $this->assertDatabaseCount('publications', 0);
        Http::assertNothingSent();
    }

    public function test_processing_failure_and_interrupted_final_write_cannot_loop(): void
    {
        [$account, $post] = $this->setupPost('x', 'image');
        Http::fake(['*/initialize' => Http::response(['data' => ['id' => '555']]), '*/append' => Http::response([], 204),
            '*/finalize' => Http::response(['data' => ['processing_info' => ['state' => 'pending']]]),
            '*command=STATUS*' => Http::response(['data' => ['processing_info' => ['state' => 'failed']]])]);
        $publication = $this->submit($account, $post);
        $this->travel(61)->seconds();
        $this->artisan('hub:complete-publications')->assertSuccessful();
        $this->assertSame('failed', $publication->fresh()->status);
        $this->assertSame('reviewed', $post->fresh()->status);
        $publication->refresh()->forceFill(['status' => 'publishing', 'transfer' => ['stage' => 'submitting'], 'next_check_at' => now()->subMinute()])->save();
        $this->artisan('hub:complete-publications')->assertSuccessful();
        $this->assertSame('uncertain', $publication->fresh()->status);
        Http::assertSentCount(4);
    }

    public function test_all_preview_forms_render_with_the_correct_channel_options(): void
    {
        foreach (['instagram', 'linkedin', 'x', 'youtube', 'whatsapp'] as $provider) {
            [$account, $post] = $this->setupPost($provider);
            $response = $this->get(route('posts.publish', $post))->assertOk()->assertSee('Publish now')->assertSee('Schedule post');
            if ($provider === 'youtube') {
                $response->assertSee('options[privacy]', false)->assertSee('options[made_for_kids]', false);
            } elseif ($provider === 'whatsapp') {
                $response->assertSee('options[recipient]', false)->assertSee('options[template_name]', false);
            }
        }
        Http::assertNothingSent();
    }

    public function test_cross_channel_account_wrong_fingerprint_and_missing_consent_send_nothing(): void
    {
        [$account, $post] = $this->setupPost('x');
        $data = ['social_account_id' => $account->id, 'request_key' => (string) Str::uuid(), 'fingerprint' => str_repeat('a', 64), 'confirm' => 1];
        $this->post(route('posts.publish.store', $post), $data)->assertSessionHasErrors('post');
        $data['fingerprint'] = $post->publishingFingerprint();
        $account->forceFill(['provider' => 'instagram'])->save();
        $this->post(route('posts.publish.store', $post), $data)->assertSessionHasErrors('social_account_id');
        [$account, $post] = $this->setupPost('whatsapp');
        $this->post(route('posts.publish.store', $post), ['social_account_id' => $account->id, 'request_key' => (string) Str::uuid(), 'fingerprint' => $post->publishingFingerprint(), 'confirm' => 1,
            'options' => ['recipient' => '919876543210', 'mode' => 'session', 'last_inbound_at' => now()->toISOString()]])->assertSessionHasErrors('consent');
        $this->assertDatabaseCount('publications', 0);
        Http::assertNothingSent();
    }

    public function test_revoked_source_approval_stops_a_waiting_media_publication(): void
    {
        [$account, $post] = $this->setupPost('x', 'image');
        Http::fake(['*/initialize' => Http::response(['data' => ['id' => '555']]), '*/append' => Http::response([], 204),
            '*/finalize' => Http::response(['data' => ['processing_info' => ['state' => 'pending']]])]);
        $publication = $this->submit($account, $post);
        $source = ContentSource::factory()->create(['brand_id' => $account->brand_id, 'enabled' => false, 'auto_publish' => true, 'approved_at' => now()]);
        $snapshot = SourceSnapshot::create(['content_source_id' => $source->id, 'post_id' => $post->id, 'source_version' => $source->version,
            'url' => $source->url, 'text' => 'Verified evidence', 'hash' => str_repeat('a', 64), 'checked_at' => now()]);
        $post->schedules()->create(['social_account_id' => $account->id, 'source_snapshot_id' => $snapshot->id,
            'request_key' => $publication->request_key, 'fingerprint' => $post->publishingFingerprint(), 'credential_version' => $account->credential_version,
            'automatic' => true, 'status' => 'processing', 'scheduled_at' => now()]);
        $this->travel(61)->seconds();
        $this->artisan('hub:complete-publications')->assertSuccessful();
        $this->assertSame('failed', $publication->fresh()->status);
        $this->assertSame('platform_approval', $publication->fresh()->error_code);
        Http::assertSentCount(3);
    }
}
