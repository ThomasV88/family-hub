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
 * File and JSON routes included. The controller must also live under App\Http\Controllers\Health (or Medical):
 * the namespace net in offenders() catches a route that serves the data under an innocent name or URI.
 *
 * PROVISIONAL (orchestrator), owner confirms in 07-20: the admin sees person NAMES and member lists on
 * Settings > Members (a `healthPeople` prop from AdminSubjectQuery::listForAdmin), never records or record counts.
 * There is no GET under settings.health_people.; the two PUT routes change who can open a record and are listed in
 * accessGranting() (admin plus access:recent).
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
            // PROVISIONAL (orchestrator), owner confirms in 06-13 Task 2: the read-only admin listing shows account
            // names and membership, no money. Decision 2026-11-13 (research Open Question 3). The routes that
            // CHANGE membership or visibility (store, update) are not allowlisted: see accessGranting().
            'settings.finance_accounts.index' => 'admin finance-account listing: metadata only, no money, read-only',
        ];
    }

    /**
     * Admin routes that grant or widen access to sensitive data (permissions, invitations, finance account
     * membership/visibility). Each must carry `access:recent` on top of `access:admin` (06-REVIEW CR-01), so a
     * stale or stolen admin session cannot mint access it never had to confirm for. By route name.
     *
     * @return list<string>
     */
    public static function accessGranting(): array
    {
        return [
            'settings.members.permissions.update',
            'settings.invitations.store',
            'settings.invitations.resend',
            'settings.invitations.link',
            'settings.invitations.destroy',
            'settings.finance_accounts.store',
            'settings.finance_accounts.update',
            'settings.health_people.members.update',
            'settings.health_people.link.update',
            // Lets a chosen member manage another member's wishlist (widens who can act for them) (v1.0 gap W3).
            'settings.members.wishlist_managers.update',
            // Removes a member's authenticator, passkeys and recovery codes: it lowers the protection of their account (v1.0 gap W3).
            'settings.members.second_factor.destroy',
            // Queues a login check in the AI worker. Grants nothing; listed so it needs a recent confirmation (08-14).
            'settings.ai_calls.check',
            // Changes where medical documents are sent (which agent, which model, in what order) (09-12).
            'settings.ai_agents.update',
            'settings.ai_agents.move',
            // Bulk re-generation sends many documents to external AI (09-13).
            'settings.ai_agents.bulk.dry_run',
            'settings.ai_agents.bulk.start',
            'settings.ai_agents.bulk.pause',
            'settings.ai_agents.bulk.resume',
            'settings.ai_agents.bulk.cancel',
        ];
    }

    /**
     * Route-name prefixes under which a NEW admin route must be either listed in accessGranting() or in
     * grantAllowlist() (a deliberate "does not grant access" decision). Safety net for CR-01.
     *
     * @return list<string>
     */
    public static function grantPrefixes(): array
    {
        return ['settings.members.permissions.', 'settings.invitations.', 'settings.finance_accounts.', 'settings.health_people.', 'settings.ai_calls.', 'settings.ai_agents.', 'settings.members.wishlist_managers.', 'settings.members.second_factor.'];
    }

    // settings.members.sessions.* is deliberately NOT a grant prefix: listing and revoking sessions only removes access (a
    // revoke is defensible without a recent confirmation) and grants nothing (v1.0 gap W3).

    /** @return array<string, string> exact route name => reason it does not grant access */
    public static function grantAllowlist(): array
    {
        return [
            'settings.finance_accounts.index' => 'read-only listing (metadata, no money)',
            'settings.ai_calls' => 'admin AI call log: names, agent, model, status, times; no medical content (08-14)',
            'settings.ai_agents' => 'admin agent settings: agent keys, booleans, positions, model ids, health enums; no medical content',
            'settings.ai_agents.bulk' => 'bulk run counts only: integers, enums and times; no person, title or content (09-13)',
        ];
    }

    /** @return list<string> routes under a grant prefix that are neither listed nor allowlisted */
    public static function unclassifiedGrantRoutes(): array
    {
        $bad = [];
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            foreach (self::grantPrefixes() as $prefix) {
                if (str_starts_with($name, $prefix)
                    && ! in_array($name, self::accessGranting(), true)
                    && ! array_key_exists($name, self::grantAllowlist())) {
                    $bad[] = $name;
                }
            }
        }

        return $bad;
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

    /**
     * The route's controller lives in a Finance, Health or Medical controller namespace (06-REVIEW WR-09): a route
     * serving that data under an innocent name or URI is still found.
     */
    public static function inSensitiveNamespace(LaravelRoute $route): bool
    {
        return preg_match('/^\\\\?App\\\\Http\\\\Controllers\\\\(Finance|Health|Medical)\\\\/', $route->getActionName()) === 1;
    }

    /** Routes that look sensitive by URI, name or controller namespace yet are neither behind the gate nor allowlisted. @return list<string> */
    public static function offenders(): array
    {
        $offenders = [];
        foreach (Route::getRoutes() as $route) {
            $uri = ltrim($route->uri(), '/');
            $name = (string) $route->getName();
            $looks = preg_match('#^(finance|health|medical)(/|$)#', $uri) === 1
                || preg_match('/finance|health|medical/', $name) === 1
                || self::inSensitiveNamespace($route);
            if ($looks && ! self::isSensitive($route) && ! self::allowlisted($route) && ! in_array($name, self::accessGranting(), true)) {
                $offenders[] = $name !== '' ? $name : 'UNNAMED:'.$uri;
            }
        }

        return $offenders;
    }
}
