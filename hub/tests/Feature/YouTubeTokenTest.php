<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\PlatformClient;
use App\Services\Social\YouTubeToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YouTubeTokenTest extends TestCase
{
    use RefreshDatabase;

    private function account(): SocialAccount
    {
        Http::preventStrayRequests();
        $this->freezeTime();
        $account = SocialAccount::factory()->create(['provider' => 'youtube', 'page_id' => 'UCabcdefghijklmnopqrstuv',
            'oauth_credentials' => ['client_id' => 'test-client', 'client_secret' => 'private-secret', 'refresh_token' => 'private-refresh']]);
        $this->actingAs(User::findOrFail($account->brand->user_id));

        return $account;
    }

    public function test_expired_token_is_renewed_and_verified_without_invalidating_schedules(): void
    {
        $account = $this->account();
        $version = $account->credential_version;
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'new-token', 'expires_in' => 3600]),
            'https://www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [['id' => $account->page_id, 'snippet' => ['title' => 'PollMedia']]]])]);

        $this->assertSame('PollMedia', app(PlatformClient::class)->verify($account));
        $this->assertSame('new-token', app(YouTubeToken::class)->accessToken($account));

        $this->assertSame($version, $account->fresh()->credential_version);
        $this->assertTrue($account->fresh()->token_expires_at->isFuture());
        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => $r->url() === 'https://oauth2.googleapis.com/token' && $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'private-refresh');
        $this->assertStringNotContainsString('private-secret', DB::table('social_accounts')->value('oauth_credentials'));
        $this->assertArrayNotHasKey('oauth_credentials', $account->toArray());
    }

    public function test_wrong_channel_refresh_does_not_replace_saved_token(): void
    {
        $account = $this->account();
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'wrong-token', 'expires_in' => 3600]),
            'https://www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [['id' => 'another-channel']]])]);

        $this->post(route('social.verify', $account))->assertSessionHasErrors('connection');

        $this->assertSame('test-page-token', $account->fresh()->access_token);
        $this->assertNull($account->fresh()->verified_at);
        Http::assertSentCount(2);
    }

    public function test_rejected_refresh_stops_before_youtube_request(): void
    {
        $account = $this->account();
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->post(route('social.verify', $account))->assertSessionHasErrors('connection');

        Http::assertSentCount(1);
        $this->assertNull($account->fresh()->token_expires_at);
    }

    public function test_renewal_credentials_are_private_tenant_scoped_and_removed_on_disconnect(): void
    {
        $account = $this->account();
        $data = ['youtube_client_id' => 'new-client', 'youtube_client_secret' => 'new-secret', 'youtube_refresh_token' => 'new-refresh'];
        $this->put(route('social.update', $account), $data)->assertSessionHasNoErrors();
        $this->assertSame('new-refresh', $account->fresh()->oauth_credentials['refresh_token']);
        $this->get(route('social', ['provider' => 'youtube']))->assertDontSee('new-secret')->assertDontSee('new-refresh');
        $this->put(route('social.update', $account), ['youtube_client_id' => 'incomplete'])->assertSessionHasErrors('youtube_refresh_token');
        $this->assertNull(session()->getOldInput('youtube_client_secret'));
        $owner = User::findOrFail($account->brand->user_id);
        $this->actingAs(User::factory()->create())->put(route('social.update', $account), $data)->assertNotFound();
        $this->actingAs($owner)->delete(route('social.disconnect', $account))->assertRedirect();
        $this->assertNull($account->fresh()->oauth_credentials);
        $this->assertNull($account->fresh()->access_token);
        Http::assertNothingSent();
    }
}
