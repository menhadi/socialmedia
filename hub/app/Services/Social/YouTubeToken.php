<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class YouTubeToken
{
    public function accessToken(SocialAccount $account): string
    {
        if (! $account->oauth_credentials) {
            return $account->access_token ?? '';
        }

        return DB::transaction(function () use ($account): string {
            $current = SocialAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            if ($current->credential_version !== $account->credential_version || ! $current->oauth_credentials) {
                throw new \RuntimeException('YouTube credentials changed. Verify the account again.');
            }
            if ($current->access_token && $current->token_expires_at?->gt(now()->addMinutes(2))) {
                return $current->access_token;
            }
            try {
                $response = Http::asForm()->acceptJson()->connectTimeout(5)->timeout(20)->withoutRedirecting()
                    ->post('https://oauth2.googleapis.com/token', $current->oauth_credentials + ['grant_type' => 'refresh_token']);
                $token = $response->json('access_token');
                $expires = $response->json('expires_in');
                if (! $response->successful() || ! is_string($token) || $token === '' || ! is_numeric($expires) || (int) $expires < 180) {
                    throw new \RuntimeException('Invalid refresh response.');
                }
                $identity = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(20)->withoutRedirecting()
                    ->get('https://www.googleapis.com/youtube/v3/channels', ['part' => 'id', 'mine' => 'true']);
                if (! $identity->successful() || ! in_array($current->page_id, array_column($identity->json('items') ?? [], 'id'), true)) {
                    throw new \RuntimeException('Channel mismatch.');
                }
                $current->forceFill(['access_token' => $token, 'token_expires_at' => now()->addSeconds(min((int) $expires, 86400))])->save();

                return $token;
            } catch (\Throwable) {
                // Never propagate provider bodies or credentials into logs or forms.
                throw new FacebookFailure('platform_token');
            }
        });
    }
}
