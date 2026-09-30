<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class XToken
{
    public const SCOPES = 'tweet.read users.read tweet.write media.write offline.access';

    public static function configured(): bool
    {
        return filled(config('services.x.client_id')) && filled(config('services.x.client_secret'))
            && filter_var(config('services.x.redirect_uri'), FILTER_VALIDATE_URL)
            && parse_url(config('services.x.redirect_uri'), PHP_URL_SCHEME) === 'https';
    }

    public function exchange(array $parameters): array
    {
        try {
            if (! self::configured()) {
                throw new \RuntimeException;
            }
            $response = Http::asForm()->acceptJson()
                ->withBasicAuth(config('services.x.client_id'), config('services.x.client_secret'))
                ->connectTimeout(5)->timeout(20)->withoutRedirecting()
                ->post('https://api.x.com/2/oauth2/token', $parameters);
            $data = $response->json();
            if (! $response->successful() || ! is_array($data)
                || ! is_string($data['access_token'] ?? null) || $data['access_token'] === ''
                || ! is_string($data['refresh_token'] ?? null) || $data['refresh_token'] === ''
                || strtolower($data['token_type'] ?? '') !== 'bearer'
                || ! is_numeric($data['expires_in'] ?? null) || $data['expires_in'] < 180
                || (isset($data['scope']) && (! is_string($data['scope']) || array_diff(explode(' ', self::SCOPES), explode(' ', $data['scope']))))) {
                throw new \RuntimeException;
            }

            return $data;
        } catch (\Throwable) {
            throw new FacebookFailure('platform_token');
        }
    }

    public function accessToken(SocialAccount $account): string
    {
        return DB::transaction(function () use ($account): string {
            $current = SocialAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            if ($current->provider !== 'x' || $current->credential_version !== $account->credential_version || ! $current->access_token) {
                throw new FacebookFailure('platform_token');
            }
            if (! $current->oauth_credentials) {
                return $current->access_token;
            }
            if (($current->oauth_credentials['client_id'] ?? null) !== config('services.x.client_id')) {
                throw new FacebookFailure('platform_configuration');
            }
            if ($current->token_expires_at?->gt(now()->addMinutes(2))) {
                return $current->access_token;
            }
            $data = $this->exchange(['grant_type' => 'refresh_token', 'refresh_token' => $current->oauth_credentials['refresh_token']]);
            $current->forceFill([
                'access_token' => $data['access_token'],
                'oauth_credentials' => ['client_id' => config('services.x.client_id'), 'refresh_token' => $data['refresh_token']],
                'token_expires_at' => now()->addSeconds(min((int) $data['expires_in'], 86400)),
            ])->save();

            return $data['access_token'];
        });
    }
}
