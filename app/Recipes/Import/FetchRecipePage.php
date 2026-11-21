<?php

namespace App\Recipes\Import;

use App\Medical\Ai\Agents\AgentRegistry;
use App\Recipes\Ai\RunRecipeImportJob;
use App\Wishlist\Fetch\FetchFailed;
use App\Wishlist\Fetch\SafeFetcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Fetches the page of one import and reduces it to text, in the app `worker` container (default connection and
 * queue, like FetchWishImage), never in php-fpm and never in the ai-worker. All network access goes through
 * SafeFetcher. The queue payload is the import id only; the address is read from the row. The page never leaves this
 * job except as reduced text on the import row, and nothing here logs: failures are codes on the row.
 */
final class FetchRecipePage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const FETCH_REASONS = ['bad_url', 'blocked_address', 'too_many_redirects', 'http_status', 'too_large', 'timeout', 'network'];

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $importId) {}

    public function uniqueId(): string
    {
        return $this->importId;
    }

    public function handle(RecipeImportQuery $imports, RecipePageReducer $reducer, AgentRegistry $agents): void
    {
        $row = DB::table('recipe_imports')->where('id', $this->importId)->first(['url', 'status', 'cancel_requested_at']);
        if ($row === null || $row->status !== 'fetching' || $row->cancel_requested_at !== null) {
            return;
        }

        // The reaper measures from here: a job that waited behind other jobs has not started a fetch yet.
        DB::table('recipe_imports')->where('id', $this->importId)->where('status', 'fetching')->update(['fetch_started_at' => Carbon::now()]);

        try {
            $fetcher = app()->make(SafeFetcher::class, ['userAgent' => (string) config('recipes.import.user_agent')]);
            $page = $fetcher->get((string) $row->url, (int) config('recipes.import.html_max_bytes'), 'html');
        } catch (FetchFailed $e) {
            // SafeFetcher tells a blocking shop apart (wishlist); recipe import keeps its single http_status reason.
            $reason = $e->reason === 'http_blocked' ? 'http_status' : $e->reason;
            $imports->failFetching($this->importId, in_array($reason, self::FETCH_REASONS, true) ? $reason : 'network');

            return;
        }

        try {
            $reduced = $reducer->reduce($page);
        } catch (PageRejected $e) {
            $imports->failFetching($this->importId, $e->reason);

            return;
        } finally {
            unset($page);
        }

        $first = $agents->candidates()[0] ?? $agents->enabled()[0] ?? $agents->primary();
        $queued = DB::transaction(function () use ($reduced, $first): bool {
            $now = Carbon::now();
            // A cancel or a reaper that landed while the page was loading wins: nothing is stored then.
            $changed = DB::table('recipe_imports')->where('id', $this->importId)->where('status', 'fetching')->whereNull('cancel_requested_at')->update([
                'status' => 'queued',
                'source_text' => $reduced->text,
                'source_kind' => $reduced->kind,
                'text_cut' => $reduced->cut,
                'queued_at' => $now,
                'updated_at' => $now,
            ]);
            if ($changed === 0) {
                return false;
            }
            $userId = (int) DB::table('recipe_imports')->where('id', $this->importId)->value('user_id');
            DB::table('ai_calls')->insert([
                'extraction_id' => $this->importId,
                'kind' => 'recipe',
                'subject_id' => null,
                'document_id' => null,
                'started_by' => $userId,
                'agent' => $first->key(),
                'model' => null,
                'attempt' => 1,
                'status' => 'queued',
                'queued_at' => $now,
            ]);

            return true;
        });

        if (! $queued) {
            return;
        }
        try {
            RunRecipeImportJob::dispatch($this->importId);
        } catch (Throwable) {
            $imports->failQueued($this->importId);
        }
    }

    /** Last resort of the queue (timeout, killed worker): a still-fetching import is closed. */
    public function failed(Throwable $e): void
    {
        try {
            app(RecipeImportQuery::class)->failFetching($this->importId, 'network');
        } catch (Throwable) {
        }
    }
}
