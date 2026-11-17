<?php

use App\Models\RecipeImport;
use App\Recipes\Import\FetchRecipePage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecipeImportFixtures as F;

beforeEach(function () {
    Queue::fake();
    F::enableCodex();
});

it('refuses a stale provider set and stores the acknowledgment for the current one', function () {
    $user = F::member(null);

    $this->actingAs($user)->postJson('/recipes/imports/notice', ['providers' => ['copilot']])
        ->assertStatus(409)->assertExactJson(['outcome' => 'notice_changed', 'providers' => ['codex']]);
    expect(DB::table('ai_notice_acks')->where('user_id', $user->id)->exists())->toBeFalse();

    $this->actingAs($user)->postJson('/recipes/imports/notice', ['providers' => ['codex']])->assertNoContent();
    expect(DB::table('ai_notice_acks')->where('user_id', $user->id)->value('recipe_providers'))->toBe('codex');

    $this->actingAs($user)->postJson('/recipes/imports/notice', [])->assertStatus(422);
});

it('maps every start outcome to its status and body', function () {
    $user = F::member(null);

    $this->actingAs($user)->postJson('/recipes/imports', ['url' => F::PAGE_URL])
        ->assertStatus(409)->assertExactJson(['outcome' => 'notice_required', 'providers' => ['codex']]);

    F::member(); // unrelated member
    DB::table('ai_notice_acks')->insert(['user_id' => $user->id, 'providers' => null, 'recipe_providers' => 'codex']);

    $started = $this->actingAs($user)->postJson('/recipes/imports', ['url' => F::PAGE_URL])->assertStatus(202)->json();
    expect($started['outcome'])->toBe('started')->and(array_keys($started))->toBe(['outcome', 'id']);
    Queue::assertPushed(FetchRecipePage::class);

    $again = $this->actingAs($user)->postJson('/recipes/imports', ['url' => F::PAGE_URL])->assertStatus(202)->json();
    expect($again)->toBe(['outcome' => 'reused', 'id' => $started['id']]);

    $this->actingAs($user)->postJson('/recipes/imports', ['url' => 'javascript:alert(1)'])
        ->assertStatus(422)->assertExactJson(['errors' => ['url' => ['recipes.import.err_url']]]);
    $this->actingAs($user)->postJson('/recipes/imports', [])
        ->assertStatus(422)->assertExactJson(['errors' => ['url' => ['recipes.import.err_url']]]);

    $this->actingAs($user)->postJson('/recipes/imports', ['url' => 'https://recipes.example/second'])->assertStatus(202);
    $this->actingAs($user)->postJson('/recipes/imports', ['url' => 'https://recipes.example/third'])
        ->assertStatus(429)->assertExactJson(['outcome' => 'limit']);
});

it('answers unavailable when no agent can run', function () {
    $user = F::member();
    DB::table('ai_agent_settings')->update(['enabled' => false]);

    $this->actingAs($user)->postJson('/recipes/imports', ['url' => F::PAGE_URL])
        ->assertStatus(409)->assertExactJson(['outcome' => 'unavailable']);
});

it('throttles the eleventh start in a minute', function () {
    $user = F::member();
    for ($i = 1; $i <= 10; $i++) {
        $this->actingAs($user)->postJson('/recipes/imports', ['url' => 'javascript:x'])->assertStatus(422);
    }

    $this->actingAs($user)->postJson('/recipes/imports', ['url' => 'javascript:x'])->assertStatus(429);
});

it('shows a coarse status and never the address path, the text or the raw failure code', function () {
    $user = F::member();
    $url = 'https://recipes.example/secret-path/pancakes';
    $cases = [
        'fetch_failed' => 'fetch', 'no_recipe' => 'no_recipe', 'invalid_output' => 'no_recipe', 'no_output' => 'no_recipe',
        'output_too_large' => 'no_recipe', 'cli_error' => 'no_recipe', 'timeout' => 'no_recipe', 'idle_timeout' => 'no_recipe',
        'worker_lost' => 'no_recipe', 'quota_exhausted' => 'unavailable', 'quota_timeout' => 'unavailable', 'no_agent' => 'unavailable',
        'auth' => 'unavailable', 'notice_required' => 'unavailable', 'preflight' => 'unavailable',
    ];
    foreach ($cases as $code => $reason) {
        $import = RecipeImport::factory()->forUrl($url)->failed($code)->create(['user_id' => $user->id, 'fetch_reason' => $code === 'fetch_failed' ? 'timeout' : null]);
        $response = $this->actingAs($user)->getJson('/recipes/imports/'.$import->id)->assertOk();
        expect($response->json())->toBe(['id' => $import->id, 'status' => 'failed', 'reason' => $reason, 'host' => 'recipes.example', 'textCut' => false])
            ->and($response->getContent())->not->toContain('secret-path');
    }

    $running = RecipeImport::factory()->forUrl($url)->running('Page text CANARY-RUN')->create(['user_id' => $user->id]);
    $body = $this->actingAs($user)->getJson('/recipes/imports/'.$running->id)->assertOk();
    expect($body->json('status'))->toBe('running')->and($body->json('reason'))->toBeNull()
        ->and($body->getContent())->not->toContain('CANARY-RUN');

    $ready = RecipeImport::factory()->ready(['is_recipe' => true, 'title' => 'Secret title'])->create(['user_id' => $user->id]);
    $body = $this->actingAs($user)->getJson('/recipes/imports/'.$ready->id)->assertOk();
    expect($body->json('status'))->toBe('ready')->and($body->getContent())->not->toContain('Secret title');
});

it('answers 404 for another member, an unknown id and a malformed id on every route', function () {
    $owner = F::member();
    $other = F::member();
    $import = RecipeImport::factory()->ready(['is_recipe' => true])->create(['user_id' => $owner->id]);
    $active = RecipeImport::factory()->forUrl(F::PAGE_URL)->running()->create(['user_id' => $owner->id]);
    $unknown = '00000000-0000-4000-8000-000000000000';

    foreach ([$import->id, $unknown] as $id) {
        $this->actingAs($other)->getJson('/recipes/imports/'.$id)->assertNotFound();
        $this->actingAs($other)->postJson('/recipes/imports/'.$id.'/cancel')->assertNotFound();
        $this->actingAs($other)->deleteJson('/recipes/imports/'.$id)->assertNotFound();
    }
    $this->actingAs($other)->getJson('/recipes/imports/not-a-uuid')->assertNotFound();
    $this->actingAs($other)->postJson('/recipes/imports/'.$active->id.'/cancel')->assertNotFound();

    expect(DB::table('recipe_imports')->where('id', $import->id)->value('status'))->toBe('ready')
        ->and(DB::table('recipe_imports')->where('id', $active->id)->value('cancel_requested_at'))->toBeNull();
});

it('cancels an active import and discards a ready draft and a failed one', function () {
    $user = F::member();
    $queued = RecipeImport::factory()->forUrl(F::PAGE_URL)->queued('text')->create(['user_id' => $user->id]);
    $ready = RecipeImport::factory()->ready(['is_recipe' => true])->create(['user_id' => $user->id]);
    $failed = RecipeImport::factory()->failed()->create(['user_id' => $user->id]);

    $this->actingAs($user)->postJson('/recipes/imports/'.$queued->id.'/cancel')->assertNoContent();
    $row = DB::table('recipe_imports')->where('id', $queued->id)->first();
    expect($row->status)->toBe('cancelled')->and($row->source_text)->toBeNull();

    $this->actingAs($user)->deleteJson('/recipes/imports/'.$ready->id)->assertNoContent();
    $row = DB::table('recipe_imports')->where('id', $ready->id)->first();
    expect($row->status)->toBe('cancelled')->and($row->payload)->toBeNull();

    $this->actingAs($user)->deleteJson('/recipes/imports/'.$failed->id)->assertNoContent();
    expect(DB::table('recipe_imports')->where('id', $failed->id)->value('dismissed_at'))->not->toBeNull();
});

it('refuses a member without Recipes edit on every route', function () {
    $viewer = F::member(null, 'view');
    $import = RecipeImport::factory()->failed()->create(['user_id' => $viewer->id]);

    $this->actingAs($viewer)->postJson('/recipes/imports/notice', ['providers' => ['codex']])->assertForbidden();
    $this->actingAs($viewer)->postJson('/recipes/imports', ['url' => F::PAGE_URL])->assertForbidden();
    $this->actingAs($viewer)->getJson('/recipes/imports/'.$import->id)->assertForbidden();
    $this->actingAs($viewer)->postJson('/recipes/imports/'.$import->id.'/cancel')->assertForbidden();
    $this->actingAs($viewer)->deleteJson('/recipes/imports/'.$import->id)->assertForbidden();
});
