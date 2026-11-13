<?php

namespace App\Http\Controllers\Finance;

use App\Finance\FinancePage;
use App\Finance\Queries\RecurringQuery;
use App\Finance\Recurring\RecurringWriter;
use App\Finance\Viewer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\RecurringRequest;
use App\Tasks\TaskRecurrence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class RecurringController extends Controller
{
    public function index(Request $request, FinancePage $page, RecurringQuery $recurring): Response
    {
        $viewer = Viewer::for($request->user());
        $finance = $page->props($viewer, $request);
        $accountId = $finance['selectedAccount'] === 'all' ? null : $finance['selectedAccount'];
        $upcoming = $recurring->upcoming($viewer, $accountId);

        return Inertia::render('Finance/Recurring', [
            'finance' => $finance,
            'recurring' => [
                'items' => array_map(fn (object $r) => $this->item($r), $recurring->visible($viewer, $accountId)),
                'calendar' => $recurring->calendar($viewer, $finance['month'], $accountId),
                'overdue' => $upcoming['overdue'],
                'next30' => $upcoming['next30'],
                'stillToPay' => $recurring->stillToPay($viewer, $finance['month'], $accountId),
            ],
            'panelBill' => $this->panelBill($request, $viewer, $recurring),
            'panelRecurring' => $this->panelRecurring($request, $viewer, $recurring),
            'dayPanel' => $this->dayPanel($request, $viewer, $recurring, $accountId),
            'candidates' => null,
        ]);
    }

    public function store(RecurringRequest $request, RecurringWriter $writer): RedirectResponse
    {
        $writer->create(Viewer::for($request->user()), $request->account(), $request->changes());

        return $this->done($request, 'finance.toast.rec_saved');
    }

    public function update(RecurringRequest $request, string $recurring, RecurringWriter $writer): RedirectResponse
    {
        $writer->update(Viewer::for($request->user()), $request->item(), $request->changes());

        return $this->done($request, 'finance.toast.rec_saved');
    }

    public function destroy(Request $request, string $recurring, RecurringQuery $query, RecurringWriter $writer): RedirectResponse
    {
        $viewer = Viewer::for($request->user());
        abort_if($query->findVisible($viewer, $recurring) === null, 404);

        $writer->delete($viewer, $recurring);

        return $this->done($request, 'finance.toast.rec_deleted');
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

    /** @return array<string, mixed> */
    private function item(object $r): array
    {
        return [
            'id' => (string) $r->id,
            'name' => (string) $r->name,
            'expectedMinor' => (string) $r->expected_amount_minor,
            'currency' => (string) $r->currency,
            'accountId' => (string) $r->account_id,
            'categoryId' => $r->category_id,
            'rule' => (string) $r->recurrence_rule,
            'pattern' => TaskRecurrence::stored((string) $r->recurrence_rule)?->toArray(),
            'startsOn' => substr((string) $r->starts_on, 0, 10),
            'endsOn' => $r->ends_on === null ? null : substr((string) $r->ends_on, 0, 10),
            'textHint' => $r->text_hint,
            'toleranceMinor' => (string) $r->tolerance_minor,
            'windowDays' => (int) $r->window_days,
        ];
    }

    /** @return array<string, mixed>|null */
    private function panelBill(Request $request, Viewer $viewer, RecurringQuery $recurring): ?array
    {
        if (! $request->query->has('bill')) {
            return null;
        }
        $bill = $request->query('bill');
        abort_unless(is_string($bill) && preg_match('/^([0-9a-f-]{36})\.(\d{4}-\d{2}-\d{2})$/', $bill, $m) === 1, 403);
        $occurrence = $recurring->bill($viewer, $m[1], $m[2]);
        abort_if($occurrence === null, 403);

        return $occurrence;
    }

    /** @return array<string, mixed>|null */
    private function panelRecurring(Request $request, Viewer $viewer, RecurringQuery $recurring): ?array
    {
        if (! $request->query->has('rec')) {
            return null;
        }
        $rec = $request->query('rec');
        abort_unless(is_string($rec), 403);
        if ($rec === 'new') {
            abort_unless($viewer->canEdit(), 403);

            return ['mode' => 'new'];
        }
        $row = Str::isUuid($rec) ? $recurring->findVisible($viewer, $rec) : null;
        abort_if($row === null, 403);

        return $this->item($row);
    }

    /** @return array<string, mixed>|null */
    private function dayPanel(Request $request, Viewer $viewer, RecurringQuery $recurring, ?string $accountId): ?array
    {
        if (! $request->query->has('day')) {
            return null;
        }
        $day = $request->query('day');
        abort_unless(is_string($day) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[1] >= 1900 && (int) $m[1] <= 2200, 403);

        return ['date' => $day, 'occurrences' => $recurring->occurrences($viewer, $day, $day, $accountId)];
    }
}
