<?php

namespace App\Services\Trends;

use App\Models\PostSchedule;
use App\Models\Publication;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use RuntimeException;

class TrendPostingTime
{
    /** @return array{when:CarbonImmutable,reason:string} */
    public function choose(SocialAccount $account, array $settings, CarbonImmutable $expires): array
    {
        $timezone = $settings['timezone'];
        $local = CarbonImmutable::now($timezone)->addMinutes(5);
        $preferred = $settings['preferred_time'];
        $reason = 'Configured audience time; no sufficient comparable engagement history yet.';
        $buckets = [];
        foreach (Publication::where('social_account_id', $account->id)->where('status', 'published')->whereNull('remote_deleted_at')->whereBetween('published_at', [now()->subDays(30), now()->subDay()])->with('latestAnalytics')->get() as $publication) {
            $analytics = $publication->latestAnalytics;
            if (! $analytics || $analytics->status !== 'available' || $analytics->created_at->lt(now()->subDays(2))) {
                continue;
            }
            $metrics = $analytics->metrics;
            $exposure = $metrics['impressions'] ?? $metrics['views'] ?? null;
            if (! is_numeric($exposure) || $exposure < 1) {
                continue;
            }
            $hour = $publication->published_at->setTimezone($timezone)->format('H:00');
            if ($hour < $settings['window_start'] || $hour > $settings['window_end']) {
                continue;
            }
            $engagement = ($metrics['reactions'] ?? $metrics['likes'] ?? 0) + ($metrics['comments'] ?? 0) + ($metrics['shares'] ?? 0);
            $buckets[$hour][] = $engagement / $exposure;
        }
        $scores = [];
        foreach ($buckets as $hour => $rates) {
            if (count($rates) >= 3) {
                $scores[$hour] = array_sum($rates) / count($rates);
            }
        }
        if (count($scores) >= 2) {
            arsort($scores);
            $preferred = array_key_first($scores);
            $reason = 'Tentative engagement-rate guidance from this account, within the configured audience window; not a guaranteed best time.';
        }
        for ($day = 0; $day < 3; $day++) {
            $date = $local->addDays($day)->startOfDay();
            $start = $date->setTimeFromTimeString($settings['window_start']);
            $end = $date->setTimeFromTimeString($settings['window_end']);
            $target = $date->setTimeFromTimeString($preferred)->max($start);
            if ($target->lt($local)) {
                $target = $local->ceilHour()->max($start);
            }
            if ($target->gt($end)) {
                continue;
            }
            $count = PostSchedule::where('social_account_id', $account->id)->whereHas('post', fn ($query) => $query->where('content_type', 'trend'))->whereNotIn('status', ['cancelled', 'blocked', 'failed'])->whereBetween('scheduled_at', [$date->utc(), $date->addDay()->utc()->subSecond()])->count();
            if ($count >= 1) {
                continue;
            }
            while ($target->lte($end)) {
                $nearby = PostSchedule::where('social_account_id', $account->id)->whereIn('status', ['queued', 'running', 'processing', 'published', 'uncertain'])->whereBetween('scheduled_at', [$target->subMinutes(59)->utc(), $target->addMinutes(59)->utc()])->exists()
                    || Publication::where('social_account_id', $account->id)->whereIn('status', ['published', 'publishing', 'uncertain'])->whereBetween('published_at', [$target->subMinutes(59)->utc(), $target->addMinutes(59)->utc()])->exists();
                if (! $nearby) {
                    if ($target->utc()->gt($expires)) {
                        throw new RuntimeException('This trend would expire before the next suitable audience posting time.');
                    }

                    return ['when' => $target->utc(), 'reason' => $reason];
                }
                $target = $target->addHour();
            }
        }

        throw new RuntimeException('No suitable posting time before this trend expires.');
    }
}
