<?php

namespace App\Http\Controllers\Finance;

use App\Finance\FinancePage;
use App\Finance\Queries\AccountQuery;
use App\Finance\Viewer;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountsController extends Controller
{
    public function index(Request $request, AccountQuery $accounts, FinancePage $page): Response
    {
        $viewer = Viewer::for($request->user());
        $balances = $accounts->balances($viewer, now((string) config('family.timezone'))->toDateString());

        return Inertia::render('Finance/Accounts', [
            'finance' => $page->props($viewer, $request),
            'accounts' => $accounts->visible($viewer)->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->type,
                'currency' => $a->currency,
                'balanceMinor' => (string) ($balances[$a->id] ?? $a->opening_balance_minor),
            ])->values()->all(),
        ]);
    }
}
