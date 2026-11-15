<?php

namespace Database\Factories;

use App\Models\Wish;
use App\Models\WishUrl;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WishUrl>
 */
class WishUrlFactory extends Factory
{
    protected $model = WishUrl::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'wish_id' => Wish::factory(),
            'url' => 'https://example.com/'.fake()->slug(2),
            'label' => null,
            'position' => 0,
        ];
    }
}
