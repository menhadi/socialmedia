<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Services\Research\DailyWebsiteWorkflow;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('hub:run-website-daily')]
#[Description('Schedule daily Online Exam PYP questions and original election reports using persistent rotations')]
class RunWebsiteDaily extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DailyWebsiteWorkflow $workflow): int
    {
        Cache::put('website-daily-heartbeat', now()->toIso8601String(), now()->addDays(7));
        foreach (Brand::lazyById(50) as $brand) {
            $workflow->run($brand);
        }

        return self::SUCCESS;
    }
}
