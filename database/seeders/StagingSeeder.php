<?php

namespace Database\Seeders;

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

        $created = 0;
        foreach (['sk_SK' => 2, 'pl_PL' => 2, 'en_GB' => 2] as $locale => $n) {
            $faker = FakerFactory::create($locale);
            for ($i = 1; $i <= $n; $i++) {
                $created++;
                User::factory()->create([
                    'name' => $faker->name().' (demo)',
                    'email' => strtolower($locale).".{$created}@example.test",
                    'password' => Hash::make(Str::random(32)),
                ]);
            }
        }

        return $created;
    }
}
