<?php

namespace App\Http\Controllers\Finance;

use App\Finance\Categories\CategoryWriter;
use App\Finance\FinancePage;
use App\Finance\Queries\CategoryQuery;
use App\Finance\Viewer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\CategoryRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(Request $request, FinancePage $page, CategoryQuery $categories): Response
    {
        $viewer = Viewer::for($request->user());

        return Inertia::render('Finance/Categories', [
            'finance' => $page->props($viewer, $request),
            'panelCategory' => $this->panel($request, $viewer, $categories),
        ]);
    }

    public function store(CategoryRequest $request, CategoryWriter $writer): RedirectResponse
    {
        $writer->create($request->kind(), $request->name());

        return $this->done('finance.toast.cat_saved');
    }

    public function update(CategoryRequest $request, string $category, CategoryWriter $writer): RedirectResponse
    {
        $writer->rename($request->category(), $request->name());

        return $this->done('finance.toast.cat_saved');
    }

    public function destroy(CategoryRequest $request, string $category, CategoryWriter $writer): RedirectResponse
    {
        $writer->delete($request->category(), $request->target());

        return $this->done('finance.toast.cat_deleted');
    }

    private function done(string $toast): RedirectResponse
    {
        return redirect()->route('finance.categories.index', [], 303)->with('toast', ['key' => $toast]);
    }

    /** @return array<string, mixed>|null */
    private function panel(Request $request, Viewer $viewer, CategoryQuery $categories): ?array
    {
        if (! $request->query->has('cat')) {
            return null;
        }
        $cat = $request->query('cat');
        abort_unless(is_string($cat), 403);
        if ($cat === 'new') {
            abort_unless($viewer->canEdit(), 403);

            return ['mode' => 'new'];
        }
        $row = Str::isUuid($cat) ? $categories->find($cat) : null;
        abort_if($row === null, 403);

        return ['id' => $row->id, 'kind' => $row->kind, 'defaultKey' => $row->default_key, 'name' => $row->name];
    }
}
