<?php

namespace App\Http\Controllers\Recipes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recipes\MoveRecipePhotoRequest;
use App\Http\Requests\Recipes\UploadRecipePhotoRequest;
use App\Models\Recipe;
use App\Models\RecipePhoto;
use App\Recipes\Images\RecipeImageStore;
use App\Recipes\Queries\RecipeQuery;
use App\Recipes\RecipeAccess;
use App\Wishlist\Catalog\ImageRejected;
use App\Wishlist\Catalog\WishImageWriteFailed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Recipe photos: private files, JSON endpoints for the editor, an authenticated image route for viewers. */
class PhotoController extends Controller
{
    public function __construct(
        private readonly RecipeQuery $query,
        private readonly RecipeAccess $access,
        private readonly RecipeImageStore $store,
    ) {}

    public function store(UploadRecipePhotoRequest $request, int $recipe): JsonResponse
    {
        $model = $this->query->visible($request->user(), $recipe);
        $file = $request->file('image');
        if ($file === null || ! $file->isValid()) {
            throw ValidationException::withMessages(['image' => 'recipes.image.err_type']);
        }

        try {
            $photo = $this->store->add($model, $file);
        } catch (ImageRejected $e) {
            $key = match ($e->reason) {
                'too_large' => 'recipes.image.err_size',
                'too_many_pixels' => 'recipes.image.err_pixels',
                'busy' => 'recipes.image.err_write',
                default => 'recipes.image.err_type',
            };
            throw ValidationException::withMessages(['image' => $key]);
        } catch (WishImageWriteFailed) {
            throw ValidationException::withMessages(['image' => 'recipes.image.err_write']);
        }

        return response()->json(['photo' => $this->shape($photo), 'photos' => $this->photos($model)], 201);
    }

    public function main(Request $request, int $recipe, int $photo): JsonResponse
    {
        [$model, $row] = $this->forEdit($request, $recipe, $photo);
        $this->store->makeMain($model, $row);

        return response()->json(['photos' => $this->photos($model)]);
    }

    public function move(MoveRecipePhotoRequest $request, int $recipe, int $photo): JsonResponse
    {
        [$model, $row] = $this->forEdit($request, $recipe, $photo);
        $this->store->move($model, $row, (string) $request->validated('direction'));

        return response()->json(['photos' => $this->photos($model)]);
    }

    public function destroy(Request $request, int $recipe, int $photo): JsonResponse
    {
        [$model, $row] = $this->forEdit($request, $recipe, $photo);
        $this->store->remove($model, $row);

        return response()->json(['photos' => $this->photos($model)]);
    }

    /** Revalidated on every view (no-cache + ETag). Hidden recipe, other recipe's photo and missing file are the same 404. */
    public function show(Request $request, int $recipe, int $photo, string $size): Response
    {
        $model = $this->query->visible($request->user(), $recipe);
        $row = RecipePhoto::query()->where('recipe_id', $model->id)->whereKey($photo)->first();
        $path = $row === null ? null : ($size === 'thumb' ? $row->thumb_path : $row->path);
        $disk = Storage::disk('local');
        abort_if($path === null || ! $disk->exists($path), 404);

        $response = response($disk->get($path), 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-cache',
            'ETag' => '"'.sha1($path.'|'.$row->version).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Vary' => 'Cookie',
        ]);
        $response->isNotModified($request);

        return $response;
    }

    /** @return array{0: Recipe, 1: RecipePhoto} */
    private function forEdit(Request $request, int $recipe, int $photo): array
    {
        $model = $this->query->visible($request->user(), $recipe);
        abort_unless($this->access->mayEdit($request->user(), $model), 403);
        $row = RecipePhoto::query()->where('recipe_id', $model->id)->whereKey($photo)->first();
        abort_if($row === null, 404);

        return [$model, $row];
    }

    /** @return array<int, array{id: int, position: int, isMain: bool, version: int}> */
    private function photos(Recipe $recipe): array
    {
        return RecipePhoto::query()->where('recipe_id', $recipe->id)->orderBy('position')->orderBy('id')->get()
            ->map(fn (RecipePhoto $p) => $this->shape($p))->all();
    }

    /** @return array{id: int, position: int, isMain: bool, version: int} */
    private function shape(RecipePhoto $photo): array
    {
        return ['id' => $photo->id, 'position' => $photo->position, 'isMain' => $photo->is_main, 'version' => $photo->version];
    }
}
