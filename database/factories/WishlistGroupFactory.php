<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WishlistGroup;
use App\Models\WishlistGroupMember;
use App\Wishlist\Groups\GroupMode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WishlistGroup>
 */
class WishlistGroupFactory extends Factory
{
    protected $model = WishlistGroup::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'mode' => GroupMode::Gifts,
            'created_by_id' => User::factory(),
        ];
    }

    public function shared(): static
    {
        return $this->state(fn () => ['mode' => GroupMode::Shared]);
    }

    /** @param  array<int, User>  $users */
    public function withMembers(array $users): static
    {
        return $this->afterCreating(function (WishlistGroup $group) use ($users) {
            foreach ($users as $user) {
                (new WishlistGroupMember)->forceFill(['group_id' => $group->id, 'user_id' => $user->id])->save();
            }
        });
    }
}
