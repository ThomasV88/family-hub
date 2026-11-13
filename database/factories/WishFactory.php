<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Wish;
use App\Wishlist\WishPriority;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Wish>
 */
class WishFactory extends Factory
{
    protected $model = Wish::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'title' => fake()->sentence(3),
            'price_currency' => 'EUR',
            'priority' => WishPriority::Love,
        ];
    }

    public function received(): static
    {
        return $this->state(fn () => ['received_at' => now()]);
    }

    public function withImage(): static
    {
        return $this->state(fn () => [
            'image_path' => 'wishes/test.jpg',
            'thumb_path' => 'wishes/test-thumb.jpg',
            'image_source' => 'fetched',
            'image_source_host' => 'example.com',
            'image_status' => 'ready',
        ]);
    }
}
