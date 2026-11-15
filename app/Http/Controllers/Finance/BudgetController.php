<?php

namespace App\Http\Controllers\Finance;

use App\Finance\Budgets\BudgetWriter;
use App\Finance\Viewer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\BudgetRequest;
use Illuminate\Http\RedirectResponse;

class BudgetController extends Controller
{
    public function store(BudgetRequest $request, BudgetWriter $writer): RedirectResponse
    {
        $writer->save(Viewer::for($request->user()), (string) $request->category()->id, $request->currency(), $request->fromMonth(), $request->minor());

        return $this->done('finance.toast.budget_saved');
    }

    public function update(BudgetRequest $request, string $budget, BudgetWriter $writer): RedirectResponse
    {
        $row = $request->budget();
        $writer->save(Viewer::for($request->user()), (string) $row->category_id, (string) $row->currency, $request->fromMonth(), $request->minor());

        return $this->done('finance.toast.budget_saved');
    }

    public function destroy(BudgetRequest $request, string $budget, BudgetWriter $writer): RedirectResponse
    {
        $writer->remove(Viewer::for($request->user()), $request->budget(), $request->fromMonth());

        return $this->done('finance.toast.budget_removed');
    }

    private function done(string $toast): RedirectResponse
    {
        return redirect()->back(303, [], route('finance.transactions.index'))->with('toast', ['key' => $toast]);
    }
}
