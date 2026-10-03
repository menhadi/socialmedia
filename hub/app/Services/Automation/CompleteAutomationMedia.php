<?php

namespace App\Services\Automation;

use App\Models\AutomationRule;
use App\Models\ContentItem;
use App\Models\MediaGeneration;
use App\Models\Post;
use App\Models\SourceSnapshot;
use App\Models\TrendRun;
use App\Services\Media\GenerateMedia;
use App\Services\Trends\PublishTrend;
use Illuminate\Support\Facades\DB;

class CompleteAutomationMedia
{
    public function run(MediaGeneration $job): void
    {
        try {
            DB::transaction(function () use ($job): void {
                $context = $job->automation_context;
                if (! $context) {
                    return;
                }
                $rule = AutomationRule::whereKey($context['rule_id'])->lockForUpdate()->first();
                $post = Post::whereKey($job->post_id)->lockForUpdate()->firstOrFail();
                $job = MediaGeneration::whereKey($job->id)->lockForUpdate()->firstOrFail();
                if (! $job->automation_context) {
                    return;
                }
                if (! $rule || ! $rule->enabled || $rule->version !== $context['version']
                    || ($rule->options['workflow'] ?? 'automatic') !== 'automatic'
                    || $rule->brand_id !== $post->brand_id || $rule->channel !== $post->channel
                    || $job->status !== 'completed') {
                    throw new \RuntimeException('Automation or generation changed.');
                }
                $snapshot = ($context['snapshot_id'] ?? null) ? SourceSnapshot::findOrFail($context['snapshot_id']) : null;
                app(GenerateMedia::class)->attach($job);
                if ($rule->category === 'trend') {
                    $run = TrendRun::findOrFail($context['trend_run_id']);
                    app(PublishTrend::class)->queue($run, $rule, mediaReady: true);
                } else {
                    app(RunAutomation::class)->queue($post->fresh(), $rule, $snapshot, mediaReady: true);
                }
                $job->update(['automation_context' => null]);
                ContentItem::where('post_id', $post->id)->update(['status' => 'scheduled', 'reason' => 'Generated media attached and scheduled after checks.']);
                $snapshot?->update(['status' => 'scheduled', 'reason' => 'Generated media scheduled with official source rechecks at delivery.']);
            }, 3);
        } catch (\Throwable) {
            $reason = 'Automatic media publishing held. Review the media, saved post, rule, source approval and provider settings before scheduling manually.';
            $job->update(['automation_context' => null]);
            Post::whereKey($job->post_id)->update(['automation_reason' => $reason]);
            ContentItem::where('post_id', $job->post_id)->update(['status' => 'held', 'reason' => $reason]);
            SourceSnapshot::where('post_id', $job->post_id)->update(['status' => 'review', 'reason' => $reason]);
            TrendRun::where('post_id', $job->post_id)->where('status', 'media')->update(['status' => 'held', 'reason' => $reason]);
        }
    }
}
