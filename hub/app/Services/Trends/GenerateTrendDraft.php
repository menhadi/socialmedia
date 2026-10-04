<?php

namespace App\Services\Trends;

use App\Models\AutomationRule;
use App\Models\Brand;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\TrendRun;
use App\Models\User;
use App\Services\Ai\GenerateContent;
use App\Services\Research\FetchSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class GenerateTrendDraft
{
    public function __construct(private DiscoverTrends $discovery, private FetchSource $reader, private GenerateContent $ai) {}

    public function run(Brand $brand, ?AutomationRule $rule = null, bool $retryDiscovery = false): ?TrendRun
    {
        if ($rule && ($rule->options['workflow'] ?? '') === 'automatic' && in_array($rule->channel, ['facebook', 'x'], true)
            && in_array(strtolower(parse_url($brand->website ?? '', PHP_URL_HOST) ?? ''), ['pollmedia.org', 'www.pollmedia.org'], true)) {
            // This account's daily slot belongs to the shared historical chart video.
            return null;
        }
        $lock = Cache::lock('trend-brand-'.$brand->id, 600);
        if (! $lock->get()) {
            return null;
        }
        $run = null;
        $processing = false;
        try {
            $brand->refresh();
            $rule?->refresh();
            $settings = $rule ? ($rule->options['trend'] ?? []) : $brand->trend_settings;
            if ($rule ? ! $rule->enabled : ! ($settings['enabled'] ?? false)) {
                return null;
            }
            $date = now($settings['timezone'])->toDateString();
            $run = TrendRun::firstOrCreate(['brand_id' => $brand->id, 'run_date' => $date, 'scope_key' => $rule ? 'rule:'.$rule->id : 'website'], [
                'settings_version' => $settings['version'], 'automation_rule_id' => $rule?->id,
                'automation_version' => $rule?->version, 'social_account_id' => $rule?->social_account_id,
            ]);
            if (! $run->wasRecentlyCreated) {
                if (! $retryDiscovery || ! in_array($run->status, ['held', 'skipped'], true) || $run->post_id || $run->ai_generation_id || $run->updated_at->gt(now()->subMinute())) {
                    return $this->finish($run, $rule);
                }
                $run->forceFill(['status' => 'running', 'reason' => null, 'evidence' => null, 'package' => null,
                    'settings_version' => $settings['version'], 'automation_version' => $rule?->version, 'social_account_id' => $rule?->social_account_id])->save();
            }
            $processing = true;
            if ($brand->pyp_only && ! $brand->trend_posts_allowed) {
                throw new RuntimeException('This application permits only sourced previous-year questions. Trend drafts are held under that policy.');
            }
            if ($rule && ($rule->brand_id !== $brand->id || $rule->category !== 'trend')) {
                throw new RuntimeException('This trend rule belongs to a different application.');
            }
            $account = $rule ? SocialAccount::where('brand_id', $brand->id)->where('provider', $rule->channel)->findOrFail($rule->social_account_id) : null;
            $evidence = $account ? app(PlatformTrends::class)->run($account, $settings) : $this->discovery->run($settings);
            $run->update(['evidence' => $evidence]);
            if ($evidence['candidates'] === []) {
                $run->update(['status' => $evidence['errors'] ? 'held' : 'skipped', 'reason' => $evidence['errors'] ? 'No relevant fresh candidates were available; check the '.($account ? 'platform' : 'feed').' errors below.' : 'No relevant topic from the last 48 hours. No filler post was created.']);

                return $this->finish($run, $rule);
            }
            $pages = [];
            foreach ($settings['landing_pages'] as $url) {
                try {
                    if (strtolower($this->reader->validate($url)) !== strtolower(parse_url($brand->website ?? '', PHP_URL_HOST) ?? '')) {
                        throw new RuntimeException('Website hostname changed.');
                    }
                    $pages[] = ['url' => $url, 'text' => mb_strcut($this->reader->fetch($url), 0, 2500, 'UTF-8')];
                } catch (\Throwable) {
                    $evidence['errors'][] = 'Website page unavailable: '.$url;
                }
            }
            $evidence['pages'] = $pages;
            $run->update(['evidence' => $evidence]);
            if ($pages === []) {
                throw new RuntimeException('No configured website page could be read. Check the final public URLs.');
            }
            $recent = TrendRun::where('brand_id', $brand->id)->where('scope_key', $run->scope_key)->whereNotNull('post_id')->where('created_at', '>=', now()->subDays(30))->latest()->limit(30)->get()->map(fn (TrendRun $entry) => $entry->package['topic'] ?? '')->all();
            $generation = $this->ai->run(User::findOrFail($brand->user_id), $brand, [
                'request_key' => (string) Str::uuid(), 'task' => 'trend', 'title' => 'Daily relevant trend',
                'channel' => $settings['channel'], 'language' => $brand->language,
                'source_text' => json_encode(['candidates' => $evidence['candidates'], 'website_pages' => $pages, 'recent_topics' => $recent], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            $run->update(['ai_generation_id' => $generation->id]);
            if ($generation->status !== 'completed') {
                throw new RuntimeException('AI generation did not complete. Check provider history and budgets. No automatic retry was made.');
            }
            $package = json_decode($generation->result, true, 16, JSON_THROW_ON_ERROR);
            $run->update(['package' => $package]);
            if (! is_array($package) || ! is_array($package['concerns'] ?? null) || ! is_bool($package['skip'] ?? null) || ! is_string($package['reason'] ?? null)) {
                throw new RuntimeException('AI returned an incomplete trend package.');
            }
            if ($package['skip']) {
                $run->update(['status' => 'skipped', 'reason' => mb_substr($package['reason'], 0, 1000) ?: 'AI found no useful website-relevant angle.']);

                return $this->finish($run, $rule);
            }
            if (count($package['concerns']) > 0) {
                throw new RuntimeException('AI flagged concerns. Review the saved evidence and package; no draft was created.');
            }
            if (! is_int($package['candidate_index'] ?? null) || ! is_int($package['page_index'] ?? null)
                || ! is_int($package['relevance'] ?? null) || $package['relevance'] < 60 || $package['relevance'] > 100
                || ! is_string($package['caption'] ?? null) || trim($package['caption']) === '' || mb_strlen($package['caption']) > 10000
                || ! is_string($package['website_quote'] ?? null)) {
                throw new RuntimeException('The trend package lacks a useful relevant angle, caption or website evidence.');
            }
            $candidate = $evidence['candidates'][$package['candidate_index'] - 1] ?? null;
            $page = $pages[$package['page_index'] - 1] ?? null;
            $quote = FetchSource::normalize($package['website_quote']);
            if (! $candidate || ! $page || mb_strlen($quote) < 20 || ! str_contains($page['text'], $quote)) {
                throw new RuntimeException('The selected topic or website evidence could not be matched to retrieved content.');
            }
            $package['topic'] = $candidate['topic'];
            $package['landing_url'] = $page['url'];
            $package['signal'] = $candidate['signal'];
            $hash = hash('sha256', mb_strtolower(FetchSource::normalize($candidate['topic'])));
            DB::transaction(function () use ($brand, $settings, $run, $package, $candidate, $page, $hash, $generation, $rule, $quote): void {
                $current = Brand::whereKey($brand->id)->lockForUpdate()->firstOrFail();
                $currentRule = $rule ? AutomationRule::whereKey($rule->id)->lockForUpdate()->firstOrFail() : null;
                if (($rule ? (! $currentRule->enabled || $currentRule->version !== $rule->version) : (! ($current->trend_settings['enabled'] ?? false) || $current->trend_settings['version'] !== $settings['version'])) || ($current->pyp_only && ! $current->trend_posts_allowed)
                    || $current->only(['website', 'description', 'audience', 'language', 'instructions']) !== $brand->only(['website', 'description', 'audience', 'language', 'instructions'])) {
                    $run->update(['status' => 'held', 'reason' => 'Application settings changed during generation.']);

                    return;
                }
                if (TrendRun::where('brand_id', $brand->id)->where('scope_key', $run->scope_key)->where('topic_hash', $hash)->whereNotNull('post_id')->where('created_at', '>=', now()->subDays(30))->exists()) {
                    $run->update(['status' => 'duplicate', 'package' => $package, 'topic_hash' => $hash, 'reason' => 'This topic already has a draft or publication in the last 30 days.']);

                    return;
                }
                $caption = preg_replace('~https?://[^\s<>]+~u', '', $package['caption']);
                $automatic = $rule && ($rule->options['workflow'] ?? 'review') === 'automatic';
                $grounded = $automatic || ($brand->pyp_only && $brand->trend_posts_allowed);
                if ($grounded && mb_strlen($quote) > ($settings['channel'] === 'x' ? 140 : 1000)) {
                    throw new RuntimeException('Select a shorter self-contained website excerpt for this platform.');
                }
                $post = new Post;
                $post->forceFill([
                    'brand_id' => $brand->id, 'content_type' => 'trend', 'title' => $grounded ? mb_substr($quote, 0, 100) : $candidate['topic'],
                    'body' => ($grounded ? $quote : trim($caption))."\n\n".$page['url'], 'source_url' => $page['url'], 'channel' => $settings['channel'], 'status' => 'draft',
                ])->save();
                $run->update(['evidence' => $run->evidence + ['post_content_hash' => PublishTrend::contentHash($post)],
                    'expires_at' => CarbonImmutable::parse($candidate['published_at'])->addHours(48)->min(CarbonImmutable::now()->addHours(24))]);
                $run->update(['status' => 'review', 'post_id' => $post->id, 'topic_hash' => $hash, 'package' => $package,
                    'reason' => 'Draft ready. Verify the trend angle and factual claims before reviewing and scheduling. Feed headlines are discovery signals, not verified announcements.']);
                $generation->forceFill(['post_id' => $post->id])->save();
            }, 3);

            if ($rule && $run->fresh()->status === 'review') {
                app(PublishTrend::class)->queue($run->fresh(), $rule);
            }

            return $this->finish($run, $rule);
        } catch (\Throwable $error) {
            if ($run && $processing) {
                $reason = $error instanceof RuntimeException ? $error->getMessage() : 'Trend generation could not finish. Check feed, AI provider and budget settings. No automatic retry was made.';
                $run->update(['status' => 'held', 'reason' => mb_substr($reason, 0, 1000)]);
            }

            return $this->finish($run, $rule);
        } finally {
            $lock->release();
        }
    }

    private function finish(?TrendRun $run, ?AutomationRule $rule): ?TrendRun
    {
        if ($run && $rule && $run->fresh()->status === 'skipped') {
            app(NormalPostFallback::class)->run($run->fresh(), $rule);
        }

        return $run?->fresh();
    }
}
