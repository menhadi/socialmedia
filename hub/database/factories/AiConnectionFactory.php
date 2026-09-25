<?php

namespace Database\Factories;

use App\Models\AiConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AiConnection> */
class AiConnectionFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'provider' => 'deepseek', 'model' => 'test-model', 'api_key' => 'fake-test-key', 'enabled' => false, 'daily_request_limit' => 10, 'max_output_tokens' => 1200, 'daily_budget_micros' => 1000000, 'input_rate' => 1, 'output_rate' => 2];
    }

    public function enabled(): static
    {
        return $this->state(fn () => ['enabled' => true]);
    }
}
