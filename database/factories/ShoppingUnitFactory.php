<?php

namespace Database\Factories;

use App\Models\ShoppingUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Produces an administrator-defined (custom) unit.
 *
 * @extends Factory<ShoppingUnit>
 */
class ShoppingUnitFactory extends Factory
{
    protected $model = ShoppingUnit::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $n = fake()->unique()->bothify('??##');

        return ['name_en' => "e{$n}", 'name_sk' => "s{$n}", 'name_pl' => "p{$n}", 'position' => 100];
    }
}
