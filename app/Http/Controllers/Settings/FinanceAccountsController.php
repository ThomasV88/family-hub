<?php

namespace App\Http\Controllers\Settings;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Finance\Accounts\AccountAdmin;
use App\Finance\Currency;
use App\Finance\Queries\AccountAdminQuery;
use App\Finance\Viewer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\FinanceAccountRequest;
use App\Models\User;
use App\Support\SettingsSections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Admin screen for account metadata and visibility. No computed figures are shown here. */
class FinanceAccountsController extends Controller
{
    public function __construct(
        private readonly AccountAdminQuery $accounts,
        private readonly AccountAdmin $admin,
        private readonly AccessGate $gate,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $level = $this->gate->level($user, Module::Finance);
        $metadata = $this->accounts->metadata();

        $panel = $request->query('acct');
        if ($panel !== null) {
            $known = $panel === 'new' || collect($metadata)->contains(fn (array $a) => $a['id'] === $panel);
            abort_unless(is_string($panel) && $known, 403);
        }

        $accounts = array_map(function (array $a) use ($user, $level) {
            $sharedWithMe = in_array($user->id, $a['memberIds'], true);
            $opening = null;
            if ($sharedWithMe && $level !== AccessLevel::None) {
                $viewer = new Viewer($user, $level);
                $row = $this->accounts->opening($viewer, $a['id']);
                if ($row !== null) {
                    $opening = [
                        'minor' => (string) $row['minor'],
                        'asOf' => $row['asOf'],
                        'currencyLocked' => $this->accounts->hasTransactions($viewer, $a['id']),
                    ];
                }
            }

            return $a + ['memberCount' => count($a['memberIds']), 'sharedWithMe' => $sharedWithMe, 'opening' => $opening];
        }, $metadata);

        return Inertia::render('Settings/FinanceAccounts', [
            'sections' => SettingsSections::for($user),
            'accounts' => $accounts,
            'members' => User::query()->orderByRaw('lower(name)')->orderBy('id')->get()->map(fn (User $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'financeAccess' => $this->gate->level($m, Module::Finance) !== AccessLevel::None,
            ])->all(),
            'panel' => $panel,
        ]);
    }

    public function store(FinanceAccountRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $currency = Currency::from($data['currency']);

        $this->admin->create($request->user(), [
            'name' => trim($data['name']),
            'type' => $data['type'],
            'currency' => $currency->value,
            'opening_balance_minor' => $request->openingMinor($currency) ?? 0,
            'balance_as_of' => $data['balance_as_of'],
        ], $request->memberIds());

        return back(303)->with('toast', ['key' => 'finance.toast.acc_saved']);
    }

    public function update(FinanceAccountRequest $request, string $account): RedirectResponse
    {
        $existing = $this->accounts->find($account);
        abort_if($existing === null, 403);

        $data = $request->validated();
        $attributes = ['name' => trim($data['name']), 'type' => $data['type']];

        if ($request->seeingViewer() !== null) {
            $currency = Currency::from($data['currency'] ?? $existing->currency);
            if (isset($data['currency'])) {
                $attributes['currency'] = $currency->value;
            }
            if (array_key_exists('opening_balance', $data)) {
                $attributes['opening_balance_minor'] = $request->openingMinor($currency) ?? 0;
            }
            if (isset($data['balance_as_of'])) {
                $attributes['balance_as_of'] = $data['balance_as_of'];
            }
        }

        $this->admin->update($request->user(), $account, $attributes, $request->memberIds());

        return back(303)->with('toast', ['key' => 'finance.toast.acc_saved']);
    }
}
