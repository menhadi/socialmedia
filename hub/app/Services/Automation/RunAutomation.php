<?php

namespace App\Services\Automation;

use App\Models\AiGeneration;
use App\Models\AutomationRule;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\Post;
use App\Models\PostSchedule;
use App\Models\SourceSnapshot;
use App\Models\User;
use App\Services\Ai\GenerateContent;
use App\Services\Analytics\PerformanceContext;
use App\Services\Research\FetchSource;
use App\Services\Research\PostImage;
use App\Services\Social\SchedulePost;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RunAutomation
{
    public function __construct(private GenerateContent $ai, private SchedulePost $scheduler, private PerformanceContext $performance, private PostImage $images) {}

    public function run(ContentItem $item): void
    {
        if ($item->fresh()->status !== 'pending') {
            return;
        }
        $rule = AutomationRule::where('brand_id', $item->brand_id)->where('channel', $item->channel)->where('category', $item->category)->where('enabled', true)->first();
        if (! $rule) {
            $item->update(['status' => 'held', 'reason' => 'Enable an automation rule for this application, category and platform. Exam announcements/results must use approved official sources in Research.']);

            return;
        }
        if (! $item->approved && ! $rule->trust_intake) {
            $item->update(['status' => 'held', 'reason' => 'Application intake is not trusted by this rule. Approve the content or explicitly trust the connector.']);

            return;
        }
        if (! ContentItem::whereKey($item->id)->where('status', 'pending')->update(['status' => 'processing', 'updated_at' => now()])) {
            return;
        }
        try {
            $brand = Brand::findOrFail($item->brand_id);
            $context = $rule->learn ? $this->performance->build($brand, $item->channel) : ['note' => 'Performance learning disabled.', 'examples' => []];
            $generation = $this->generate($brand, $item->channel, $item->title, 'autopilot', json_encode(['approved_content' => $item->body, 'performance' => ['note' => $context['note'], 'examples' => array_slice($context['examples'], 0, 8)]], JSON_THROW_ON_ERROR), 'intake-'.$item->id);
            $package = $this->package($generation);
            $headline = FetchSource::normalize($package['headline_quote'] ?? '');
            $excerpt = FetchSource::normalize($package['excerpt_quote'] ?? '');
            $source = FetchSource::normalize($item->body);
            if (strlen($headline) < 3 || mb_strlen($headline) > 200 || strlen($excerpt) < 20 || mb_strlen($excerpt) > 3000 || ! str_contains($source, $headline) || ! str_contains($source, $excerpt)) {
                throw new \RuntimeException('AI evidence quotes could not be matched to the approved content. Review the input.');
            }
            $tags = array_filter($package['hashtags'] ?? [], fn ($v) => is_string($v) && preg_match('/^#[\p{L}\p{N}_]{1,30}$/uD', $v));
            $body = $headline."\n\n".$excerpt.($tags ? "\n\n".implode(' ', array_slice($tags, 0, 3)) : '');
            $post = DB::transaction(function () use ($brand, $item, $body, $generation): Post {
                $post = $brand->posts()->create(['title' => $item->title, 'channel' => $item->channel, 'body' => $body, 'source_url' => $item->source_url]);
                $item->update(['post_id' => $post->id]);
                $generation->post_id = $post->id;
                $generation->save();

                return $post;
            });
            $post->forceFill(['learning_note' => $context['note']])->save();
            if ($package['concerns']) {
                throw new \RuntimeException('AI flagged missing, conflicting, unsafe, time-sensitive or unclear information. Review the content and its source.');
            }
            if ($rule->with_image) {
                $post->forceFill($this->images->create($post))->save();
            }
            $this->queue($post, $rule);
            $item->update(['status' => 'scheduled', 'reason' => 'Grounded excerpts scheduled; no review flags.']);
        } catch (\Throwable $e) {
            $reason = $this->reason($e);
            $item->update(['status' => 'held', 'reason' => $reason]);
            if ($item->post_id) {
                Post::whereKey($item->post_id)->update(['automation_reason' => $reason]);
            }
        }
    }

    public function assess(Post $post, string $category): void
    {
        $lock = Cache::lock('assess:'.$post->id, 120);
        if (! $lock->get()) {
            return;
        }
        try {
            $post->refresh();
            $post->assertEditable();
            $rule = AutomationRule::where('brand_id', $post->brand_id)->where('channel', $post->channel)->where('category', $category)->where('enabled', true)->first();
            if (! $rule) {
                throw new \RuntimeException('Enable a matching automation rule first.');
            }
            $fingerprint = $post->publishingFingerprint();
            $generation = $this->generate($post->brand, $post->channel, $post->title, 'assess', json_encode(['admin_confirmed_draft' => $post->body, 'reference_url' => $post->source_url], JSON_THROW_ON_ERROR), 'assess-'.$post->id.'-'.$fingerprint.'-'.$rule->version);
            $package = $this->package($generation);
            if ($package['concerns']) {
                throw new \RuntimeException('AI flagged this draft. Check facts, dates, claims and suitability before reviewing manually.');
            }
            DB::transaction(function () use ($post, $fingerprint, $rule): void {
                $current = Post::whereKey($post->id)->lockForUpdate()->firstOrFail();
                if (! hash_equals($fingerprint, $current->publishingFingerprint())) {
                    throw new \RuntimeException('Post changed during assessment. Assess the saved version.');
                }
                $this->queue($current, $rule);
            });
        } catch (\Throwable $e) {
            $post->forceFill(['automation_reason' => $this->reason($e)])->save();
        } finally {
            $lock->release();
        }
    }

    private function generate(Brand $brand, string $channel, string $title, string $task, string $source, string $key): AiGeneration
    {
        return $this->ai->run(User::findOrFail($brand->user_id), $brand, ['request_key' => substr(hash('sha256', $key), 0, 8).'-'.substr(hash('sha256', $key), 8, 4).'-4'.substr(hash('sha256', $key), 13, 3).'-a'.substr(hash('sha256', $key), 17, 3).'-'.substr(hash('sha256', $key), 20, 12), 'task' => $task, 'title' => $title, 'channel' => $channel, 'language' => 'English', 'source_text' => $source]);
    }

    private function package(AiGeneration $generation): array
    {
        if ($generation->status !== 'completed') {
            throw new \RuntimeException('AI did not complete. Check provider budgets and generation history. No automatic retry was made.');
        }
        $data = json_decode($generation->result, true);
        if (! is_array($data) || ! isset($data['concerns']) || ! is_array($data['concerns'])) {
            throw new \RuntimeException('AI assessment was incomplete. Manual review required.');
        }

        return $data;
    }

    public function queue(Post $post, AutomationRule $rule, ?SourceSnapshot $snapshot = null): void
    {
        DB::transaction(function () use ($post, $rule, $snapshot): void {
            $current = AutomationRule::whereKey($rule->id)->lockForUpdate()->firstOrFail();
            if (! $current->enabled || $current->version !== $rule->version) {
                throw new \RuntimeException('Automation rule changed during generation.');
            }
            $post = Post::whereKey($post->id)->lockForUpdate()->firstOrFail();
            $post->assertEditable();
            $last = PostSchedule::where('automation_rule_id', $rule->id)->whereIn('status', ['queued', 'running', 'processing', 'published'])->max('scheduled_at');
            $when = now('UTC')->addMinutes($rule->delay_minutes);
            if ($last) {
                $when = $when->max(Carbon::parse($last)->addMinutes($rule->delay_minutes));
            }
            for ($day = 0; $day < 31; $day++) {
                $count = PostSchedule::where('automation_rule_id', $rule->id)->whereNotIn('status', ['cancelled', 'blocked', 'failed'])->whereBetween('scheduled_at', [$when->copy()->startOfDay(), $when->copy()->endOfDay()])->count();
                if ($count < $rule->daily_limit) {
                    break;
                } $when = $when->addDay()->startOfDay()->addMinutes($rule->delay_minutes);
            }
            if ($day === 31) {
                throw new \RuntimeException('Automation queue is full for the next 30 days.');
            }
            $post->forceFill(['status' => 'reviewed', 'reviewed_at' => now()])->save();
            $schedule = $this->scheduler->create($post, (int) $rule->social_account_id, $when, true, $snapshot, $rule->options ?? []);
            $schedule->update(['automation_rule_id' => $rule->id, 'automation_version' => $rule->version]);
            $post->forceFill(['automation_reason' => 'Automatically scheduled for '.$when->utc()->format('Y-m-d H:i').' UTC; no review flags.'])->save();
        });
    }

    private function reason(\Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return mb_substr(implode(' ', Arr::flatten($e->errors())), 0, 1000);
        }
        if (get_class($e) === \RuntimeException::class) {
            return mb_substr($e->getMessage(), 0, 1000);
        }

        return 'Automation held because generation, media or a publishing check failed. Check provider settings and content; no publishing retry was made.';
    }
}
