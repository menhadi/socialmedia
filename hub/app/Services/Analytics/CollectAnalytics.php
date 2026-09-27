<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsSnapshot;
use App\Models\Publication;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CollectAnalytics
{
    public function run(Publication $publication): void
    {
        $lock = Cache::lock('analytics:'.$publication->id, 45);
        if (! $lock->get()) {
            return;
        }
        try {
            $publication->refresh();
            if ($publication->remote_deleted_at || $publication->status !== 'published' || ! $publication->remote_post_id) {
                return;
            }
            if (AnalyticsSnapshot::where('publication_id', $publication->id)->where('created_at', '>', now()->subMinutes(30))->exists()) {
                return;
            }
            $account = $publication->account;
            $provider = $publication->provider;
            $id = rawurlencode($publication->remote_post_id);
            if (! $account || ! $account->access_token || ! $account->verified_at || $account->brand_id !== $publication->post->brand_id) {
                $this->save($publication, 'unavailable', [], 'Reconnect and verify the publishing account.');

                return;
            }
            if ($provider === 'whatsapp' || ($provider === 'linkedin' && ! str_starts_with($account->page_id, 'urn:li:organization:'))) {
                $this->save($publication, 'unsupported', [], 'Metrics are not available through this connector. WhatsApp delivery/read webhooks and LinkedIn personal-profile analytics are not connected.');

                return;
            }
            $http = Http::withToken($account->access_token)->acceptJson()->connectTimeout(5)->timeout(15)->withoutRedirecting();
            $graph = 'https://'.($provider === 'instagram' && ($account->settings['login_method'] ?? 'facebook') === 'instagram' ? 'graph.instagram.com' : 'graph.facebook.com').'/'.config('services.facebook.version');
            $response = match ($provider) {
                'facebook' => $http->get($graph.'/'.$id, ['fields' => 'id,reactions.limit(0).summary(true),comments.limit(0).summary(true),shares']),
                'instagram' => $http->get($graph.'/'.$id, ['fields' => 'id,like_count,comments_count']),
                'x' => $http->get('https://api.x.com/2/tweets/'.$id, ['tweet.fields' => 'public_metrics']),
                'youtube' => $http->get('https://www.googleapis.com/youtube/v3/videos', ['part' => 'statistics', 'id' => $publication->remote_post_id]),
                'linkedin' => $http->withHeaders(['LinkedIn-Version' => config('services.linkedin.version'), 'X-Restli-Protocol-Version' => '2.0.0'])->get('https://api.linkedin.com/rest/organizationalEntityShareStatistics', ['q' => 'organizationalEntity', 'organizationalEntity' => $account->page_id, str_starts_with($publication->remote_post_id, 'urn:li:ugcPost:') ? 'ugcPosts' : 'shares' => 'List('.$publication->remote_post_id.')']),
                default => null,
            };
            if (! $response || ! $response->successful() || $response->json('error') || $response->json('errors')) {
                $this->save($publication, 'unavailable', [], 'Could not read this post. It may be private, removed, rate limited, or missing API permission. No deletion or republishing was performed.');

                return;
            }
            $data = $response->json();
            $paths = match ($provider) {
                'facebook' => ['reactions' => 'reactions.summary.total_count', 'comments' => 'comments.summary.total_count', 'shares' => 'shares.count'],
                'instagram' => ['likes' => 'like_count', 'comments' => 'comments_count'],
                'x' => ['likes' => 'data.public_metrics.like_count', 'comments' => 'data.public_metrics.reply_count', 'shares' => 'data.public_metrics.retweet_count', 'quotes' => 'data.public_metrics.quote_count', 'impressions' => 'data.public_metrics.impression_count'],
                'youtube' => ['views' => 'items.0.statistics.viewCount', 'likes' => 'items.0.statistics.likeCount', 'comments' => 'items.0.statistics.commentCount'],
                'linkedin' => ['impressions' => 'elements.0.totalShareStatistics.impressionCount', 'clicks' => 'elements.0.totalShareStatistics.clickCount', 'likes' => 'elements.0.totalShareStatistics.likeCount', 'comments' => 'elements.0.totalShareStatistics.commentCount', 'shares' => 'elements.0.totalShareStatistics.shareCount'],
            };
            $metrics = [];
            foreach ($paths as $name => $path) {
                $value = data_get($data, $path);
                if (is_numeric($value) && $value >= 0) {
                    $metrics[$name] = (int) $value;
                }
            }
            $this->save($publication, $metrics ? 'available' : 'unavailable', $metrics, $metrics ? null : 'The API returned no supported metrics. Missing metrics are not zero.');
        } catch (\Throwable) {
            $this->save($publication, 'unavailable', [], 'Analytics request failed. Check account permissions and try after the refresh interval.');
        } finally {
            $lock->release();
        }
    }

    private function save(Publication $publication, string $status, array $metrics, ?string $reason): void
    {
        AnalyticsSnapshot::create(['publication_id' => $publication->id, 'status' => $status, 'metrics' => $metrics, 'reason' => $reason]);
    }
}
