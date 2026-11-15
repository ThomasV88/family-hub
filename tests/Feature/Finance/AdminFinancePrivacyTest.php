<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FinancePrivacy;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

/**
 * An admin with a finance edit grant, member of the visible account A but not of the hidden account X.
 *
 * @return array<string, mixed>
 */
function fhApScenario(): array
{
    $ctx = FinancePrivacy::scenario();
    $admin = User::factory()->admin()->withGrant(Module::Finance, AccessLevel::Edit)->create(['name' => 'Ada Admin']);
    $ctx['account']->members()->attach($admin->id, ['created_at' => now()]);
    $ctx['viewer'] = $admin;
    FinancePrivacy::seedVisible($ctx);
    FinancePrivacy::seedHidden($ctx);

    return $ctx;
}

it('shows the unshared account name only on Settings > Finance accounts and nothing of its money anywhere', function () {
    $ctx = fhApScenario();
    $hiddenId = $ctx['hiddenAccount']->id;
    $captured = FinancePrivacy::capture($this, $ctx['viewer'], $ctx);

    expect($captured)->not->toBeEmpty();
    $settingsKeys = 0;
    foreach ($captured as $key => $body) {
        $scan = FinancePrivacy::scannable($body);
        foreach (['777777', '7777.77', '7777,77'] as $amount) {
            expect(str_contains($scan, $amount))->toBeFalse("hidden amount in $key");
        }
        if (str_contains($key, '/settings/finance-accounts')) {
            $settingsKeys++;

            continue;
        }
        expect(str_contains($scan, FinancePrivacy::CANARY))->toBeFalse("hidden account name in $key")
            ->and(str_contains($scan, $hiddenId))->toBeFalse("hidden account id in $key");
    }
    expect($settingsKeys)->toBeGreaterThan(0);

    $this->actingAs($ctx['viewer'])->get('/settings/finance-accounts')->assertOk()->assertInertia(function (Assert $page) use ($ctx) {
        $accounts = collect($page->toArray()['props']['accounts']);
        $x = $accounts->firstWhere('id', $ctx['hiddenAccount']->id);
        expect($x)->not->toBeNull()
            ->and($x['name'])->toBe($ctx['hiddenAccount']->name)
            ->and($x['type'])->toBe('bank')
            ->and($x['sharedWithMe'])->toBeFalse()
            ->and($x['opening'])->toBeNull()
            ->and(json_encode($x))->not->toContain('currencyLocked')->not->toContain('777777');
        expect($accounts->firstWhere('id', $ctx['account']->id)['sharedWithMe'])->toBeTrue();
    });
});

it('shows the account on finance pages once the admin ticks themselves', function () {
    $ctx = fhApScenario();
    $ctx['hiddenAccount']->members()->attach($ctx['viewer']->id, ['created_at' => now()]);

    $json = $this->actingAs($ctx['viewer'])->get('/finance/accounts', ['X-Inertia' => 'true', 'X-Inertia-Version' => FinancePrivacy::inertiaVersion()])->assertOk()->getContent();

    expect($json)->toContain(FinancePrivacy::CANARY);
});

it('refuses every finance write to a view-only member and sends no edit rights to the pages', function () {
    $ctx = FinancePrivacy::scenario();
    $viewOnly = User::factory()->withGrant(Module::Finance, AccessLevel::View)->create();
    $ctx['account']->members()->attach($viewOnly->id, ['created_at' => now()]);
    $ctx['viewer'] = $viewOnly;
    FinancePrivacy::seedVisible($ctx);

    $checked = 0;
    foreach (FinancePrivacy::writes() as $route) {
        $url = route($route->getName(), FinancePrivacy::params($route, $ctx), false);
        app('auth')->forgetGuards();
        $this->flushSession();
        $status = $this->actingAs($viewOnly)->call(FinancePrivacy::method($route), $url, FinancePrivacy::payload($route->getName(), $ctx))->getStatusCode();
        expect($status)->toBe(403, $route->getName().' answered '.$status);
        $checked++;
    }
    expect($checked)->toBeGreaterThanOrEqual(21);

    $version = FinancePrivacy::inertiaVersion();
    // The import screen is an edit-only page: a view-only member does not even get in.
    $this->actingAs($viewOnly)->get('/finance/import')->assertForbidden();
    foreach (['/finance', '/finance/transactions', '/finance/recurring', '/finance/accounts', '/finance/categories'] as $url) {
        $body = $this->actingAs($viewOnly)->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->assertOk()->getContent();
        expect($body)->toContain('"canEdit":false')->not->toContain('"canEdit":true');
    }
});
