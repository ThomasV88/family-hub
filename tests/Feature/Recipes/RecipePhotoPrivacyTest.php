<?php

use App\Models\RecipePhoto;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;
use Tests\Support\RecipeFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    config(['app.debug' => false]);
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->author = RecipeFixtures::member();
    $this->other = RecipeFixtures::member();
    $this->private = RecipeFixtures::privateRecipe($this->author);
    $this->photo = RecipePhoto::query()->findOrFail(
        $this->actingAs($this->author)->postJson("/recipes/{$this->private->id}/photos", ['image' => UploadedFile::fake()->createWithContent('a.png', ImageFixtures::jpeg())])->json('photo.id')
    );
});

/** @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> */
function rcpPhotoCalls(int $recipe, int $photo): array
{
    return [
        'store' => ['POST', "/recipes/{$recipe}/photos", ['image' => UploadedFile::fake()->createWithContent('b.png', ImageFixtures::jpeg())]],
        'main' => ['PUT', "/recipes/{$recipe}/photos/{$photo}/main", []],
        'move' => ['PUT', "/recipes/{$recipe}/photos/{$photo}/position", ['direction' => 'later']],
        'destroy' => ['DELETE', "/recipes/{$recipe}/photos/{$photo}", []],
        'full' => ['GET', "/recipes/{$recipe}/photos/{$photo}/full", []],
        'thumb' => ['GET', "/recipes/{$recipe}/photos/{$photo}/thumb", []],
    ];
}

it('answers every photo route for another member private recipe exactly like an unknown recipe id', function () {
    $calls = rcpPhotoCalls($this->private->id, $this->photo->id);
    $unknown = rcpPhotoCalls(999999, $this->photo->id);
    foreach ($calls as $name => [$method, $uri, $data]) {
        $hidden = $this->actingAs($this->other)->json($method, $uri, $data);
        $missing = $this->actingAs($this->other)->json($method, $unknown[$name][1], $unknown[$name][2]);
        expect($hidden->getStatusCode())->toBe(404, $name)->and($hidden->getContent())->toBe($missing->getContent(), $name);
    }
    expect(RecipePhoto::query()->where('recipe_id', $this->private->id)->count())->toBe(1);
});

it('answers 404 for a photo id of another recipe, even to the author', function () {
    $mine = RecipeFixtures::privateRecipe($this->author);
    foreach (['main' => ['PUT', 'main'], 'move' => ['PUT', 'position'], 'destroy' => ['DELETE', ''], 'thumb' => ['GET', 'thumb']] as $name => [$method, $tail]) {
        $uri = "/recipes/{$mine->id}/photos/{$this->photo->id}".($tail === '' ? '' : "/{$tail}");
        $data = $name === 'move' ? ['direction' => 'later'] : [];
        $this->actingAs($this->author)->json($method, $uri, $data)->assertNotFound();
    }
    expect(RecipePhoto::query()->whereKey($this->photo->id)->exists())->toBeTrue();
});

it('lets a member of a shared recipe see photos but not change them (403)', function () {
    $this->private->forceFill(['visibility' => 'shared'])->save();
    foreach (rcpPhotoCalls($this->private->id, $this->photo->id) as $name => [$method, $uri, $data]) {
        $status = $this->actingAs($this->other)->json($method, $uri, $data)->getStatusCode();
        expect($status)->toBe(in_array($name, ['full', 'thumb'], true) ? 200 : 403, $name);
    }
    expect(RecipePhoto::query()->where('recipe_id', $this->private->id)->count())->toBe(1);
});

it('sends a guest to login and refuses members without Recipes view', function () {
    $this->private->forceFill(['visibility' => 'shared'])->save();
    $this->app['auth']->forgetGuards();
    $this->get("/recipes/{$this->private->id}/photos/{$this->photo->id}/thumb")->assertRedirect('/login');
    $none = RecipeFixtures::member('none');
    $this->actingAs($none)->get("/recipes/{$this->private->id}/photos/{$this->photo->id}/thumb")->assertForbidden();
    $this->actingAs($none)->putJson("/recipes/{$this->private->id}/photos/{$this->photo->id}/main")->assertForbidden();
});
