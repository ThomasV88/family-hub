<?php

namespace Database\Seeders;

use App\Access\Preset;
use App\Models\User;
use Faker\Factory as FakerFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
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
        DB::transaction(function () {
            $this->deleteSyntheticOwnedRows(User::where('email', 'like', '%@example.test')->pluck('id')->all());
            User::where('email', 'like', '%@example.test')->delete();
        });

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

    /**
     * Delete the domain rows the synthetic users own, so deleting the users leaves no orphan for staging:audit and does
     * not trip a RESTRICT foreign key. Rows of real users are never touched (the audit must keep failing on those).
     * Children are removed by the existing ON DELETE CASCADE foreign keys. audit_logs and ai_calls are append-only and
     * stay (the audit accepts their dangling synthetic ids). Medical files live on disk and are NOT deleted here:
     * `medical:prune-orphans` removes the files whose rows are gone.
     *
     * @param  list<int>  $ids  ids of the synthetic users
     */
    private function deleteSyntheticOwnedRows(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        // Todo: tasks.creator_id is RESTRICT. task_members cascade.
        DB::table('tasks')->whereIn('creator_id', $ids)->delete();

        // Wishlist: wishes.owner_id is RESTRICT. urls, reservations and contributions cascade. Family-wide occasions
        // (no owner) made by a synthetic user are removed too; an occasion owned by a real user stays.
        DB::table('wishes')->whereIn('owner_id', $ids)->delete();
        DB::table('wish_occasions')->whereIn('created_by_id', $ids)
            ->where(fn ($q) => $q->whereNull('owner_id')->orWhereIn('owner_id', $ids))->delete();

        // Finance: matches, recurring, transactions, budgets, profiles, batches, then accounts (members, rows cascade).
        foreach (['finance_recurring_matches', 'finance_recurring', 'finance_transactions', 'finance_budgets', 'finance_import_profiles'] as $table) {
            DB::table($table)->whereIn('created_by', $ids)->delete();
        }
        DB::table('finance_import_batches')->whereIn('user_id', $ids)->delete();
        DB::table('finance_accounts')->whereIn('created_by', $ids)->delete();

        // Medical: a proposal started by a synthetic user, then the people it created (documents, visits, allergies,
        // medications, vaccinations, contacts, memberships, proposals cascade). A person linked to a real user is kept.
        DB::table('medical_ai_extractions')->whereIn('started_by', $ids)->delete();
        DB::table('medical_subjects')->whereIn('created_by', $ids)
            ->where(fn ($q) => $q->whereNull('user_id')->orWhereIn('user_id', $ids))->delete();
    }
}
