<?php

namespace Database\Factories;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ContentItemFactory extends Factory
{
    public function definition(): array
    {
        return ['brand_id' => Brand::factory(), 'external_id' => (string) Str::uuid(), 'category' => 'general', 'channel' => 'facebook', 'title' => 'Study tip', 'body' => 'Study a little every day and review your notes.', 'fingerprint' => hash('sha256', 'sample'), 'approved' => true];
    }
}
