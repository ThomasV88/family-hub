<?php

use App\Recipes\RecipeStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\RecipeFixtures;

uses(RefreshDatabase::class);

it('ticks idempotently and untick of a missing row is harmless', function () {
    $a = RecipeFixtures::member();
    $r = RecipeFixtures::sharedRecipe($a);
    $i = $r->ingredients()->value('id');
    $s = app(RecipeStateService::class);
    $s->tick($a, $r->id, $i, true);
    $s->tick($a, $r->id, $i, true);
    expect(DB::table('recipe_ingredient_ticks')->count())->toBe(1);
    $s->tick($a, $r->id, $i, false);
    $s->tick($a, $r->id, $i, false);
    expect(DB::table('recipe_ingredient_ticks')->count())->toBe(0);

    $st = $r->steps()->value('id');
    $s->stepDone($a, $r->id, $st, true);
    $s->stepDone($a, $r->id, $st, true);
    expect(DB::table('recipe_step_done')->count())->toBe(1);
    $s->stepDone($a, $r->id, $st, false);
    $s->stepDone($a, $r->id, $st, false);
    expect(DB::table('recipe_step_done')->count())->toBe(0);
});

it('keeps each member state separate', function () {
    $a = RecipeFixtures::member();
    $b = RecipeFixtures::member();
    $r = RecipeFixtures::sharedRecipe($a);
    $i = $r->ingredients()->value('id');
    $s = app(RecipeStateService::class);
    $s->tick($a, $r->id, $i, true);
    $s->favourite($a, $r->id, true);
    expect($s->stateFor($b, $r))->toMatchArray(['favourite' => false, 'tickedIngredientIds' => [], 'doneStepIds' => []]);
    expect($s->stateFor($a, $r)['tickedIngredientIds'])->toBe([$i]);
});

it('answers 404 for a line of another recipe and for a hidden recipe', function () {
    $a = RecipeFixtures::member();
    $b = RecipeFixtures::member();
    $r1 = RecipeFixtures::sharedRecipe($a);
    $r2 = RecipeFixtures::sharedRecipe($a);
    $hidden = RecipeFixtures::privateRecipe($a);
    $s = app(RecipeStateService::class);
    $code = function (callable $f) {
        try {
            $f();
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }

        return 200;
    };
    expect($code(fn () => $s->tick($b, $r1->id, $r2->ingredients()->value('id'), true)))->toBe(404);
    expect($code(fn () => $s->stepDone($b, $r1->id, $r2->steps()->value('id'), true)))->toBe(404);
    expect($code(fn () => $s->tick($b, $hidden->id, $hidden->ingredients()->value('id'), true)))->toBe(404);
    expect($code(fn () => $s->note($b, $hidden->id, 'x')))->toBe(404);
    expect($code(fn () => $s->reset($b, $hidden->id)))->toBe(404);
    expect(DB::table('recipe_ingredient_ticks')->count())->toBe(0);
});

it('normalises the note and validates servings', function () {
    $a = RecipeFixtures::member();
    $r = RecipeFixtures::sharedRecipe($a);
    $s = app(RecipeStateService::class);
    $s->note($a, $r->id, '  hello ');
    expect($s->stateFor($a, $r)['note'])->toBe('hello');
    $s->note($a, $r->id, '  ');
    expect($s->stateFor($a, $r)['note'])->toBeNull();
    expect(fn () => $s->note($a, $r->id, str_repeat('x', 2001)))->toThrow(ValidationException::class);

    $s->servings($a, $r->id, 6);
    expect($s->stateFor($a, $r)['servings'])->toBe(6);
    foreach ([0, 100] as $bad) {
        expect(fn () => $s->servings($a, $r->id, $bad))->toThrow(ValidationException::class);
    }
    $s->servings($a, $r->id, null);
    expect($s->stateFor($a, $r)['servings'])->toBeNull();
});

it('resets only the actor ticks and done marks of this recipe', function () {
    $a = RecipeFixtures::member();
    $b = RecipeFixtures::member();
    $r = RecipeFixtures::sharedRecipe($a);
    $other = RecipeFixtures::sharedRecipe($a);
    $s = app(RecipeStateService::class);
    foreach ([$r, $other] as $rec) {
        $s->tick($b, $rec->id, $rec->ingredients()->value('id'), true);
        $s->stepDone($b, $rec->id, $rec->steps()->value('id'), true);
    }
    $s->tick($a, $r->id, $r->ingredients()->value('id'), true);
    $s->favourite($b, $r->id, true);
    $s->note($b, $r->id, 'keep');
    $s->servings($b, $r->id, 8);

    $s->reset($b, $r->id);

    $st = $s->stateFor($b, $r);
    expect($st['tickedIngredientIds'])->toBe([])->and($st['doneStepIds'])->toBe([])
        ->and($st['favourite'])->toBeTrue()->and($st['note'])->toBe('keep')->and($st['servings'])->toBe(8);
    expect($s->stateFor($b, $other)['tickedIngredientIds'])->toHaveCount(1);
    expect($s->stateFor($a, $r)['tickedIngredientIds'])->toHaveCount(1);
});
