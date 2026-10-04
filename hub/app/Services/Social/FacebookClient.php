<?php

namespace App\Services\Social;

use App\Models\Publication;
use App\Models\SocialAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class FacebookClient
{
    private function request(SocialAccount $account): PendingRequest
    {
        $version = config('services.facebook.version');
        if (! is_string($version) || ! preg_match('/^v[0-9]{2}\\.0$/D', $version)) {
            throw new FacebookFailure('configuration');
        }

        return Http::baseUrl('https://graph.facebook.com/'.$version)
            ->withToken($account->access_token)->acceptJson()
            ->connectTimeout(5)->timeout(25)->withoutRedirecting();
    }

    private function check(Response $response): void
    {
        if ($response->successful() && ! $response->json('error')) {
            return;
        }
        $code = (int) $response->json('error.code', 0);
        $definite = in_array($response->status(), [400, 401, 403], true)
            && ! $response->json('error.is_transient', false)
            && in_array($code, [10, 100, 190, 200, 294], true);
        $reason = match (true) {
            $definite && $code === 190 => 'token',
            $definite && in_array($code, [10, 200, 294], true) => 'permission',
            $definite && $code === 100 => 'invalid',
            default => 'response',
        };
        throw new FacebookFailure($reason, ! $definite);
    }

    public function verify(SocialAccount $account): string
    {
        try {
            $response = $this->request($account)->get('me', ['fields' => 'id,name,category']);
        } catch (ConnectionException) {
            throw new FacebookFailure('response');
        }
        $this->check($response);
        if ($response->json('id') !== $account->page_id || ! is_string($response->json('name')) || ! $response->json('name') || ! is_string($response->json('category'))) {
            throw new FacebookFailure('identity');
        }

        return mb_substr($response->json('name'), 0, 255);
    }

    public function publish(SocialAccount $account, Publication $publication): string
    {
        $payload = ['message' => $publication->message, 'published' => 'true'];
        if ($publication->link) {
            $payload['link'] = $publication->link;
        }
        try {
            if (count($publication->card_images ?? []) > 1) {
                $attached = [];
                foreach ($publication->card_images as $card) {
                    $upload = $this->request($account)->attach('source', Storage::disk('local')->get($card['path']), 'card.png')->post($publication->page_id.'/photos', ['published' => 'false']);
                    $this->check($upload);
                    $id = $upload->json('id');
                    if (! is_string($id) || ! preg_match('/^[0-9]+$/D', $id)) {
                        throw new FacebookFailure('response');
                    }
                    $attached[] = ['media_fbid' => $id];
                }
                $publication->forceFill(['transfer' => ['stage' => 'submitting', 'photos' => $attached]])->save();
                $response = $this->request($account)->post($publication->page_id.'/feed', ['message' => $publication->message.($publication->link ? "\n\n".$publication->link : ''), 'attached_media' => $attached, 'published' => true]);
            } elseif ($publication->video_path) {
                $description = $publication->message;
                if ($publication->link && ! str_contains($description, $publication->link)) {
                    $description .= "\n\n".$publication->link;
                }
                $response = $this->request($account)->timeout(120)->attach('source', Storage::disk('local')->get($publication->video_path), 'post.mp4')
                    ->post($publication->page_id.'/videos', ['description' => $description, 'published' => 'true',
                        'is_ai_generated' => ($publication->post->visual['type'] ?? '') === 'chart_video' ? 'false' : 'true']);
            } elseif ($publication->image_path) {
                $caption = $publication->message;
                if ($publication->link && ! str_contains($caption, $publication->link)) {
                    $caption .= "\n\n".$publication->link;
                }
                $response = $this->request($account)->attach('source', Storage::disk('local')->get($publication->image_path), 'post.png')
                    ->post($publication->page_id.'/photos', ['caption' => $caption, 'published' => 'true']);
            } else {
                $response = $this->request($account)->asForm()->post($publication->page_id.'/feed', $payload);
            }
        } catch (ConnectionException) {
            throw new FacebookFailure('response', true);
        }
        $this->check($response);
        if ($publication->video_path) {
            $id = $response->json('id');
            if (! is_string($id) || ! preg_match('/^[0-9]{1,64}$/D', $id)) {
                throw new FacebookFailure('response', true);
            }

            return $id;
        }
        $id = $response->json($publication->image_path && count($publication->card_images ?? []) <= 1 ? 'post_id' : 'id');
        if (! is_string($id) || ! preg_match('/^'.preg_quote($publication->page_id, '/').'_[0-9]+$/D', $id) || strlen($id) > 255) {
            throw new FacebookFailure('response', true);
        }

        return $id;
    }

    public function permalink(SocialAccount $account, string $postId): ?string
    {
        try {
            $response = $this->request($account)->get($postId, ['fields' => 'id,permalink_url']);
            $this->check($response);
            $url = $response->json('permalink_url');
            if ($response->json('id') === $postId && is_string($url) && strlen($url) <= 2048
                && filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https'
                && in_array(parse_url($url, PHP_URL_HOST), ['facebook.com', 'www.facebook.com', 'm.facebook.com'], true)
                && parse_url($url, PHP_URL_USER) === null) {
                return $url;
            }
        } catch (\Throwable) {
            // Publishing success is retained even if this read-only lookup fails.
        }

        return null;
    }
}
