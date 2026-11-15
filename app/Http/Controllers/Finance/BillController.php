<?php

namespace App\Http\Controllers\Finance;

use App\Finance\Queries\RecurringQuery;
use App\Finance\Recurring\RecurringMatchService;
use App\Finance\Viewer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\BillMatchRequest;
use App\Http\Requests\Finance\BillRecordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BillController extends Controller
{
    public function record(BillRecordRequest $request, string $recurring, string $date, RecurringMatchService $matching): RedirectResponse
    {
        $matching->recordPayment(Viewer::for($request->user()), $request->item(), $request->dueDate(), $request->payment());

        return $this->done($request, 'finance.toast.recorded');
    }

    public function match(BillMatchRequest $request, string $recurring, string $date, RecurringMatchService $matching): RedirectResponse
    {
        $matching->matchManually(Viewer::for($request->user()), $request->item(), $request->dueDate(), $request->transactionId());

        return $this->done($request, 'finance.toast.matched');
    }

    public function unmatch(Request $request, string $recurring, string $date, RecurringQuery $query, RecurringMatchService $matching): RedirectResponse
    {
        $viewer = Viewer::for($request->user());
        abort_if($query->bill($viewer, $recurring, $date) === null, 404);
        $item = $query->findVisible($viewer, $recurring) ?? abort(404);

        $matching->unmatch($viewer, $item, $date);

        return $this->done($request, 'finance.toast.unmatched');
    }

    /** Back to the recurring page the user came from (account and month kept, panels closed). */
    private function done(Request $request, string $toast): RedirectResponse
    {
        $params = [];
        parse_str((string) parse_url((string) $request->headers->get('referer'), PHP_URL_QUERY), $query);
        foreach (['account', 'month'] as $key) {
            if (isset($query[$key]) && is_string($query[$key])) {
                $params[$key] = $query[$key];
            }
        }

        return redirect()->route('finance.recurring.index', $params, 303)->with('toast', ['key' => $toast]);
    }
}
