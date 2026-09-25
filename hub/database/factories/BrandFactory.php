<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Brand> */
class BrandFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'name' => fake()->company(), 'description' => 'A general application.', 'tone' => 'Helpful and clear', 'language' => 'English'];
    }
}
