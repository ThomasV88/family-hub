<?php

namespace Database\Seeders;

use App\Access\AccessLevel;
use App\Access\Module;
use App\Access\Preset;
use App\Actions\Profile\CreateChild;
use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Medical\Documents\MedicalDocumentStore;
use App\Models\User;
use App\Profile\ProfilePhotoStore;
use App\Recipes\Images\RecipeImageStore;
use App\Shopping\Images\ShoppingImageStore;
use App\Support\SyntheticChild;
use App\Wishlist\Catalog\ImageProcessor;
use App\Wishlist\WishlistManagerSync;
use Database\Seeders\Staging\AiProposalsSeeder;
use Database\Seeders\Staging\DocumentsSeeder;
use Database\Seeders\Staging\DocumentTextsSeeder;
use Database\Seeders\Staging\FinanceSeeder;
use Database\Seeders\Staging\HealthSeeder;
use Database\Seeders\Staging\RecipesSeeder;
use Database\Seeders\Staging\ShoppingSeeder;
use Database\Seeders\Staging\StagingContext;
use Database\Seeders\Staging\TasksSeeder;
use Database\Seeders\Staging\UploadBatchSeeder;
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
            // Synthetic = example.test e-mail, or a child (no e-mail) with the demo suffix whose managers are all
            // synthetic (the suffix alone is free text, see SyntheticChild). Never a real child. The ids are taken
            // first and deleted by id, because deleteSyntheticOwnedRows removes the manager rows the rule reads.
            $ids = User::query()->from('users as c')
                ->whereRaw("(c.email like '%@example.test' or ".SyntheticChild::condition('c').')')
                ->pluck('c.id')->all();
            $this->deleteSyntheticOwnedRows($ids);
            User::query()->whereIn('id', $ids)->delete();
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

        // One synthetic child, created through the production path (CreateChild) and managed by the first parent. The
        // seed writes no audit rows by hand, so the audit logger is a silent subclass here.
        $child = $this->seedChild($admin, $users['parent1']);

        $ctx = new StagingContext($today, $users);
        $this->summary['tasks'] = (new TasksSeeder)->seed($ctx);
        $this->summary['wishlist'] = (new WishlistSeeder)->seed($ctx);
        $this->summary['child_wishes'] = (new WishlistSeeder)->seedChild($child, $today);
        $this->summary['finance'] = (new FinanceSeeder)->seed($ctx);
        $health = (new HealthSeeder)->seed($ctx);
        $this->summary['health'] = $health['counts'];
        $documents = (new DocumentsSeeder)->seed($ctx, $health['subjects']);
        $this->summary['documents'] = count($documents);
        $this->summary['ai'] = (new AiProposalsSeeder)->seed($ctx, $health['subjects'], $documents);
        $this->summary['texts'] = (new DocumentTextsSeeder)->seed($ctx, $health['subjects'], $documents);
        $this->summary['upload_batch'] = (new UploadBatchSeeder)->seed($ctx, $health['subjects']);
        $this->summary['shopping'] = (new ShoppingSeeder)->seed($ctx);
        $this->summary['recipes'] = (new RecipesSeeder)->seed($ctx);
        $this->summary['seconds'] = round(microtime(true) - $started, 2);

        return $created;
    }

    /** A synthetic child (no e-mail, cannot sign in) with a generated photo. Name ends in " (demo)" so a reseed purges it. */
    private function seedChild(User $admin, User $parent): User
    {
        $silent = new class extends AuditLogger
        {
            public function record(AuditEvent $event, ?User $actor, array $meta = [], ?User $target = null, ?Module $module = null): void {}
        };
        $child = (new CreateChild(new WishlistManagerSync, $silent))->handle($admin, 'Maťko Demo (demo)', [$parent->id]);
        $child->forceFill(['locale' => 'sk'])->save();

        app(ProfilePhotoStore::class)->put($child, app(ImageProcessor::class)->process(
            $this->demoPhoto(),
            (int) config('profile.photo_max_bytes'),
            (int) config('profile.photo_full_px'),
            (int) config('profile.photo_thumb_px'),
        ));

        return $child;
    }

    /** Coloured shapes only: no face, no text, no network. Drawn in memory with GD. */
    private function demoPhoto(): string
    {
        $img = imagecreatetruecolor(400, 400);
        imagefilledrectangle($img, 0, 0, 400, 400, imagecolorallocate($img, 250, 214, 120));
        imagefilledellipse($img, 200, 230, 260, 260, imagecolorallocate($img, 70, 130, 200));
        imagefilledrectangle($img, 90, 60, 190, 160, imagecolorallocate($img, 220, 80, 80));
        imagefilledpolygon($img, [260, 160, 340, 60, 340, 160], imagecolorallocate($img, 60, 170, 110));
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    /**
     * Delete the domain rows the synthetic users own, so deleting the users leaves no orphan for staging:audit and does
     * not trip a RESTRICT foreign key. Rows of real users are never touched (the audit must keep failing on those).
     * Children are removed by the existing ON DELETE CASCADE foreign keys: task members, wish urls, reservations and
     * contributions, wishlist managers and own occasions, finance rows of an account (members, import rows, match
     * rejections), medical records, contacts, memberships, documents, recognised document texts, AI proposals and their decisions, ai_notice_acks.
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

        // Shopping: shopping_lists.creator_id is RESTRICT (items and members cascade). Catalog items, stores and custom
        // units made by a synthetic user go too; their picture folders are removed after the commit.
        DB::table('shopping_lists')->whereIn('creator_id', $ids)->delete();
        $catalogIds = DB::table('shopping_catalog_items')->whereIn('created_by', $ids)->pluck('id')->all();
        $storeIds = DB::table('shopping_stores')->whereIn('created_by', $ids)->pluck('id')->all();
        DB::table('shopping_catalog_items')->whereIn('id', $catalogIds)->delete();
        DB::table('shopping_stores')->whereIn('id', $storeIds)->delete();
        DB::table('shopping_units')->whereIn('created_by', $ids)->delete();
        if ($catalogIds !== [] || $storeIds !== []) {
            DB::afterCommit(function () use ($catalogIds, $storeIds) {
                $images = app(ShoppingImageStore::class);
                foreach ([ShoppingImageStore::KIND_CATALOG => $catalogIds, ShoppingImageStore::KIND_STORES => $storeIds] as $kind => $kindIds) {
                    foreach ($kindIds as $id) {
                        try {
                            $images->deleteDirectory($kind, (int) $id);
                        } catch (\Throwable $e) {
                            Log::warning('staging seed: could not remove shopping pictures of a reseeded row', ['reason' => $e::class]);
                        }
                    }
                }
            });
        }

        // Recipes: recipes.author_id is RESTRICT (photos, lines, categories, member states, ticks and done marks cascade).
        // Imports have no foreign key to users, so they go by user id. A synthetic member's own state, ticks and done marks
        // on a recipe of a real author go too (they cascade with the user, but the audit sees them until then). The photo
        // folders of the removed recipes are deleted after the commit.
        $recipeIds = DB::table('recipes')->whereIn('author_id', $ids)->pluck('id')->all();
        DB::table('recipes')->whereIn('id', $recipeIds)->delete();
        DB::table('recipe_imports')->whereIn('user_id', $ids)->delete();
        DB::table('recipe_member_states')->whereIn('user_id', $ids)->delete();
        DB::table('recipe_ingredient_ticks')->whereIn('user_id', $ids)->delete();
        DB::table('recipe_step_done')->whereIn('user_id', $ids)->delete();
        if ($recipeIds !== []) {
            DB::afterCommit(function () use ($recipeIds) {
                $images = app(RecipeImageStore::class);
                foreach ($recipeIds as $id) {
                    try {
                        $images->deleteDirectory((int) $id);
                    } catch (\Throwable $e) {
                        Log::warning('staging seed: could not remove recipe photos of a reseeded recipe', ['reason' => $e::class]);
                    }
                }
            });
        }

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
        // Phase 15: batches of a synthetic user (also those of a kept person); their pending documents and items cascade.
        DB::table('medical_upload_batches')->whereIn('created_by', $ids)->delete();
        DB::table('medical_ai_extractions')->whereIn('started_by', $ids)->delete();
        // Phase 13: recognised texts written for a synthetic user (also those of a kept person, which the cascade misses).
        DB::table('medical_document_texts')->whereIn('started_by', $ids)->delete();
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
