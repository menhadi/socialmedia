<?php

namespace App\Services\Trends;

use App\Models\AutomationRule;
use App\Models\Post;
use App\Models\PostSchedule;
use App\Models\SocialAccount;
use App\Models\TrendRun;
use App\Models\User;
use App\Services\Media\GenerateMedia;
use App\Services\Research\FetchSource;
use App\Services\Research\PostImage;
use App\Services\Social\SchedulePost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PublishTrend
{
    public function __construct(private SchedulePost $scheduler, private TrendPostingTime $timing, private FetchSource $reader, private PlatformTrends $discovery) {}

    public static function contentHash(Post $post): string
    {
        return hash('sha256', json_encode([$post->brand_id, $post->channel, $post->title, $post->body, $post->source_url], JSON_THROW_ON_ERROR));
    }

    public function queue(TrendRun $run, AutomationRule $rule, bool $mediaReady = false): void
    {
        try {
            DB::transaction(function () use ($run, $rule, $mediaReady): void {
                $current = AutomationRule::whereKey($rule->id)->lockForUpdate()->firstOrFail();
                $run = TrendRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
                if (! in_array($run->status, ['review', 'media'], true)) {
                    return;
                }
                $post = Post::whereKey($run->post_id)->lockForUpdate()->firstOrFail();
                $account = SocialAccount::findOrFail($current->social_account_id);
                if (! $current->enabled || $current->category !== 'trend' || $current->version !== $run->automation_version
                    || $current->brand_id !== $post->brand_id || $current->channel !== $post->channel
                    || $account->brand_id !== $post->brand_id || $account->provider !== $post->channel
                    || $run->automation_rule_id !== $current->id || $run->social_account_id !== $account->id
                    || ! $account->verified_at || ! $account->access_token
                    || ! hash_equals($run->evidence['post_content_hash'] ?? '', self::contentHash($post))) {
                    throw new RuntimeException('Trend settings, account or saved content changed.');
                }
                $post->assertEditable();
                if (($current->options['workflow'] ?? 'review') !== 'automatic') {
                    return;
                }
                if (! $run->expires_at || $run->expires_at->isPast()) {
                    throw new RuntimeException('This trend has expired.');
                }
                $settings = $current->options['trend'];
                $slot = $this->timing->choose($account, $settings, $run->expires_at);
                if (! $mediaReady && ($current->options['media_kind'] ?? '') === 'video') {
                    $job = app(GenerateMedia::class)->reserve(User::findOrFail($post->brand->user_id), $post, [
                        'request_key' => (string) Str::uuid(), 'fingerprint' => $post->publishingFingerprint(),
                        'kind' => 'video', 'aspect_ratio' => '9:16',
                        'prompt' => 'Create a useful original visual explaining the saved website excerpt. Do not copy source-platform media or invent figures, events, dates, results or endorsements.',
                    ]);
                    $job->update(['automation_context' => ['rule_id' => $current->id, 'version' => $current->version, 'trend_run_id' => $run->id]]);
                    $run->update(['status' => 'media', 'reason' => 'Original video queued; audience time and trend freshness will be checked again when it finishes.']);

                    return;
                }
                if (! $mediaReady && ($current->with_image || $post->channel === 'instagram')) {
                    $post->forceFill(app(PostImage::class)->create($post))->save();
                }
                $post->forceFill(['status' => 'reviewed', 'reviewed_at' => now()])->save();
                $options = $post->channel === 'youtube' ? ['privacy' => 'public', 'made_for_kids' => (bool) ($current->options['made_for_kids'] ?? false)] : [];
                $schedule = $this->scheduler->create($post, $account->id, $slot['when'], false, options: $options);
                $schedule->update(['automation_rule_id' => $current->id, 'automation_version' => $current->version]);
                $when = $slot['when']->setTimezone($settings['timezone'])->format('d M H:i').' '.$settings['timezone'];
                $run->update(['status' => 'scheduled', 'reason' => 'Automatic publishing scheduled for '.$when.'. '.$slot['reason']]);
                $post->forceFill(['automation_reason' => $run->reason])->save();
            }, 3);
        } catch (\Throwable $error) {
            $reason = get_class($error) === RuntimeException::class ? $error->getMessage() : 'A publishing or media check failed. Check provider settings, evidence and destination requirements; no automatic retry was made.';
            $run->update(['status' => 'held', 'reason' => $reason]);
            $run->post?->forceFill(['status' => 'draft', 'reviewed_at' => null, 'automation_reason' => $reason])->save();
        }
    }

    public function assertSchedule(PostSchedule $schedule): void
    {
        $rule = $schedule->automation_rule_id ? AutomationRule::find($schedule->automation_rule_id) : null;
        if ($rule?->category !== 'trend') {
            return;
        }
        $run = $schedule->post->trendRun;
        $settings = $rule->options['trend'];
        $local = now($settings['timezone']);
        if (! $run || ! in_array($run->status, ['scheduled', 'processing'], true) || $run->automation_rule_id !== $rule->id || $run->automation_version !== $rule->version
            || ! $run->expires_at || $run->expires_at->isPast()
            || $local->format('H:i') < $settings['window_start'] || $local->format('H:i') > $settings['window_end']
            || ! hash_equals($run->evidence['post_content_hash'] ?? '', self::contentHash($schedule->post))) {
            throw new RuntimeException('Trend expired, posting window closed or saved content changed.');
        }
        if (($run->package['normal_fallback'] ?? false) === true && $schedule->post->content_type === 'standard') {
            $schedule->post->assertContentPolicy();

            return;
        }
        $account = SocialAccount::findOrFail($schedule->social_account_id);
        $brand = $schedule->post->brand;
        $url = $run->package['landing_url'];
        if (strtolower($this->reader->validate($url)) !== strtolower(parse_url($brand->website ?? '', PHP_URL_HOST) ?? '')
            || ! str_contains($this->reader->fetch($url), FetchSource::normalize($run->package['website_quote']))) {
            throw new RuntimeException('Website evidence is no longer available.');
        }
        $fresh = $this->discovery->run($account, $settings);
        $topic = mb_strtolower(FetchSource::normalize($run->package['topic']));
        if (! array_any($fresh['candidates'], fn (array $candidate) => mb_strtolower(FetchSource::normalize($candidate['topic'])) === $topic)) {
            throw new RuntimeException('The selected topic is no longer present in the platform discovery signals.');
        }
    }
}
