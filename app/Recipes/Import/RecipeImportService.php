<?php

namespace App\Recipes\Import;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Medical\Ai\Agents\AgentRegistry;
use App\Medical\Ai\LocalDay;
use App\Models\RecipeImport;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The request side of a recipe import: the one-time notice, start with its limits, cancel and discard. Every method
 * is scoped to the acting member (another member's import id answers 404). The recipe notice lives in its own column
 * (ai_notice_acks.recipe_providers) and never overlaps the medical one. Nothing here logs and no page content passes
 * through; the queue payload is the import id only.
 */
class RecipeImportService
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly AgentRegistry $agents,
        private readonly RecipeImportQuery $imports,
    ) {}

    /** @return array{available: bool, acknowledged: bool, providers: list<string>} */
    public function noticeState(User $user): array
    {
        $providers = array_values($this->agents->providers());

        return [
            'available' => $this->mayImport($user) && $this->agents->anyRunnable(),
            'acknowledged' => $this->covers($this->ackedProviders($user), $providers),
            'providers' => $providers,
        ];
    }

    /**
     * Stores the acknowledgment for the provider set the member saw, but only when that set is the enabled set right
     * now (read under a shared lock that provider changes take exclusively). False means nothing was stored and the
     * dialog must open again with the current set.
     *
     * @param  list<string>  $shownProviders
     */
    public function acknowledge(User $user, array $shownProviders): bool
    {
        if (! $this->mayImport($user)) {
            return false;
        }

        return DB::transaction(function () use ($user, $shownProviders): bool {
            DB::select("select pg_advisory_xact_lock_shared(hashtext('ai-agent-settings'))");
            $enabled = array_values($this->agents->providers());
            if (! self::sameSet($shownProviders, $enabled)) {
                return false;
            }
            $set = $enabled;
            sort($set);
            // A new row has no medical acknowledgment (providers NULL); an existing one keeps its medical columns.
            DB::table('ai_notice_acks')->upsert(
                [['user_id' => $user->id, 'acknowledged_at' => Carbon::now(), 'providers' => null, 'recipe_providers' => implode(',', $set)]],
                ['user_id'],
                ['recipe_providers'],
            );

            return true;
        });
    }

    public function start(User $user, string $url): StartOutcome
    {
        $normalised = ImportUrl::normalise($url);
        if ($normalised === null) {
            return new StartOutcome('invalid_url');
        }
        if (! $this->mayImport($user) || ! $this->agents->anyRunnable()) {
            return new StartOutcome('unavailable');
        }

        $hash = ImportUrl::hash($normalised);
        $outcome = DB::transaction(function () use ($user, $normalised, $hash): StartOutcome {
            // Enabling or disabling a provider takes this lock exclusively, so the set cannot change before commit.
            DB::select("select pg_advisory_xact_lock_shared(hashtext('ai-agent-settings'))");
            $enabled = array_values($this->agents->providers());
            if (! $this->covers($this->ackedProviders($user), $enabled)) {
                return new StartOutcome('notice_required', null, $enabled);
            }

            DB::select("select pg_advisory_xact_lock(hashtext('recipe-import-start'))");

            $existing = $this->reusable($user, $hash);
            if ($existing !== null) {
                return new StartOutcome('reused', $existing);
            }
            if ($this->overLimit($user)) {
                return new StartOutcome('limit');
            }

            $id = (string) Str::uuid();
            $now = Carbon::now();
            try {
                DB::transaction(fn () => DB::table('recipe_imports')->insert([
                    'id' => $id,
                    'user_id' => $user->id,
                    'url' => $normalised,
                    'url_hash' => $hash,
                    'host' => ImportUrl::host($normalised),
                    'status' => 'fetching',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? null) === '23505') {
                    // A double click that slipped past the lock: report the winner.
                    $winner = $this->reusable($user, $hash);
                    if ($winner !== null) {
                        return new StartOutcome('reused', $winner);
                    }
                }
                throw $e;
            }

            return new StartOutcome('started', $id);
        });

        if ($outcome->outcome === 'started') {
            // After the commit by construction: the worker always finds the row.
            try {
                FetchRecipePage::dispatch((string) $outcome->id);
            } catch (Throwable) {
                $this->imports->failFetching((string) $outcome->id, 'network');
            }
        }

        return $outcome;
    }

    /**
     * Own import only. Fetching or queued: cancelled at once. Running: asked to stop. Terminal: nothing changes.
     *
     * @return string the status after the call
     */
    public function cancel(User $user, string $id): string
    {
        return DB::transaction(function () use ($user, $id): string {
            $row = $this->lockOwn($user, $id);
            if (in_array($row->status, ['fetching', 'queued'], true)) {
                $this->closeNow($id, 'user');

                return 'cancelled';
            }
            if ($row->status === 'running') {
                $now = Carbon::now();
                DB::table('recipe_imports')->where('id', $id)->where('status', 'running')->whereNull('cancel_requested_at')
                    ->update(['cancel_requested_at' => $now, 'updated_at' => $now]);
            }

            return (string) $row->status;
        });
    }

    /**
     * Own import only. An active one is cancelled, a ready draft is dropped (payload gone), a failed or cancelled one
     * is hidden from the list.
     *
     * @return string the status after the call
     */
    public function discard(User $user, string $id): string
    {
        return DB::transaction(function () use ($user, $id): string {
            $row = $this->lockOwn($user, $id);
            $now = Carbon::now();
            if (in_array($row->status, ImportStatus::active(), true)) {
                return $this->cancel($user, $id);
            }
            if ($row->status === 'ready') {
                $this->closeNow($id, 'user');

                return 'cancelled';
            }
            if (in_array($row->status, ['failed', 'cancelled'], true)) {
                DB::table('recipe_imports')->where('id', $id)->whereNull('dismissed_at')->update(['dismissed_at' => $now, 'updated_at' => $now]);
            }

            return (string) $row->status;
        });
    }

    private function mayImport(User $user): bool
    {
        // Long-lived workers must not trust a cached grant.
        $this->gate->forget($user);

        return $this->gate->allows($user, Module::Recipes, AccessLevel::Edit);
    }

    /** An active import, or a ready one that is still listed, for the same address. */
    private function reusable(User $user, string $hash): ?string
    {
        $id = DB::table('recipe_imports')->where('user_id', $user->id)->where('url_hash', $hash)
            ->where(fn ($q) => $q->whereIn('status', ImportStatus::active())
                ->orWhere(fn ($r) => $r->where('status', 'ready')->whereNull('dismissed_at')
                    ->where('created_at', '>=', Carbon::now()->subHours((int) config('recipes.import.ready_ttl_hours')))))
            ->orderByDesc('created_at')->value('id');

        return $id === null ? null : (string) $id;
    }

    /** Counted under the start lock, so the bounds hold under concurrent starts. */
    private function overLimit(User $user): bool
    {
        $active = fn () => DB::table('recipe_imports')->whereIn('status', ImportStatus::active());
        if ($active()->where('user_id', $user->id)->count() >= (int) config('recipes.import.active_per_user')) {
            return true;
        }
        if ($active()->count() >= (int) config('recipes.import.global_active')) {
            return true;
        }

        return DB::table('recipe_imports')->where('user_id', $user->id)->where('created_at', '>=', LocalDay::startBinding())->count()
            >= (int) config('recipes.import.per_user_per_day');
    }

    private function lockOwn(User $user, string $id): object
    {
        $row = Str::isUuid($id)
            ? DB::table('recipe_imports')->where('id', $id)->where('user_id', $user->id)->lockForUpdate()->first(['status'])
            : null;
        if ($row === null) {
            throw (new ModelNotFoundException)->setModel(RecipeImport::class);
        }

        return $row;
    }

    /** Ends an import that holds no worker: cancelled, text and draft gone, queued call row closed. */
    private function closeNow(string $id, string $reason): void
    {
        $now = Carbon::now();
        DB::table('recipe_imports')->where('id', $id)->update([
            'status' => 'cancelled', 'cancel_reason' => $reason, 'source_text' => null, 'payload' => null,
            'finished_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('ai_calls')->where('extraction_id', $id)->where('kind', 'recipe')->where('status', 'queued')
            ->update(['status' => 'cancelled', 'model' => 'default', 'finished_at' => $now]);
    }

    /** @return list<string> the providers named in this member's recipe acknowledgment */
    private function ackedProviders(User $user): array
    {
        return $this->imports->ackedRecipeProviders($user->id);
    }

    /**
     * @param  list<string>  $acked
     * @param  list<string>  $enabled
     */
    private function covers(array $acked, array $enabled): bool
    {
        return array_diff($enabled, $acked) === [];
    }

    /**
     * @param  list<string>  $shown
     * @param  list<string>  $enabled
     */
    private static function sameSet(array $shown, array $enabled): bool
    {
        $a = array_values(array_unique(array_map('strval', $shown)));
        $b = array_values(array_unique($enabled));
        sort($a);
        sort($b);

        return $a === $b;
    }
}
