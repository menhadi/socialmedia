<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Post;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\PublishPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as OutboundRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FacebookPublishingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.facebook.version' => 'v25.0']);
        Http::preventStrayRequests();
    }

    private function account(): SocialAccount
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return SocialAccount::factory()->create(['brand_id' => Brand::factory()->create(['user_id' => $user->id])->id, 'page_id' => '12345']);
    }

    private function reviewed(SocialAccount $account): Post
    {
        $post = $account->brand->posts()->create([
            'title' => 'Internal only', 'channel' => 'facebook', 'body' => 'नमस्ते! A useful update.',
            'source_url' => 'https://example.com/news',
        ]);
        $post->forceFill(['status' => 'reviewed', 'reviewed_at' => now()])->save();

        return $post;
    }

    private function payload(Post $post, SocialAccount $account): array
    {
        return ['social_account_id' => $account->id, 'request_key' => (string) Str::uuid(), 'fingerprint' => $post->publishingFingerprint(), 'confirm' => '1'];
    }

    private function fakeSuccess(): void
    {
        Http::fake([
            'https://graph.facebook.com/v25.0/12345/feed' => Http::response(['id' => '12345_9876']),
            'https://graph.facebook.com/v25.0/12345_9876?fields=id%2Cpermalink_url' => Http::response(['id' => '12345_9876', 'permalink_url' => 'https://www.facebook.com/12345/posts/9876']),
        ]);
    }

    public function test_social_and_publishing_actions_require_login(): void
    {
        $this->get('/social-accounts')->assertRedirect('/login');
        $this->post('/social-accounts')->assertRedirect('/login');
        $this->put('/social-accounts/1')->assertRedirect('/login');
        $this->delete('/social-accounts/1')->assertRedirect('/login');
        $this->post('/social-accounts/1/verify')->assertRedirect('/login');
        $this->get('/posts/1/publish')->assertRedirect('/login');
        $this->post('/posts/1/publish')->assertRedirect('/login');
        $this->post('/publications/1/link')->assertRedirect('/login');
        Http::assertNothingSent();
    }

    public function test_saving_a_connection_encrypts_and_hides_the_token_without_contacting_facebook(): void
    {
        $user = User::factory()->create();
        $brand = Brand::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user)->post('/social-accounts', ['brand_id' => $brand->id, 'page_id' => '12345', 'access_token' => 'private-page-token'])->assertSessionHasNoErrors();
        $account = SocialAccount::firstOrFail();
        $this->assertSame('private-page-token', $account->access_token);
        $this->assertNotSame('private-page-token', DB::table('social_accounts')->value('access_token'));
        $this->assertArrayNotHasKey('access_token', $account->toArray());
        $this->assertNull($account->verified_at);
        $this->get('/social-accounts')->assertOk()->assertDontSee('private-page-token');
        $this->post('/social-accounts', ['brand_id' => $brand->id, 'page_id' => '12345', 'access_token' => 'another-token'])->assertSessionHasErrors('page_id');
        $this->assertDatabaseCount('social_accounts', 1);
        Http::assertNothingSent();
    }

    public function test_invalid_connection_data_never_flashes_secrets_or_accepts_foreign_applications(): void
    {
        $otherBrand = Brand::factory()->create();
        $this->actingAs(User::factory()->create())->post('/social-accounts', ['brand_id' => $otherBrand->id, 'page_id' => '../me', 'access_token' => "secret\nvalue"])->assertSessionHasErrors(['brand_id', 'page_id', 'access_token']);
        $this->assertArrayNotHasKey('access_token', session()->getOldInput());
        $this->assertDatabaseCount('social_accounts', 0);
        Http::assertNothingSent();
    }

    public function test_verify_checks_token_identity_and_token_changes_invalidate_verification(): void
    {
        $account = $this->account();
        $account->forceFill(['verified_at' => null])->save();
        Http::fake(['https://graph.facebook.com/v25.0/me?fields=id%2Cname%2Ccategory' => Http::response(['id' => '12345', 'name' => 'Verified Page', 'category' => 'Education'])]);
        $this->post("/social-accounts/{$account->id}/verify")->assertSessionHasNoErrors();
        $this->assertNotNull($account->fresh()->verified_at);
        $this->assertSame('Verified Page', $account->fresh()->page_name);
        Http::assertSent(fn (OutboundRequest $request): bool => $request->method() === 'GET' && $request->hasHeader('Authorization', 'Bearer test-page-token') && ! str_contains($request->url(), 'test-page-token'));
        $this->put("/social-accounts/{$account->id}", ['access_token' => 'replacement'])->assertSessionHasNoErrors();
        $this->assertNull($account->fresh()->verified_at);
        $this->assertSame('replacement', $account->fresh()->access_token);
        $this->delete("/social-accounts/{$account->id}")->assertSessionHasNoErrors();
        $this->assertNull($account->fresh()->access_token);
        $this->post("/social-accounts/{$account->id}/verify")->assertSessionHasErrors('access_token');
        Http::assertSentCount(1);
    }

    public function test_verify_rejects_another_page_and_redacts_provider_errors(): void
    {
        $account = $this->account();
        Http::fake(['https://graph.facebook.com/v25.0/me?fields=id%2Cname%2Ccategory' => Http::sequence()
            ->push(['id' => '999', 'name' => 'Wrong Page', 'category' => 'Education'])
            ->push(['error' => ['code' => 190, 'message' => 'test-page-token private-provider-details']], 400)]);
        $this->post("/social-accounts/{$account->id}/verify")->assertSessionHasErrors('connection');
        $this->assertNull($account->fresh()->verified_at);
        $this->assertSame('identity', $account->fresh()->error_code);
        $this->post("/social-accounts/{$account->id}/verify")->assertSessionHasErrors('connection');
        $this->assertSame('token', $account->fresh()->error_code);
        $this->get('/social-accounts')->assertDontSee('test-page-token')->assertDontSee('private-provider-details');
        Http::assertSentCount(2);
    }

    public function test_verification_cannot_restore_a_token_disconnected_during_the_request(): void
    {
        $account = $this->account();
        Http::fake(['https://graph.facebook.com/v25.0/me?fields=id%2Cname%2Ccategory' => function () use ($account) {
            $account->forceFill(['access_token' => null, 'verified_at' => null, 'credential_version' => (string) Str::uuid()])->save();

            return Http::response(['id' => '12345', 'name' => 'Page', 'category' => 'Education']);
        }]);
        $this->post("/social-accounts/{$account->id}/verify")->assertSessionHasErrors('access_token');
        $this->assertNull($account->fresh()->verified_at);
        $this->assertNull($account->fresh()->access_token);
        Http::assertSentCount(1);
    }

    public function test_foreign_accounts_posts_and_history_are_inaccessible(): void
    {
        $account = $this->account();
        $post = $this->reviewed($account);
        $publication = Publication::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id]);
        $this->actingAs(User::factory()->create());
        $this->get('/social-accounts')->assertDontSee($account->page_name);
        $this->put("/social-accounts/{$account->id}", ['access_token' => 'stolen'])->assertNotFound();
        $this->post("/social-accounts/{$account->id}/verify")->assertNotFound();
        $this->delete("/social-accounts/{$account->id}")->assertNotFound();
        $this->get("/posts/{$post->id}/publish")->assertNotFound();
        $this->post("/posts/{$post->id}/publish", $this->payload($post, $account))->assertNotFound();
        $this->post("/publications/{$publication->id}/link")->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_reviewed_hindi_post_publishes_once_and_saves_its_public_link(): void
    {
        $account = $this->account();
        $post = $this->reviewed($account);
        $payload = $this->payload($post, $account) + ['include_link' => '1'];
        $this->fakeSuccess();
        $this->get("/posts/{$post->id}/publish")->assertOk()->assertSee('नमस्ते! A useful update.')->assertSee('Publish now');
        $this->post("/posts/{$post->id}/publish", $payload)->assertSessionHasNoErrors()->assertRedirect("/posts/{$post->id}/publish");
        $this->post("/posts/{$post->id}/publish", $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('publications', 1);
        $this->assertSame('published', $post->fresh()->status);
        $publication = Publication::firstOrFail();
        $this->assertSame('12345_9876', $publication->remote_post_id);
        $this->assertSame('https://www.facebook.com/12345/posts/9876', $publication->permalink_url);
        $this->assertNotNull($publication->published_at);
        Http::assertSent(fn (OutboundRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://graph.facebook.com/v25.0/12345/feed'
            && $request['message'] === 'नमस्ते! A useful update.' && $request['link'] === 'https://example.com/news'
            && $request['published'] === 'true' && ! isset($request['title'])
            && $request->hasHeader('Authorization', 'Bearer test-page-token'));
        Http::assertSentCount(2);
        $this->get("/posts/{$post->id}/publish")->assertOk()->assertSee('View on platform')->assertDontSee('Publish now');
        $this->get("/posts/{$post->id}/edit")->assertOk()->assertSee('locked');
        $this->post("/posts/{$post->id}/review")->assertSessionHasErrors('post');
        $this->put("/posts/{$post->id}", ['brand_id' => $post->brand_id, 'title' => 'Changed', 'body' => 'Changed', 'channel' => 'facebook'])->assertSessionHasErrors('post');
        $this->assertSame('नमस्ते! A useful update.', $post->fresh()->body);
    }

    public function test_link_sharing_is_opt_in_and_failed_link_lookup_does_not_undo_publication(): void
    {
        $account = $this->account();
        $post = $this->reviewed($account);
        Http::fake([
            'https://graph.facebook.com/v25.0/12345/feed' => Http::response(['id' => '12345_9876']),
            'https://graph.facebook.com/v25.0/12345_9876?fields=id%2Cpermalink_url' => Http::sequence()->push([], 500)->push(['id' => '12345_9876', 'permalink_url' => 'https://www.facebook.com/12345/posts/9876']),
        ]);
        $this->post("/posts/{$post->id}/publish", $this->payload($post, $account))->assertSessionHasNoErrors();
        $publication = Publication::firstOrFail();
        $this->assertSame('published', $post->fresh()->status);
        $this->assertNull($publication->link);
        $this->assertNull($publication->permalink_url);
        $this->post("/publications/{$publication->id}/link")->assertSessionHasNoErrors();
        $this->assertSame('https://www.facebook.com/12345/posts/9876', $publication->fresh()->permalink_url);
        Http::assertSent(fn (OutboundRequest $request): bool => $request->method() === 'POST' && ! isset($request['link']));
        Http::assertSentCount(3);
    }

    public function test_a_provider_cannot_inject_a_dangerous_permalink(): void
    {
        $account = $this->account();
        $post = $this->reviewed($account);
        Http::fake([
            'https://graph.facebook.com/v25.0/12345/feed' => Http::response(['id' => '12345_9876']),
            'https://graph.facebook.com/v25.0/12345_9876?fields=id%2Cpermalink_url' => Http::response(['id' => '12345_9876', 'permalink_url' => 'https://www.facebook.com.attacker.test/steal']),
        ]);
        $this->post("/posts/{$post->id}/publish", $this->payload($post, $account))->assertSessionHasNoErrors();
        $this->assertNull(Publication::firstOrFail()->permalink_url);
        $this->assertSame('published', $post->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_drafts_wrong_channels_unverified_and_other_app_pages_cannot_publish(): void
    {
        $account = $this->account();
        $post = $this->reviewed($account);
        $post->forceFill(['status' => 'draft', 'reviewed_at' => null])->save();
        $this->post("/posts/{$post->id}/publish", $this->payload($post, $account))->assertSessionHasErrors('post');
        $post->forceFill(['status' => 'reviewed', 'reviewed_at' => now(), 'channel' => 'linkedin'])->save();
        $this->post("/posts/{$post->id}/publish", $this->payload($post, $account))->assertSessionHasErrors('social_account_id');
        $post->channel = 'facebook';
        $post->save();
        $otherAccount = SocialAccount::factory()->create();
        $this->post("/posts/{$post->id}/publish", $this->payload($post, $otherAccount))->assertSessionHasErrors('social_account_id');
        $account->verified_at = null;
        $account->save();
        $this->post("/posts/{$post->id}/publish", $this->payload($post, $account))->assertSessionHasErrors('social_account_id');
        $this->assertDatabaseCount('publications', 0);
        Http::assertNothingSent();
    }

    public function test_changed_saved_content_and_missing_confirmation_block_publication(): void
    {
        $account = $this->account();
        $post = $this->reviewed($account);
        $payload = $this->payload($post, $account);
        $this->post("/posts/{$post->id}/publish", array_merge($payload, ['confirm' => '0']))->assertSessionHasErrors('confirm');
        $post->body = 'A newer version';
        $post->save();
        $this->post("/posts/{$post->id}/publish", $payload)->assertSessionHasErrors('post');
        $this->assertDatabaseCount('publications', 0);
        Http::assertNothingSent();
    }

    public function test_rejected_attempt_requires_a_new_preview_and_preserves_history(): void
    {
        $account = $this->account();
        $post = $this->reviewed($account);
        $payload = $this->payload($post, $account);
        Http::fake([
            'https://graph.facebook.com/v25.0/12345/feed' => Http::sequence()->push(['error' => ['code' => 200, 'message' => 'secret-provider-details']], 403)->push(['id' => '12345_9876']),
            'https://graph.facebook.com/v25.0/12345_9876?fields=id%2Cpermalink_url' => Http::response(['id' => '12345_9876', 'permalink_url' => 'https://www.facebook.com/12345/posts/9876']),
        ]);
        $this->post("/posts/{$post->id}/publish", $payload)->assertSessionHasNoErrors();
        $this->assertSame('reviewed', $post->fresh()->status);
        $this->assertSame('failed', Publication::firstOrFail()->status);
        $this->get("/posts/{$post->id}/publish")->assertSee('missing Page permissions')->assertDontSee('secret-provider-details');
        $this->post("/posts/{$post->id}/publish", $payload)->assertSessionHasNoErrors();
        Http::assertSentCount(1);
        $this->post("/posts/{$post->id}/publish", $this->payload($post->fresh(), $account))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('publications', 2);
        $this->assertSame('published', $post->fresh()->status);
        Http::assertSentCount(3);
    }

    public static function uncertainResponses(): array
    {
        return [
            'timeout' => [null, 0],
            'server error' => [['error' => ['code' => 2]], 500],
            'missing id' => [[], 200],
            'wrong page id' => [['id' => '999_9876'], 200],
            'unknown rejection' => [['error' => ['code' => 506]], 400],
            'transient validation' => [['error' => ['code' => 100, 'is_transient' => true]], 400],
        ];
    }

    #[DataProvider('uncertainResponses')]
    public function test_uncertain_outcomes_remain_locked_without_automatic_retry(?array $body, int $status): void
    {
        $account = $this->account();
        $post = $this->reviewed($account);
        $payload = $this->payload($post, $account);
        Http::fake(['https://graph.facebook.com/v25.0/12345/feed' => $body === null ? Http::failedConnection() : Http::response($body, $status)]);
        $this->post("/posts/{$post->id}/publish", $payload)->assertSessionHasNoErrors();
        $this->assertSame('uncertain', $post->fresh()->status);
        $this->assertSame('uncertain', Publication::firstOrFail()->status);
        $this->post("/posts/{$post->id}/publish", $payload)->assertSessionHasNoErrors();
        $this->post("/posts/{$post->id}/publish", $this->payload($post->fresh(), $account))->assertSessionHasErrors('post');
        $this->post("/posts/{$post->id}/review")->assertSessionHasErrors('post');
        $this->assertDatabaseCount('publications', 1);
        $this->get("/posts/{$post->id}/publish")->assertSee('Outcome needs checking')->assertDontSee('Publish now');
        if ($body !== null) {
            Http::assertSentCount(1);
        }
    }

    public function test_in_flight_submission_blocks_a_second_send_and_survives_stale_post_status(): void
    {
        $account = $this->account();
        $post = $this->reviewed($account);
        $payload = $this->payload($post, $account);
        $user = $account->brand->user_id;
        Http::fake([
            'https://graph.facebook.com/v25.0/12345/feed' => function () use ($account, $post, $payload, $user) {
                $existing = app(PublishPost::class)->run(User::findOrFail($user), $post, $payload);
                $this->assertSame('publishing', $existing->status);
                $this->assertSame('publishing', $post->fresh()->status);
                $post->forceFill(['status' => 'reviewed'])->save();
                try {
                    app(PublishPost::class)->run(User::findOrFail($user), $post, $this->payload($post, $account));
                    $this->fail('A second submission should have been blocked.');
                } catch (ValidationException $error) {
                    $this->assertArrayHasKey('post', $error->errors());
                }

                return Http::response(['id' => '12345_9876']);
            },
            'https://graph.facebook.com/v25.0/12345_9876?fields=id%2Cpermalink_url' => Http::response([], 500),
        ]);
        $this->post("/posts/{$post->id}/publish", $payload)->assertSessionHasNoErrors();
        $this->assertSame('published', $post->fresh()->status);
        $this->assertDatabaseCount('publications', 1);
        Http::assertSentCount(2);
    }

    public function test_page_and_post_content_is_escaped_in_new_views(): void
    {
        $account = $this->account();
        $account->forceFill(['page_name' => '<script>alert(1)</script>'])->save();
        $post = $this->reviewed($account);
        $post->body = '<script>alert(2)</script>';
        $post->save();
        $this->get('/social-accounts')->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
        $this->get("/posts/{$post->id}/publish")->assertOk()->assertDontSee('<script>alert(2)</script>', false)->assertSee('&lt;script&gt;', false);
        Http::assertNothingSent();
    }
}
