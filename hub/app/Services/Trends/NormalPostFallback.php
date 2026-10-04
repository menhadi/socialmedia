<?php

namespace App\Services\Trends;

use App\Models\AutomationRule;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\TrendRun;
use App\Models\User;
use App\Services\Ai\GenerateContent;
use App\Services\Research\DailyWebsiteContent;
use App\Services\Research\DailyWebsiteWorkflow;
use App\Services\Research\FetchSource;
use App\Services\Research\PostImage;
use App\Services\Social\SchedulePost;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class NormalPostFallback
{
    public const CONTENT_VERSION = 3;

    public function run(TrendRun $run, AutomationRule $rule): void
    {
        $lock = Cache::lock('normal-fallback-run-'.$run->id, 600);
        if (! $lock->get()) {
            return;
        }
        try {
            $this->prepare($run->fresh(), $rule->fresh());
        } finally {
            $lock->release();
        }
    }

    private function prepare(TrendRun $run, AutomationRule $rule): void
    {
        if ($run->status !== 'skipped' || $run->post_id || ($run->evidence['fallback_content_version'] ?? 0) >= self::CONTENT_VERSION
            || ! $rule->enabled || ($rule->options['workflow'] ?? 'review') !== 'automatic') {
            return;
        }
        $run->update(['evidence' => array_merge($run->evidence ?? [], ['fallback_attempted_at' => now()->toIso8601String(), 'fallback_content_version' => self::CONTENT_VERSION])]);
        try {
            $brand = $run->brand;
            if (DailyWebsiteWorkflow::manages($brand)) {
                $run->update(['reason' => 'No relevant trend. The daily website rotation supplies this application’s normal posts.']);

                return;
            }
            $account = SocialAccount::findOrFail($rule->social_account_id);
            if (! $account->verified_at || ! $account->access_token) {
                throw new RuntimeException('Normal fallback needs a verified publishing account.');
            }
            $post = Post::where('brand_id', $brand->id)->where('channel', $rule->channel)
                ->where('content_type', 'standard')->where('status', 'reviewed')->whereNotNull('reviewed_at')
                ->whereNull('archived_at')->whereDoesntHave('publications')->whereDoesntHave('schedules')->oldest()->first();
            if (! $post) {
                $post = app(DailyWebsiteContent::class)->create($brand, $rule->channel);
            }
            if (! $post) {
                if ($brand->pyp_only) {
                    throw new RuntimeException('Normal fallback needs an unused reviewed, verified PYP question from the existing content workflow.');
                }
                $post = $this->websitePost($run, $rule);
            }
            DB::transaction(function () use ($run, $rule, $account, $post): void {
                $current = AutomationRule::whereKey($rule->id)->lockForUpdate()->firstOrFail();
                $post = Post::whereKey($post->id)->lockForUpdate()->firstOrFail();
                if (! $current->enabled || $current->version !== $rule->version || $post->publications()->exists() || $post->schedules()->exists()) {
                    throw new RuntimeException('Normal content or automation settings changed.');
                }
                if ($post->channel === 'youtube' && ! $post->video_path) {
                    throw new RuntimeException('Normal YouTube fallback needs a ready original video from the existing media workflow.');
                }
                if (($rule->with_image || $post->channel === 'instagram' || $post->visual) && ! $post->image_hash) {
                    $post->forceFill(app(PostImage::class)->create($post))->save();
                }
                $post->assertContentPolicy();
                $slot = app(TrendPostingTime::class)->choose($account, $rule->options['trend'], CarbonImmutable::now()->addDays(3));
                $options = $post->channel === 'youtube' ? ['privacy' => 'public', 'made_for_kids' => (bool) ($rule->options['made_for_kids'] ?? false)] : [];
                $schedule = app(SchedulePost::class)->create($post, $account->id, $slot['when'], true, options: $options);
                $schedule->update(['automation_rule_id' => $rule->id, 'automation_version' => $rule->version]);
                $run->update(['status' => 'scheduled', 'post_id' => $post->id, 'expires_at' => now()->addDays(3),
                    'package' => ['normal_fallback' => true], 'evidence' => $run->evidence + ['post_content_hash' => PublishTrend::contentHash($post)],
                    'reason' => 'No relevant trend: normal website content scheduled for '.$slot['when']->setTimezone($rule->options['trend']['timezone'])->format('d M H:i').' '.$rule->options['trend']['timezone'].'.']);
            }, 3);
        } catch (\Throwable $error) {
            $reason = $error instanceof ValidationException ? implode(' ', array_keys($error->errors())).': '.$error->getMessage()
                : ($error instanceof RuntimeException ? $error->getMessage() : 'Normal fallback held: '.class_basename($error).'.');
            $run->update(['evidence' => array_merge($run->evidence ?? [], ['fallback_reason' => $reason]), 'reason' => mb_substr($run->reason.' Normal fallback: '.$reason, 0, 1000)]);
        }
    }

    private function websitePost(TrendRun $run, AutomationRule $rule): Post
    {
        $reader = app(FetchSource::class);
        $brand = $run->brand;
        $pages = [];
        foreach ($rule->options['trend']['landing_pages'] as $url) {
            if (strtolower($reader->validate($url)) !== strtolower(parse_url($brand->website, PHP_URL_HOST))) {
                throw new RuntimeException('Normal fallback website hostname changed.');
            }
            $pages[] = ['url' => $url, 'text' => mb_strcut($reader->fetch($url), 0, 6000, 'UTF-8')];
        }
        $recent = Post::where('brand_id', $brand->id)->where('channel', $rule->channel)->where('created_at', '>=', now()->subDays(30))->latest()->limit(15)->pluck('body')->map(fn (string $body) => mb_substr($body, 0, 1000))->all();
        $generation = app(GenerateContent::class)->run(User::findOrFail($brand->user_id), $brand, [
            'request_key' => (string) Str::uuid(), 'task' => 'autopilot', 'title' => 'Normal website content, no trend claim',
            'channel' => $rule->channel, 'language' => $brand->language, 'normal_fallback' => true,
            'source_text' => json_encode(['approved_content' => $pages, 'recent_posts' => $recent,
                'selection_instruction' => 'Choose a useful self-contained exact excerpt from one website page, avoiding repeated recent posts. Do not claim a trend or news event. For X keep excerpt_quote under 200 characters.'], JSON_THROW_ON_ERROR),
        ]);
        $run->update(['evidence' => $run->evidence + ['fallback_generation_id' => $generation->id, 'fallback_pages' => $pages]]);
        $package = $generation->status === 'completed' ? json_decode($generation->result, true) : null;
        $quote = FetchSource::normalize(is_string($package['excerpt_quote'] ?? null) ? $package['excerpt_quote'] : '');
        if (! is_array($package) || ($package['concerns'] ?? null) !== [] || mb_strlen($quote) < 20 || mb_strlen($quote) > ($rule->channel === 'x' ? 200 : 3000)) {
            throw new RuntimeException('Normal website generation needs a valid exact excerpt with no AI concerns.');
        }
        $page = collect($pages)->first(fn (array $page) => str_contains($page['text'], $quote));
        if (! $page || array_any($recent, fn (string $body) => str_contains(FetchSource::normalize($body), $quote))) {
            throw new RuntimeException('Normal excerpt is unsupported or repeats recent content.');
        }
        $post = $brand->posts()->create(['title' => mb_substr($quote, 0, 100), 'body' => $quote,
            'source_url' => $page['url'], 'channel' => $rule->channel]);
        $post->forceFill(['content_type' => 'standard', 'status' => 'reviewed', 'reviewed_at' => now()])->save();
        $generation->forceFill(['post_id' => $post->id])->save();

        return $post;
    }
}
