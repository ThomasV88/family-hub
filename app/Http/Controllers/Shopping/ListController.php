<?php

namespace App\Http\Controllers\Shopping;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shopping\StoreListRequest;
use App\Http\Requests\Shopping\UpdateListRequest;
use App\Shopping\ListService;
use App\Shopping\Queries\ListPayload;
use App\Shopping\Queries\ListQuery;
use App\Shopping\ShoppingAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Overview, one list page and the list header. Every rule lives in the Shopping services. */
class ListController extends Controller
{
    public function index(Request $request, ListPayload $payload): Response
    {
        $tab = $request->query('tab') === 'archive' ? 'archive' : 'open';
        $page = $request->query('page');

        return Inertia::render('Shopping/Index', $payload->index($request->user(), $tab, is_string($page) && ctype_digit($page) ? (int) $page : 1));
    }

    public function show(Request $request, int $list, ShoppingAccess $access, ListPayload $payload): Response
    {
        $user = $request->user();
        $model = ListQuery::visibleTo($user)->whereKey($list)->first();
        // Hidden and unknown lists answer the same 404.
        abort_if($model === null || ! $access->canView($user, $model), 404);

        return Inertia::render('Shopping/Show', $payload->show($user, $model));
    }

    public function store(StoreListRequest $request, ListService $service): RedirectResponse
    {
        $data = $request->validated();
        $list = $service->create($request->user(), $data['name'], $data['store_id'] ?? null, $data['responsible_id'] ?? null);

        return redirect()->route('shopping.lists.show', $list->id, 303)
            ->with('flash_data', ['open_picker' => true])
            ->with('toast', ['key' => 'shopping.toast.list_created']);
    }

    public function update(UpdateListRequest $request, int $list, ListService $service): RedirectResponse
    {
        $service->updateSettings($request->user(), $list, $request->settings());

        return back(303)->with('toast', ['key' => 'shopping.toast.list_saved']);
    }

    public function destroy(Request $request, int $list, ListService $service): RedirectResponse
    {
        $service->delete($request->user(), $list);

        return redirect()->route('shopping.index', [], 303)->with('toast', ['key' => 'shopping.toast.list_deleted']);
    }
}
