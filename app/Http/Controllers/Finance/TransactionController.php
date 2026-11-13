<?php

namespace App\Http\Controllers\Finance;

use App\Finance\FinancePage;
use App\Finance\Queries\TransactionQuery;
use App\Finance\Transactions\TransactionPresenter;
use App\Finance\Transactions\TransactionWriter;
use App\Finance\Viewer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\TransactionRequest;
use Illuminate\Http\RedirectResponse;
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

    public function store(TransactionRequest $request, TransactionWriter $writer): RedirectResponse
    {
        $viewer = Viewer::for($request->user());
        $date = (string) $request->input('booked_on');
        $description = $request->input('description');
        $note = $request->input('note');

        if ($request->input('kind') === 'transfer') {
            $writer->createTransfer($viewer, $request->fromAccount(), $request->toAccount(), $request->magnitude(), $date, $description, $note);
        } else {
            $writer->createSingle($viewer, $request->account(), (string) $request->input('kind'), $request->magnitude(), $date, (string) $description, $request->category()?->id, $note);
        }

        return $this->done($request, 'finance.toast.tx_saved');
    }

    public function update(TransactionRequest $request, string $transaction, TransactionWriter $writer): RedirectResponse
    {
        $writer->update(Viewer::for($request->user()), $request->leg(), $request->counterpart(), $request->changes());

        return $this->done($request, 'finance.toast.tx_saved');
    }

    public function destroy(Request $request, string $transaction, TransactionQuery $transactions, TransactionWriter $writer): RedirectResponse
    {
        $viewer = Viewer::for($request->user());
        $leg = $transactions->findVisible($viewer, $transaction);
        abort_if($leg === null, 404);

        $writer->delete($viewer, $leg, $transactions->visibleCounterpart($viewer, $leg));

        return $this->done($request, 'finance.toast.tx_deleted');
    }

    /** Back to the list the user came from (month, account and filters kept, panel closed). */
    private function done(Request $request, string $toast): RedirectResponse
    {
        $params = [];
        parse_str((string) parse_url((string) $request->headers->get('referer'), PHP_URL_QUERY), $query);
        foreach (['account', 'month', 'q', 'type', 'category', 'source'] as $key) {
            if (isset($query[$key]) && is_string($query[$key])) {
                $params[$key] = $query[$key];
            }
        }

        return redirect()->route('finance.transactions.index', $params, 303)->with('toast', ['key' => $toast]);
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
