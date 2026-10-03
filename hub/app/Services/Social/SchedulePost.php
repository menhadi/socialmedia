<?php

namespace App\Services\Social;

use App\Models\AutomationRule;
use App\Models\ContentSource;
use App\Models\Post;
use App\Models\PostSchedule;
use App\Models\SocialAccount;
use App\Models\SourceSnapshot;
use App\Models\User;
use App\Services\Research\FetchSource;
use App\Services\Trends\PublishTrend;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SchedulePost
{
    public function __construct(private FetchSource $reader, private PublishPost $publisher) {}

    public function create(Post $post, int $accountId, \DateTimeInterface $when, bool $includeLink = true, ?SourceSnapshot $automaticSnapshot = null, array $options = []): PostSchedule
    {
        return DB::transaction(function () use ($post, $accountId, $when, $includeLink, $automaticSnapshot, $options): PostSchedule {
            $post = Post::whereKey($post->id)->lockForUpdate()->firstOrFail();
            $post->assertEditable();
            if ($post->status !== 'reviewed' || ! $post->reviewed_at || ! ChannelRules::supported($post->channel)) {
                throw ValidationException::withMessages(['schedule' => 'Review a draft before scheduling.']);
            }
            if ($post->schedules()->whereIn('status', ['queued', 'running', 'processing'])->exists()) {
                throw ValidationException::withMessages(['schedule' => 'This post already has an active schedule. Cancel it first.']);
            }
            $account = SocialAccount::whereKey($accountId)->where('brand_id', $post->brand_id)->where('provider', $post->channel)->first();
            if (! $account || ! $account->verified_at || ! $account->access_token) {
                throw ValidationException::withMessages(['schedule' => 'Select a verified account for this application.']);
            }
            if ($automaticSnapshot) {
                $source = ContentSource::whereKey($automaticSnapshot->content_source_id)->lockForUpdate()->firstOrFail();
                if (! $source->enabled || ! $source->auto_publish || ! $source->approved_at
                    || $source->version !== $automaticSnapshot->source_version || $automaticSnapshot->post_id !== $post->id) {
                    throw ValidationException::withMessages(['schedule' => 'Automatic source approval changed.']);
                }
            }

            $options = ChannelRules::options($post->channel, $options);
            ChannelRules::validate($post, $includeLink, $options, $when);

            return $post->schedules()->create([
                'options' => $options,
                'social_account_id' => $accountId, 'source_snapshot_id' => $automaticSnapshot?->id,
                'automatic' => $automaticSnapshot !== null, 'request_key' => (string) Str::uuid(),
                'fingerprint' => $post->publishingFingerprint(), 'credential_version' => $account->credential_version,
                'include_link' => $includeLink, 'scheduled_at' => $when,
            ]);
        }, 3);
    }

    public function run(PostSchedule $schedule): void
    {
        $claimed = DB::transaction(function () use ($schedule): bool {
            Post::whereKey($schedule->post_id)->lockForUpdate()->firstOrFail();

            return (bool) PostSchedule::whereKey($schedule->id)->where('status', 'queued')->where('scheduled_at', '<=', now())
                ->update(['status' => 'running', 'started_at' => now()]);
        }, 3);
        if (! $claimed) {
            return;
        }
        try {
            $schedule->refresh();
            $post = $schedule->post;
            AutomationRule::assertSchedule($schedule);
            app(PublishTrend::class)->assertSchedule($schedule);
            if ($post->archived_at) {
                throw new \RuntimeException('Post archived.');
            }
            if (! hash_equals($schedule->fingerprint, $post->publishingFingerprint())) {
                throw new \RuntimeException('The saved post changed. Review and schedule the current version.');
            }
            $account = SocialAccount::find($schedule->social_account_id);
            if (! $account || $account->credential_version !== $schedule->credential_version || ! $account->verified_at) {
                throw new \RuntimeException('The account connection changed. Verify it and create a new schedule.');
            }
            if ($schedule->automatic) {
                $snapshot = $schedule->snapshot;
                $source = $snapshot?->source;
                if (! $source || ! $source->enabled || ! $source->auto_publish || ! $source->approved_at
                    || $source->version !== $snapshot->source_version) {
                    throw new \RuntimeException('Automatic publishing is disabled or source settings changed.');
                }
                $text = $this->reader->fetch($snapshot->url, $source->element_id);
                $comparison = $snapshot->comparison_url ? $this->reader->fetch($snapshot->comparison_url) : '';
                if (! hash_equals($snapshot->hash, hash('sha256', $text."\n".$comparison))) {
                    throw new \RuntimeException('Source evidence changed since this draft was created. Review the latest source.');
                }
                $snapshot->update(['rechecked_at' => now()]);
            }
            $publication = $this->publisher->run(User::findOrFail($post->brand->user_id), $post, [
                'social_account_id' => $schedule->social_account_id, 'request_key' => $schedule->request_key,
                'fingerprint' => $schedule->fingerprint, 'include_link' => $schedule->include_link,
                'options' => $schedule->options ?? [], 'schedule_id' => $schedule->id, 'credential_version' => $schedule->credential_version,
            ]);
            $schedule->update(['status' => $publication->status === 'publishing' ? 'processing' : $publication->status,
                'reason' => $publication->status === 'published' ? null : 'Check publishing history. No automatic retry will be made.']);
            if ($post->trendRun !== null && $schedule->automation_rule_id) {
                $post->trendRun?->update(['status' => $schedule->status, 'reason' => $publication->status === 'published' ? (($post->trendRun->package['normal_fallback'] ?? false) ? 'Normal fallback published after content, account and audience-window checks.' : 'Published after platform freshness, website evidence and audience-window checks.') : 'Check publishing history. No automatic retry will be made.']);
            }
        } catch (\Throwable) {
            $schedule->update(['status' => 'blocked', 'reason' => 'Publishing held: content, source evidence, approval or account credentials changed, or a check failed. Review before scheduling again.']);
            if ($schedule->post->trendRun !== null && $schedule->automation_rule_id) {
                $schedule->post->trendRun?->update(['status' => 'held', 'reason' => $schedule->reason]);
            }
        }
    }
}
