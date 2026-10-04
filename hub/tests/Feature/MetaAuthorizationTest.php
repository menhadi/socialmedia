<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\MetaAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MetaAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $provider = 'facebook'): SocialAccount
    {
        Http::preventStrayRequests();
        config(['services.facebook.app_id' => '123', 'services.facebook.app_secret' => 'secret',
            'services.facebook.redirect_uri' => 'https://hub.example/social-accounts/meta/callback', 'services.facebook.version' => 'v25.0']);
        $account = SocialAccount::factory()->create(['provider' => $provider, 'page_id' => '456']);
        $this->actingAs(User::findOrFail($account->brand->user_id));

        return $account;
    }

    private function start(SocialAccount $account): array
    {
        $response = $this->post(route('social.meta.connect', $account))->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);

        return $parameters;
    }

    private function fakeGrant(SocialAccount $account, bool $match = true, bool $permissions = true, bool $direct = false, ?int $lifetime = 5184000, array $metadata = []): void
    {
        Http::fake([
            'https://graph.facebook.com/v25.0/oauth/access_token*' => fn ($request) => Http::response([
                'access_token' => isset($request['fb_exchange_token']) ? 'long-user' : 'short-user', 'expires_in' => $lifetime,
            ]),
            'https://graph.facebook.com/v25.0/debug_token*' => Http::response(['data' => $metadata]),
            'https://graph.facebook.com/v25.0/me/permissions*' => Http::response(['data' => array_map(fn ($scope) => ['permission' => $scope, 'status' => $permissions ? 'granted' : 'declined'], MetaAuthorization::scopes($account))]),
            'https://graph.facebook.com/v25.0/me/accounts*' => Http::response(['data' => $direct ? [] : [[
                'id' => $account->provider === 'facebook' && $match ? '456' : '789', 'name' => 'Page',
                'access_token' => 'page-token', 'instagram_business_account' => ['id' => $match ? '456' : '999'],
            ]]]),
            'https://graph.facebook.com/v25.0/me?*' => Http::response(['id' => '456', 'name' => 'Verified Page', 'category' => 'Education']),
            'https://graph.facebook.com/v25.0/456*' => Http::response(['id' => $direct && ! $match ? '999' : '456', 'name' => 'Verified Page', 'username' => 'verified_instagram']
                + ($direct ? ['access_token' => 'direct-page-token'] : [])),
        ]);
    }

    #[TestWith(['facebook'])]
    #[TestWith(['instagram'])]
    public function test_matching_identity_is_connected_with_encrypted_page_credentials(string $provider): void
    {
        $account = $this->account($provider);
        $version = $account->credential_version;
        $parameters = $this->start($account);
        $this->assertSame('123', $parameters['client_id']);
        $this->fakeGrant($account);
        $this->get(route('social.meta.callback', ['state' => $parameters['state'], 'code' => 'code']))->assertSessionHasNoErrors();
        $saved = $account->fresh();
        $this->assertSame('page-token', $saved->access_token);
        $this->assertSame('meta_page', $saved->oauth_credentials['connection_type']);
        $this->assertNotSame($version, $saved->credential_version);
        $this->assertNotNull($saved->verified_at);
        $this->assertNull($saved->token_expires_at);
        $this->assertStringNotContainsString('page-token', $saved->getRawOriginal('access_token'));
        $this->get(route('social.meta.callback', ['state' => $parameters['state'], 'code' => 'code']))->assertSessionHasErrors('connection');
        Http::assertSentCount(5);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/me/accounts')
            && $request['fields'] === ($provider === 'instagram' ? 'id,name,access_token,instagram_business_account' : 'id,name,access_token'));
    }

    #[TestWith([0])]
    #[TestWith([172800])]
    public function test_missing_lifetime_uses_verified_token_metadata(int $remaining): void
    {
        $account = $this->account('instagram');
        $parameters = $this->start($account);
        $this->fakeGrant($account, lifetime: null, metadata: [
            'is_valid' => true, 'app_id' => '123',
            'expires_at' => $remaining ? now()->timestamp + $remaining : 0,
        ]);
        $this->get(route('social.meta.callback', ['state' => $parameters['state'], 'code' => 'code']))->assertSessionHasNoErrors();
        $this->assertSame('page-token', $account->fresh()->access_token);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/debug_token')
            && $request['input_token'] === 'long-user' && $request->hasHeader('Authorization', 'Bearer 123|secret'));
    }

    #[TestWith([[]])]
    #[TestWith([['is_valid' => false, 'app_id' => '123', 'expires_at' => 0]])]
    #[TestWith([['is_valid' => true, 'app_id' => '999', 'expires_at' => 0]])]
    #[TestWith([['is_valid' => true, 'app_id' => '123', 'expires_at' => 1]])]
    #[TestWith([['is_valid' => true, 'app_id' => '123', 'expires_at' => 0, 'data_access_expires_at' => 1]])]
    public function test_invalid_metadata_preserves_saved_credentials(array $metadata): void
    {
        $account = $this->account('instagram');
        $original = $account->access_token;
        $parameters = $this->start($account);
        $this->fakeGrant($account, lifetime: null, metadata: $metadata);
        $this->get(route('social.meta.callback', ['state' => $parameters['state'], 'code' => 'code']))
            ->assertSessionHasErrors(['connection' => MetaAuthorization::failureMessage(62011)]);
        $this->assertSame($original, $account->fresh()->access_token);
        Http::assertSentCount(3);
    }

    public function test_provider_rejection_identifies_the_exchange_step_without_exposing_secrets(): void
    {
        $account = $this->account();
        $original = $account->access_token;
        $parameters = $this->start($account);
        Http::fake(['https://graph.facebook.com/v25.0/oauth/access_token*' => Http::response([
            'error' => ['code' => 190, 'error_subcode' => 123, 'message' => 'private-provider-message secret-token'],
        ], 400)]);
        $this->get(route('social.meta.callback', ['state' => $parameters['state'], 'code' => 'private-login-code']))
            ->assertSessionHasErrors(['connection' => MetaAuthorization::failureMessage(62001)]);
        $this->assertStringNotContainsString('secret-token', json_encode(session('errors')->all()));
        $this->assertSame($original, $account->fresh()->access_token);
        Http::assertSentCount(1);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_direct_saved_page_lookup_requires_matching_identity_and_page_credentials(bool $match): void
    {
        $account = $this->account();
        $original = $account->access_token;
        $parameters = $this->start($account);
        $this->fakeGrant($account, match: $match, direct: true);
        $response = $this->get(route('social.meta.callback', ['state' => $parameters['state'], 'code' => 'code']));
        if ($match) {
            $response->assertSessionHasNoErrors();
            $this->assertSame('direct-page-token', $account->fresh()->access_token);
            $this->assertNotNull($account->fresh()->verified_at);
        } else {
            $response->assertSessionHasErrors('connection');
            $this->assertSame($original, $account->fresh()->access_token);
        }
    }

    #[TestWith([false, true])]
    #[TestWith([true, false])]
    public function test_wrong_identity_or_missing_permissions_preserves_existing_credentials(bool $match, bool $permissions): void
    {
        $account = $this->account();
        $original = $account->access_token;
        $parameters = $this->start($account);
        $this->fakeGrant($account, $match, $permissions);
        $this->get(route('social.meta.callback', ['state' => $parameters['state'], 'code' => 'code']))->assertSessionHasErrors('connection');
        $this->assertSame($original, $account->fresh()->access_token);
        $this->assertNull($account->fresh()->oauth_credentials);
    }

    public function test_wrong_state_disconnection_and_foreign_accounts_cannot_connect(): void
    {
        $account = $this->account();
        $parameters = $this->start($account);
        $this->get(route('social.meta.callback', ['state' => 'wrong', 'code' => 'code']))->assertSessionHasErrors('connection');
        $this->delete(route('social.disconnect', $account));
        $this->get(route('social.meta.callback', ['state' => $parameters['state'], 'code' => 'code']))->assertSessionHasErrors('connection');
        $foreign = SocialAccount::factory()->create(['provider' => 'facebook']);
        $this->post(route('social.meta.connect', $foreign))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_missing_server_configuration_explains_setup_without_external_request(): void
    {
        $account = $this->account();
        config(['services.facebook.app_id' => null]);
        $this->post(route('social.meta.connect', $account))->assertSessionHasErrors('connection');
        $this->get(route('social'))->assertOk()->assertSee('FACEBOOK_APP_ID');
        Http::assertNothingSent();
    }
}
