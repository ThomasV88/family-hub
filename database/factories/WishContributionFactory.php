<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WishContribution;
use App\Models\WishReservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WishContribution>
 */
class WishContributionFactory extends Factory
{
    protected $model = WishContribution::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'reservation_id' => WishReservation::factory(),
            'user_id' => User::factory(),
            'amount_minor' => null,
        ];
    }
}
