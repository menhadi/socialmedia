<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SocialAccount> */
class SocialAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(), 'provider' => 'facebook',
            'page_id' => fake()->unique()->numerify('##########'),
            'page_name' => fake()->company(), 'access_token' => 'test-page-token',
            'credential_version' => (string) Str::uuid(), 'verified_at' => now(),
        ];
    }
}
