<?php

namespace Database\Factories;

use App\Models\MediaConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaConnection>
 */
class MediaConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(), 'kind' => 'image', 'model' => 'gemini-3.1-flash-image',
            'api_key' => 'fake-media-key', 'enabled' => true, 'daily_budget_micros' => 1000000,
            'request_cost_micros' => 50000, 'daily_request_limit' => 5,
        ];
    }
}
