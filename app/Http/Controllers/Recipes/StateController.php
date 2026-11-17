<?php

namespace App\Http\Controllers\Recipes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recipes\FavouriteRequest;
use App\Http\Requests\Recipes\NoteRequest;
use App\Http\Requests\Recipes\ServingsRequest;
use App\Http\Requests\Recipes\StepDoneRequest;
use App\Http\Requests\Recipes\TickRequest;
use App\Recipes\RecipeStateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Per-member marks: ticks, done steps, favourite, note, servings. Always the actor's own, never another member's. */
class StateController extends Controller
{
    public function __construct(private readonly RecipeStateService $state) {}

    public function tick(TickRequest $request, int $recipe, int $ingredient): RedirectResponse|Response
    {
        $this->state->tick($request->user(), $recipe, $ingredient, $request->boolean('ticked'));

        return $this->done($request);
    }

    public function step(StepDoneRequest $request, int $recipe, int $step): RedirectResponse|Response
    {
        $this->state->stepDone($request->user(), $recipe, $step, $request->boolean('done'));

        return $this->done($request);
    }

    public function favourite(FavouriteRequest $request, int $recipe): RedirectResponse|Response
    {
        $this->state->favourite($request->user(), $recipe, $request->boolean('favourite'));

        return $this->done($request);
    }

    public function note(NoteRequest $request, int $recipe): RedirectResponse|Response
    {
        $note = $request->validated('note');
        $this->state->note($request->user(), $recipe, is_string($note) ? $note : null);

        return $this->done($request);
    }

    public function servings(ServingsRequest $request, int $recipe): RedirectResponse|Response
    {
        $servings = $request->validated('servings');
        $this->state->servings($request->user(), $recipe, $servings === null ? null : (int) $servings);

        return $this->done($request);
    }

    public function reset(Request $request, int $recipe): RedirectResponse|Response
    {
        $this->state->reset($request->user(), $recipe);

        return $this->done($request, 'recipes.toast.progress_reset');
    }

    private function done(Request $request, ?string $toast = null): RedirectResponse|Response
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->noContent();
        }
        $redirect = back(303);

        return $toast === null ? $redirect : $redirect->with('toast', ['key' => $toast]);
    }
}
