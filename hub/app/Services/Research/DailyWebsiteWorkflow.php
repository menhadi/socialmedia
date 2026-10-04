<?php

namespace App\Services\Research;

use App\Models\AutomationRule;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostSchedule;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\TrendRun;
use App\Models\WebsiteDailyBatch;
use App\Models\WebsiteDailyRotation;
use App\Services\Social\SchedulePost;
use App\Services\Trends\PublishTrend;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class DailyWebsiteWorkflow
{
    public function __construct(private WebsiteRotation $rotation, private PostImage $images, private SchedulePost $scheduler) {}

    public static function requestRetry(Brand $brand): void
    {
        Cache::put('website-daily-retry:'.$brand->id, true, now()->addDay());
    }

    public static function manages(Brand $brand): bool
    {
        return in_array(strtolower(parse_url($brand->website ?? '', PHP_URL_HOST) ?? ''), ['examelite.com', 'www.examelite.com', 'pollmedia.org', 'www.pollmedia.org'], true);
    }

    public function slots(array $settings, int $count): array
    {
        $local = CarbonImmutable::now($settings['timezone']);
        $day = $local->toDateString();
        $start = CarbonImmutable::parse($day.' '.$settings['window_start'], $settings['timezone'])->addHour();
        $start = $start->max($local->addMinutes(5))->max(CarbonImmutable::parse($day.' '.$settings['daily_time'], $settings['timezone']));
        $end = CarbonImmutable::parse($day.' '.$settings['window_end'], $settings['timezone']);
        if ($start->gt($end)) {
            return [];
        }
        $fit = min($count, (int) floor($start->diffInMinutes($end) / 60) + 1);
        $preferred = CarbonImmutable::parse($day.' '.$settings['preferred_time'], $settings['timezone']);
        $last = $preferred->max($start->addHours($fit - 1))->min($end);
        $interval = $fit > 1 ? (int) floor($start->diffInMinutes($last) / ($fit - 1)) : 0;
        $slots = [];
        for ($i = 0; $i < $fit; $i++) {
            $slots[] = ($fit === 1 ? $preferred->max($start)->min($end) : $start->addMinutes($interval * $i))->utc();
        }

        return $slots;
    }

    public function run(Brand $brand, bool $retry = false): void
    {
        if (! self::manages($brand)) {
            return;
        }
        $lock = Cache::lock('website-daily-brand-'.$brand->id, 900);
        if (! $lock->get()) {
            return;
        }
        try {
            $retry = (bool) Cache::pull('website-daily-retry:'.$brand->id, false) || $retry;
            $rules = AutomationRule::where('brand_id', $brand->id)->where('category', 'trend')->where('enabled', true)->get()
                ->filter(fn ($rule) => ($rule->options['workflow'] ?? '') === 'automatic'
                    && in_array($rule->channel, ['facebook', 'instagram', 'linkedin', 'x'], true)
                    && isset($rule->options['trend'])
                    && now($rule->options['trend']['timezone'])->format('H:i') >= $rule->options['trend']['daily_time']
                    && now($rule->options['trend']['timezone'])->format('H:i') <= $rule->options['trend']['window_end']);
            $rules = $rules->filter(function ($rule) use ($brand): bool {
                $account = SocialAccount::find($rule->social_account_id);

                return $account && $account->brand_id === $brand->id && $account->provider === $rule->channel && $account->verified_at && $account->access_token;
            });
            if ($rules->isEmpty()) {
                return;
            }
            try {
                $sourceKey = 'website-daily-source-failure:'.$brand->id.':'.now('Asia/Kolkata')->toDateString();
                if (! $retry && Cache::has($sourceKey)) {
                    return;
                }
                $batch = $this->batch($brand);
                Cache::forget($sourceKey);
            } catch (\Throwable $error) {
                Cache::put($sourceKey, true, now()->addMinutes(15));
                foreach ($rules as $rule) {
                    TrendRun::updateOrCreate(['brand_id' => $brand->id, 'scope_key' => 'website-daily:'.$rule->id.':source', 'run_date' => now('Asia/Kolkata')->toDateString()],
                        ['status' => 'held', 'settings_version' => $rule->options['trend']['version'] ?? (string) Str::uuid(), 'automation_rule_id' => $rule->id, 'automation_version' => $rule->version,
                            'social_account_id' => $rule->social_account_id, 'reason' => $this->reason($error)]);
                }

                return;
            }
            foreach ($rules as $rule) {
                $slots = $this->slots($rule->options['trend'], count($batch->payloads['posts']));
                foreach ($batch->payloads['posts'] as $i => $package) {
                    $key = $rule->id.':'.$i;
                    $entry = $batch->fresh()->posts[$key] ?? [];
                    if (($entry['status'] ?? '') === 'scheduled' || (($entry['status'] ?? '') === 'held' && ($entry['version'] ?? 0) === $rule->version && ! $retry)) {
                        continue;
                    }
                    $scope = ['brand_id' => $brand->id, 'scope_key' => 'website-daily:'.$key, 'run_date' => $batch->run_date];
                    $run = TrendRun::firstOrCreate($scope, ['status' => 'running', 'settings_version' => $rule->options['trend']['version'] ?? (string) Str::uuid(),
                        'automation_rule_id' => $rule->id, 'automation_version' => $rule->version, 'social_account_id' => $rule->social_account_id]);
                    try {
                        if (! isset($slots[$i])) {
                            throw new RuntimeException('Not enough daytime slots remain today. This post will not be moved to tomorrow or posted late.');
                        }
                        $post = isset($entry['post_id']) ? Post::findOrFail($entry['post_id']) : $this->draft($brand, $rule, $package, $batch);
                        $this->entry($batch, $key, ['post_id' => $post->id, 'status' => 'preparing', 'version' => $rule->version]);
                        $run->update(['post_id' => $post->id, 'status' => 'running', 'automation_version' => $rule->version]);
                        if ($post->schedules()->exists() || $post->publications()->exists()) {
                            // A worker can stop after committing its schedule. Never submit the saved post twice.
                            $this->entry($batch, $key, ['post_id' => $post->id, 'status' => 'scheduled', 'version' => $rule->version]);

                            continue;
                        }
                        if (! $post->image_hash) {
                            $post->forceFill($this->images->create($post))->save();
                        }
                        DB::transaction(function () use ($post, $rule, $run, $slots, $i): void {
                            $current = AutomationRule::whereKey($rule->id)->lockForUpdate()->firstOrFail();
                            if (! $current->enabled || $current->version !== $rule->version || ($current->options['workflow'] ?? '') !== 'automatic') {
                                throw new RuntimeException('Daily publishing settings changed during preparation.');
                            }
                            $when = $this->space($rule->social_account_id, $slots[$i], $rule->options['trend']);
                            $post->forceFill(['status' => 'reviewed', 'reviewed_at' => now()])->save();
                            $schedule = $this->scheduler->create($post, $rule->social_account_id, $when, true);
                            $schedule->update(['automation_rule_id' => $rule->id, 'automation_version' => $rule->version]);
                            $end = CarbonImmutable::parse(now($rule->options['trend']['timezone'])->toDateString().' '.$rule->options['trend']['window_end'], $rule->options['trend']['timezone'])->utc();
                            $run->update(['status' => 'scheduled', 'package' => ['normal_fallback' => true, 'website_daily' => true],
                                'expires_at' => $end, 'evidence' => ['post_content_hash' => PublishTrend::contentHash($post)],
                                'reason' => 'Daily website content scheduled for '.$when->setTimezone($rule->options['trend']['timezone'])->format('d M H:i').' '.$rule->options['trend']['timezone'].'.']);
                        }, 3);
                        $this->entry($batch, $key, ['post_id' => $post->id, 'status' => 'scheduled', 'version' => $rule->version]);
                    } catch (\Throwable $error) {
                        $reason = $this->reason($error);
                        $run->update(['status' => 'held', 'reason' => $reason]);
                        $this->entry($batch, $key, $entry + ['post_id' => $run->post_id], ['status' => 'held', 'version' => $rule->version, 'reason' => $reason]);
                    }
                }
                TrendRun::where('brand_id', $brand->id)->where('scope_key', 'website-daily:'.$rule->id.':source')->where('run_date', $batch->run_date)->delete();
            }
        } finally {
            $lock->release();
        }
    }

    private function batch(Brand $brand): WebsiteDailyBatch
    {
        $date = now('Asia/Kolkata')->toDateString();
        if ($batch = WebsiteDailyBatch::where('brand_id', $brand->id)->where('run_date', $date)->first()) {
            return $batch;
        }
        $rotation = WebsiteDailyRotation::firstOrCreate(['brand_id' => $brand->id], ['state' => []]);
        if (str_contains(strtolower(parse_url($brand->website, PHP_URL_HOST)), 'examelite')) {
            $feed = $this->rotation->pyp($brand);
            $payloads = ['posts' => $feed['posts'], 'coverage' => $feed['coverage'] ?? []];
            $state = $rotation->state;
            $reason = $feed['reason'] ?? null;
        } else {
            $state = $rotation->state;
            if (! $state) {
                $previous = Post::where('brand_id', $brand->id)->where(fn ($query) => $query->whereHas('publications', fn ($query) => $query->where('status', 'published'))
                    ->orWhereHas('schedules', fn ($query) => $query->whereIn('status', ['queued', 'running', 'processing'])))->pluck('source_url');
                $state = ['overviews' => $previous->filter(fn ($url) => str_contains($url ?? '', '/state/'))->map(fn ($url) => explode('?', explode('#', $url, 2)[0], 2)[0])->unique()->values()->all()];
            }
            [$package, $state] = $this->rotation->pollmedia($brand, $state);
            $payloads = ['posts' => [$package]];
            $reason = null;
        }

        return DB::transaction(function () use ($rotation, $date, $brand, $payloads, $state, $reason): WebsiteDailyBatch {
            WebsiteDailyRotation::whereKey($rotation->id)->lockForUpdate()->firstOrFail()->update(['state' => $state]);

            return WebsiteDailyBatch::firstOrCreate(['brand_id' => $brand->id, 'run_date' => $date], ['payloads' => $payloads, 'reason' => $reason]);
        }, 3);
    }

    private function draft(Brand $brand, AutomationRule $rule, array $package, WebsiteDailyBatch $batch): Post
    {
        $visual = app(ContentVisual::class)->validate($package['visual'], $package['source_url']);
        if (($visual['type'] ?? '') === 'question' && ($batch->payloads['coverage']['question_cycle'] ?? 1) <= 1) {
            $old = Post::where('brand_id', $brand->id)->where('visual->question', $visual['question'])
                ->where('created_at', '<', $batch->created_at)
                ->where(fn ($query) => $query->whereHas('publications', fn ($query) => $query->where('status', 'published'))
                    ->orWhereHas('schedules', fn ($query) => $query->whereIn('status', ['queued', 'running', 'processing', 'uncertain'])))
                ->get()->contains(fn ($previous) => ($previous->visual['options'] ?? []) === $visual['options']);
            if ($old) {
                throw new RuntimeException('This source question was already selected before the new rotation began. Held to prevent an early repeat.');
            }
        }
        $body = $package['body'];
        if ($rule->channel === 'x') {
            $body = mb_substr($package['title'], 0, 55)."\n\n".($visual['type'] === 'question'
                ? 'Try the PYP question in the card. Practise the linked Online Exam paper. #ExamElite'
                : 'Original report pages with recorded graphs and tables. Full report and source notes in the link. #ElectionData');
        }

        return $brand->posts()->create(['channel' => $rule->channel, 'title' => $package['title'], 'body' => $body,
            'source_url' => $package['source_url'], 'visual' => $visual]);
    }

    private function entry(WebsiteDailyBatch $batch, string $key, array $entry, array $replace = []): void
    {
        $posts = $batch->fresh()->posts ?? [];
        $posts[$key] = array_replace($entry, $replace);
        $batch->update(['posts' => $posts]);
    }

    private function space(int $account, CarbonImmutable $when, array $settings): CarbonImmutable
    {
        $end = CarbonImmutable::parse($when->setTimezone($settings['timezone'])->toDateString().' '.$settings['window_end'], $settings['timezone'])->utc();
        while ($when->lte($end)) {
            $queued = PostSchedule::where('social_account_id', $account)->whereIn('status', ['queued', 'running', 'processing', 'uncertain'])
                ->whereBetween('scheduled_at', [$when->subMinutes(59), $when->addMinutes(59)])->exists();
            $published = Publication::where('social_account_id', $account)->whereIn('status', ['published', 'publishing', 'uncertain'])
                ->whereBetween('created_at', [$when->subMinutes(59), $when->addMinutes(59)])->exists();
            if (! $queued && ! $published) {
                return $when;
            }
            $when = $when->addHour();
        }
        throw new RuntimeException('Today’s audience window is full. No late or overnight post was scheduled.');
    }

    private function reason(\Throwable $error): string
    {
        if ($error instanceof ValidationException) {
            return mb_substr(implode(' ', array_merge(...array_values($error->errors()))), 0, 1000);
        }

        return $error instanceof RuntimeException ? mb_substr($error->getMessage(), 0, 1000) : 'Daily content preparation failed ('.class_basename($error).'). Check the source and report-renderer configuration; no duplicate publication was attempted.';
    }
}
