<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Publication;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Publication> */
class PublicationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'social_account_id' => SocialAccount::factory(),
            'post_id' => function (array $attributes): int {
                $account = SocialAccount::findOrFail($attributes['social_account_id']);

                return Brand::findOrFail($account->brand_id)->posts()->create([
                    'title' => 'A useful update', 'channel' => 'facebook', 'body' => 'A saved message.',
                ])->id;
            },
            'request_key' => (string) Str::uuid(), 'fingerprint' => hash('sha256', 'test-preview'),
            'page_id' => fn (array $attributes): string => SocialAccount::findOrFail($attributes['social_account_id'])->page_id,
            'page_name' => 'Example Page', 'message' => 'A saved message.', 'status' => 'publishing',
        ];
    }
}
