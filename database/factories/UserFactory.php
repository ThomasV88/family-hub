<?php

namespace Database\Factories;

use App\Access\AccessLevel;
use App\Access\Module;
use App\Access\Preset;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => Str::lower(fake()->unique()->safeEmail()),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the user is a family admin.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => ['is_admin' => true]);
    }

    /** An account created through Google: no password the member knows. */
    public function googleOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'password_set' => false,
            'google_id' => (string) fake()->unique()->numerify('##################'),
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /** Grant the preset's module levels after the user is created. */
    public function withPreset(Preset $preset): static
    {
        return $this->afterCreating(function (User $user) use ($preset) {
            foreach ($preset->grants() as $module => $level) {
                $user->modulePermissions()->updateOrCreate(['module' => $module], ['level' => $level]);
            }
        });
    }

    /** Grant one module level after the user is created. */
    public function withGrant(Module $module, AccessLevel $level): static
    {
        return $this->afterCreating(function (User $user) use ($module, $level) {
            $user->modulePermissions()->updateOrCreate(['module' => $module->value], ['level' => $level]);
        });
    }
}
