<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\FacebookFailure;
use App\Services\Social\PlatformClient;
use App\Services\Social\XToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class XAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function account(): SocialAccount
    {
        Http::preventStrayRequests();
        $this->freezeTime();
        config(['services.x.client_id' => 'client', 'services.x.client_secret' => 'secret',
            'services.x.redirect_uri' => 'https://hub.example/social-accounts/x/callback']);
        $account = SocialAccount::factory()->create(['provider' => 'x']);
        $this->actingAs(User::findOrFail($account->brand->user_id));

        return $account;
    }

    private function start(SocialAccount $account): array
    {
        $response = $this->post(route('social.x.connect', $account))->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);

        return $parameters;
    }

    private function grant(array $overrides = []): array
    {
        return array_merge(['access_token' => 'new-access', 'refresh_token' => 'new-refresh',
            'token_type' => 'bearer', 'expires_in' => 7200, 'scope' => XToken::SCOPES], $overrides);
    }

    public function test_connection_checks_identity_stores_encrypted_tokens_and_consumes_pkce_state(): void
    {
        $account = $this->account();
        $parameters = $this->start($account);
        $this->assertSame('S256', $parameters['code_challenge_method']);
        $this->assertSame(XToken::SCOPES, $parameters['scope']);
        Http::fake(['api.x.com/2/oauth2/token' => Http::response($this->grant()),
            'api.x.com/2/users/me' => Http::response(['data' => ['id' => $account->page_id, 'username' => 'pollmedia']])]);
        $callback = route('social.x.callback', ['state' => $parameters['state'], 'code' => 'private-code']);
        $this->get($callback)->assertRedirect(route('social', ['provider' => 'x']))->assertSessionHasNoErrors();
        $this->assertSame('new-access', $account->fresh()->access_token);
        $this->assertSame('new-refresh', $account->fresh()->oauth_credentials['refresh_token']);
        $this->assertNotSame($account->credential_version, $account->fresh()->credential_version);
        $this->assertNotNull($account->fresh()->verified_at);
        $this->assertStringNotContainsString('new-refresh', DB::table('social_accounts')->value('oauth_credentials'));
        $this->get(route('social', ['provider' => 'x']))->assertDontSee('new-refresh')->assertDontSee('new-access')->assertSee('Reconnect X');
        Http::assertSent(fn ($r) => $r->url() === 'https://api.x.com/2/oauth2/token'
            && rtrim(strtr(base64_encode(hash('sha256', $r['code_verifier'], true)), '+/', '-_'), '=') === $parameters['code_challenge']);
        $this->get($callback)->assertSessionHasErrors('connection');
        Http::assertSentCount(2);
    }

    public function test_other_tenants_and_bad_states_cannot_authorize(): void
    {
        $account = $this->account();
        $this->get(route('social.x.callback', ['state' => 'wrong', 'code' => 'code']))->assertSessionHasErrors('connection');
        $this->actingAs(User::factory()->create())->post(route('social.x.connect', $account))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_expired_or_cancelled_authorization_does_not_exchange(): void
    {
        $account = $this->account();
        $p = $this->start($account);
        $this->travel(11)->minutes();
        $this->get(route('social.x.callback', ['state' => $p['state'], 'code' => 'code']))->assertSessionHasErrors('connection');
        $p = $this->start($account);
        $this->get(route('social.x.callback', ['state' => $p['state'], 'error' => 'access_denied']))->assertSessionHasErrors('connection');
        Http::assertNothingSent();
    }

    public function test_wrong_account_does_not_overwrite_connection(): void
    {
        $account = $this->account();
        $p = $this->start($account);
        Http::fake(['api.x.com/2/oauth2/token' => Http::response($this->grant()),
            'api.x.com/2/users/me' => Http::response(['data' => ['id' => 'another', 'username' => 'other']])]);
        $this->get(route('social.x.callback', ['state' => $p['state'], 'code' => 'code']))->assertSessionHasErrors('connection');
        $this->assertSame('test-page-token', $account->fresh()->access_token);
        $this->assertNull($account->fresh()->oauth_credentials);
    }

    public function test_incomplete_permissions_and_secret_provider_errors_are_not_saved_or_exposed(): void
    {
        $account = $this->account();
        $p = $this->start($account);
        Http::fake(['api.x.com/2/oauth2/token' => Http::response($this->grant(['scope' => 'tweet.read users.read']))]);
        $this->get(route('social.x.callback', ['state' => $p['state'], 'code' => 'code']))->assertSessionHasErrors('connection');
        $this->assertNull($account->fresh()->oauth_credentials);
        Http::assertSentCount(1);
        $p = $this->start($account);
        Http::fake(['api.x.com/2/oauth2/token' => Http::response(['error' => 'secret-provider-data'], 400)]);
        $this->get(route('social.x.callback', ['state' => $p['state'], 'code' => 'private-code']))->assertSessionHasErrors('connection');
        $this->assertStringNotContainsString('secret-provider-data', json_encode(session()->all()));
        $this->assertNull(session()->getOldInput('code'));
    }

    public function test_disconnection_during_authorization_cannot_restore_access(): void
    {
        $account = $this->account();
        $p = $this->start($account);
        $this->delete(route('social.disconnect', $account))->assertRedirect();
        $this->get(route('social.x.callback', ['state' => $p['state'], 'code' => 'code']))->assertSessionHasErrors('connection');
        Http::assertNothingSent();
    }

    public function test_refresh_rotates_tokens_preserves_schedule_version_and_is_reused(): void
    {
        $account = $this->account();
        $account->forceFill(['oauth_credentials' => ['client_id' => 'client', 'refresh_token' => 'old-refresh'],
            'token_expires_at' => now()->subMinute()])->save();
        Http::fake(['api.x.com/2/oauth2/token' => Http::response($this->grant()),
            'api.x.com/2/users/me' => Http::response(['data' => ['id' => $account->page_id, 'username' => 'pollmedia']])]);
        $this->assertSame('pollmedia', app(PlatformClient::class)->verify($account));
        $this->assertSame('new-access', app(XToken::class)->accessToken($account));
        $this->assertSame('new-refresh', $account->fresh()->oauth_credentials['refresh_token']);
        $this->assertSame($account->credential_version, $account->fresh()->credential_version);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.x.com/2/oauth2/token' && $r['refresh_token'] === 'old-refresh');
    }

    public function test_failed_refresh_stops_before_identity_request_and_disconnect_blocks_stale_workers(): void
    {
        $account = $this->account();
        $account->forceFill(['oauth_credentials' => ['client_id' => 'client', 'refresh_token' => 'old-refresh']])->save();
        Http::fake(['api.x.com/2/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->post(route('social.verify', $account))->assertSessionHasErrors('connection');
        Http::assertSentCount(1);
        $this->delete(route('social.disconnect', $account));
        $this->expectException(FacebookFailure::class);
        app(XToken::class)->accessToken($account);
    }

    public function test_unconfigured_connection_does_not_redirect_to_x(): void
    {
        $account = $this->account();
        config(['services.x.client_secret' => null]);
        $this->post(route('social.x.connect', $account))->assertRedirect(route('social', ['provider' => 'x']))->assertSessionHasErrors('connection');
        Http::assertNothingSent();
    }
}
