<?php

namespace App\Http\Controllers\Recipes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recipes\AddToShoppingRequest;
use App\Recipes\Shopping\RecipeShoppingBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** "Add to shopping list" for a visible recipe: review data, then an all-or-nothing commit. Both answer JSON. */
class ShoppingController extends Controller
{
    public function __construct(private readonly RecipeShoppingBridge $bridge) {}

    public function preview(Request $request, int $recipe): JsonResponse
    {
        return response()->json($this->bridge->preview($request->user(), $recipe));
    }

    public function store(AddToShoppingRequest $request, int $recipe): JsonResponse
    {
        $data = $request->validated();

        try {
            $result = $this->bridge->commit(
                $request->user(),
                $recipe,
                $data['target'],
                isset($data['list_id']) ? (int) $data['list_id'] : null,
                $data['name'] ?? null,
                isset($data['store_id']) ? (int) $data['store_id'] : null,
                array_map(fn (array $row) => array_filter($row, fn ($v, $k) => $k === 'unit_id' || $v !== null, ARRAY_FILTER_USE_BOTH), $data['items']),
            );
        } catch (HttpException $e) {
            // A list locked or finished since the preview: nothing was added.
            if ($e->getStatusCode() === 409) {
                return response()->json(['message' => 'shopping.error.list_changed'], 409);
            }
            throw $e;
        }

        return response()->json($result);
    }
}
