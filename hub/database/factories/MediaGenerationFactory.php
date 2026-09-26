<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\MediaConnection;
use App\Models\MediaGeneration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MediaGeneration>
 */
class MediaGenerationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'media_connection_id' => MediaConnection::factory(),
            'post_id' => fn () => Brand::factory()->create()->posts()->create(['title' => 'Media test', 'body' => 'Example facts', 'channel' => 'facebook'])->id,
            'request_key' => (string) Str::uuid(), 'fingerprint' => str_repeat('a', 64),
            'kind' => 'image', 'model' => 'gemini-3.1-flash-image', 'api_key' => 'fake-media-key',
            'prompt' => 'An illustrative scene', 'aspect_ratio' => '16:9', 'cost_micros' => 50000,
        ];
    }
}
