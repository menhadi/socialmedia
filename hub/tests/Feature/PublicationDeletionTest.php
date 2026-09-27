<?php

namespace Tests\Feature;

use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Analytics\CollectAnalytics;
use App\Services\Social\DeletePublication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PublicationDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function publication(string $provider = 'facebook'): Publication
    {
        $account = SocialAccount::factory()->create(['provider' => $provider, 'page_id' => $provider === 'linkedin' ? 'urn:li:organization:123' : '123']);
        $publication = Publication::factory()->create(['social_account_id' => $account->id, 'provider' => $provider, 'status' => 'published', 'remote_post_id' => match ($provider) {
            'facebook' => '123_456','linkedin' => 'urn:li:share:456','youtube' => 'abcdefghijk',default => '456'
        }, 'published_at' => now()->subDay()]);
        $publication->post->forceFill(['status' => 'published'])->save();
        $this->actingAs(User::findOrFail($publication->post->brand->user_id));

        return $publication->fresh();
    }

    public function test_signed_confirmation_wins_over_a_late_failed_delete_response(): void
    {
        $publication = $this->publication();
        Http::fake(function () use ($publication) {
            app(DeletePublication::class)->confirm($publication, 'facebook_webhook');

            return Http::response([], 500);
        });
        $this->post('/publications/'.$publication->id.'/delete', $this->input($publication))->assertSessionHasNoErrors();
        $this->assertNotNull($publication->fresh()->remote_deleted_at);
        $this->assertDatabaseMissing('publication_deletions', ['publication_id' => $publication->id, 'status' => 'uncertain']);
        Http::assertSentCount(1);
    }

    private function input(Publication $p): array
    {
        return ['request_key' => (string) Str::uuid(), 'fingerprint' => DeletePublication::fingerprint($p), 'confirm' => 1, 'confirmation_text' => 'DELETE'];
    }

    #[DataProvider('deletions')]
    public function test_supported_deletions_use_exact_saved_id_and_archive_after_confirmation(string $provider, string $url, array $body, int $status): void
    {
        Http::preventStrayRequests();
        $p = $this->publication($provider);
        Http::fake([$url => Http::response($body, $status)]);
        $input = $this->input($p);
        $this->post('/publications/'.$p->id.'/delete', $input)->assertSessionHasNoErrors();
        $this->assertNotNull($p->fresh()->remote_deleted_at);
        $this->assertNotNull($p->post->fresh()->archived_at);
        $this->assertDatabaseHas('publication_deletions', ['publication_id' => $p->id, 'status' => 'succeeded']);
        $this->assertModelExists($p->post);
        $this->post('/publications/'.$p->id.'/delete', $input)->assertSessionHasNoErrors();
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && $r->url() === $url && $r->hasHeader('Authorization', 'Bearer test-page-token'));
    }

    public static function deletions(): array
    {
        return [
            'Facebook' => ['facebook', 'https://graph.facebook.com/v25.0/123_456', ['success' => true], 200],
            'X' => ['x', 'https://api.x.com/2/tweets/456', ['data' => ['deleted' => true]], 200],
            'LinkedIn' => ['linkedin', 'https://api.linkedin.com/rest/posts/urn%3Ali%3Ashare%3A456', [], 204],
            'YouTube' => ['youtube', 'https://www.googleapis.com/youtube/v3/videos?id=abcdefghijk', [], 204],
        ];
    }

    public function test_confirmation_owner_scope_and_original_account_are_required(): void
    {
        Http::preventStrayRequests();
        $p = $this->publication();
        $input = $this->input($p);
        $this->post('/publications/'.$p->id.'/delete', array_replace($input, ['confirm' => 0]))->assertSessionHasErrors('confirm');
        $this->post('/publications/'.$p->id.'/delete', array_replace($input, ['confirmation_text' => 'archive']))->assertSessionHasErrors('confirmation_text');
        $p->account->forceFill(['page_id' => '999'])->save();
        $this->post('/publications/'.$p->id.'/delete', $input)->assertSessionHasErrors('deletion');
        $this->actingAs(User::factory()->create())->get('/publications/'.$p->id.'/delete')->assertNotFound();
        $this->post('/publications/'.$p->id.'/delete', $input)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_changed_credentials_invalidate_preview(): void
    {
        Http::preventStrayRequests();
        $p = $this->publication();
        $input = $this->input($p);
        $p->account->forceFill(['credential_version' => (string) Str::uuid()])->save();
        $this->post('/publications/'.$p->id.'/delete', $input)->assertSessionHasErrors('deletion');
        $this->assertDatabaseCount('publication_deletions', 0);
        Http::assertNothingSent();
    }

    public function test_token_rejection_keeps_live_record_and_allows_new_confirmed_attempt(): void
    {
        Http::preventStrayRequests();
        $p = $this->publication();
        Http::fake(['https://graph.facebook.com/*' => Http::sequence()->push(['error' => ['code' => 190]], 401)->push(['success' => true])]);
        $this->post('/publications/'.$p->id.'/delete', $this->input($p))->assertSessionHasNoErrors();
        $this->assertNull($p->fresh()->remote_deleted_at);
        $this->assertNull($p->post->fresh()->archived_at);
        $this->post('/publications/'.$p->id.'/delete', $this->input($p))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('publication_deletions', ['status' => 'rejected']);
        $this->assertDatabaseHas('publication_deletions', ['status' => 'succeeded']);
        Http::assertSentCount(2);
    }

    #[TestWith([404])]
    #[TestWith([500])]
    #[TestWith([200])]
    public function test_ambiguous_response_never_means_deleted_or_automatically_retries(int $status): void
    {
        Http::preventStrayRequests();
        $p = $this->publication();
        Http::fake(['https://graph.facebook.com/*' => Http::response([], $status)]);
        $this->post('/publications/'.$p->id.'/delete', $this->input($p))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('publication_deletions', ['status' => 'uncertain']);
        $this->assertNull($p->fresh()->remote_deleted_at);
        $this->post('/publications/'.$p->id.'/delete', $this->input($p))->assertSessionHasErrors('deletion');
        Http::assertSentCount(1);
    }

    public function test_timeout_keeps_record_uncertain(): void
    {
        Http::preventStrayRequests();
        $p = $this->publication();
        Http::fake(['https://graph.facebook.com/*' => Http::failedConnection()]);
        $this->post('/publications/'.$p->id.'/delete', $this->input($p))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('publication_deletions', ['status' => 'uncertain']);
        $this->assertNull($p->fresh()->remote_deleted_at);
    }

    #[TestWith(['instagram'])]
    #[TestWith(['whatsapp'])]
    public function test_unsupported_connector_has_no_remote_delete_request(string $provider): void
    {
        Http::preventStrayRequests();
        $p = $this->publication($provider);
        $this->get('/publications/'.$p->id.'/delete')->assertOk()->assertSee('not supported by this connector');
        $this->post('/publications/'.$p->id.'/delete', $this->input($p))->assertSessionHasErrors('deletion');
        Http::assertNothingSent();
    }

    public function test_external_confirmation_is_audited_without_remote_request(): void
    {
        Http::preventStrayRequests();
        $p = $this->publication();
        $this->post('/publications/'.$p->id.'/external-removal', ['confirm_external' => 1, 'remote_id' => 'wrong'])->assertSessionHasErrors('remote_id');
        $this->post('/publications/'.$p->id.'/external-removal', ['confirm_external' => 1, 'remote_id' => $p->remote_post_id])->assertSessionHasNoErrors();
        $this->assertSame('owner_confirmation', $p->fresh()->deletion_origin);
        $this->assertDatabaseHas('publication_deletions', ['user_id' => auth()->id(), 'origin' => 'owner_confirmation', 'status' => 'succeeded']);
        app(CollectAnalytics::class)->run($p->fresh());
        Http::assertNothingSent();
        $this->get('/posts/'.$p->post_id.'/publish')->assertSee('Removed from platform');
    }

    private function webhook(Publication $p, array $overrides = []): array
    {
        return ['object' => 'page', 'entry' => [['id' => $p->page_id, 'time' => now()->timestamp, 'changes' => [['field' => 'feed', 'value' => $overrides + ['item' => 'post', 'verb' => 'remove', 'post_id' => $p->remote_post_id]]]]]];
    }

    private function sendWebhook(array $payload, bool $valid = true): TestResponse
    {
        $raw = json_encode($payload);
        $secret = str_repeat('s', 32);
        config(['services.facebook.app_secret' => $secret]);

        return $this->call('POST', '/webhooks/facebook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, $valid ? $secret : 'wrong')], $raw);
    }

    public function test_signed_facebook_removal_archives_only_matching_post_and_is_idempotent(): void
    {
        $this->freezeTime();
        $p = $this->publication();
        $other = $this->publication();
        $other->forceFill(['remote_post_id' => '123_999'])->save();
        $payload = $this->webhook($p);
        $this->sendWebhook($payload, false)->assertForbidden();
        $this->assertNull($p->fresh()->remote_deleted_at);
        $this->sendWebhook($payload)->assertOk();
        $this->sendWebhook($payload)->assertOk();
        $this->assertSame('facebook_webhook', $p->fresh()->deletion_origin);
        $this->assertNull($other->fresh()->remote_deleted_at);
        $this->assertDatabaseCount('publication_deletions', 1);
    }

    public function test_comment_removal_wrong_page_and_old_event_cannot_delete_post(): void
    {
        $this->freezeTime();
        $p = $this->publication();
        $payload = $this->webhook($p, ['item' => 'comment']);
        $this->sendWebhook($payload)->assertOk();
        $payload = $this->webhook($p);
        $payload['entry'][0]['id'] = '999';
        $this->sendWebhook($payload)->assertOk();
        $payload = $this->webhook($p);
        $payload['entry'][0]['time'] = now()->subDays(2)->timestamp;
        $this->sendWebhook($payload)->assertOk();
        $this->assertNull($p->fresh()->remote_deleted_at);
        $this->assertDatabaseCount('publication_deletions', 0);
    }

    public function test_webhook_verification_requires_configured_secret_token(): void
    {
        config(['services.facebook.webhook_verify_token' => str_repeat('v', 32)]);
        $this->get('/webhooks/facebook?hub.mode=subscribe&hub.verify_token=bad&hub.challenge=123')->assertForbidden();
        $this->get('/webhooks/facebook?hub.mode=subscribe&hub.verify_token='.str_repeat('v', 32).'&hub.challenge=123')->assertOk()->assertContent('123');
    }
}
