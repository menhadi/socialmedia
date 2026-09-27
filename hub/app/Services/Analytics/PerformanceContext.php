<?php

namespace App\Services\Analytics;

use App\Models\Brand;
use App\Models\Publication;

class PerformanceContext
{
    public function build(Brand $brand, string $channel): array
    {
        $examples = [];
        foreach (Publication::where('provider', $channel)->where('status', 'published')->where('published_at', '>=', now()->subDays(30))->where('published_at', '<=', now()->subDay())->whereHas('post', fn ($q) => $q->where('brand_id', $brand->id))->with('latestAnalytics')->latest('published_at')->limit(30)->get() as $publication) {
            $snapshot = $publication->latestAnalytics;
            if (! $snapshot || $snapshot->status !== 'available' || $snapshot->created_at->lt(now()->subDays(2))) {
                continue;
            }
            $examples[] = ['publication_id' => $publication->id, 'age_days' => $publication->published_at->diffInDays(now()), 'text' => mb_substr($publication->message, 0, 500), 'metrics' => $snapshot->metrics];
        }
        if (count($examples) < 3) {
            return ['note' => 'Not enough recent comparable data: need at least 3 published posts with fresh metrics on this application and platform.', 'examples' => []];
        }

        return ['note' => 'Performance context from '.count($examples).' posts on this application and platform. Lifetime counts; different post ages and exposure are not controlled. Treat patterns as tentative, not causal. Missing metrics are unknown.', 'examples' => $examples];
    }
}
