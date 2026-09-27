<?php

namespace Database\Factories;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;

class AutomationRuleFactory extends Factory
{
    public function definition(): array
    {
        return ['brand_id' => Brand::factory(), 'channel' => 'facebook', 'category' => 'general', 'enabled' => true, 'delay_minutes' => 30, 'daily_limit' => 3, 'learn' => true];
    }
}
