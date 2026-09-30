<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Services\Social\XToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class XAuthorizationController extends Controller
{
    public function connect(Request $request, SocialAccount $account): RedirectResponse
    {
        abort_unless($account->provider === 'x' && $account->brand->user_id === $request->user()->id, 404);
        if (! XToken::configured()) {
            return to_route('social', ['provider' => 'x'])->withErrors(['connection' => 'Configure X_CLIENT_ID, X_CLIENT_SECRET and X_REDIRECT_URI on the server first.']);
        }
        $state = Str::random(64);
        $verifier = Str::random(64);
        $request->session()->put('x_oauth_state', $state);
        Cache::put('x-oauth:'.$state, Crypt::encryptString(json_encode([
            'user_id' => $request->user()->id, 'account_id' => $account->id,
            'version' => $account->credential_version, 'verifier' => $verifier,
            'client_id' => config('services.x.client_id'), 'redirect_uri' => config('services.x.redirect_uri'),
        ], JSON_THROW_ON_ERROR)), now()->addMinutes(10));

        return redirect()->away('https://x.com/i/oauth2/authorize?'.http_build_query([
            'response_type' => 'code', 'client_id' => config('services.x.client_id'),
            'redirect_uri' => config('services.x.redirect_uri'), 'scope' => XToken::SCOPES,
            'state' => $state, 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986));
    }

    public function callback(Request $request, XToken $tokens): RedirectResponse
    {
        $failure = fn () => to_route('social', ['provider' => 'x'])->withErrors(['connection' => 'X authorization could not be completed. Check API credits and permissions, sign in to the matching X account, then connect again.']);
        $state = $request->query('state');
        $expected = $request->session()->get('x_oauth_state');
        if (! is_string($state) || ! is_string($expected) || ! hash_equals($expected, $state)) {
            return $failure();
        }
        $request->session()->forget('x_oauth_state');
        try {
            $pending = Cache::lock('x-oauth-lock:'.$state, 30)->block(2, fn () => Cache::pull('x-oauth:'.$state));
            if (! is_string($pending)) {
                return $failure();
            }
            $pending = json_decode(Crypt::decryptString($pending), true, flags: JSON_THROW_ON_ERROR);
            $account = SocialAccount::findOrFail($pending['account_id']);
            if ($pending['user_id'] !== $request->user()->id || $account->brand->user_id !== $request->user()->id
                || $account->provider !== 'x' || $account->credential_version !== $pending['version']
                || $pending['client_id'] !== config('services.x.client_id') || $pending['redirect_uri'] !== config('services.x.redirect_uri')
                || $request->has('error') || ! is_string($request->query('code')) || $request->query('code') === '') {
                return $failure();
            }
            $data = $tokens->exchange(['grant_type' => 'authorization_code', 'code' => $request->query('code'),
                'redirect_uri' => $pending['redirect_uri'], 'code_verifier' => $pending['verifier']]);
            if (! is_string($data['scope'] ?? null) || array_diff(explode(' ', XToken::SCOPES), explode(' ', $data['scope']))) {
                return $failure();
            }
            $response = Http::withToken($data['access_token'])->acceptJson()->connectTimeout(5)->timeout(20)->withoutRedirecting()->get('https://api.x.com/2/users/me');
            $identity = $response->json('data');
            if (! $response->successful() || ! is_array($identity) || ($identity['id'] ?? null) !== $account->page_id || ! is_string($identity['username'] ?? null)) {
                return $failure();
            }
            DB::transaction(function () use ($account, $pending, $data, $identity): void {
                $current = SocialAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
                if ($current->credential_version !== $pending['version']) {
                    throw new \RuntimeException;
                }
                $current->forceFill(['access_token' => $data['access_token'],
                    'oauth_credentials' => ['client_id' => $pending['client_id'], 'refresh_token' => $data['refresh_token']],
                    'token_expires_at' => now()->addSeconds(min((int) $data['expires_in'], 86400)),
                    'page_name' => $identity['username'], 'verified_at' => now(), 'error_code' => null,
                    'credential_version' => (string) Str::uuid(),
                ])->save();
            });
        } catch (\Throwable) {
            return $failure();
        }

        return to_route('social', ['provider' => 'x'])->with('success', 'X account connected and verified. Access will renew automatically while authorization remains valid.');
    }
}
