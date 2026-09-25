<?php

namespace Database\Factories;

use App\Models\AiConnection;
use App\Models\AiGeneration;
use App\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AiGeneration> */
class AiGenerationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'user_id' => fn (array $attributes) => Brand::findOrFail($attributes['brand_id'])->user_id,
            'ai_connection_id' => fn (array $attributes) => AiConnection::factory()->create(['user_id' => $attributes['user_id']])->id,
            'request_key' => (string) Str::uuid(), 'fingerprint' => str_repeat('a', 64), 'provider' => 'deepseek', 'model' => 'test-model', 'task' => 'draft', 'title' => 'Sample generation', 'channel' => 'facebook', 'language' => 'English', 'status' => 'pending', 'cost_micros' => 5000, 'input_rate' => 1, 'output_rate' => 2,
        ];
    }
}
