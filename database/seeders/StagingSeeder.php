<?php

namespace Database\Seeders;

use App\Access\AccessLevel;
use App\Access\Module;
use App\Access\Preset;
use App\Medical\Documents\MedicalDocumentStore;
use App\Models\User;
use Database\Seeders\Staging\AiProposalsSeeder;
use Database\Seeders\Staging\DocumentsSeeder;
use Database\Seeders\Staging\FinanceSeeder;
use Database\Seeders\Staging\HealthSeeder;
use Database\Seeders\Staging\StagingContext;
use Database\Seeders\Staging\TasksSeeder;
use Database\Seeders\Staging\WishlistSeeder;
use Faker\Factory as FakerFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Synthetic data for staging only: a demo family with several years of history in every module (see
 * docs/ops/staging-data.md for the scenario map). One class per module lives in database/seeders/Staging.
 *
 * Later phases must append their own synthetic rows here and never import real data. All emails use the reserved
 *
 * @example.test TLD. Every row is owned by a synthetic user and every owner-linked table is covered by the purge below,
 * so a second run leaves no orphan and no duplicate (the seed runs on every staging deploy).
 */
class StagingSeeder extends Seeder
{
    /** @var array<string, mixed> row counts of the last run, for the command output and the tests */
    public array $summary = [];

    /** @return int number of members created (the admin is extra) */
    public function run(): int
    {
        $started = microtime(true);
        $this->summary = [];

        DB::transaction(function () {
            // Synthetic = example.test e-mail, or a child (no e-mail) with the demo suffix. Never a real child.
            $synthetic = fn () => User::query()->where(fn ($q) => $q->where('email', 'like', '%@example.test')
                ->orWhere(fn ($c) => $c->whereNull('email')->where('name', 'like', '% (demo)')));
            $ids = $synthetic()->pluck('id')->all();
            $this->deleteSyntheticOwnedRows($ids);
            $synthetic()->delete();
            if ($ids !== []) {
                DB::afterCommit(function () use ($ids) {
                    foreach ($ids as $id) {
                        try {
                            Storage::disk('local')->deleteDirectory("wish-images/profile-photos/{$id}");
                        } catch (\Throwable $e) {
                            Log::warning('staging seed: could not remove the photos of a reseeded member', ['reason' => $e::class]);
                        }
                    }
                });
            }
        });

        // A shared, server-side password makes the synthetic accounts usable and survives the reseed on every deploy.
        // Without it each account gets a random password nobody knows (nobody can log in). Hashed once (bcrypt is slow).
        $shared = (string) config('family.staging_seed_password');
        $usable = strlen($shared) >= 16;
        $hash = Hash::make($usable ? $shared : Str::random(32));
        $credentials = ['password' => $hash, 'password_set' => $usable];

        $today = StagingContext::today();
        $created = 0;
        $presets = [Preset::Parent, Preset::Child, Preset::Grandparent, Preset::Guest];
        // Birthdays fall at different distances from today (negative = already passed this year): sk1 in 20 days, sk2 in 65,
        // pl3 in 100, pl4 30 days ago, en5 in 150, en6 in 200. Age is the age in years of the next birthday's year.
        $birthdays = [[42, 20], [15, 65], [71, 100], [38, -30], [40, 150], [12, 200]];
        $locales = ['sk_SK' => 'sk', 'pl_PL' => 'pl', 'en_GB' => 'en'];
        $users = [];
        $roles = StagingContext::ROLES;
        $localeIndex = 0;
        foreach ($locales as $locale => $short) {
            // Names are drawn up front: the user factory also uses the shared random generator, which would shift the
            // second name of a locale between runs.
            $faker = FakerFactory::create($locale);
            $faker->seed(1100 + $localeIndex++);
            $names = [$faker->name(), $faker->name()];
            for ($i = 1; $i <= 2; $i++) {
                $created++;
                [$age, $offset] = $birthdays[$created - 1];
                $users[$roles[$created - 1]] = User::factory()->withPreset($presets[($created - 1) % count($presets)])->create([
                    'name' => $names[$i - 1].' (demo)',
                    'email' => strtolower($locale).".{$created}@example.test",
                    'locale' => $short,
                    'birth_date' => $today->addDays($offset)->subYears($age)->format('Y-m-d'),
                    ...$credentials,
                ]);
            }
        }

        // Synthetic admin: settings access only, no health or finance grants and no membership of anything.
        $admin = User::factory()->create([
            'name' => 'Admin (demo)',
            'email' => 'admin@example.test',
            ...$credentials,
        ]);
        $admin->forceFill(['is_admin' => true])->save();
        $users['admin'] = $admin;

        // Grants beyond the presets so the privacy scenarios have people on both sides: Health Edit for the two parents,
        // View for the Child and the Grandparent (the Guest and the second Child have none), and Finance View for the Child
        // (so there are finance accounts the Child can and cannot see).
        $grant = fn (string $role, Module $module, AccessLevel $level) => $users[$role]->modulePermissions()->updateOrCreate(['module' => $module->value], ['level' => $level]);
        $grant('parent1', Module::Health, AccessLevel::Edit);
        $grant('parent2', Module::Health, AccessLevel::Edit);
        $grant('child1', Module::Health, AccessLevel::View);
        $grant('grandparent', Module::Health, AccessLevel::View);
        $grant('child1', Module::Finance, AccessLevel::View);
        // The Guest preset has no Wishlist grant, so a list for the Guest would be invisible; View makes it readable
        // (and shows that the Guest cannot reserve).
        $grant('guest', Module::Wishlist, AccessLevel::View);

        $ctx = new StagingContext($today, $users);
        $this->summary['tasks'] = (new TasksSeeder)->seed($ctx);
        $this->summary['wishlist'] = (new WishlistSeeder)->seed($ctx);
        $this->summary['finance'] = (new FinanceSeeder)->seed($ctx);
        $health = (new HealthSeeder)->seed($ctx);
        $this->summary['health'] = $health['counts'];
        $documents = (new DocumentsSeeder)->seed($ctx, $health['subjects']);
        $this->summary['documents'] = count($documents);
        $this->summary['ai'] = (new AiProposalsSeeder)->seed($ctx, $health['subjects'], $documents);
        $this->summary['seconds'] = round(microtime(true) - $started, 2);

        return $created;
    }

    /**
     * Delete the domain rows the synthetic users own, so deleting the users leaves no orphan for staging:audit and does
     * not trip a RESTRICT foreign key. Rows of real users are never touched (the audit must keep failing on those).
     * Children are removed by the existing ON DELETE CASCADE foreign keys: task members, wish urls, reservations and
     * contributions, wishlist managers and own occasions, finance rows of an account (members, import rows, match
     * rejections), medical records, contacts, memberships, documents, AI proposals and their decisions, ai_notice_acks.
     * Finance categories are never created by the seed (only the migration's defaults are used), so none needs purging.
     * audit_logs and ai_calls are append-only and stay (the audit accepts their dangling synthetic ids). The files of the
     * medical documents of the deleted people are removed after the transaction commits (a rolled-back seed keeps them);
     * `medical:prune-orphans` stays the safety net.
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
        $people = DB::table('medical_subjects')->whereIn('created_by', $ids)
            ->where(fn ($q) => $q->whereNull('user_id')->orWhereIn('user_id', $ids));
        $subjectIds = $people->pluck('id')->map(fn ($id) => (string) $id)->all();
        $people->delete();
        if ($subjectIds !== []) {
            DB::afterCommit(function () use ($subjectIds) {
                $store = app(MedicalDocumentStore::class);
                foreach ($subjectIds as $id) {
                    try {
                        $store->deleteSubjectDirectory($id);
                    } catch (\Throwable $e) {
                        Log::warning('staging seed: could not remove the files of a reseeded person', ['reason' => $e::class]);
                    }
                }
            });
        }
    }
}
