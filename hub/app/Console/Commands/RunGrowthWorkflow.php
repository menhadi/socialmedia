<?php

namespace App\Console\Commands;

use App\Models\ContentItem;
use App\Models\Publication;
use App\Services\Analytics\CollectAnalytics;
use App\Services\Automation\RunAutomation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RunGrowthWorkflow extends Command
{
    protected $signature = 'hub:run-growth-workflow';

    protected $description = 'Process approved application content and refresh publication metrics';

    public function handle(RunAutomation $runner, CollectAnalytics $analytics): int
    {
        $lock = Cache::lock('growth-workflow', 600);
        if (! $lock->get()) {
            return self::SUCCESS;
        }
        try {
            ContentItem::where('status', 'processing')->where('updated_at', '<', now()->subMinutes(15))->update(['status' => 'held', 'reason' => 'Processing interrupted. Check generation and post history before retrying.']);
            $start = microtime(true);
            foreach (ContentItem::where('status', 'pending')->oldest()->limit(3)->get() as $item) {
                $runner->run($item);
                if (microtime(true) - $start > 40) {
                    return self::SUCCESS;
                }
            }
            foreach (Publication::where('status', 'published')->whereNull('remote_deleted_at')->where('published_at', '>=', now()->subDays(90))->whereDoesntHave('latestAnalytics', fn ($q) => $q->where('created_at', '>', now()->subHours(6)))->oldest('published_at')->limit(3)->get() as $publication) {
                $analytics->run($publication);
                if (microtime(true) - $start > 50) {
                    break;
                }
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
