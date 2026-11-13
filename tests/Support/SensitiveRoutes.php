<?php

namespace Tests\Support;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/**
 * Enumerates the sensitive (finance and health) routes and holds the reasoned allowlist for the idle gate proof.
 *
 * PHASE 7 REMINDER: every future health/medical route MUST carry `access:module,health,<level>`. That middleware
 * is what puts it behind the idle gate by construction, because SensitiveGate runs inside RequireAccess for
 * Module::sensitive(). It MUST also use a URI under /health or /medical and a route name containing `health` or
 * `medical`, so the naming safety net in SensitiveGateMatrixTest catches a route that forgets the middleware.
 * File and JSON routes included.
 */
class SensitiveRoutes
{
    /**
     * Routes that look sensitive by name or URI but are deliberately not gated. Prefix match on route name.
     * Each entry needs a reason.
     *
     * @return array<string, string> route-name prefix => reason
     */
    public static function allowlist(): array
    {
        return [
            // PROVISIONAL (orchestrator), owner confirms in 06-13 Task 2: admin account metadata only (names,
            // membership), no money. Decision 2026-11-13 (research Open Question 3). If rejected, 06-13 Task 3
            // removes this entry and gates the route.
            'settings.finance_accounts.' => 'admin finance-account settings: metadata only, no money',
        ];
    }

    /**
     * Not routes, but recorded here so the decisions live in one place.
     *
     * PROVISIONAL (orchestrator), owner confirms in 06-13 Task 2: the Home "still to pay" card (counts only) is
     * served by `home`, which is not finance-routed, and stays visible on an idle session (research Open
     * Question 2). SensitiveGateMatrixTest asserts it separately.
     *
     * PROVISIONAL (orchestrator), owner confirms in 06-13 Task 2: no import-wizard keepalive. The wizard does not
     * ping to keep the window open; the 423 handling in importApi.js sends the member to /confirm-access.
     */
    public const DECISIONS = ['home_card_visible_when_idle', 'no_import_keepalive'];

    /** @return array<string, LaravelRoute> name => route, for every route behind access:module,finance|health */
    public static function all(): array
    {
        $found = [];
        foreach (Route::getRoutes() as $route) {
            if (self::isSensitive($route)) {
                $found[$route->getName() ?? 'UNNAMED:'.$route->uri()] = $route;
            }
        }

        return $found;
    }

    public static function isSensitive(LaravelRoute $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && preg_match('/^access:module,(finance|health),/', $middleware)) {
                return true;
            }
        }

        return false;
    }

    public static function allowlisted(LaravelRoute $route): bool
    {
        $name = (string) $route->getName();
        foreach (array_keys(self::allowlist()) as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Routes that look sensitive by URI or name yet are neither behind the gate nor allowlisted. @return list<string> */
    public static function offenders(): array
    {
        $offenders = [];
        foreach (Route::getRoutes() as $route) {
            $uri = ltrim($route->uri(), '/');
            $name = (string) $route->getName();
            $looks = preg_match('#^(finance|health|medical)(/|$)#', $uri) === 1
                || preg_match('/finance|health|medical/', $name) === 1;
            if ($looks && ! self::isSensitive($route) && ! self::allowlisted($route)) {
                $offenders[] = $name !== '' ? $name : 'UNNAMED:'.$uri;
            }
        }

        return $offenders;
    }
}
