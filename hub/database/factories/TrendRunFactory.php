<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\TrendRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TrendRun> */
class TrendRunFactory extends Factory
{
    public function definition(): array
    {
        return ['brand_id' => Brand::factory(), 'run_date' => now()->toDateString(), 'settings_version' => (string) Str::uuid(), 'status' => 'skipped', 'reason' => 'No relevant topic.'];
    }
}
