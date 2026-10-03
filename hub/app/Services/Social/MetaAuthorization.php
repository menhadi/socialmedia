<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
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
        $response = $request->get('https://graph.facebook.com/'.config('services.facebook.version').'/'.$path, $parameters);
        if (! $response->successful() || $response->json('error') || ! is_array($response->json())) {
            throw new RuntimeException('Meta authorization failed.');
        }

        return $response->json();
    }

    public function credentials(SocialAccount $account, string $code): array
    {
        if (! self::configured()) {
            throw new RuntimeException('Configure Meta authorization first.');
        }
        $parameters = ['client_id' => config('services.facebook.app_id'), 'client_secret' => config('services.facebook.app_secret')];
        $short = $this->read('oauth/access_token', $parameters + ['redirect_uri' => config('services.facebook.redirect_uri'), 'code' => $code]);
        if (! is_string($short['access_token'] ?? null) || $short['access_token'] === '') {
            throw new RuntimeException('Meta did not issue an access token.');
        }
        $long = $this->read('oauth/access_token', $parameters + ['grant_type' => 'fb_exchange_token', 'fb_exchange_token' => $short['access_token']]);
        $token = $long['access_token'] ?? null;
        if (! is_string($token) || $token === '' || ! is_numeric($long['expires_in'] ?? null) || $long['expires_in'] < 86400) {
            throw new RuntimeException('Meta did not issue long-lived authorization.');
        }
        $permissions = $this->read('me/permissions', token: $token);
        $granted = array_column(array_filter($permissions['data'] ?? [], fn (array $permission) => ($permission['status'] ?? '') === 'granted'), 'permission');
        if (array_diff(self::scopes($account), $granted)) {
            throw new RuntimeException('Required Meta permissions were not granted.');
        }
        $after = null;
        for ($page = 0; $page < 5; $page++) {
            $data = $this->read('me/accounts', ['fields' => 'id,name,access_token,instagram_business_account', 'limit' => 100] + ($after ? ['after' => $after] : []), $token);
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
        throw new RuntimeException('The authorized Pages do not include this saved account.');
    }
}
