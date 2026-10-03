<?php

namespace App\Services\Trends;

use App\Models\SocialAccount;
use App\Services\Research\FetchSource;
use App\Services\Social\XToken;
use App\Services\Social\YouTubeToken;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PlatformTrends
{
    public function run(SocialAccount $account, array $settings): array
    {
        try {
            if (! $account->verified_at || ! $account->access_token) {
                throw new RuntimeException('Verify this account and save its publishing credentials first.');
            }
            $candidates = match ($account->provider) {
                'x' => $this->x($account, $settings),
                'youtube' => $this->youtube($account, $settings),
                'instagram' => $this->instagram($account, $settings),
                'facebook' => $this->facebook($account),
                default => throw new RuntimeException('This platform has no supported public trend-discovery endpoint in this connector. A platform-specific discovery integration is required.'),
            };
            $keywords = array_filter(array_map('trim', explode(',', $settings['keywords'])));
            $seen = [];
            $candidates = array_values(array_filter($candidates, function (array $candidate) use ($keywords, &$seen): bool {
                $key = mb_strtolower(FetchSource::normalize($candidate['topic']));
                if (isset($seen[$key])) {
                    return false;
                }
                if (! array_any($keywords, fn (string $word) => str_contains(mb_strtolower($candidate['topic'].' '.$candidate['summary']), mb_strtolower($word)))) {
                    return false;
                }
                $seen[$key] = true;

                return true;
            }));

            return ['candidates' => array_slice($candidates, 0, 15), 'errors' => [], 'platform' => $account->provider, 'retrieved_at' => now()->toIso8601String()];
        } catch (\Throwable $error) {
            $reason = get_class($error) === RuntimeException::class ? $error->getMessage() : 'Platform discovery failed. Check trend-read permissions, token validity, API credits and request limits. No Google fallback was used.';

            return ['candidates' => [], 'errors' => [$reason], 'platform' => $account->provider, 'retrieved_at' => now()->toIso8601String()];
        }
    }

    private function request(SocialAccount $account): PendingRequest
    {
        $token = match ($account->provider) {
            'x' => app(XToken::class)->accessToken($account),
            'youtube' => app(YouTubeToken::class)->accessToken($account),
            default => $account->access_token,
        };

        return Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(20)->withoutRedirecting();
    }

    private function read(SocialAccount $account, string $url, array $query): array
    {
        $response = $this->request($account)->get($url, $query);
        if (! $response->successful() || $response->json('error') || $response->json('errors') || ! is_array($response->json())) {
            throw new RuntimeException('Platform discovery unavailable (HTTP '.$response->status().'). Check read permissions, credentials and API credits. No Google fallback was used.');
        }

        return $response->json();
    }

    private function graph(SocialAccount $account): string
    {
        $host = $account->provider === 'instagram' && ($account->settings['login_method'] ?? 'facebook') === 'instagram' ? 'graph.instagram.com' : 'graph.facebook.com';
        $version = config('services.facebook.version');
        if (! preg_match('/^v[0-9]{2}\\.0$/D', $version ?? '')) {
            throw new RuntimeException('Configure a supported Meta API version.');
        }

        return 'https://'.$host.'/'.$version;
    }

    private function candidate(string $topic, string $url, string $summary, string $signal, string $date, array $metrics = []): ?array
    {
        if (! $topic || ! trim($date) || ! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)) {
            return null;
        }
        try {
            $published = CarbonImmutable::parse($date)->utc();
        } catch (\Throwable) {
            return null;
        }
        if ($published->lt(now('UTC')->subHours(48)) || $published->gt(now('UTC')->addMinutes(5))) {
            return null;
        }

        return ['topic' => mb_substr(FetchSource::normalize($topic), 0, 200), 'url' => $url,
            'summary' => mb_substr(FetchSource::normalize($summary), 0, 700), 'signal' => $signal,
            'published_at' => $published->toIso8601String(), 'traffic' => '', 'metrics' => $metrics, 'feed_url' => $url];
    }

    private function x(SocialAccount $account, array $settings): array
    {
        $woeid = (int) ($settings['woeid'] ?? 23424848);
        $data = $this->read($account, 'https://api.x.com/2/trends/by/woeid/'.$woeid, ['max_trends' => 50, 'trend.fields' => 'trend_name,tweet_count']);
        $result = [];
        foreach ($data['data'] ?? [] as $trend) {
            if (! is_string($trend['trend_name'] ?? null)) {
                continue;
            }
            $candidate = $this->candidate($trend['trend_name'], 'https://x.com/search?q='.rawurlencode($trend['trend_name']), '', 'X location trend', now()->toIso8601String(), ['posts' => $trend['tweet_count'] ?? null]);
            if ($candidate) {
                $result[] = $candidate;
            }
        }

        return $result;
    }

    private function youtube(SocialAccount $account, array $settings): array
    {
        $words = array_slice(array_filter(array_map('trim', explode(',', $settings['keywords']))), 0, 5);
        $search = $this->read($account, 'https://www.googleapis.com/youtube/v3/search', [
            'part' => 'snippet', 'type' => 'video', 'q' => implode('|', $words), 'order' => 'viewCount',
            'publishedAfter' => now('UTC')->subHours(48)->toIso8601String(), 'regionCode' => $settings['region'], 'maxResults' => 20,
        ]);
        $ids = array_filter(array_map(fn (array $item) => data_get($item, 'id.videoId'), $search['items'] ?? []));
        if (! $ids) {
            return [];
        }
        $data = $this->read($account, 'https://www.googleapis.com/youtube/v3/videos', ['part' => 'snippet,statistics', 'id' => implode(',', $ids)]);
        $result = [];
        foreach ($data['items'] ?? [] as $video) {
            $views = (int) data_get($video, 'statistics.viewCount', 0);
            if ($views < (int) ($settings['minimum_views'] ?? 100)) {
                continue;
            }
            $candidate = $this->candidate(data_get($video, 'snippet.title', ''), 'https://www.youtube.com/watch?v='.$video['id'], data_get($video, 'snippet.description', ''), 'YouTube recent niche video, ranked by reported views', data_get($video, 'snippet.publishedAt', ''), ['views' => $views]);
            if ($candidate) {
                $result[] = $candidate;
            }
        }

        return $result;
    }

    private function instagram(SocialAccount $account, array $settings): array
    {
        if (($account->settings['login_method'] ?? 'facebook') === 'instagram') {
            throw new RuntimeException('Instagram hashtag discovery needs Facebook Login with approved public-content permissions. This account uses Instagram Login.');
        }
        $tags = array_slice(array_filter(array_map(fn (string $tag) => ltrim(trim($tag), '#'), explode(',', $settings['hashtags'] ?? ''))), 0, 3);
        if (! $tags) {
            throw new RuntimeException('Configure up to three relevant Instagram hashtags for discovery.');
        }
        $result = [];
        foreach ($tags as $tag) {
            $search = $this->read($account, $this->graph($account).'/ig_hashtag_search', ['user_id' => $account->page_id, 'q' => $tag]);
            $id = data_get($search, 'data.0.id');
            if (! is_string($id) || ! ctype_digit($id)) {
                continue;
            }
            $data = $this->read($account, $this->graph($account).'/'.$id.'/recent_media', ['user_id' => $account->page_id, 'fields' => 'id,caption,permalink,like_count,comments_count', 'limit' => 20]);
            foreach ($data['data'] ?? [] as $media) {
                $caption = $media['caption'] ?? '';
                $engagement = (int) ($media['like_count'] ?? 0) + (int) ($media['comments_count'] ?? 0);
                if ($engagement < 1) {
                    continue;
                }
                $candidate = $this->candidate('#'.$tag.' '.mb_substr($caption, 0, 160), $media['permalink'] ?? '', $caption, 'Instagram recent hashtag media ranked by reported engagement (not a global trend chart)', now()->subDay()->toIso8601String(), ['likes' => $media['like_count'] ?? null, 'comments' => $media['comments_count'] ?? null]);
                if ($candidate) {
                    $candidate['time_basis'] = 'Conservative earliest time in the recent-media endpoint 24-hour window; exact publication timestamp unavailable.';
                    $result[] = $candidate;
                }
            }
        }

        usort($result, fn (array $a, array $b) => (($b['metrics']['likes'] ?? 0) + ($b['metrics']['comments'] ?? 0)) <=> (($a['metrics']['likes'] ?? 0) + ($a['metrics']['comments'] ?? 0)));

        return $result;
    }

    private function facebook(SocialAccount $account): array
    {
        $data = $this->read($account, $this->graph($account).'/'.$account->page_id.'/posts', [
            'fields' => 'message,created_time,permalink_url,reactions.limit(0).summary(true),comments.limit(0).summary(true),shares',
            'since' => now('UTC')->subHours(48)->timestamp, 'limit' => 30,
        ]);
        $result = [];
        foreach ($data['data'] ?? [] as $post) {
            $engagement = (int) data_get($post, 'reactions.summary.total_count', 0) + (int) data_get($post, 'comments.summary.total_count', 0) + (int) data_get($post, 'shares.count', 0);
            if ($engagement < 1 || empty($post['message'])) {
                continue;
            }
            $candidate = $this->candidate($post['message'], $post['permalink_url'] ?? '', $post['message'], 'Facebook Page audience activity (not a Facebook-wide trend)', $post['created_time'] ?? '', ['engagement' => $engagement]);
            if ($candidate) {
                $result[] = $candidate;
            }
        }
        usort($result, fn (array $a, array $b) => $b['metrics']['engagement'] <=> $a['metrics']['engagement']);

        return $result;
    }
}
