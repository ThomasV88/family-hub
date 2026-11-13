<?php

namespace App\Http\Controllers\Finance;

use App\Finance\FinancePage;
use App\Finance\Queries\BudgetQuery;
use App\Finance\Queries\OverviewQuery;
use App\Finance\Queries\RecurringQuery;
use App\Finance\Viewer;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OverviewController extends Controller
{
    public function index(Request $request, FinancePage $page, OverviewQuery $overview, BudgetQuery $budgets, RecurringQuery $recurring): Response
    {
        $viewer = Viewer::for($request->user());
        $finance = $page->props($viewer, $request);
        $accountId = $finance['selectedAccount'] === 'all' ? null : $finance['selectedAccount'];
        $month = $finance['month'];

        $summary = $overview->summary($viewer, $month, $accountId);
        $categories = $overview->categorySpending($viewer, $month, $accountId);
        $series = $overview->balanceSeries($viewer, $month, $accountId, 12);
        $budgetRows = $budgets->forMonth($viewer, $month, $accountId);
        $hasRecurring = $recurring->visible($viewer) !== [];
        $stillToPay = [];
        if ($hasRecurring) {
            foreach ($recurring->stillToPay($viewer, $month, $accountId) as $block) {
                $stillToPay[$block['currency']] = $block;
            }
        }

        $blocks = [];
        foreach ($summary as $currency => $sums) {
            $blocks[] = [
                'currency' => $currency,
                'summary' => $sums,
                'stillToPay' => $hasRecurring ? ($stillToPay[$currency] ?? null) : null,
                'budgets' => $budgetRows[$currency] ?? [],
                'categorySpending' => $categories[$currency],
                'balanceSeries' => $series[$currency],
            ];
        }

        return Inertia::render('Finance/Overview', [
            'finance' => $finance,
            'overview' => ['hasRecurring' => $hasRecurring, 'blocks' => $blocks],
            'panelBudget' => $this->panelBudget($request, $viewer, $budgets, $budgetRows, $blocks),
        ]);
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $budgetRows
     * @param  list<array<string, mixed>>  $blocks
     * @return array<string, mixed>|null
     */
    private function panelBudget(Request $request, Viewer $viewer, BudgetQuery $budgets, array $budgetRows, array $blocks): ?array
    {
        if (! $request->query->has('budget')) {
            return null;
        }
        $raw = $request->query('budget');
        abort_unless(is_string($raw), 403);

        if ($raw === 'new') {
            abort_unless($viewer->canEdit() && $blocks !== [], 403);

            return ['mode' => 'new', 'currency' => (string) $blocks[0]['currency']];
        }

        $row = $budgets->find($viewer, $raw);
        abort_if($row === null, 403);

        foreach ($budgetRows[(string) $row->currency] ?? [] as $candidate) {
            if ($candidate['id'] === (string) $row->id) {
                return $candidate + ['currency' => (string) $row->currency];
            }
        }

        return [
            'id' => (string) $row->id,
            'categoryId' => (string) $row->category_id,
            'currency' => (string) $row->currency,
            'limitMinor' => $row->amount_minor === null ? null : (string) $row->amount_minor,
            'spentMinor' => '0',
            'percent' => 0,
            'state' => 'ok',
            'overMinor' => null,
            'effectiveFrom' => substr((string) $row->effective_from, 0, 7),
        ];
    }
}
