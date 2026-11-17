<?php

use App\Medical\Queries\AiJobQuery;
use App\Models\AiAgentSetting;
use App\Models\RecipeImport;
use App\Models\User;
use App\Recipes\Import\FetchRecipePage;
use App\Recipes\Import\RecipeImportService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecipeImportFixtures as F;

function istService(): RecipeImportService
{
    return app(RecipeImportService::class);
}

beforeEach(function () {
    Queue::fake();
    F::enableCodex();
});

it('offers import only with an available agent and Recipes edit', function () {
    $member = F::member();
    expect(istService()->noticeState($member))->toBe(['available' => true, 'acknowledged' => true, 'providers' => ['codex']]);

    AiAgentSetting::query()->toBase()->where('agent', 'codex')->update(['enabled' => false]);
    expect(istService()->noticeState($member)['available'])->toBeFalse();
    AiAgentSetting::query()->toBase()->where('agent', 'codex')->update(['enabled' => true]);

    expect(istService()->noticeState(F::member('codex', 'view'))['available'])->toBeFalse()
        ->and(istService()->noticeState(F::member('codex', 'none'))['available'])->toBeFalse();
});

it('never lets the medical notice stand in for the recipe notice, or the other way round', function () {
    $user = F::member(null);
    DB::table('ai_notice_acks')->insert(['user_id' => $user->id, 'providers' => 'codex']);

    expect(istService()->noticeState($user)['acknowledged'])->toBeFalse()
        ->and(istService()->start($user, F::PAGE_URL)->outcome)->toBe('notice_required');

    expect(istService()->acknowledge($user, ['codex']))->toBeTrue();
    $row = DB::table('ai_notice_acks')->where('user_id', $user->id)->first();
    expect($row->recipe_providers)->toBe('codex')->and($row->providers)->toBe('codex');

    // A recipe-only acknowledgment leaves the medical side empty.
    $other = F::member(null);
    expect(istService()->acknowledge($other, ['codex']))->toBeTrue()
        ->and(DB::table('ai_notice_acks')->where('user_id', $other->id)->value('providers'))->toBeNull()
        ->and(app(AiJobQuery::class)->ackedProviders($other->id))->toBe([])
        ->and(istService()->noticeState($other)['acknowledged'])->toBeTrue();
});

it('asks again once a further provider is enabled', function () {
    $member = F::member('codex');
    F::enableCopilot();

    expect(istService()->noticeState($member))->toBe(['available' => true, 'acknowledged' => false, 'providers' => ['codex', 'copilot']])
        ->and(istService()->start($member, F::PAGE_URL))->outcome->toBe('notice_required')
        ->and(istService()->start($member, F::PAGE_URL)->providers)->toBe(['codex', 'copilot']);
    Queue::assertNothingPushed();

    expect(istService()->acknowledge($member, ['copilot', 'codex']))->toBeTrue()
        ->and(DB::table('ai_notice_acks')->where('user_id', $member->id)->value('recipe_providers'))->toBe('codex,copilot')
        ->and(istService()->start($member, F::PAGE_URL)->outcome)->toBe('started');
});

it('refuses an acknowledgment of a stale provider set and stores nothing', function () {
    $member = F::member(null);
    F::enableCopilot();

    expect(istService()->acknowledge($member, ['codex']))->toBeFalse()
        ->and(DB::table('ai_notice_acks')->where('user_id', $member->id)->exists())->toBeFalse();
    // Without Recipes edit there is nothing to acknowledge.
    expect(istService()->acknowledge(F::member(null, 'view'), ['codex', 'copilot']))->toBeFalse();
});

it('starts an import, stores a fetching row and dispatches the fetch with the id only', function () {
    $member = F::member();

    $out = istService()->start($member, ' HTTPS://Recipes.Example/pancakes#top ');

    expect($out->outcome)->toBe('started');
    $row = DB::table('recipe_imports')->where('id', $out->id)->first();
    expect($row->status)->toBe('fetching')->and($row->user_id)->toBe($member->id)
        ->and($row->url)->toBe('https://recipes.example/pancakes')->and($row->host)->toBe('recipes.example')
        ->and($row->source_text)->toBeNull()
        ->and(DB::table('ai_calls')->where('extraction_id', $out->id)->exists())->toBeFalse();
    Queue::assertPushed(FetchRecipePage::class, function (FetchRecipePage $job) use ($out) {
        $serialized = serialize($job);

        return $job->importId === $out->id && ! str_contains($serialized, 'recipes.example') && ! str_contains($serialized, 'pancakes')
            && $job->connection === null && $job->queue === null;
    });
});

it('answers invalid_url and unavailable without writing anything', function () {
    $member = F::member();
    expect(istService()->start($member, 'ftp://x.example/a')->outcome)->toBe('invalid_url')
        ->and(istService()->start($member, 'https://u:p@x.example/a')->outcome)->toBe('invalid_url')
        ->and(istService()->start(F::member('codex', 'view'), F::PAGE_URL)->outcome)->toBe('unavailable');

    AiAgentSetting::query()->toBase()->where('agent', 'codex')->update(['enabled' => false]);
    expect(istService()->start($member, F::PAGE_URL)->outcome)->toBe('unavailable')
        ->and(DB::table('recipe_imports')->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('returns the existing import for the same url while active and while ready, not once dismissed', function () {
    $member = F::member();
    $first = istService()->start($member, F::PAGE_URL);

    $again = istService()->start($member, F::PAGE_URL.'#other');
    expect($again->outcome)->toBe('reused')->and($again->id)->toBe($first->id)
        ->and(DB::table('recipe_imports')->count())->toBe(1);

    DB::table('recipe_imports')->where('id', $first->id)->update(['status' => 'ready', 'payload' => '{"a":1}', 'source_text' => null]);
    expect(istService()->start($member, F::PAGE_URL))->id->toBe($first->id);

    DB::table('recipe_imports')->where('id', $first->id)->update(['dismissed_at' => now()]);
    $fresh = istService()->start($member, F::PAGE_URL);
    expect($fresh->outcome)->toBe('started')->and($fresh->id)->not->toBe($first->id);

    // Another member's import of the same address is not reused.
    expect(istService()->start(F::member(), F::PAGE_URL)->outcome)->toBe('started');
});

it('limits active imports per member, per local day and globally', function () {
    $member = F::member();
    RecipeImport::factory()->count(2)->create(['user_id' => $member->id]);
    expect(istService()->start($member, 'https://recipes.example/third')->outcome)->toBe('limit');
    // An existing import of the same address is still returned at the limit.
    $existing = RecipeImport::factory()->forUrl('https://recipes.example/same')->create(['user_id' => $member->id]);
    DB::table('recipe_imports')->where('id', '!=', $existing->id)->where('user_id', $member->id)->delete();
    expect(istService()->start($member, 'https://recipes.example/same')->outcome)->toBe('reused');

    $busy = F::member();
    RecipeImport::factory()->count(20)->failed()->create(['user_id' => $busy->id]);
    expect(istService()->start($busy, 'https://recipes.example/21st')->outcome)->toBe('limit');

    DB::table('recipe_imports')->where('user_id', $busy->id)->update(['created_at' => now()->subDays(2)]);
    expect(istService()->start($busy, 'https://recipes.example/next-day')->outcome)->toBe('started');

    DB::table('recipe_imports')->delete();
    foreach (range(1, 10) as $_) {
        RecipeImport::factory()->create(['user_id' => User::factory()->create()->id]);
    }
    expect(istService()->start(F::member(), 'https://recipes.example/global')->outcome)->toBe('limit');
});

it('cancels a fetching or queued import at once and closes its call row', function () {
    $member = F::member();
    $fetching = RecipeImport::factory()->create(['user_id' => $member->id]);
    $queued = RecipeImport::factory()->queued()->create(['user_id' => $member->id]);
    DB::table('ai_calls')->insert(['extraction_id' => $queued->id, 'kind' => 'recipe', 'started_by' => $member->id, 'agent' => 'codex', 'attempt' => 1, 'status' => 'queued', 'queued_at' => now()]);

    expect(istService()->cancel($member, $fetching->id))->toBe('cancelled')
        ->and(istService()->cancel($member, $queued->id))->toBe('cancelled');

    $row = DB::table('recipe_imports')->where('id', $queued->id)->first();
    expect($row->status)->toBe('cancelled')->and($row->cancel_reason)->toBe('user')->and($row->source_text)->toBeNull()
        ->and(DB::table('ai_calls')->where('extraction_id', $queued->id)->value('status'))->toBe('cancelled');
});

it('asks a running import to stop and leaves terminal ones alone', function () {
    $member = F::member();
    $running = RecipeImport::factory()->running()->create(['user_id' => $member->id]);
    $ready = RecipeImport::factory()->ready()->create(['user_id' => $member->id]);

    expect(istService()->cancel($member, $running->id))->toBe('running')
        ->and(DB::table('recipe_imports')->where('id', $running->id)->first())->status->toBe('running')->cancel_requested_at->not->toBeNull()
        ->and(istService()->cancel($member, $ready->id))->toBe('ready')
        ->and(DB::table('recipe_imports')->where('id', $ready->id)->value('payload'))->not->toBeNull();
});

it('discards by state: active cancels, ready drops the payload, failed hides', function () {
    $member = F::member();
    $active = RecipeImport::factory()->create(['user_id' => $member->id]);
    $ready = RecipeImport::factory()->ready(['is_recipe' => true, 'title' => 'x'])->create(['user_id' => $member->id]);
    $failed = RecipeImport::factory()->failed()->create(['user_id' => $member->id]);

    expect(istService()->discard($member, $active->id))->toBe('cancelled')
        ->and(istService()->discard($member, $ready->id))->toBe('cancelled')
        ->and(istService()->discard($member, $failed->id))->toBe('failed');

    $r = DB::table('recipe_imports')->where('id', $ready->id)->first();
    expect($r->status)->toBe('cancelled')->and($r->payload)->toBeNull()
        ->and(DB::table('recipe_imports')->where('id', $failed->id)->value('dismissed_at'))->not->toBeNull();
});

it('answers 404 for another member\'s import and for a malformed id', function () {
    $member = F::member();
    $theirs = RecipeImport::factory()->ready()->create(['user_id' => F::member()->id]);

    expect(fn () => istService()->cancel($member, $theirs->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => istService()->discard($member, $theirs->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => istService()->cancel($member, 'nope'))->toThrow(ModelNotFoundException::class)
        ->and(DB::table('recipe_imports')->where('id', $theirs->id)->value('status'))->toBe('ready');
});
