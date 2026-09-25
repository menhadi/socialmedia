<?php

namespace Database\Factories;

use App\Models\HealthIncident;
use App\Models\HealthMonitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HealthIncident> */
class HealthIncidentFactory extends Factory
{
    public function definition(): array
    {
        return ['health_monitor_id' => HealthMonitor::factory(), 'url' => 'https://health.example.com/up', 'started_at' => now()];
    }
}
