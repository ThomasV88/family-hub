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

    /** Tables whose statements must always carry the membership subquery. */
    private const READ_TABLES = [
        'finance_accounts', 'finance_transactions', 'finance_recurring', 'finance_recurring_matches',
        'finance_match_rejections', 'finance_import_batches', 'finance_import_rows',
    ];

    private const MUTATION_TABLES = [
        'finance_transactions', 'finance_recurring', 'finance_recurring_matches', 'finance_match_rejections',
        'finance_budgets', 'finance_import_batches', 'finance_import_rows',
    ];

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

    /** Status, redirect target and body with only the per-session noise removed (CSRF token). */
    public static function normalise(TestResponse $response): string
    {
        $body = $response->baseResponse instanceof BinaryFileResponse
            || $response->baseResponse instanceof StreamedResponse
            ? 'binary:'.md5((string) $response->streamedContent())
            : (string) $response->getContent();

        $body = preg_replace('/(name="csrf-token"\s+content=")[^"]*/', '$1', $body) ?? $body;
        $body = preg_replace('/"csrf_token"\s*:\s*"[^"]*"/', '"csrf_token":""', $body) ?? $body;

        return $response->getStatusCode()."\n".(string) $response->headers->get('Location')."\n".$body;
    }

    /** The text a canary scan runs over: ISO timestamps removed so microsecond digits cannot collide. */
    public static function scannable(string $normalised): string
    {
        return preg_replace('/\d{4}-\d{2}-\d{2}T[\d:.]+Z?/', '', $normalised) ?? $normalised;
    }

    /**
     * Statements run by $request that touch an account-owned finance table without the membership subquery.
     *
     * @return list<string>
     */
    public static function unscopedReads(array $statements): array
    {
        $bad = [];
        foreach ($statements as $sql) {
            $lower = strtolower($sql);
            foreach (self::READ_TABLES as $table) {
                if (str_contains($lower, $table) && ! str_contains($lower, 'finance_account_members')) {
                    $bad[] = $sql;
                    break;
                }
            }
        }

        return $bad;
    }

    /**
     * Statements that change an account-owned finance table (UPDATE, DELETE, INSERT ... ON CONFLICT DO UPDATE)
     * without the membership subquery.
     *
     * @param  list<string>  $statements
     * @return list<string>
     */
    public static function unscopedMutations(array $statements): array
    {
        $bad = [];
        foreach ($statements as $sql) {
            $lower = strtolower(trim($sql));
            $mutation = preg_match('/^(update|delete)\b/', $lower) === 1
                || preg_match('/^insert\b.*\bon\s+conflict\b.*\bdo\s+update\b/s', $lower) === 1;
            if (! $mutation || str_contains($lower, 'finance_account_members')) {
                continue;
            }
            foreach (self::MUTATION_TABLES as $table) {
                if (str_contains($lower, $table)) {
                    $bad[] = $sql;
                    break;
                }
            }
        }

        return $bad;
    }

    /** Runs $request and fails, naming the statement, when it issues an unscoped finance mutation. */
    public static function assertScopedMutations(callable $request): void
    {
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });
        $request();

        $bad = self::unscopedMutations($statements);
        if ($bad !== []) {
            Assert::fail('Unscoped finance mutation (no finance_account_members): '.$bad[0]);
        }
        Assert::assertTrue(true);
    }
}
