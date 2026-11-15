<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Wish;
use App\Models\WishContribution;
use App\Models\WishReservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WishReservation>
 */
class WishReservationFactory extends Factory
{
    protected $model = WishReservation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'wish_id' => Wish::factory(),
            'user_id' => User::factory(),
            'cobuy_state' => 'none',
        ];
    }

    public function cobuyOpen(): static
    {
        return $this->state(fn () => ['cobuy_state' => 'open']);
    }

    public function configure(): static
    {
        return $this->afterCreating(function (WishReservation $reservation) {
            WishContribution::query()->create([
                'reservation_id' => $reservation->id,
                'user_id' => $reservation->user_id,
                'amount_minor' => null,
            ]);
        });
    }
}
