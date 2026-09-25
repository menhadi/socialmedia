<?php

namespace App\Console\Commands;

use App\Models\HealthCheck;
use App\Models\HealthMonitor;
use App\Services\Monitoring\CheckMonitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CheckHealthMonitors extends Command
{
    protected $signature = 'hub:check-monitors';

    protected $description = 'Check enabled application monitors whose next check is due';

    public function handle(CheckMonitor $service): int
    {
        $checked = 0;
        $failed = 0;
        Cache::forever('monitoring.last_sweep_at', now()->toIso8601String());
        HealthMonitor::where('enabled', true)
            ->where(fn ($query) => $query->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()))
            ->chunkById(50, function ($monitors) use ($service, &$checked, &$failed): void {
                foreach ($monitors as $monitor) {
                    try {
                        if ($service->run($monitor, scheduled: true)) {
                            $checked++;
                        }
                    } catch (\Throwable) {
                        $failed++;
                        $this->error('A monitor could not be processed. Its last result has not been replaced.');
                    }
                    Cache::forever('monitoring.last_sweep_at', now()->toIso8601String());
                }
            });
        HealthCheck::where('checked_at', '<', now()->subDays(30))->where('status', '!=', 'running')->delete();
        $this->info('Completed '.$checked.' health checks.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
