<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WishlistManager;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WishlistManager>
 */
class WishlistManagerFactory extends Factory
{
    protected $model = WishlistManager::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'manager_id' => User::factory(),
        ];
    }
}
