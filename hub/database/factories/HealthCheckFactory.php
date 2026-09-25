<?php

namespace Database\Factories;

use App\Models\HealthCheck;
use App\Models\HealthMonitor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<HealthCheck> */
class HealthCheckFactory extends Factory
{
    public function definition(): array
    {
        return ['health_monitor_id' => HealthMonitor::factory(), 'request_key' => (string) Str::uuid(),
            'configuration_version' => (string) Str::uuid(), 'url' => 'https://health.example.com/up',
            'checked_at' => now(), 'completed_at' => now(), 'status' => 'online', 'http_status' => 200, 'response_ms' => 120];
    }
}
