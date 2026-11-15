<?php

namespace App\Finance;

use App\Access\AccessGate;
use App\Finance\Queries\AccountQuery;
use App\Finance\Queries\CategoryQuery;
use Illuminate\Http\Request;

/** Shared props of every finance page (the `finance` prop). */
class FinancePage
{
    public function __construct(
        private readonly AccountQuery $accounts,
        private readonly CategoryQuery $categories,
        private readonly AccessGate $gate,
    ) {}

    /** @return array<string, mixed> */
    public function props(Viewer $viewer, Request $request): array
    {
        $timezone = (string) config('family.timezone');
        $visible = $this->accounts->visible($viewer);

        return [
            'accounts' => $visible->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->type,
                'currency' => $a->currency,
            ])->values()->all(),
            'selectedAccount' => $this->selectedAccount($viewer, $request) ?? 'all',
            'month' => $this->month($request, $timezone),
            'today' => now($timezone)->toDateString(),
            'timezone' => $timezone,
            'canEdit' => $viewer->canEdit(),
            'isAdmin' => $this->gate->isAdmin($viewer->user),
            'categories' => $this->categories->all()->map(fn ($c) => [
                'id' => $c->id,
                'kind' => $c->kind,
                'defaultKey' => $c->default_key,
                'name' => $c->name,
            ])->values()->all(),
            'currencies' => $this->accounts->currencies($viewer),
        ];
    }

    /** The visible account id from ?account=, null for "all"; hidden, unknown or malformed ids abort 403. */
    public function selectedAccount(Viewer $viewer, Request $request): ?string
    {
        if (! $request->query->has('account')) {
            return null;
        }
        $raw = $request->query('account');
        if ($raw === 'all' || $raw === '' || $raw === null) {
            return null;
        }
        abort_unless(is_string($raw), 403);
        $account = $this->accounts->findVisible($viewer, $raw);
        abort_if($account === null, 403);

        return $account->id;
    }

    public function month(Request $request, ?string $timezone = null): string
    {
        $raw = $request->query('month');
        if (is_string($raw) && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $raw) === 1 && (int) substr($raw, 0, 4) >= 1900) {
            return $raw;
        }

        return now($timezone ?? (string) config('family.timezone'))->format('Y-m');
    }
}
