<?php

namespace Tests\Support;

use App\Access\Preset;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\FinanceAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared machinery of the finance leak net. Surfaces are enumerated from the router at run time and the seed data,
 * route parameters, panels and payloads come from one area file per later plan (tests/Support/FinancePrivacy/*.php),
 * so a new finance page or write route is covered the moment it exists and no shared file needs editing.
 *
 * Area file contract: returns an array, every key optional:
 *   visible      => fn (array &$ctx): void
 *   hidden       => fn (array &$ctx): void
 *   params       => [paramName => fn (array $ctx): string]
 *   hiddenParams => [paramName => fn (array $ctx): string]
 *   panels       => [routeName => fn (array $ctx): list<array<string,string>>]
 *   payloads     => [routeName => fn (array $ctx): array]
 */
class FinancePrivacy
{
    public const CANARY = 'ZxCanaryFin';

    /** @var list<string> */
    public const CANARY_NEEDLES = ['ZxCanaryFin', '777777', '7777.77', '7777,77'];

    /** Tables whose statements must always carry the membership predicate (user_id = ?). */
    private const READ_TABLES = [
        'finance_accounts', 'finance_transactions', 'finance_recurring', 'finance_recurring_matches',
        'finance_match_rejections', 'finance_import_batches', 'finance_import_rows',
    ];

    private const MUTATION_TABLES = [
        'finance_transactions', 'finance_recurring', 'finance_recurring_matches', 'finance_match_rejections',
        'finance_budgets', 'finance_import_batches', 'finance_import_rows',
    ];

    /**
     * finance_* tables that are family-wide by design and therefore carry no membership predicate. Every finance table
     * must be in READ_TABLES or here (FinanceLeakSuiteTest checks the schema), so a new table cannot slip past the net.
     */
    public const FAMILY_WIDE_TABLES = [
        'finance_account_members' => 'the membership table itself is the scope',
        'finance_categories' => 'one family-wide category list',
        'finance_budgets' => 'family-wide limits per category; spending is read from scoped transactions',
        'finance_import_profiles' => 'family-wide generic CSV profiles, validated to hold header text only',
    ];

    /**
     * The membership predicate: a subselect or join of finance_account_members constrained to the viewer
     * (user_id = ?), with an optional alias and table-qualified column. A bare mention of the table does not count.
     */
    private const MEMBERSHIP = '/\bfinance_account_members"?(?:\s+(?:as\s+)?"?\w+"?)?\s+(?:where|on)\b[^()]*?(?:"?\w+"?\.)?"?user_id"?\s*=\s*\?/is';

    /** @var array<string, mixed>|null */
    private static ?array $areas = null;

    /**
     * Viewer V and co-member O (both Parent preset), visible account A (V and O) and hidden account B (O only).
     *
     * @return array<string, mixed>
     */
    public static function scenario(): array
    {
        $viewer = User::factory()->withPreset(Preset::Parent)->create(['name' => 'Vera Viewer']);
        $other = User::factory()->withPreset(Preset::Parent)->create(['name' => 'Otto Other']);
        $account = FinanceAccount::factory()->withMembers($viewer, $other)->create(['name' => 'Visible Account']);
        $hidden = FinanceAccount::factory()->withMembers($other)->create(['name' => self::CANARY.' Hidden']);

        return [
            'viewer' => $viewer,
            'other' => $other,
            'account' => $account,
            'hiddenAccount' => $hidden,
            'hidden' => [],
        ];
    }

    /**
     * Every tests/Support/FinancePrivacy/*.php merged: lists keep order, maps keep the first definition.
     *
     * @return array{visible: list<callable>, hidden: list<callable>, params: array<string, callable>, hiddenParams: array<string, callable>, panels: array<string, list<callable>>, payloads: array<string, callable>}
     */
    public static function areas(): array
    {
        if (self::$areas !== null) {
            return self::$areas;
        }
        $merged = ['visible' => [], 'hidden' => [], 'params' => [], 'hiddenParams' => [], 'panels' => [], 'payloads' => []];
        $files = glob(__DIR__.'/FinancePrivacy/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $area = require $file;
            foreach (['visible', 'hidden'] as $key) {
                if (isset($area[$key])) {
                    $merged[$key][] = $area[$key];
                }
            }
            foreach (['params', 'hiddenParams', 'payloads'] as $key) {
                $merged[$key] += $area[$key] ?? [];
            }
            foreach ($area['panels'] ?? [] as $route => $provider) {
                $merged['panels'][$route][] = $provider;
            }
        }

        return self::$areas = $merged;
    }

    /** @param  array<string, mixed>  $ctx */
    public static function seedVisible(array &$ctx): void
    {
        foreach (self::areas()['visible'] as $seed) {
            $seed($ctx);
        }
    }

    /** @param  array<string, mixed>  $ctx */
    public static function seedHidden(array &$ctx): void
    {
        foreach (self::areas()['hidden'] as $seed) {
            $seed($ctx);
        }
    }

    private static function isFinanceName(string $name): bool
    {
        return str_starts_with($name, 'finance.') || str_starts_with($name, 'settings.finance_accounts.');
    }

    /** @return list<LaravelRoute> every GET finance route plus settings.index and home */
    public static function surfaces(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if ($name === null || ! in_array('GET', $route->methods(), true)) {
                continue;
            }
            if (self::isFinanceName($name) || in_array($name, ['settings.index', 'home'], true)) {
                $out[] = $route;
            }
        }

        return $out;
    }

    /** @return list<LaravelRoute> every non-GET finance route */
    public static function writes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if ($name !== null && self::isFinanceName($name) && ! in_array('GET', $route->methods(), true)) {
                $out[] = $route;
            }
        }

        return $out;
    }

    public static function method(LaravelRoute $route): string
    {
        return collect($route->methods())->first(fn ($m) => ! in_array($m, ['HEAD', 'OPTIONS'], true));
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array<string, string>
     */
    public static function params(LaravelRoute $route, array $ctx): array
    {
        $providers = self::areas()['params'];
        $params = [];
        foreach ($route->parameterNames() as $name) {
            if (! isset($providers[$name])) {
                throw new RuntimeException('No finance privacy provider for parameter {'.$name.'} of route '.$route->getName());
            }
            $params[$name] = $providers[$name]($ctx);
        }

        return $params;
    }

    /**
     * Route parameters with every hidden-record parameter replaced by the hidden value (or by a given override).
     *
     * @param  array<string, mixed>  $ctx
     * @param  callable(string): ?string|null  $override  param name => replacement or null to use the hidden provider
     * @return array<string, string>|null null when the route has no hidden-record parameter
     */
    public static function hiddenParams(LaravelRoute $route, array $ctx, ?callable $override = null): ?array
    {
        $hiddenProviders = self::areas()['hiddenParams'];
        $params = self::params($route, $ctx);
        $touched = false;
        foreach ($route->parameterNames() as $name) {
            if (isset($hiddenProviders[$name])) {
                $touched = true;
                $params[$name] = $override !== null ? ($override($name) ?? $hiddenProviders[$name]($ctx)) : $hiddenProviders[$name]($ctx);
            }
        }

        return $touched ? $params : null;
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    public static function payload(string $routeName, array $ctx): array
    {
        $provider = self::areas()['payloads'][$routeName] ?? null;

        return $provider === null ? [] : $provider($ctx);
    }

    /**
     * The route itself plus one URL per panel variant.
     *
     * @param  array<string, mixed>  $ctx
     * @return list<string>
     */
    public static function urls(LaravelRoute $route, array $ctx): array
    {
        $url = route($route->getName(), self::params($route, $ctx), false);
        $urls = [$url];
        foreach (self::areas()['panels'][$route->getName()] ?? [] as $provider) {
            foreach ($provider($ctx) as $query) {
                $urls[] = $url.'?'.http_build_query($query);
            }
        }

        return $urls;
    }

    /**
     * Requests every surface twice (Inertia JSON and HTML) as $viewer and returns the normalised responses.
     *
     * @param  array<string, mixed>  $ctx
     * @return array<string, string>
     */
    public static function capture(object $test, User $viewer, array $ctx): array
    {
        $version = self::inertiaVersion();
        $out = [];
        foreach (self::surfaces() as $route) {
            foreach (self::urls($route, $ctx) as $url) {
                foreach (['inertia', 'html'] as $kind) {
                    app('auth')->forgetGuards();
                    app('cache')->store()->flush();
                    $test->flushSession();
                    $headers = $kind === 'inertia' ? ['X-Inertia' => 'true', 'X-Inertia-Version' => $version] : [];
                    $response = $test->actingAs($viewer)->get($url, $headers);
                    $out[$kind.' '.$url] = self::normalise($response);
                }
            }
        }

        return $out;
    }

    public static function inertiaVersion(): string
    {
        return (string) (app(HandleInertiaRequests::class)->version(Request::create('/')) ?? '');
    }

    /** Status, redirect target and body with only the per-request noise removed (CSRF token, echoed page url). */
    public static function normalise(TestResponse $response): string
    {
        $body = $response->baseResponse instanceof BinaryFileResponse
            || $response->baseResponse instanceof StreamedResponse
            ? 'binary:'.md5((string) $response->streamedContent())
            : (string) $response->getContent();

        $body = preg_replace('/(name="csrf-token"\s+content=")[^"]*/', '$1', $body) ?? $body;
        $body = preg_replace('/"csrf_token"\s*:\s*"[^"]*"/', '"csrf_token":""', $body) ?? $body;

        // The Inertia page url echoes the requested path, which differs by construction between a hidden and a random id.
        $body = preg_replace('/"url"\s*:\s*"[^"]*"/', '"url":""', $body) ?? $body;

        return $response->getStatusCode()."\n".(string) $response->headers->get('Location')."\n".$body;
    }

    /** The text a canary scan runs over: ISO timestamps removed so microsecond digits cannot collide. */
    public static function scannable(string $normalised): string
    {
        return preg_replace('/\d{4}-\d{2}-\d{2}T[\d:.]+Z?/', '', $normalised) ?? $normalised;
    }

    /** Whether the statement carries the membership predicate for the viewer. */
    public static function hasMembershipPredicate(string $sql): bool
    {
        return preg_match(self::MEMBERSHIP, $sql) === 1;
    }

    /** @return list<string> the account-owned tables a statement names */
    private static function tablesIn(string $lower, array $tables): array
    {
        return array_values(array_filter($tables, fn (string $t) => preg_match('/\b'.preg_quote($t, '/').'\b/', $lower) === 1));
    }

    /**
     * Statements that touch an account-owned finance table without the membership predicate for the viewer.
     *
     * @return list<string>
     */
    public static function unscopedReads(array $statements): array
    {
        $bad = [];
        foreach ($statements as $sql) {
            if (self::tablesIn(strtolower($sql), self::READ_TABLES) !== [] && ! self::hasMembershipPredicate($sql)) {
                $bad[] = $sql;
            }
        }

        return $bad;
    }

    /**
     * Statements that change an account-owned finance table without proof that the account is visible, by shape:
     * UPDATE and DELETE need the membership predicate, and so does INSERT ... SELECT and INSERT ... ON CONFLICT DO
     * UPDATE. A plain INSERT ... VALUES cannot carry a predicate, so it needs an earlier statement of the same request
     * that does (the visible-account lookup). Statements matching an $exempt pattern are skipped; the exemption list is
     * explicit per route and itself tested.
     *
     * @param  list<string>  $statements  in execution order
     * @param  list<string>  $exempt  regular expressions matched against the lower-cased, whitespace-collapsed statement
     * @return list<string>
     */
    public static function unscopedMutations(array $statements, array $exempt = []): array
    {
        $bad = [];
        $proved = false;
        foreach ($statements as $sql) {
            $lower = strtolower(trim((string) preg_replace('/\s+/', ' ', $sql)));
            $scoped = self::hasMembershipPredicate($sql);
            $isWrite = preg_match('/^(update|delete|insert)\b/', $lower) === 1;
            $touches = $isWrite && self::tablesIn(preg_replace('/\bfinance_account_members\b/', '', $lower), self::MUTATION_TABLES) !== [];
            $wasProved = $proved;
            $proved = $proved || $scoped;
            if (! $touches || self::exempt($lower, $exempt)) {
                continue;
            }
            $plainInsert = preg_match('/^insert\b/', $lower) === 1
                && preg_match('/\bselect\b/', $lower) !== 1
                && preg_match('/\bon conflict\b.*\bdo update\b/', $lower) !== 1;
            if ($plainInsert ? ! $wasProved : ! $scoped) {
                $bad[] = $sql;
            }
        }

        return $bad;
    }

    /** @param  list<string>  $exempt */
    private static function exempt(string $lower, array $exempt): bool
    {
        foreach ($exempt as $pattern) {
            if (preg_match($pattern, $lower) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Runs $request and fails, naming the statement, when it issues an unscoped finance mutation. */
    public static function assertScopedMutations(callable $request, array $exempt = []): void
    {
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });
        $request();

        $bad = self::unscopedMutations($statements, $exempt);
        if ($bad !== []) {
            Assert::fail('Unscoped finance mutation (no membership proof): '.$bad[0]);
        }
        Assert::assertTrue(true);
    }
}
