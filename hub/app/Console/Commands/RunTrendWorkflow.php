<?php

namespace App\Console\Commands;

use App\Models\AutomationRule;
use App\Models\Brand;
use App\Models\TrendRun;
use App\Services\Trends\GenerateTrendDraft;
use App\Services\Trends\NormalPostFallback;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RunTrendWorkflow extends Command
{
    protected $signature = 'hub:run-trend-workflow';

    protected $description = 'Discover platform topics and prepare or schedule one relevant daily post per enabled trend rule';

    public function handle(GenerateTrendDraft $service): int
    {
        $lock = Cache::lock('trend-workflow', 600);
        if (! $lock->get()) {
            return self::SUCCESS;
        }
        try {
            Cache::put('trend-workflow-heartbeat', now()->toIso8601String(), now()->addDays(7));
            TrendRun::where('status', 'running')->where('updated_at', '<', now()->subMinutes(15))->update(['status' => 'held', 'reason' => 'The worker stopped before completing this attempt. Check AI history; no automatic retry was made.']);
            $started = microtime(true);
            $processed = 0;
            foreach (AutomationRule::where('category', 'trend')->where('enabled', true)->lazyById(50) as $rule) {
                $settings = $rule->options['trend'] ?? null;
                if (! $settings) {
                    continue;
                }
                $local = now($settings['timezone']);
                if ($local->format('H:i') < $settings['daily_time']) {
                    continue;
                }
                $existing = TrendRun::where('brand_id', $rule->brand_id)->where('scope_key', 'rule:'.$rule->id)->where('run_date', $local->toDateString())->first();
                if ($existing) {
                    if ($existing->status !== 'skipped' || ($existing->evidence['fallback_content_version'] ?? 0) >= 2) {
                        continue;
                    }
                    app(NormalPostFallback::class)->run($existing, $rule);
                } else {
                    $service->run(Brand::findOrFail($rule->brand_id), $rule);
                }
                if (++$processed >= 3 || microtime(true) - $started > 45) {
                    break;
                }
            }
            foreach (Brand::whereNotNull('trend_settings')->lazyById(50) as $brand) {
                if ($processed >= 3 || microtime(true) - $started > 45) {
                    break;
                }
                $settings = $brand->trend_settings;
                if (! ($settings['enabled'] ?? false)) {
                    continue;
                }
                $local = now($settings['timezone']);
                if ($local->format('H:i') < $settings['daily_time'] || TrendRun::where('brand_id', $brand->id)->where('scope_key', 'website')->where('run_date', $local->toDateString())->exists()) {
                    continue;
                }
                $service->run($brand);
                if (++$processed >= 3 || microtime(true) - $started > 45) {
                    break;
                }
            }
            $this->info('Daily trend drafts checked.');

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
