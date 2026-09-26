<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\ContentSource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ContentSource> */
class ContentSourceFactory extends Factory
{
    public function definition(): array
    {
        return ['brand_id' => Brand::factory(), 'name' => 'Official notices', 'topic' => 'Exam results',
            'url' => 'https://official.example.com/notices', 'version' => (string) Str::uuid(),
            'enabled' => false, 'auto_publish' => false, 'with_image' => false,
            'channel' => 'facebook', 'interval_minutes' => 60, 'delay_minutes' => 60];
    }
}
