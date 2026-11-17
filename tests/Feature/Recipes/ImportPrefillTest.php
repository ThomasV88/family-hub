<?php

use App\Models\Recipe;
use App\Models\RecipeImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RecipeFixtures;
use Tests\Support\RecipeImportFixtures as F;

uses(RefreshDatabase::class);

/** @param array<string, mixed> $over */
function ipfPayload(array $over = []): array
{
    return array_replace([
        'schema_version' => 1, 'is_recipe' => true, 'title' => 'Pancakes', 'description' => 'Thin pancakes.',
        'servings' => 4, 'prep_minutes' => 10, 'cook_minutes' => null, 'categories' => ['breakfast'],
        'ingredients' => [
            ['name' => 'Flour', 'quantity' => 250, 'unit' => 'g', 'note' => null, 'original' => '250 g flour'],
            ['name' => 'Eggs', 'quantity' => 1.5, 'unit' => null, 'note' => 'large', 'original' => '1 1/2 large eggs'],
        ],
        'steps' => ['Mix the batter.', 'Fry thin pancakes.'],
    ], $over);
}

/** @return array<string, mixed> a Recipes save body */
function ipfBody(array $over = []): array
{
    return array_merge([
        'title' => 'Reviewed pancakes', 'visibility' => 'private',
        'ingredients' => [['name' => 'Flour', 'quantity' => '250']], 'steps' => [['body' => 'Mix']],
        'source_url' => F::PAGE_URL,
    ], $over);
}

it('adds the import flags and only the member\'s own drafts to the overview', function () {
    F::enableCodex();
    $me = F::member();
    $other = F::member();
    $ready = RecipeImport::factory()->forUrl(F::PAGE_URL)->ready(ipfPayload())->create(['user_id' => $me->id]);
    $failed = RecipeImport::factory()->failed('worker_lost')->create(['user_id' => $me->id, 'host' => 'broken.example']);
    RecipeImport::factory()->ready(ipfPayload(['title' => 'Other secret']))->create(['user_id' => $other->id]);
    RecipeImport::factory()->cancelled()->create(['user_id' => $me->id]);

    $response = $this->actingAs($me)->get('/recipes')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Recipes/Index')
        ->where('import.acknowledged', true)
        ->where('import.providers', ['codex'])
        ->has('import.available')
        ->has('drafts', 2)
        ->where('drafts', fn ($drafts) => collect($drafts)->pluck('id')->sort()->values()->all() === collect([$ready->id, $failed->id])->sort()->values()->all()));

    $drafts = collect($response->viewData('page')['props']['drafts'])->keyBy('id');
    expect($drafts[$ready->id])->toBe(['id' => $ready->id, 'status' => 'ready', 'host' => 'recipes.example', 'reason' => null])
        ->and($drafts[$failed->id])->toBe(['id' => $failed->id, 'status' => 'failed', 'host' => 'broken.example', 'reason' => 'no_recipe'])
        ->and($response->getContent())->not->toContain('Other secret')->not->toContain('worker_lost');
});

it('reports an unacknowledged notice and no availability without an agent', function () {
    DB::table('ai_agent_settings')->update(['enabled' => false]);
    $me = F::member(null);

    $this->actingAs($me)->get('/recipes')->assertInertia(fn (Assert $p) => $p
        ->where('import.available', false)->where('import.acknowledged', true)->where('import.providers', [])->where('drafts', []));
});

it('prefills the form from an own ready draft without creating a recipe', function () {
    F::enableCodex();
    $me = F::member();
    $import = RecipeImport::factory()->forUrl(F::PAGE_URL)->ready(ipfPayload())->create(['user_id' => $me->id, 'text_cut' => true]);
    $before = Recipe::count();

    $this->actingAs($me)->get('/recipes/create?import='.$import->id)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Recipes/Form', false)
        ->where('prefill.importId', $import->id)
        ->where('prefill.title', 'Pancakes')
        ->where('prefill.description', 'Thin pancakes.')
        ->where('prefill.servings', 4)
        ->where('prefill.prep_minutes', 10)
        ->where('prefill.cook_minutes', null)
        ->where('prefill.categories', ['breakfast'])
        ->where('prefill.ingredients.0.name', 'Flour')
        ->where('prefill.ingredients.0.quantity', '250')
        ->where('prefill.ingredients.0.unit_id', RecipeFixtures::unitId('g'))
        ->where('prefill.ingredients.0.original', '250 g flour')
        ->where('prefill.ingredients.1.quantity', '1.5')
        ->where('prefill.ingredients.1.unit_id', null)
        ->where('prefill.ingredients.1.note', 'large')
        ->where('prefill.steps', [['body' => 'Mix the batter.'], ['body' => 'Fry thin pancakes.']])
        ->where('prefill.aiFields', ['title', 'description', 'servings', 'prep_minutes', 'categories', 'ingredients', 'steps'])
        ->where('prefill.sourceUrl', F::PAGE_URL)
        ->where('prefill.sourceHost', F::PAGE_HOST)
        ->where('prefill.textCut', true));

    expect(Recipe::count())->toBe($before)
        ->and(DB::table('recipe_imports')->where('id', $import->id)->value('status'))->toBe('ready');
});

it('answers 404 for another member\'s or an unknown draft and a plain form for an own unfinished one', function () {
    $me = F::member();
    $other = F::member();
    $theirs = RecipeImport::factory()->ready(ipfPayload(['title' => 'Their draft']))->create(['user_id' => $other->id]);
    $failed = RecipeImport::factory()->failed()->create(['user_id' => $me->id]);
    $running = RecipeImport::factory()->forUrl(F::PAGE_URL)->running()->create(['user_id' => $me->id]);

    $this->actingAs($me)->get('/recipes/create?import='.$theirs->id)->assertNotFound();
    $this->actingAs($me)->get('/recipes/create?import=00000000-0000-4000-8000-000000000000')->assertNotFound();
    $this->actingAs($me)->get('/recipes/create?import=nope')->assertNotFound();
    foreach ([$failed, $running] as $own) {
        $response = $this->actingAs($me)->get('/recipes/create?import='.$own->id)->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Recipes/Form', false)->missing('prefill'));
        expect($response->getContent())->not->toContain('Their draft');
    }
});

it('keeps a usable source address and ignores an unusable one', function () {
    $me = F::member();

    $this->actingAs($me)->get('/recipes/create?source='.urlencode('https://recepty.example/a'))->assertInertia(fn (Assert $p) => $p
        ->where('prefill.sourceUrl', 'https://recepty.example/a')->where('prefill.sourceHost', 'recepty.example')
        ->where('prefill.importId', null)->where('prefill.aiFields', []));
    foreach (['javascript:x', 'ftp://recepty.example/a', 'https://user:pw@recepty.example/a', 'not a url'] as $bad) {
        $this->actingAs($me)->get('/recipes/create?source='.urlencode($bad))->assertOk()->assertInertia(fn (Assert $p) => $p->missing('prefill'));
    }
});

it('saves the recipe as from a url, keeps the edited source address and consumes the draft on Save', function () {
    $me = F::member();
    $import = RecipeImport::factory()->forUrl(F::PAGE_URL)->ready(ipfPayload())->create(['user_id' => $me->id]);

    $this->actingAs($me)->postJson('/recipes', ipfBody(['import_id' => $import->id, 'source_url' => 'https://recipes.example/edited']))->assertCreated();

    $recipe = Recipe::query()->latest('id')->first();
    $row = DB::table('recipe_imports')->where('id', $import->id)->first();
    expect($recipe->created_via)->toBe('url')->and($recipe->source_url)->toBe('https://recipes.example/edited')
        ->and($recipe->title)->toBe('Reviewed pancakes')
        ->and($row->status)->toBe('used')->and($row->payload)->toBeNull()->and($row->source_text)->toBeNull();
});

it('saves as manual when the draft is gone, used, foreign or malformed, and leaves a foreign draft alone', function () {
    $me = F::member();
    $other = F::member();
    $theirs = RecipeImport::factory()->ready(ipfPayload())->create(['user_id' => $other->id]);
    $used = RecipeImport::factory()->ready(ipfPayload())->create(['user_id' => $me->id]);
    DB::table('recipe_imports')->where('id', $used->id)->update(['status' => 'used', 'payload' => null]);

    foreach ([$theirs->id, $used->id, '00000000-0000-4000-8000-000000000000', 'not-a-uuid'] as $id) {
        $this->actingAs($me)->postJson('/recipes', ipfBody(['import_id' => $id]))->assertCreated();
        expect(Recipe::query()->latest('id')->value('created_via'))->toBe('manual');
    }

    $row = DB::table('recipe_imports')->where('id', $theirs->id)->first();
    expect($row->status)->toBe('ready')->and($row->payload)->not->toBeNull();
});

it('does not consume the draft when the save is refused', function () {
    $me = F::member();
    $import = RecipeImport::factory()->ready(ipfPayload())->create(['user_id' => $me->id]);

    $this->actingAs($me)->postJson('/recipes', ipfBody(['import_id' => $import->id, 'title' => '']))->assertStatus(422);

    expect(DB::table('recipe_imports')->where('id', $import->id)->value('status'))->toBe('ready')->and(Recipe::count())->toBe(0);
});
