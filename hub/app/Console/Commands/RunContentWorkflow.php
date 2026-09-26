<?php

namespace App\Console\Commands;

use App\Models\ContentSource;
use App\Models\PostSchedule;
use App\Services\Research\ResearchSource;
use App\Services\Social\SchedulePost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RunContentWorkflow extends Command
{
    protected $signature = 'hub:run-content-workflow';

    protected $description = 'Read enabled content sources and publish due, approved posts';

    public function handle(ResearchSource $research, SchedulePost $publisher): int
    {
        $lock = Cache::lock('content-workflow', 900);
        if (! $lock->get()) {
            $this->info('Another workflow run is active.');

            return self::SUCCESS;
        }
        try {
            Cache::put('content-workflow-heartbeat', now()->toIso8601String(), now()->addDays(7));
            PostSchedule::where('status', 'running')->where('started_at', '<', now()->subMinutes(15))->update([
                'status' => 'uncertain', 'reason' => 'The worker stopped before recording the outcome. Check Facebook before taking further action.',
            ]);
            $start = microtime(true);
            foreach (PostSchedule::where('status', 'queued')->where('scheduled_at', '<=', now())->orderBy('scheduled_at')->limit(10)->get() as $schedule) {
                $publisher->run($schedule);
                if (microtime(true) - $start > 45) {
                    return self::SUCCESS;
                }
            }
            foreach (ContentSource::where('enabled', true)->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()))->orderBy('next_check_at')->limit(5)->get() as $source) {
                $research->run($source);
                if (microtime(true) - $start > 45) {
                    break;
                }
            }
            $this->info('Due workflow work checked.');

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
