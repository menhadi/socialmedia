<?php

namespace App\Console\Commands;

use App\Models\MediaGeneration;
use App\Services\Media\GenerateMedia;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class GeneratePendingMedia extends Command
{
    protected $signature = 'hub:generate-media';

    protected $description = 'Generate queued images and poll pending video operations';

    public function handle(GenerateMedia $service): int
    {
        $lock = Cache::lock('generate-media-worker', 600);
        if (! $lock->get()) {
            return self::SUCCESS;
        }
        try {
            MediaGeneration::where('status', 'starting')->where('started_at', '<', now()->subMinutes(10))
                ->update(['status' => 'uncertain', 'api_key' => null, 'error' => 'The worker stopped during submission. Check provider usage before trying again.']);
            $start = microtime(true);
            foreach (MediaGeneration::whereIn('status', ['queued', 'processing'])->where(fn ($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<=', now()->subMinute()))->orderBy('id')->limit(3)->get() as $job) {
                $service->run($job);
                if (microtime(true) - $start > 120) {
                    break;
                }
            }
            $this->info('Pending media checked.');

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
