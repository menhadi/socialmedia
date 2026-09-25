<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\HealthMonitor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<HealthMonitor> */
class HealthMonitorFactory extends Factory
{
    public function definition(): array
    {
        return ['brand_id' => Brand::factory(), 'url' => 'https://health.example.com/up',
            'expected_status' => 200, 'interval_minutes' => 5, 'enabled' => false,
            'configuration_version' => (string) Str::uuid()];
    }
}
