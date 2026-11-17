<?php

use App\Models\RecipeImport;
use App\Recipes\Ai\RunRecipeImportJob;
use App\Recipes\Import\FetchRecipePage;
use App\Wishlist\Fetch\FetchFailed;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecipeImportFixtures as F;

function frpImport(array $over = []): RecipeImport
{
    $user = F::member();

    return RecipeImport::factory()->forUrl(F::PAGE_URL)->create(array_merge(['user_id' => $user->id], $over));
}

function frpRun(RecipeImport $import): void
{
    app()->call([new FetchRecipePage($import->id), 'handle']);
}

function frpHtml(string $body, string $type = 'text/html; charset=utf-8', int $status = 200): Response
{
    return new Response($status, ['Content-Type' => $type], $body);
}

beforeEach(function () {
    Queue::fake();
    F::enableCodex();
});

it('fetches a json-ld page, stores the reduced text and queues the ai job with the id only', function () {
    $import = frpImport();
    $mock = F::fetcher([frpHtml(F::jsonLdPage())]);

    frpRun($import);

    $row = DB::table('recipe_imports')->where('id', $import->id)->first();
    expect($row->status)->toBe('queued')->and($row->source_kind)->toBe('json_ld')->and($row->text_cut)->toBeFalse()
        ->and($row->source_text)->toContain('Pancakes')->and($row->queued_at)->not->toBeNull()
        ->and($row->failure_code)->toBeNull();
    $call = DB::table('ai_calls')->where('extraction_id', $import->id)->first();
    expect($call->kind)->toBe('recipe')->and($call->status)->toBe('queued')->and($call->subject_id)->toBeNull()
        ->and($call->document_id)->toBeNull()->and($call->started_by)->toBe($import->user_id)->and($call->attempt)->toBe(1)
        ->and($call->agent)->toBe('codex');
    Queue::assertPushed(RunRecipeImportJob::class, function (RunRecipeImportJob $job) use ($import) {
        $serialized = serialize($job);

        return $job->importId === $import->id && $job->connection === 'database' && $job->queue === config('ai.queue')
            && ! str_contains($serialized, 'recipes.example') && ! str_contains($serialized, 'flour');
    });
    expect($mock->getLastRequest()->getHeaderLine('User-Agent'))->toBe('FamilyHubRecipeFetcher/1.0')
        ->and((string) $mock->getLastRequest()->getUri())->toBe(F::PAGE_URL);
});

it('runs on the default queue of the worker container, not on the ai queue', function () {
    $job = new FetchRecipePage('x');

    expect($job->connection)->toBeNull()->and($job->queue)->toBeNull()
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('x');
});

it('fails without an ai call row for a host that resolves to a private address', function () {
    $import = frpImport();
    F::fetcher([], [F::PAGE_HOST => ['10.0.0.5']]);

    frpRun($import);

    $row = DB::table('recipe_imports')->where('id', $import->id)->first();
    expect($row->status)->toBe('failed')->and($row->failure_code)->toBe('fetch_failed')->and($row->fetch_reason)->toBe('blocked_address')
        ->and($row->source_text)->toBeNull()
        ->and(DB::table('ai_calls')->where('extraction_id', $import->id)->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

it('refuses a redirect to the metadata address', function () {
    $import = frpImport();
    F::fetcher([new Response(302, ['Location' => 'http://169.254.169.254/latest/meta-data'])]);

    frpRun($import);

    expect(DB::table('recipe_imports')->where('id', $import->id)->first())
        ->status->toBe('failed')->fetch_reason->toBe('blocked_address');
    Queue::assertNothingPushed();
});

it('maps fetch and page failures to reason codes', function (callable $response, string $reason) {
    $import = frpImport();
    F::fetcher([$response()]);

    frpRun($import);

    expect(DB::table('recipe_imports')->where('id', $import->id)->first())
        ->status->toBe('failed')->failure_code->toBe('fetch_failed')->fetch_reason->toBe($reason);
    Queue::assertNothingPushed();
})->with([
    'http 403' => [fn () => frpHtml('forbidden', 'text/html', 403), 'http_status'],
    'http 500' => [fn () => frpHtml('oops', 'text/html', 500), 'http_status'],
    'three megabytes' => [fn () => frpHtml(str_repeat('a', 3 * 1024 * 1024)), 'too_large'],
    'image' => [fn () => frpHtml('PNG', 'image/png'), 'not_html'],
    'empty body' => [fn () => frpHtml(''), 'empty'],
]);

it('does not fetch an import that was cancelled or is no longer fetching', function (array $over) {
    $import = frpImport($over);
    $mock = F::fetcher([frpHtml(F::jsonLdPage())]);

    frpRun($import);

    expect($mock->count())->toBe(1)
        ->and(DB::table('ai_calls')->where('extraction_id', $import->id)->exists())->toBeFalse();
    Queue::assertNothingPushed();
})->with([
    'cancelled' => [['status' => 'cancelled', 'cancel_reason' => 'user']],
    'cancel requested' => [['cancel_requested_at' => now()]],
    'already queued' => [['status' => 'queued', 'source_text' => 'x', 'source_kind' => 'text']],
]);

it('stores nothing when the import was cancelled while the page loaded', function () {
    $import = frpImport();
    F::fetcher([function () use ($import) {
        DB::table('recipe_imports')->where('id', $import->id)->update(['status' => 'cancelled', 'cancel_reason' => 'user']);

        return frpHtml(F::jsonLdPage());
    }]);

    frpRun($import);

    expect(DB::table('recipe_imports')->where('id', $import->id)->first())->status->toBe('cancelled')->source_text->toBeNull()
        ->and(DB::table('ai_calls')->where('extraction_id', $import->id)->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

it('closes a still-fetching row as failed when the queue gives up', function () {
    $import = frpImport();
    (new FetchRecipePage($import->id))->failed(new RuntimeException('secret https://recipes.example/x'));

    expect(DB::table('recipe_imports')->where('id', $import->id)->first())
        ->status->toBe('failed')->fetch_reason->toBe('network')->failure_code->toBe('fetch_failed');

    $queued = frpImport(['status' => 'queued', 'source_text' => 'x', 'source_kind' => 'text']);
    (new FetchRecipePage($queued->id))->failed(new RuntimeException('x'));
    expect(DB::table('recipe_imports')->where('id', $queued->id)->value('status'))->toBe('queued');
});

it('keeps unknown fetch reasons content-free', function () {
    $import = frpImport();
    F::fetcher([new FetchFailed('weird')]);

    frpRun($import);

    expect(DB::table('recipe_imports')->where('id', $import->id)->first())->status->toBe('failed')->fetch_reason->toBe('network');
});
