<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MetaAuthorization
{
    public static function configured(): bool
    {
        return filled(config('services.facebook.app_id')) && filled(config('services.facebook.app_secret'))
            && filter_var(config('services.facebook.redirect_uri'), FILTER_VALIDATE_URL)
            && parse_url(config('services.facebook.redirect_uri'), PHP_URL_SCHEME) === 'https'
            && preg_match('/^v[0-9]{2}\\.0$/D', config('services.facebook.version', ''));
    }

    public static function scopes(SocialAccount $account): array
    {
        return $account->provider === 'instagram'
            ? ['pages_show_list', 'pages_read_engagement', 'instagram_basic', 'instagram_content_publish']
            : ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts'];
    }

    private function read(string $path, array $parameters = [], ?string $token = null): array
    {
        $request = Http::acceptJson()->connectTimeout(5)->timeout(20)->withoutRedirecting();
        if ($token) {
            $request = $request->withToken($token);
        }
        $stage = match ($path) {
            'oauth/access_token' => isset($parameters['fb_exchange_token']) ? 62002 : 62001,
            'me/permissions' => 62003,
            default => 62004,
        };
        try {
            $response = $request->get('https://graph.facebook.com/'.config('services.facebook.version').'/'.$path, $parameters);
        } catch (\Throwable) {
            throw new RuntimeException('Meta request failed.', $stage);
        }
        if (! $response->successful() || $response->json('error') || ! is_array($response->json())) {
            Log::warning('Meta authorization request rejected', [
                'stage' => $stage, 'status' => $response->status(),
                'provider_code' => is_numeric($response->json('error.code')) ? (int) $response->json('error.code') : null,
                'provider_subcode' => is_numeric($response->json('error.error_subcode')) ? (int) $response->json('error.error_subcode') : null,
            ]);
            throw new RuntimeException('Meta authorization failed.', $stage);
        }

        return $response->json();
    }

    public static function failureMessage(int $code): string
    {
        return match ($code) {
            62001 => 'Meta could not exchange the login code. Check that the server App ID, App Secret and callback belong to the same Meta app.',
            62002 => 'Meta could not exchange the token for long-lived authorization.',
            62003 => 'Meta could not read the granted publishing permissions.',
            62004 => 'Meta could not list the authorized Pages. Check the Meta app permissions and Page access.',
            62010 => 'Meta did not issue a login access token.',
            62011 => 'Meta did not issue valid long-lived authorization.',
            62012 => 'Required publishing permissions were not granted. Reconnect and enable the requested Page or Instagram permissions.',
            62013 => 'The authorized Pages do not include this saved Page or linked Instagram account.',
            62014 => 'Meta did not provide publishing credentials for the saved Facebook Page. Check Facebook access to that Page and the app permissions.',
            default => 'Meta authorization could not be completed. Select the saved Page or linked Instagram account and grant the required publishing permissions.',
        };
    }

    public function credentials(SocialAccount $account, string $code): array
    {
        if (! self::configured()) {
            throw new RuntimeException('Configure Meta authorization first.');
        }
        $parameters = ['client_id' => config('services.facebook.app_id'), 'client_secret' => config('services.facebook.app_secret')];
        $short = $this->read('oauth/access_token', $parameters + ['redirect_uri' => config('services.facebook.redirect_uri'), 'code' => $code]);
        if (! is_string($short['access_token'] ?? null) || $short['access_token'] === '') {
            throw new RuntimeException('Meta did not issue an access token.', 62010);
        }
        $long = $this->read('oauth/access_token', $parameters + ['grant_type' => 'fb_exchange_token', 'fb_exchange_token' => $short['access_token']]);
        $token = $long['access_token'] ?? null;
        if (! is_string($token) || $token === '' || ! is_numeric($long['expires_in'] ?? null) || $long['expires_in'] < 86400) {
            throw new RuntimeException('Meta did not issue long-lived authorization.', 62011);
        }
        $permissions = $this->read('me/permissions', token: $token);
        $granted = array_column(array_filter($permissions['data'] ?? [], fn (array $permission) => ($permission['status'] ?? '') === 'granted'), 'permission');
        if (array_diff(self::scopes($account), $granted)) {
            throw new RuntimeException('Required Meta permissions were not granted.', 62012);
        }
        $after = null;
        for ($page = 0; $page < 5; $page++) {
            $fields = 'id,name,access_token'.($account->provider === 'instagram' ? ',instagram_business_account' : '');
            $data = $this->read('me/accounts', ['fields' => $fields, 'limit' => 100] + ($after ? ['after' => $after] : []), $token);
            foreach ($data['data'] ?? [] as $entry) {
                $identity = $account->provider === 'instagram' ? data_get($entry, 'instagram_business_account.id') : ($entry['id'] ?? null);
                if ((string) $identity === $account->page_id && is_string($entry['access_token'] ?? null) && $entry['access_token'] !== '') {
                    return ['access_token' => $entry['access_token'], 'page_id' => (string) $entry['id'], 'name' => $entry['name'] ?? $account->page_name];
                }
            }
            $after = data_get($data, 'paging.cursors.after');
            if (! data_get($data, 'paging.next') || ! is_string($after) || $after === '') {
                break;
            }
        }
        if ($account->provider === 'facebook' && preg_match('/^[0-9]+$/D', (string) $account->page_id)) {
            $entry = $this->read((string) $account->page_id, ['fields' => 'id,name,access_token'], $token);
            if ((string) ($entry['id'] ?? '') === (string) $account->page_id
                && is_string($entry['access_token'] ?? null) && $entry['access_token'] !== '') {
                return ['access_token' => $entry['access_token'], 'page_id' => (string) $entry['id'], 'name' => $entry['name'] ?? $account->page_name];
            }
            throw new RuntimeException('Meta did not provide Page credentials.', 62014);
        }
        throw new RuntimeException('The authorized Pages do not include this saved account.', 62013);
    }
}
