<?php

namespace App\Services\Social;

use App\Models\Publication;
use App\Models\SocialAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class PlatformClient
{
    public function __construct(private FacebookClient $facebook) {}

    private function request(SocialAccount $account): PendingRequest
    {
        $token = $account->provider === 'youtube' ? app(YouTubeToken::class)->accessToken($account) : $account->access_token;
        $request = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(60)->withoutRedirecting();
        if ($account->provider === 'linkedin') {
            $request->withHeaders(['LinkedIn-Version' => config('services.linkedin.version'), 'X-Restli-Protocol-Version' => '2.0.0']);
        }

        return $request;
    }

    private function graph(SocialAccount $account): string
    {
        $host = $account->provider === 'instagram' && ($account->settings['login_method'] ?? 'facebook') === 'instagram'
            ? 'graph.instagram.com' : 'graph.facebook.com';
        $version = config('services.facebook.version');
        if (! is_string($version) || ! preg_match('/^v[0-9]{2}\.0$/D', $version)) {
            throw new FacebookFailure('platform_configuration');
        }

        return "https://{$host}/{$version}";
    }

    private function checked(Response $response, bool $publishing = false): Response
    {
        if ($response->successful() && ! $response->json('error') && ! $response->json('errors')) {
            return $response;
        }
        $reason = match ($response->status()) {
            401 => 'platform_token', 403 => 'platform_permission', 429 => 'platform_rate',
            400, 404, 422 => 'platform_invalid', default => 'platform_response',
        };
        throw new FacebookFailure($reason, $publishing && ! in_array($response->status(), [400, 401, 403, 404, 422, 429], true));
    }

    private function identifier(mixed $value, string $pattern = '/^[0-9]{1,50}$/D', bool $publishing = false): string
    {
        if (! is_string($value) || ! preg_match($pattern, $value)) {
            throw new FacebookFailure('platform_response', $publishing);
        }

        return $value;
    }

    public function verify(SocialAccount $account): string
    {
        if ($account->provider === 'facebook') {
            return $this->facebook->verify($account);
        }
        $http = $this->request($account);
        if ($account->provider === 'instagram') {
            $data = $this->checked($http->get($this->graph($account).'/'.$account->page_id, ['fields' => 'id,username']))->json();
            $id = (string) ($data['id'] ?? '');
            $name = $data['username'] ?? '';
        } elseif ($account->provider === 'x') {
            $data = $this->checked($http->get('https://api.x.com/2/users/me'))->json('data');
            $id = (string) ($data['id'] ?? '');
            $name = $data['username'] ?? '';
        } elseif ($account->provider === 'youtube') {
            $items = $this->checked($http->get('https://www.googleapis.com/youtube/v3/channels', ['part' => 'snippet', 'mine' => 'true']))->json('items', []);
            $data = collect($items)->firstWhere('id', $account->page_id) ?? [];
            $id = $data['id'] ?? '';
            $name = $data['snippet']['title'] ?? '';
        } elseif ($account->provider === 'whatsapp') {
            $data = $this->checked($http->get($this->graph($account).'/'.$account->page_id, ['fields' => 'id,verified_name,display_phone_number']))->json();
            $id = (string) ($data['id'] ?? '');
            $name = $data['verified_name'] ?? $data['display_phone_number'] ?? '';
        } elseif ($account->provider === 'linkedin' && str_starts_with($account->page_id, 'urn:li:person:')) {
            $data = $this->checked($http->get('https://api.linkedin.com/v2/userinfo'))->json();
            $id = 'urn:li:person:'.($data['sub'] ?? '');
            $name = $data['name'] ?? '';
        } elseif ($account->provider === 'linkedin') {
            $roles = $this->checked($http->get('https://api.linkedin.com/rest/organizationAcls', ['q' => 'roleAssignee', 'state' => 'APPROVED']))->json('elements', []);
            $allowed = collect($roles)->contains(fn ($role) => ($role['organization'] ?? '') === $account->page_id
                && in_array($role['role'] ?? '', ['ADMINISTRATOR', 'CONTENT_ADMIN'], true));
            if (! $allowed) {
                throw new FacebookFailure('platform_permission');
            }
            $organization = substr($account->page_id, strlen('urn:li:organization:'));
            $data = $this->checked($http->get('https://api.linkedin.com/rest/organizations/'.$organization))->json();
            $id = 'urn:li:organization:'.($data['id'] ?? '');
            $name = $data['localizedName'] ?? $account->display_name ?? '';
        } else {
            throw new FacebookFailure('platform_configuration');
        }
        if ($id !== $account->page_id || ! is_string($name) || $name === '') {
            throw new FacebookFailure('platform_identity');
        }

        return mb_substr($name, 0, 200);
    }

    public function publish(SocialAccount $account, Publication $publication): ?string
    {
        if (count($publication->card_images ?? []) > 1 && $account->provider !== 'facebook') {
            return $this->multiImage($account, $publication);
        }

        return match ($account->provider) {
            'facebook' => $this->facebook->publish($account, $publication),
            'instagram' => $this->instagram($account, $publication),
            'linkedin' => $this->linkedin($account, $publication),
            'x' => $this->x($account, $publication),
            'youtube' => $this->youtube($account, $publication),
            'whatsapp' => $this->whatsapp($account, $publication),
            default => throw new FacebookFailure('platform_configuration'),
        };
    }

    private function message(Publication $publication): string
    {
        return $publication->message.($publication->link ? "\n\n".$publication->link : '');
    }

    private function multiImage(SocialAccount $account, Publication $publication): ?string
    {
        if (! in_array($account->provider, ['instagram', 'linkedin', 'x'], true)) {
            throw new FacebookFailure('platform_media');
        }
        $assets = [];
        $pending = [];
        foreach ($publication->card_images as $index => $card) {
            $bytes = Storage::disk('local')->get($card['path']);
            if ($account->provider === 'instagram') {
                $source = imagecreatefromstring($bytes);
                if (! $source) {
                    throw new FacebookFailure('platform_media');
                }
                ob_start();
                imagejpeg($source, null, 90);
                $jpeg = ob_get_clean();
                imagedestroy($source);
                $path = 'publishing/'.$publication->id.'-'.$index.'.jpg';
                if (! Storage::disk('local')->put($path, $jpeg)) {
                    throw new FacebookFailure('platform_media');
                }
                $cards = $publication->card_images;
                $cards[$index]['asset_path'] = $path;
                $publication->forceFill(['card_images' => $cards])->save();
                $url = URL::temporarySignedRoute('publishing.asset', now()->addHours(2), ['publication' => $publication->id, 'card' => $index]);
                if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
                    throw new FacebookFailure('platform_public_url');
                }
                $assets[] = $this->identifier($this->checked($this->request($account)->post($this->graph($account).'/'.$account->page_id.'/media', ['image_url' => $url, 'is_carousel_item' => true]))->json('id'));
            } elseif ($account->provider === 'linkedin') {
                $value = $this->checked($this->request($account)->post('https://api.linkedin.com/rest/images?action=initializeUpload', ['initializeUploadRequest' => ['owner' => $account->page_id]]))->json('value');
                $assets[] = $this->identifier($value['image'] ?? null, '/^urn:li:image:[a-zA-Z0-9_-]+$/D');
                $this->upload($account, $value['uploadUrl'] ?? '', $bytes, 'image/png', false);
            } else {
                $id = $this->identifier($this->checked($this->request($account)->post('https://api.x.com/2/media/upload/initialize', ['media_type' => 'image/png', 'total_bytes' => strlen($bytes), 'media_category' => 'tweet_image']))->json('data.id'));
                $this->checked($this->request($account)->attach('media', $bytes, 'card.png')->post('https://api.x.com/2/media/upload/'.$id.'/append', ['segment_index' => 0]));
                $data = $this->checked($this->request($account)->post('https://api.x.com/2/media/upload/'.$id.'/finalize'))->json('data', []);
                $assets[] = $id;
                if (isset($data['processing_info'])) {
                    $pending[] = $id;
                }
            }
        }
        $publication->forceFill(['transfer' => ['stage' => 'waiting', 'asset' => $assets[0], 'assets' => $assets, 'pending' => $pending, 'multi' => true], 'next_check_at' => now()->addMinute()])->save();
        if ($account->provider === 'x' && ! $pending) {
            return $this->xPost($account, $publication, $assets);
        }

        return null;
    }

    private function resumeImages(SocialAccount $account, Publication $publication): ?string
    {
        $assets = $publication->transfer['assets'];
        foreach ($assets as $asset) {
            if ($account->provider === 'instagram') {
                $state = $this->checked($this->request($account)->get($this->graph($account).'/'.$asset, ['fields' => 'status_code']))->json('status_code');
                $ready = $state === 'FINISHED';
            } elseif ($account->provider === 'linkedin') {
                $state = $this->checked($this->request($account)->get('https://api.linkedin.com/rest/images/'.rawurlencode($asset)))->json('status');
                $ready = $state === 'AVAILABLE';
            } else {
                if (! in_array($asset, $publication->transfer['pending'] ?? [], true)) {
                    continue;
                }
                $state = $this->checked($this->request($account)->get('https://api.x.com/2/media/upload', ['command' => 'STATUS', 'media_id' => $asset]))->json('data.processing_info.state');
                $ready = $state === 'succeeded';
            }
            if (in_array($state, ['ERROR', 'EXPIRED', 'PROCESSING_FAILED', 'FAILED', 'failed'], true)) {
                throw new FacebookFailure('platform_media');
            }
            if (! $ready) {
                $publication->forceFill(['next_check_at' => now()->addMinute()])->save();

                return null;
            }
        }
        if ($account->provider === 'instagram') {
            $parent = $this->identifier($this->checked($this->request($account)->post($this->graph($account).'/'.$account->page_id.'/media', ['media_type' => 'CAROUSEL', 'children' => implode(',', $assets), 'caption' => $this->message($publication)]))->json('id'));

            return $this->waiting($publication, $parent);
        }

        return $account->provider === 'linkedin' ? $this->linkedinPost($account, $publication, $assets) : $this->xPost($account, $publication, $assets);
    }

    private function waiting(Publication $publication, string $asset, int $delay = 60): ?string
    {
        $publication->forceFill(['transfer' => ['stage' => 'waiting', 'asset' => $asset], 'next_check_at' => now()->addSeconds(max(30, min(600, $delay)))])->save();

        return null;
    }

    private function submitting(Publication $publication): void
    {
        // Persist before the irreversible request. A worker never repeats this stage after a crash.
        $publication->forceFill(['transfer' => array_merge($publication->transfer ?? [], ['stage' => 'submitting']), 'next_check_at' => now()->addMinutes(15)])->save();
    }

    private function instagram(SocialAccount $account, Publication $publication): ?string
    {
        if ($publication->video_path) {
            $path = $publication->video_path;
        } else {
            $source = imagecreatefromstring(Storage::disk('local')->get($publication->image_path));
            if (! $source) {
                throw new FacebookFailure('platform_media');
            }
            $ratio = imagesx($source) / imagesy($source);
            if ($ratio < 0.8 || $ratio > 1.91) {
                imagedestroy($source);
                throw new FacebookFailure('platform_aspect');
            }
            $width = min(1440, imagesx($source));
            $canvas = imagecreatetruecolor($width, (int) round($width / $ratio));
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, imagesx($canvas), imagesy($canvas), imagesx($source), imagesy($source));
            ob_start();
            imagejpeg($canvas, null, 90);
            $jpeg = ob_get_clean();
            imagedestroy($canvas);
            imagedestroy($source);
            $path = 'publishing/'.$publication->id.'.jpg';
            Storage::disk('local')->put($path, $jpeg);
        }
        $publication->forceFill(['asset_path' => $path])->save();
        $url = URL::temporarySignedRoute('publishing.asset', now()->addHours(2), ['publication' => $publication->id]);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new FacebookFailure('platform_public_url');
        }
        $body = ['caption' => $this->message($publication)];
        $body += $publication->video_path ? ['media_type' => 'REELS', 'video_url' => $url, 'share_to_feed' => true] : ['image_url' => $url];
        $id = $this->identifier($this->checked($this->request($account)->post($this->graph($account).'/'.$account->page_id.'/media', $body))->json('id'));

        return $this->waiting($publication, $id);
    }

    private function linkedin(SocialAccount $account, Publication $publication): ?string
    {
        if (! $publication->image_path && ! $publication->video_path) {
            return $this->linkedinPost($account, $publication);
        }
        $video = (bool) $publication->video_path;
        $bytes = Storage::disk('local')->get($publication->video_path ?: $publication->image_path);
        $type = $video ? 'videos' : 'images';
        $body = ['owner' => $account->page_id];
        if ($video) {
            $body += ['fileSizeBytes' => strlen($bytes), 'uploadCaptions' => false, 'uploadThumbnail' => false];
        }
        $value = $this->checked($this->request($account)->post('https://api.linkedin.com/rest/'.$type.'?action=initializeUpload', ['initializeUploadRequest' => $body]))->json('value');
        $asset = $this->identifier($value[$video ? 'video' : 'image'] ?? null, '/^urn:li:(image|video):[a-zA-Z0-9_-]+$/D');
        if ($video) {
            $parts = $value['uploadInstructions'] ?? [];
            if (! is_array($parts) || count($parts) < 1 || count($parts) > 20) {
                throw new FacebookFailure('platform_media');
            }
            $ids = [];
            $offset = 0;
            foreach ($parts as $part) {
                $first = $part['firstByte'] ?? -1;
                $last = $part['lastByte'] ?? -1;
                if ($first !== $offset || $last < $first || $last >= strlen($bytes)) {
                    throw new FacebookFailure('platform_media');
                }
                $response = $this->upload($account, $part['uploadUrl'] ?? '', substr($bytes, $first, $last - $first + 1), 'application/octet-stream', false);
                $etag = trim($response->header('ETag'), '"');
                if ($etag === '') {
                    throw new FacebookFailure('platform_media');
                }
                $ids[] = $etag;
                $offset = $last + 1;
            }
            if ($offset !== strlen($bytes)) {
                throw new FacebookFailure('platform_media');
            }
            $this->checked($this->request($account)->post('https://api.linkedin.com/rest/videos?action=finalizeUpload', ['finalizeUploadRequest' => [
                'video' => $asset, 'uploadToken' => $value['uploadToken'] ?? '', 'uploadedPartIds' => $ids,
            ]]));
        } else {
            $this->upload($account, $value['uploadUrl'] ?? '', $bytes, 'image/png', false);
        }

        return $this->waiting($publication, $asset);
    }

    private function linkedinPost(SocialAccount $account, Publication $publication, string|array|null $asset = null): string
    {
        $body = ['author' => $publication->page_id, 'commentary' => $this->message($publication), 'visibility' => 'PUBLIC',
            'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []],
            'lifecycleState' => 'PUBLISHED', 'isReshareDisabledByAuthor' => false];
        if (is_array($asset)) {
            $body['content'] = ['multiImage' => ['images' => array_map(fn ($id) => ['id' => $id], $asset)]];
        } elseif ($asset) {
            $body['content'] = ['media' => ['id' => $asset, 'title' => $publication->title_snapshot]];
        }
        $this->submitting($publication);
        $response = $this->checked($this->request($account)->post('https://api.linkedin.com/rest/posts', $body), true);

        return $this->identifier($response->header('x-restli-id'), '/^urn:li:(share|ugcPost):[0-9]+$/D', true);
    }

    private function x(SocialAccount $account, Publication $publication): ?string
    {
        if (! $publication->video_path && ! $publication->image_path) {
            return $this->xPost($account, $publication);
        }
        $video = (bool) $publication->video_path;
        $bytes = Storage::disk('local')->get($publication->video_path ?: $publication->image_path);
        $id = $this->identifier($this->checked($this->request($account)->post('https://api.x.com/2/media/upload/initialize', [
            'media_type' => $video ? 'video/mp4' : 'image/png', 'total_bytes' => strlen($bytes), 'media_category' => $video ? 'tweet_video' : 'tweet_image',
        ]))->json('data.id'));
        foreach (str_split($bytes, 4 * 1024 * 1024) as $index => $chunk) {
            $this->checked($this->request($account)->attach('media', $chunk, 'chunk.bin')->post('https://api.x.com/2/media/upload/'.$id.'/append', ['segment_index' => $index]));
        }
        $data = $this->checked($this->request($account)->post('https://api.x.com/2/media/upload/'.$id.'/finalize'))->json('data', []);
        if (isset($data['processing_info'])) {
            return $this->waiting($publication, $id, (int) ($data['processing_info']['check_after_secs'] ?? 60));
        }

        return $this->xPost($account, $publication, $id);
    }

    private function xPost(SocialAccount $account, Publication $publication, string|array|null $asset = null): string
    {
        $body = ['text' => $this->message($publication)];
        if ($asset) {
            $body['media'] = ['media_ids' => is_array($asset) ? $asset : [$asset]];
        }
        $this->submitting($publication);

        return $this->identifier($this->checked($this->request($account)->post('https://api.x.com/2/tweets', $body), true)->json('data.id'), publishing: true);
    }

    private function youtube(SocialAccount $account, Publication $publication): string
    {
        $bytes = Storage::disk('local')->get($publication->video_path);
        $response = $this->checked($this->request($account)->withHeaders(['X-Upload-Content-Length' => strlen($bytes), 'X-Upload-Content-Type' => 'video/mp4'])
            ->post('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', [
                'snippet' => ['title' => $publication->title_snapshot, 'description' => $this->message($publication), 'categoryId' => '22'],
                'status' => ['privacyStatus' => $publication->options['privacy'], 'selfDeclaredMadeForKids' => (bool) $publication->options['made_for_kids'], 'containsSyntheticMedia' => true],
            ]));
        $url = $response->header('Location');
        $this->assertUploadUrl($url, 'youtube');
        $this->submitting($publication);
        $result = $this->upload($account, $url, $bytes, 'video/mp4', true);

        return $this->identifier($result->json('id'), '/^[a-zA-Z0-9_-]{11}$/D', true);
    }

    private function whatsapp(SocialAccount $account, Publication $publication): string
    {
        $options = $publication->options;
        $body = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $options['recipient']];
        if ($options['mode'] === 'template') {
            $template = ['name' => $options['template_name'], 'language' => ['code' => $options['template_language']]];
            if (filled($options['template_values'] ?? null)) {
                $template['components'] = [['type' => 'body', 'parameters' => array_map(fn ($text) => ['type' => 'text', 'text' => $text], preg_split('/\r?\n/', trim($options['template_values'])))]];
            }
            $body += ['type' => 'template', 'template' => $template];
        } elseif ($publication->video_path || $publication->image_path) {
            $video = (bool) $publication->video_path;
            $mime = $video ? 'video/mp4' : 'image/png';
            $bytes = Storage::disk('local')->get($publication->video_path ?: $publication->image_path);
            $media = $this->identifier($this->checked($this->request($account)->attach('file', $bytes, $video ? 'post.mp4' : 'post.png', ['Content-Type' => $mime])
                ->post($this->graph($account).'/'.$account->page_id.'/media', ['messaging_product' => 'whatsapp', 'type' => $mime]))->json('id'));
            $type = $video ? 'video' : 'image';
            $body += ['type' => $type, $type => ['id' => $media, 'caption' => $this->message($publication)]];
        } else {
            $body += ['type' => 'text', 'text' => ['body' => $this->message($publication), 'preview_url' => true]];
        }
        $this->submitting($publication);

        return $this->identifier($this->checked($this->request($account)->post($this->graph($account).'/'.$account->page_id.'/messages', $body), true)->json('messages.0.id'), '/^wamid\.[a-zA-Z0-9+\/=_.-]+$/D', true);
    }

    private function assertUploadUrl(string $url, string $provider): void
    {
        $parts = parse_url($url);
        $valid = is_array($parts) && ($parts['scheme'] ?? '') === 'https' && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['fragment'])
            && (! isset($parts['port']) || $parts['port'] === 443);
        $valid = $valid && match ($provider) {
            'linkedin' => ($parts['host'] ?? '') === 'www.linkedin.com' && str_starts_with($parts['path'] ?? '', '/dms-uploads/'),
            'youtube' => ($parts['host'] ?? '') === 'www.googleapis.com' && str_starts_with($parts['path'] ?? '', '/upload/youtube/v3/videos'),
            default => false,
        };
        if (! $valid) {
            throw new FacebookFailure('platform_upload_url');
        }
    }

    private function upload(SocialAccount $account, string $url, string $bytes, string $type, bool $publishing): Response
    {
        $this->assertUploadUrl($url, $account->provider);

        return $this->checked($this->request($account)->timeout(120)->withBody($bytes, $type)->put($url), $publishing);
    }

    public function resume(SocialAccount $account, Publication $publication): ?string
    {
        if ($publication->transfer['multi'] ?? false) {
            return $this->resumeImages($account, $publication);
        }
        $asset = $publication->transfer['asset'];
        if ($publication->provider === 'instagram') {
            $state = $this->checked($this->request($account)->get($this->graph($account).'/'.$asset, ['fields' => 'status_code']))->json('status_code');
            if (in_array($state, ['ERROR', 'EXPIRED'], true)) {
                throw new FacebookFailure('platform_media');
            }
            if ($state !== 'FINISHED') {
                return $this->waiting($publication, $asset);
            }
            $this->submitting($publication);

            return $this->identifier($this->checked($this->request($account)->post($this->graph($account).'/'.$publication->page_id.'/media_publish', ['creation_id' => $asset]), true)->json('id'), publishing: true);
        }
        if ($publication->provider === 'linkedin') {
            $type = $publication->video_path ? 'videos' : 'images';
            $state = $this->checked($this->request($account)->get('https://api.linkedin.com/rest/'.$type.'/'.rawurlencode($asset)))->json('status');
            if (in_array($state, ['PROCESSING_FAILED', 'FAILED'], true)) {
                throw new FacebookFailure('platform_media');
            }

            return $state === 'AVAILABLE' ? $this->linkedinPost($account, $publication, $asset) : $this->waiting($publication, $asset);
        }
        if ($publication->provider === 'x') {
            $info = $this->checked($this->request($account)->get('https://api.x.com/2/media/upload', ['command' => 'STATUS', 'media_id' => $asset]))->json('data.processing_info', []);
            if (($info['state'] ?? '') === 'failed') {
                throw new FacebookFailure('platform_media');
            }

            return ($info['state'] ?? '') === 'succeeded' ? $this->xPost($account, $publication, $asset) : $this->waiting($publication, $asset, (int) ($info['check_after_secs'] ?? 60));
        }
        throw new FacebookFailure('platform_configuration');
    }

    public function permalink(SocialAccount $account, string $id): ?string
    {
        try {
            if ($account->provider === 'facebook') {
                return $this->facebook->permalink($account, $id);
            }
            if ($account->provider === 'instagram') {
                $url = $this->checked($this->request($account)->get($this->graph($account).'/'.$id, ['fields' => 'permalink']))->json('permalink');

                return is_string($url) && preg_match('~^https://(?:www\.)?instagram\.com/(?:p|reel)/[a-zA-Z0-9_-]+/?$~D', $url) ? $url : null;
            }

            return match ($account->provider) {
                'linkedin' => 'https://www.linkedin.com/feed/update/'.rawurlencode($id).'/',
                'x' => 'https://x.com/i/web/status/'.$id,
                'youtube' => 'https://www.youtube.com/watch?v='.$id,
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }
}
