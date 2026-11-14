<?php

namespace Database\Seeders;

use App\Access\Preset;
use App\Models\User;
use Faker\Factory as FakerFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Synthetic data for staging only.
 *
 * Later phases must append their own synthetic rows here and never import
 * real data. All emails use the reserved @example.test TLD.
 */
class StagingSeeder extends Seeder
{
    /** @return int number of users created */
    public function run(): int
    {
        User::where('email', 'like', '%@example.test')->delete();

        // A shared, server-side password makes the synthetic accounts usable and survives the reseed on every deploy.
        // Without it each account gets a random password nobody knows (nobody can log in).
        $shared = (string) config('family.staging_seed_password');
        $usable = strlen($shared) >= 16;
        $credentials = fn (): array => [
            'password' => Hash::make($usable ? $shared : Str::random(32)),
            'password_set' => $usable,
        ];

        $created = 0;
        $presets = [Preset::Parent, Preset::Child, Preset::Grandparent, Preset::Guest];
        foreach (['sk_SK' => 2, 'pl_PL' => 2, 'en_GB' => 2] as $locale => $n) {
            $faker = FakerFactory::create($locale);
            for ($i = 1; $i <= $n; $i++) {
                $created++;
                User::factory()->withPreset($presets[($created - 1) % count($presets)])->create([
                    'name' => $faker->name().' (demo)',
                    'email' => strtolower($locale).".{$created}@example.test",
                    ...$credentials(),
                ]);
            }
        }

        // Synthetic admin: settings access only, no health or finance grants.
        $admin = User::factory()->create([
            'name' => 'Admin (demo)',
            'email' => 'admin@example.test',
            ...$credentials(),
        ]);
        $admin->forceFill(['is_admin' => true])->save();

        return $created;
    }
}
