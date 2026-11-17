<?php

use App\Models\Recipe;
use App\Models\RecipePhoto;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\ImageFixtures;
use Tests\Support\RecipeFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->author = RecipeFixtures::member();
    $this->recipe = RecipeFixtures::sharedRecipe($this->author);
});

function rcpPhoto(string $bytes, string $name = 'pic.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes);
}

function rcpUpload($test, Recipe $recipe, ?string $bytes = null): TestResponse
{
    return $test->actingAs($test->author)->postJson("/recipes/{$recipe->id}/photos", ['image' => rcpPhoto($bytes ?? ImageFixtures::jpeg())]);
}

it('re-encodes an upload without EXIF, stores it under the recipe folder and makes the first photo main', function () {
    $res = rcpUpload($this, $this->recipe, ImageFixtures::jpegWithExif(6, 800, 600))->assertCreated();
    $res->assertJsonPath('photo.isMain', true)->assertJsonPath('photos.0.id', $res->json('photo.id'));
    $row = RecipePhoto::query()->findOrFail($res->json('photo.id'));
    expect($row->path)->toStartWith("wish-images/recipes/{$this->recipe->id}/")->and($row->version)->toBe(1);
    foreach ([$row->path, $row->thumb_path] as $path) {
        $bytes = Storage::disk('local')->get($path);
        expect(str_starts_with($bytes, "\xFF\xD8"))->toBeTrue()->and(str_contains($bytes, 'Exif'))->toBeFalse();
    }

    rcpUpload($this, $this->recipe)->assertCreated()->assertJsonPath('photo.isMain', false)->assertJsonPath('photo.position', 2);
});

it('refuses the eleventh photo, a text file and an oversize file', function () {
    foreach (range(1, 10) as $_) {
        rcpUpload($this, $this->recipe)->assertCreated();
    }
    rcpUpload($this, $this->recipe)->assertStatus(422)->assertJsonPath('errors.image.0', 'recipes.image.err_count');
    expect(RecipePhoto::query()->where('recipe_id', $this->recipe->id)->count())->toBe(10);
    expect(count(Storage::disk('local')->allFiles("wish-images/recipes/{$this->recipe->id}")))->toBe(20);

    $other = RecipeFixtures::sharedRecipe($this->author);
    rcpUpload($this, $other, 'just text, not an image')->assertStatus(422)->assertJsonPath('errors.image.0', 'recipes.image.err_type');
    $big = UploadedFile::fake()->create('big.png', 5121);
    $this->actingAs($this->author)->postJson("/recipes/{$other->id}/photos", ['image' => $big])
        ->assertStatus(422)->assertJsonPath('errors.image.0', 'recipes.image.err_size');
    expect(RecipePhoto::query()->where('recipe_id', $other->id)->count())->toBe(0);
});

it('makes a photo main, moves photos and promotes the next one when the main is removed', function () {
    $ids = [];
    foreach (range(1, 3) as $_) {
        $ids[] = rcpUpload($this, $this->recipe)->json('photo.id');
    }
    $base = "/recipes/{$this->recipe->id}/photos";
    $order = fn ($res) => array_column($res->json('photos'), 'id');

    $res = $this->actingAs($this->author)->putJson("{$base}/{$ids[2]}/main")->assertOk();
    expect(collect($res->json('photos'))->firstWhere('isMain', true)['id'])->toBe($ids[2])->and($order($res))->toBe($ids);

    $res = $this->actingAs($this->author)->putJson("{$base}/{$ids[2]}/position", ['direction' => 'earlier'])->assertOk();
    expect($order($res))->toBe([$ids[0], $ids[2], $ids[1]]);
    $res = $this->actingAs($this->author)->putJson("{$base}/{$ids[0]}/position", ['direction' => 'earlier'])->assertOk();
    expect($order($res))->toBe([$ids[0], $ids[2], $ids[1]]);
    $res = $this->actingAs($this->author)->putJson("{$base}/{$ids[0]}/position", ['direction' => 'later'])->assertOk();
    expect($order($res))->toBe([$ids[2], $ids[0], $ids[1]]);
    $this->actingAs($this->author)->putJson("{$base}/{$ids[0]}/position", ['direction' => 'sideways'])->assertStatus(422);

    $gone = RecipePhoto::query()->findOrFail($ids[2]);
    $res = $this->actingAs($this->author)->deleteJson("{$base}/{$ids[2]}")->assertOk();
    expect($order($res))->toBe([$ids[0], $ids[1]])->and(collect($res->json('photos'))->firstWhere('isMain', true)['id'])->toBe($ids[0]);
    Storage::disk('local')->assertMissing($gone->path);
    Storage::disk('local')->assertMissing($gone->thumb_path);
});

it('serves photos with private no-cache, ETag, nosniff and 304 to the author and a member of a shared recipe', function () {
    $id = rcpUpload($this, $this->recipe)->json('photo.id');
    $viewer = RecipeFixtures::member('view');

    foreach ([$this->author, $viewer] as $user) {
        $res = $this->actingAs($user)->get("/recipes/{$this->recipe->id}/photos/{$id}/thumb")->assertOk();
        expect($res->headers->get('Cache-Control'))->toContain('private')->toContain('no-cache')
            ->and($res->headers->get('Content-Type'))->toBe('image/jpeg')
            ->and($res->headers->get('X-Content-Type-Options'))->toBe('nosniff');
        $etag = $res->headers->get('ETag');
        expect($etag)->not->toBeEmpty();
        $this->actingAs($user)->get("/recipes/{$this->recipe->id}/photos/{$id}/thumb", ['If-None-Match' => $etag])->assertStatus(304);
    }
    $full = $this->actingAs($this->author)->get("/recipes/{$this->recipe->id}/photos/{$id}/full")->assertOk();
    $thumb = $this->actingAs($this->author)->get("/recipes/{$this->recipe->id}/photos/{$id}/thumb")->assertOk();
    expect($full->headers->get('ETag'))->not->toBe($thumb->headers->get('ETag'));
});
