<?php

namespace App\Http\Controllers\Finance;

use App\Finance\FinancePage;
use App\Finance\Queries\TransactionQuery;
use App\Finance\Transactions\TransactionPresenter;
use App\Finance\Viewer;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function index(Request $request, FinancePage $page, TransactionQuery $transactions, TransactionPresenter $presenter): Response
    {
        $viewer = Viewer::for($request->user());
        $finance = $page->props($viewer, $request);
        $accountId = $finance['selectedAccount'] === 'all' ? null : $finance['selectedAccount'];
        $names = collect($finance['accounts'])->pluck('name', 'id')->all();
        $filters = $this->filters($request);

        $result = $transactions->page($viewer, $accountId, $finance['month'], $filters, $this->requestedPage($request));

        return Inertia::render('Finance/Transactions', [
            'finance' => $finance,
            'filters' => $filters,
            'transactions' => ['total' => $result['total'], 'page' => $result['page'], 'hasMore' => $result['hasMore']],
            'transactionItems' => Inertia::merge(array_map(fn ($r) => $presenter->row($r, $names), $result['rows'])),
            'panelTransaction' => $this->panel($request, $viewer, $transactions, $presenter, $names),
        ]);
    }

    /** @return array{q: string, type: string, category: string, source: string} */
    private function filters(Request $request): array
    {
        $q = $request->query('q');
        $type = $request->query('type');
        $category = $request->query('category');
        $source = $request->query('source');

        return [
            'q' => is_string($q) ? mb_substr(trim($q), 0, 100) : '',
            'type' => in_array($type, ['income', 'expense', 'transfer'], true) ? $type : 'all',
            'category' => $category === 'none' || (is_string($category) && Str::isUuid($category)) ? $category : 'all',
            'source' => in_array($source, ['manual', 'import'], true) ? $source : 'all',
        ];
    }

    /** Only a partial reload that asks for the items may page; every other request starts at page 1. */
    private function requestedPage(Request $request): int
    {
        $only = array_map('trim', explode(',', (string) $request->header('X-Inertia-Partial-Data', '')));
        if (! $request->header('X-Inertia') || ! in_array('transactionItems', $only, true)) {
            return 1;
        }
        $page = $request->query('page');

        return is_string($page) && ctype_digit($page) && strlen($page) < 7 ? (int) $page : 1;
    }

    /**
     * @param  array<string, string>  $names
     * @return array<string, mixed>|null
     */
    private function panel(Request $request, Viewer $viewer, TransactionQuery $transactions, TransactionPresenter $presenter, array $names): ?array
    {
        if (! $request->query->has('tx')) {
            return null;
        }
        $tx = $request->query('tx');
        abort_unless(is_string($tx), 403);
        if ($tx === 'new') {
            abort_unless($viewer->canEdit(), 403);

            return ['mode' => 'new'];
        }
        $row = $transactions->panel($viewer, $tx);
        abort_if($row === null, 403);

        return $presenter->panel($row, $names, $viewer->canEdit());
    }
}
