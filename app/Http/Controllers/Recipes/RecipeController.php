<?php

namespace App\Http\Controllers\Recipes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recipes\RecipeIndexRequest;
use App\Http\Requests\Recipes\StoreRecipeRequest;
use App\Http\Requests\Recipes\UpdateRecipeRequest;
use App\Models\RecipeImport;
use App\Recipes\Import\ImportPrefill;
use App\Recipes\Import\ImportUrl;
use App\Recipes\Queries\RecipePayload;
use App\Recipes\Queries\RecipeQuery;
use App\Recipes\RecipeAccess;
use App\Recipes\RecipeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** Overview, detail, form pages and the recipe CRUD. Every rule lives in the Recipes services. */
class RecipeController extends Controller
{
    public function index(RecipeIndexRequest $request, RecipePayload $payload): Response
    {
        return Inertia::render('Recipes/Index', $payload->index($request->user(), $request->filters(), $request->page()));
    }

    public function create(Request $request, RecipePayload $payload): Response
    {
        $props = $payload->form($request->user(), null);
        $prefill = $this->prefill($request);
        if ($prefill !== null) {
            $props['prefill'] = $prefill;
        }

        return Inertia::render('Recipes/Form', $props);
    }

    public function store(StoreRecipeRequest $request, RecipeService $service): RedirectResponse|JsonResponse
    {
        $recipe = $service->create($request->user(), $request->validated(), $request->validated('import_id'));

        if ($this->plainJson($request)) {
            return response()->json(['id' => $recipe->id], 201);
        }

        return redirect()->route('recipes.show', $recipe->id, 303)->with('toast', ['key' => 'recipes.toast.saved']);
    }

    public function show(Request $request, int $recipe, RecipeQuery $query, RecipePayload $payload): Response
    {
        $model = $query->find($request->user(), $recipe);

        return Inertia::render('Recipes/Show', $payload->show($request->user(), $model));
    }

    public function edit(Request $request, int $recipe, RecipeQuery $query, RecipeAccess $access, RecipePayload $payload): Response
    {
        $model = $query->find($request->user(), $recipe);
        abort_unless($access->mayEdit($request->user(), $model), 403);

        return Inertia::render('Recipes/Form', $payload->form($request->user(), $model));
    }

    public function update(UpdateRecipeRequest $request, int $recipe, RecipeService $service): RedirectResponse|JsonResponse
    {
        $updated = $service->update($request->user(), $recipe, $request->validated());

        if ($this->plainJson($request)) {
            return response()->json(['id' => $updated->id]);
        }

        return redirect()->route('recipes.show', $updated->id, 303)->with('toast', ['key' => 'recipes.toast.saved']);
    }

    public function destroy(Request $request, int $recipe, RecipeService $service): RedirectResponse|\Illuminate\Http\Response
    {
        $service->delete($request->user(), $recipe);

        if ($this->plainJson($request)) {
            return response()->noContent();
        }

        return redirect()->route('recipes.index', [], 303)->with('toast', ['key' => 'recipes.toast.deleted']);
    }

    /**
     * ?import={id}: the member's own ready draft (anyone else's or an unknown id is a 404; an own draft that is not
     * ready opens the plain form). ?source={url}: the address kept after "type it in", when it is a usable page address.
     *
     * @return array<string, mixed>|null
     */
    private function prefill(Request $request): ?array
    {
        $id = $request->query('import');
        if ($id !== null) {
            $row = is_string($id) && Str::isUuid($id) ? RecipeImport::query()->whereKey($id)->where('user_id', $request->user()->id)->first() : null;
            abort_if($row === null, 404);

            return $row->status === 'ready' ? ImportPrefill::fromPayload($row) : null;
        }
        $source = $request->query('source');
        if (is_string($source) && ($url = ImportUrl::normalise($source)) !== null) {
            return ImportPrefill::fromUrl($url);
        }

        return null;
    }

    /** A script (not an Inertia visit) that asks for JSON. */
    private function plainJson(Request $request): bool
    {
        return $request->expectsJson() && ! $request->header('X-Inertia');
    }
}
