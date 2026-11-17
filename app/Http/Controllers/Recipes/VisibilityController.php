<?php

namespace App\Http\Controllers\Recipes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recipes\VisibilityRequest;
use App\Recipes\RecipeService;
use App\Recipes\RecipeVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class VisibilityController extends Controller
{
    public function __invoke(VisibilityRequest $request, int $recipe, RecipeService $service): RedirectResponse|Response
    {
        $service->setVisibility($request->user(), $recipe, RecipeVisibility::from((string) $request->validated('visibility')));

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->noContent();
        }

        return back(303)->with('toast', ['key' => 'recipes.toast.visibility_changed']);
    }
}
