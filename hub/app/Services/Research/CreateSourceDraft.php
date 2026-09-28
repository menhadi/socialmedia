<?php

namespace App\Services\Research;

use App\Models\AutomationRule;
use App\Models\ContentSource;
use App\Models\Post;
use App\Models\SourceSnapshot;
use App\Models\User;
use App\Services\Ai\GenerateContent;
use App\Services\Analytics\PerformanceContext;
use App\Services\Automation\RunAutomation;
use App\Services\Media\GenerateMedia;
use App\Services\Social\SchedulePost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateSourceDraft
{
    public function __construct(private GenerateContent $ai, private PostImage $images, private SchedulePost $scheduler) {}

    public function run(SourceSnapshot $snapshot, bool $baseline): void
    {
        if (! SourceSnapshot::whereKey($snapshot->id)->where('status', 'captured')->update(['status' => 'generating'])) {
            return;
        }
        $source = $snapshot->source;
        $automationRule = AutomationRule::where('brand_id', $source->brand_id)->where('channel', $source->channel)->where('category', 'official')->first();
        try {
            $generation = $this->ai->run(User::findOrFail($source->brand->user_id), $source->brand, [
                'request_key' => (string) Str::uuid(), 'task' => 'research', 'title' => $source->topic,
                'channel' => $source->channel, 'language' => $source->brand->language, 'source_url' => $snapshot->url,
                'source_text' => json_encode([
                    'primary_source' => mb_strcut($snapshot->text, 0, 14000, 'UTF-8'),
                    'comparison_source' => mb_strcut($snapshot->comparison_text ?? '', 0, 8000, 'UTF-8'),
                    'performance_context' => $automationRule?->learn ? mb_strcut(json_encode(app(PerformanceContext::class)->build($source->brand, $source->channel)), 0, 4000, 'UTF-8') : null,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            $snapshot->update(['ai_generation_id' => $generation->id]);
            if ($generation->status !== 'completed') {
                throw new \RuntimeException('AI generation did not complete. Check AI providers and generation history; no automatic retry was made.');
            }
            $package = json_decode($generation->result, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($package) || ! is_string($package['headline_quote'] ?? null) || ! is_string($package['excerpt_quote'] ?? null)
                || ! is_string($package['caption'] ?? null) || ! is_string($package['date_text'] ?? null)
                || ! is_array($package['concerns'] ?? null) || ! is_array($package['hashtags'] ?? null)
                || mb_strlen($package['caption']) > 10000 || mb_strlen($package['headline_quote']) > 160
                || mb_strlen($package['excerpt_quote']) > 1000) {
                throw new \RuntimeException('AI returned an incomplete post package. This source needs review.');
            }
            $headline = FetchSource::normalize($package['headline_quote']);
            $excerpt = FetchSource::normalize($package['excerpt_quote']);
            $quotesMatch = mb_strlen($headline) >= 10 && mb_strlen($excerpt) >= 40
                && str_contains($snapshot->text, $headline) && str_contains($snapshot->text, $excerpt);
            $reason = match (true) {
                $automationRule && (! $automationRule->enabled || $automationRule->social_account_id !== $source->social_account_id) => 'The official automation rule is disabled or selects a different account.',
                $baseline && ! $automationRule => 'Initial source capture: review this first post. Future source changes can use automatic publishing.',
                ! $source->approved_at => 'This source has not been approved as official.',
                ! $quotesMatch => 'The selected quotes could not be matched exactly to the source.',
                count($package['concerns']) > 0 => 'AI flagged missing, conflicting or unclear information. Check the source evidence.',
                ! $this->recentDate($package['date_text'], $snapshot->text) => 'A recent, unambiguous notice date could not be verified in the source.',
                $snapshot->comparison_text && (! str_contains($snapshot->comparison_text, $headline) || ! str_contains($snapshot->comparison_text, $excerpt)) => 'The comparison page does not contain the same headline and excerpt. Review the differences.',
                default => null,
            };
            $eventHash = hash('sha256', $headline."\n".$excerpt);
            if (SourceSnapshot::where('content_source_id', $source->id)->where('id', '!=', $snapshot->id)->where('event_hash', $eventHash)->whereNotNull('post_id')->exists()) {
                $snapshot->update(['status' => 'duplicate', 'reason' => 'This announcement already has a draft or publication.', 'package' => $package, 'event_hash' => $eventHash]);

                return;
            }
            $automatic = $reason === null && $source->enabled && $source->auto_publish && (in_array($source->channel, ['facebook', 'instagram', 'linkedin', 'x'], true) || ($automationRule && $source->channel === 'youtube'));
            $hashtags = array_filter($package['hashtags'], fn ($tag) => is_string($tag) && preg_match('/^#[\p{L}\p{N}_]{1,40}$/uD', $tag));
            $body = $automatic ? $headline."\n\n".$excerpt."\n\nSource: ".$snapshot->url
                : trim($package['caption'])."\n\n".implode(' ', array_slice($hashtags, 0, 5))."\n\nSource: ".$snapshot->url;
            $post = DB::transaction(function () use ($source, $snapshot, $package, $headline, $body, $eventHash, $reason): ?Post {
                $current = ContentSource::whereKey($source->id)->lockForUpdate()->firstOrFail();
                if ($current->version !== $snapshot->source_version) {
                    $snapshot->update(['status' => 'held', 'reason' => 'Source settings changed during research.']);

                    return null;
                }
                $post = $source->brand->posts()->create([
                    'title' => $headline ?: $source->topic, 'body' => $body, 'source_url' => $snapshot->url, 'channel' => $source->channel,
                ]);
                $snapshot->update(['post_id' => $post->id, 'package' => $package, 'event_hash' => $eventHash,
                    'status' => 'review', 'reason' => $reason ?? 'Draft ready for review.']);

                return $post;
            });
            if (! $post) {
                return;
            }
            $generation->post_id = $post->id;
            $generation->save();
            $draftFingerprint = $post->publishingFingerprint();
            if ($automatic && $automationRule && ($automationRule->options['media_kind'] ?? 'none') !== 'none') {
                app(RunAutomation::class)->queue($post, $automationRule, $snapshot);
                $snapshot->update(['reason' => $post->fresh()->automation_reason]);

                return;
            }
            if (in_array($source->media_kind, ['image', 'video'], true)) {
                $media = app(GenerateMedia::class)->reserve(User::findOrFail($source->brand->user_id), $post, [
                    'request_key' => (string) Str::uuid(), 'fingerprint' => $draftFingerprint,
                    'kind' => $source->media_kind, 'aspect_ratio' => '16:9',
                    'prompt' => 'Create a professional illustrative visual supporting the saved post. Do not invent facts, official seals, exam dates or results. Avoid small text.',
                ]);
                if ($automatic && $automationRule && ($automationRule->options['workflow'] ?? 'automatic') === 'automatic') {
                    $media->update(['automation_context' => ['rule_id' => $automationRule->id, 'version' => $automationRule->version, 'snapshot_id' => $snapshot->id]]);
                    $snapshot->update(['reason' => 'Media queued for automatic attachment and scheduling after checks.']);

                    return;
                }
                $snapshot->update(['reason' => 'AI media queued. Open the draft’s AI media page to review and attach it, then review and schedule the post.']);

                return;
            }
            $image = [];
            if ($source->with_image) {
                try {
                    $image = $this->images->create($post);
                } catch (\Throwable) {
                    $snapshot->update(['reason' => 'Draft saved. Image creation failed; automatic scheduling was held. Check the image renderer.']);

                    return;
                }
            }
            DB::transaction(function () use ($post, $draftFingerprint, $image, $automatic, $source, $snapshot, $automationRule): void {
                $currentPost = Post::whereKey($post->id)->lockForUpdate()->firstOrFail();
                if (! hash_equals($draftFingerprint, $currentPost->publishingFingerprint()) || $currentPost->status !== 'draft') {
                    $snapshot->update(['reason' => 'The post was edited during generation. Review the saved version.']);

                    return;
                }
                $currentPost->forceFill($image)->save();
                if ($automatic) {
                    $currentPost->forceFill(['status' => 'reviewed', 'reviewed_at' => now()])->save();
                    if ($automationRule) {
                        if (! app(RunAutomation::class)->queue($currentPost, $automationRule, $snapshot)) {
                            $snapshot->update(['reason' => $currentPost->fresh()->automation_reason]);

                            return;
                        }
                    } else {
                        $this->scheduler->create($currentPost, (int) $source->social_account_id, now()->addMinutes($source->delay_minutes), true, $snapshot);
                    }
                    $snapshot->update(['status' => 'scheduled', 'reason' => 'Exact official-source excerpts scheduled under this source’s automatic publishing setting.']);
                }
            }, 3);
        } catch (\Throwable) {
            $snapshot->refresh();
            $snapshot->update(['status' => $snapshot->post_id ? 'review' : 'held',
                'reason' => 'The workflow could not complete. Check the source, AI budget/provider settings and destination Page. No publishing retry was made.']);
        }
    }

    private function recentDate(string $date, string $text): bool
    {
        if ($date === '' || ! str_contains($text, $date)) {
            return false;
        }
        foreach (['!Y-m-d', '!d/m/Y', '!d-m-Y', '!j F Y', '!j M Y', '!F j, Y', '!F j Y'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $date, new \DateTimeZone('UTC'));
            $errors = \DateTimeImmutable::getLastErrors();
            if ($parsed && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $parsed >= now('UTC')->subDays(7)->startOfDay() && $parsed <= now('UTC')->endOfDay()) {
                return true;
            }
        }

        return false;
    }
}
