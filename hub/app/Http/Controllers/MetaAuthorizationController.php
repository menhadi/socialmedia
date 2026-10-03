<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Services\Social\MetaAuthorization;
use App\Services\Social\PlatformClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MetaAuthorizationController extends Controller
{
    public function connect(Request $request, SocialAccount $account): RedirectResponse
    {
        abort_unless(in_array($account->provider, ['facebook', 'instagram'], true) && $account->brand->user_id === $request->user()->id, 404);
        if (! MetaAuthorization::configured()) {
            return to_route('applications.accounts.edit', [$account->brand_id, $account])->withErrors(['connection' => 'Configure FACEBOOK_APP_ID, FACEBOOK_APP_SECRET and FACEBOOK_REDIRECT_URI on the server, and add that callback to the Meta app first.']);
        }
        $state = Str::random(64);
        $request->session()->put('meta_oauth_state', $state);
        Cache::put('meta-oauth:'.$state, Crypt::encryptString(json_encode([
            'user_id' => $request->user()->id, 'account_id' => $account->id,
            'version' => $account->credential_version,
            'client_id' => config('services.facebook.app_id'), 'redirect_uri' => config('services.facebook.redirect_uri'),
        ], JSON_THROW_ON_ERROR)), now()->addMinutes(10));

        return redirect()->away('https://www.facebook.com/'.config('services.facebook.version').'/dialog/oauth?'.http_build_query([
            'client_id' => config('services.facebook.app_id'), 'redirect_uri' => config('services.facebook.redirect_uri'),
            'response_type' => 'code', 'scope' => implode(',', MetaAuthorization::scopes($account)), 'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986));
    }

    public function callback(Request $request, MetaAuthorization $tokens): RedirectResponse
    {
        $failure = fn () => to_route('social', ['provider' => 'facebook'])->withErrors(['connection' => 'Meta authorization could not be completed. Select the saved Page or linked Instagram account and grant the required publishing permissions.']);
        $state = $request->query('state');
        $expected = $request->session()->get('meta_oauth_state');
        if (! is_string($state) || ! is_string($expected) || ! hash_equals($expected, $state)) {
            return $failure();
        }
        $request->session()->forget('meta_oauth_state');
        try {
            $pending = Cache::lock('meta-oauth-lock:'.$state, 30)->block(2, fn () => Cache::pull('meta-oauth:'.$state));
            if (! is_string($pending)) {
                return $failure();
            }
            $pending = json_decode(Crypt::decryptString($pending), true, flags: JSON_THROW_ON_ERROR);
            $account = SocialAccount::findOrFail($pending['account_id']);
            if ($pending['user_id'] !== $request->user()->id || $account->brand->user_id !== $request->user()->id
                || ! in_array($account->provider, ['facebook', 'instagram'], true) || $account->credential_version !== $pending['version']
                || $pending['client_id'] !== config('services.facebook.app_id') || $pending['redirect_uri'] !== config('services.facebook.redirect_uri')
                || $request->has('error') || ! is_string($request->query('code')) || $request->query('code') === '') {
                return $failure();
            }
            $data = $tokens->credentials($account, $request->query('code'));
            $candidate = clone $account;
            $candidate->access_token = $data['access_token'];
            $candidate->settings = array_merge($account->settings ?? [], ['login_method' => 'facebook', 'facebook_page_id' => $data['page_id']]);
            $name = app(PlatformClient::class)->verify($candidate);
            DB::transaction(function () use ($account, $pending, $data, $name, $candidate): void {
                $current = SocialAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
                if ($current->credential_version !== $pending['version']) {
                    throw new \RuntimeException;
                }
                $current->forceFill(['access_token' => $data['access_token'],
                    'oauth_credentials' => ['connection_type' => 'meta_page', 'app_id' => $pending['client_id'], 'page_id' => $data['page_id']],
                    'token_expires_at' => null, 'settings' => $candidate->settings,
                    'page_name' => $name, 'verified_at' => now(), 'error_code' => null,
                    'credential_version' => (string) Str::uuid(),
                ])->save();
            });
        } catch (\Throwable) {
            return $failure();
        }

        return to_route('applications.accounts.edit', [$account->brand_id, $account])->with('success', 'Meta account connected and verified with Page credentials obtained through long-lived authorization. No daily token replacement is needed; revoked permissions still require reconnection.');
    }
}
