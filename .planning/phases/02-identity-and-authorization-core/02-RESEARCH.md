# Phase 2: Identity and Authorization Core - Research

**Researched:** 2026-11-13
**Domain:** Laravel 13 + Fortify (headless) + Socialite (Google), invite-only onboarding, DB sessions, custom deny-by-default policy layer, append-only audit log on Postgres
**Confidence:** MEDIUM-HIGH (package versions and Fortify/Socialite/Laravel source read from upstream; several behaviours flagged `[ASSUMED]` need a spike or test)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
**Invites and first admin**
- First admin is created by an artisan command `family:create-admin` run once on the server; it is the only way to create a user without an invite.
- Invite is single-use, expires after 7 days, admin can revoke or resend.
- Invite is sent by email via the owner's Stalwart SMTP; admin can also copy the link to share manually.
- At accept the invitee sets name and password, or signs up with Google; the Google email must match the invited address.

**Sign-in and sessions**
- Laravel Fortify (email/password) and Socialite (Google) per CLAUDE.md; passwords hashed with Laravel's default hasher.
- Google links only by verified Google email and Google ID to an already invited or existing account; no self-signup via Google.
- "Stay signed in" 30 days, otherwise browser session; sessions stored in the database so admin can list and revoke per member.
- Login rate limiting and lockout, generic error messages, password reset by email.

**Permissions**
- Per member per module: none, view, edit. Modules: todo, wishlist, finance, health; settings is admin-only.
- Presets (adjustable after applying): Parent: todo/wishlist/finance edit, health none. Child: todo/wishlist edit, finance and health none. Grandparent: todo/wishlist edit, finance and health none. Guest: todo view only.
- One deny-by-default `requireAccess(user, module, level)` policy layer called by every controller/route; role-by-endpoint Pest test matrix that fails if an endpoint is not registered in it.
- Admin always has settings access, but health and finance default to none even for admins until explicitly granted.

**Audit log**
- Logs reads and writes of health and finance data, permission changes, invites and session revokes: who, what, when, IP; never content.
- Admin view is a filterable read-only page; no edit/delete. Append-only, retained 2 years.

### Claude's Discretion
Table and class names, middleware structure, email templates, admin UI layout (use the Phase 01.1 design system components and AppShell), test organisation.

### Deferred Ideas (OUT OF SCOPE)
TOTP/passkey and re-auth timeout (Phase 6); record-level rules such as hidden wishlist reservations (Phase 4).
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| AUTH-01 | Registration is invite-only: a family admin invites a member by email | Invitation table + hashed token, `family:create-admin`, no Fortify `registration` feature, queued mail via Stalwart, accept flow in one transaction |
| AUTH-02 | User can sign in with email and password | Fortify headless with `Fortify::loginView` -> Inertia, custom `authenticateUsing` (dummy hash), named login limiter |
| AUTH-03 | User can sign in with Google, linked by verified Google identity | Socialite `google` driver, `email_verified` + `sub` (`google_id`) link rules, invite-session flow |
| AUTH-04 | Session persists across refresh; admin can revoke a member's sessions | `SESSION_DRIVER=database` (`sessions.user_id` already exists), delete rows + rotate `remember_token`, `setRememberDuration(43200)` |
| AUTH-05 | Admin sets per-member per-module access (none/view/edit) | `module_permissions` table + `AccessLevel`/`Module` enums, single atomic save endpoint |
| AUTH-06 | Presets Parent/Child/Grandparent/Guest, then adjustable | `Preset` enum -> grants map; apply = fill form (client), save = same endpoint; invite carries preset |
| AUTH-07 | One deny-by-default policy layer + role-by-endpoint test matrix | `access` route middleware + fail-closed global guard + `AccessGate::require`; matrix test over `Route::getRoutes()` |
| AUTH-08 | Access/changes of medical + finance data audited, admin-viewable | `audit_logs` (INSERT+SELECT only for `fh_app`, UPDATE trigger), auto-logging in the `access` middleware for audited modules, read-only filtered page |
</phase_requirements>

## Summary

The phase is mostly assembly of first-party Laravel pieces plus two hand-written cores (policy layer, audit log). Fortify 1.42.0 is a headless backend: with `Features::resetPasswords()` only (no `registration`, no `emailVerification`, no 2FA/passkeys) it exposes login, logout, forgot/reset password and nothing else, and every view is a callback, so Inertia pages drop straight in. Socialite 5.32.0's Google driver returns the OpenID userinfo (`sub` as id, `email`, raw `email_verified`), which is exactly what the link rules need. Laravel's `DatabaseSessionHandler` already writes `user_id`, `ip_address`, `user_agent` into the Phase 1 `sessions` table, so list/revoke is plain SQL.

The non-obvious traps are all about "revoke really means revoke": Laravel's remember-me cookie lives 400 days by default (`SessionGuard::$rememberDuration = 576000` minutes) and survives deletion of session rows, so revoke must also rotate `users.remember_token`, and the 30-day requirement needs `setRememberDuration(43200)`. Fortify's default forgot-password response leaks whether an email exists (failed status becomes a validation error), so the `FailedPasswordResetLinkRequestResponse` contract must be rebound to return the success response. Fortify's `EnsureLoginIsNotThrottled` throttles per email+IP only and returns a validation message, so define a named `login` limiter (config `fortify.limiters.login`) with a second per-email bucket and convert 429 into an Inertia-friendly flash.

Append-only audit: Phase 1 default privileges grant `fh_app` SELECT/INSERT/UPDATE/DELETE on every table created by `fh_owner`; the audit migration (run as owner) must `REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs FROM fh_app` and add a `BEFORE UPDATE` trigger. That means 2-year retention cannot be done by the app role: run `audit:prune` through the owner-credential `migrate` compose service from host cron. Tests run as `fh_owner` (phpunit.xml), so privilege tests must assert via `has_table_privilege('fh_app', ...)`, not by attempting the write.

**Primary recommendation:** Add only `laravel/fortify` and `laravel/socialite` (no other packages). Route every endpoint through one `access:<rule>` middleware alias that fails closed when a route declares no rule, mirror all routes in a Pest matrix test that fails on unregistered or unnamed routes, and write audit rows from that same middleware for health/finance so later phases inherit auditing for free.

## Project Constraints (from CLAUDE.md)

- Stack locked: Laravel (PHP), Inertia + Vue, PostgreSQL, database queue + separate worker container, Fortify + Socialite, **custom policy layer** (no Spatie/CASL), Laravel localization + vue-i18n, Pest + Vitest. Ignore TypeScript/Next.js recommendations.
- Privacy: per-module permissions enforced server-side; health and finance default restricted. Languages SK/PL/EN. Devices: phone/desktop/iPad.
- Never store subscription OAuth tokens in the app; do not set per-token AI API keys (not touched this phase).
- Global rules: tests only on staging, production only via PR to `main`, secrets (`.env`, `app.env`, `secrets/`) never printed; edit server files in place with backup first; no restart of other stacks.
- File-changing work goes through GSD commands.
- Existing conventions to follow: `NoStoreDynamicResponses` on all web responses; service worker precache-only; Inertia pages in `resources/js/Pages`; `Ui*` components and `AppShell`; i18n keys in `lang/{en,sk,pl}.json` (UI-SPEC names `lang/en-GB.json` etc.; **verify actual filenames in `lang/` before writing keys**, Phase 1 `SetLocale` reads `lang/{locale}.json` with codes `sk|pl|en`).

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Authentication (password, Google), throttling, session issue | API / Backend (Fortify, Socialite controllers) | Browser (forms only) | Server-only trust boundary |
| Permission enforcement (`requireAccess`) | API / Backend (route middleware + `AccessGate`) | — | Hiding nav in Vue is cosmetic; never the control |
| Nav hiding of modules without grant | Browser (reads shared `auth.access` prop) | API (computes prop) | UX only |
| Invite lifecycle and token validation | API / Backend | Database (unique/partial indexes) | Single-use must be race-free (row lock) |
| Session list/revoke | API / Backend | Database (`sessions` table) | Handler already persists user/IP/UA |
| Audit write | API / Backend (middleware + service) | Database (privileges + trigger make it append-only) | DB layer protects against app bugs/compromise of `fh_app` |
| Audit retention purge | Host cron -> owner-credential one-shot container | Database | App role has no DELETE by design |
| Email delivery | Worker container (queue) | Stalwart SMTP | Keeps request fast, retries |
| 403/419 UX pages | Frontend (Inertia error pages) | API (status codes) | Status always set server-side |

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| laravel/fortify | v1.42.0 (2026-10-21), requires `illuminate/*` ^11\|^12\|^13, php ^8.2 | Headless login/logout/password reset | Owner-locked [VERIFIED: packagist p2 API]. Hard-requires `laravel/passkeys ^0.2.0`, `pragmarx/google2fa ^9.0`, `bacon/bacon-qr-code ^3.0` (installed but unused until Phase 6) |
| laravel/socialite | v5.32.0 (2026-11-10), illuminate ^12\|^13 | Google OAuth | Owner-locked [VERIFIED: packagist p2 API]. Google provider is built in, no `socialiteproviders/*` needed |
| laravel/framework | ^13.17 already installed | Sessions, rate limiter, password broker, mail | [VERIFIED: composer.json] |
| inertiajs/inertia-laravel ^3 + @inertiajs/vue3 3.11.0 | installed | Pages | [VERIFIED: composer.json, npm view] |
| pestphp/pest ^4.7 (not 5.x as Phase 1 research said) | installed | Matrix and feature tests | [VERIFIED: composer.json] |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| vitest + vitest-axe (+ jsdom) | per UI-SPEC | Page a11y tests | UI-SPEC requires axe per page in light/dark and en/sk/pl. `vitest-axe` is not yet in package.json: `[ASSUMED]` legitimacy, verify with `npm view vitest-axe` before adding |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Fortify | Hand-rolled login with `Auth::attempt` | Fortify is owner-locked; the footprint (3 transitive deps) is acceptable and they are needed in Phase 6 anyway |
| hand-rolled audit | `spatie/laravel-activitylog`, `owen-it/laravel-auditing` | They update/delete rows and record model content (violates "never content"); not append-only. Do not add |
| custom policy | `spatie/laravel-permission`, CASL | CLAUDE.md forbids; 4 modules x 3 levels is a lookup table |
| `jenssegers/agent` for UA parsing | ~30-line regex helper | Avoid a package for "Safari on iPhone"; unit-test the helper |

**Installation:**
```bash
composer require laravel/fortify laravel/socialite     # run in the app container
php artisan fortify:install                             # [ASSUMED] publishes FortifyServiceProvider, Actions/Fortify, config; delete any published 2FA/passkey migration (Phase 6)
```

**Version verification:** 2026-11-13 via `repo.packagist.org/p2/<pkg>.json`: fortify v1.42.0, socialite v5.32.0, laravel/passkeys v0.3.0 (2026-11-10, repo laravel/passkeys-server), pragmarx/google2fa v9.1.0, bacon/bacon-qr-code v3.1.1. Fortify's `^0.2.0` constraint on passkeys may force 0.2.x; let composer resolve.

## Package Legitimacy Audit

slopcheck could not be installed (`pip` install was blocked in this session), so every package is `[ASSUMED]` per protocol. All are first-party Laravel packages or long-standing libraries confirmed on Packagist with the expected vendor and source repo. Planner: one `checkpoint:human-verify` before the first `composer require`.

| Package | Registry | Age | Downloads | Source Repo | slopcheck | Disposition |
|---------|----------|-----|-----------|-------------|-----------|-------------|
| laravel/fortify | Packagist | years | very high | github.com/laravel/fortify | not run | Approved pending human check [ASSUMED] |
| laravel/socialite | Packagist | years | very high | github.com/laravel/socialite | not run | Approved pending human check [ASSUMED] |
| laravel/passkeys (transitive) | Packagist | months | n/a | github.com/laravel/passkeys-server | not run | Transitive of Fortify; pulled by composer [ASSUMED] |
| pragmarx/google2fa, bacon/bacon-qr-code (transitive) | Packagist | years | high | github.com/antonioribeiro/google2fa, github.com/Bacon/BaconQrCode | not run | Transitive of Fortify [ASSUMED] |
| vitest-axe | npm | unknown | unknown | unknown | not run | Verify before adding [ASSUMED] |

**Packages removed:** none. **Flagged [SUS]:** none (tool unavailable). Composer scripts: check `composer.json` of fortify/socialite for `scripts` that run on install (they are plain package-discovery only in the official repos, `[ASSUMED]`).

## Architecture Patterns

### System Architecture Diagram

```
Browser --HTTPS--> nginx (real_ip = CF-Connecting-IP) --> php-fpm Laravel
   |                                                          |
   |  web group: SetLocale > StartSession > AuthenticateSession > HandleInertia > NoStore
   |                                                          |
   +--> /login, /logout, /forgot-password, /reset-password  (Fortify controllers, Inertia views)
   +--> /invite/{token}  show / accept (password)  --------> InviteService (tx + row lock) --> users, module_permissions
   +--> /auth/google/redirect (+invite token in session) --> Google --> /auth/google/callback
   |        callback: email_verified? -> invite flow | existing user by google_id | link by verified email | deny
   +--> app routes (todo/wishlist/finance/health later)
   |        every route carries  access:<rule>  (public | authenticated | admin | module,level)
   |        Middleware RequireAccess: unauth -> login redirect; denied -> 403 Inertia page
   |                                  audited module + allowed -> AuditLog::record(read|write) BEFORE controller (fail closed)
   |        Global EnsureRouteDeclaresAccess: route has no access rule -> 403 (deny by default)
   +--> /settings/members, /settings/audit (access:admin) -> PermissionService, SessionRevoker, AuditQuery

AuditLog::record --INSERT only (fh_app)--> audit_logs  (REVOKE UPDATE/DELETE/TRUNCATE + BEFORE UPDATE trigger)
Host cron (deploy) --> docker compose run migrate php artisan audit:prune  (owner role, rows > 2y)

Invite/reset mail --queue--> jobs table --> worker --SMTP (465 implicit TLS)--> Stalwart
```

### Recommended Project Structure
```
app/
├── Access/            # Module, AccessLevel, Preset enums; AccessGate (requireAccess); AccessRule parser
├── Audit/             # AuditEvent enum, AuditLog service (allowlisted metadata), AuditQuery
├── Auth/              # InviteService, GoogleLoginService, SessionRevoker, UserAgentLabel
├── Console/Commands/  # CreateAdmin (family:create-admin), AuditPrune (audit:prune)
├── Http/Controllers/  # Auth/ (Login view, Invite, Google), Settings/ (Members, Permissions, Sessions, Audit)
├── Http/Middleware/   # RequireAccess (alias `access`), EnsureRouteDeclaresAccess, ThrottleAuthRoutes, NoReferrer
├── Models/            # User, Invitation, ModulePermission, AuditLog (no update/delete methods)
├── Notifications/     # InviteNotification, ResetPasswordNotification (ShouldQueue)
├── Providers/FortifyServiceProvider.php
resources/js/Pages/{Auth,Settings,Errors}/   # per UI-SPEC
resources/js/Layouts/AuthLayout.vue
tests/Feature/Access/EndpointMatrixTest.php, tests/Feature/Auth/*, tests/Feature/Audit/*
tests/Support/EndpointMatrix.php             # explicit expected-status table
```

### Pattern 1: Fortify headless with Inertia, no registration
**What:** enable only `resetPasswords`; register views as Inertia renders; bind custom responses.
```php
// config/fortify.php
'features' => [Features::resetPasswords()],   // no registration, emailVerification, 2FA, passkeys
'lowercase_usernames' => true,
'limiters' => ['login' => 'login'],            // Fortify then applies throttle:login to POST /login
'home' => '/',

// FortifyServiceProvider::boot()
Fortify::loginView(fn () => Inertia::render('Auth/Login'));
Fortify::requestPasswordResetLinkView(fn () => Inertia::render('Auth/ForgotPassword'));
Fortify::resetPasswordView(fn (Request $r) => Inertia::render('Auth/ResetPassword', ['token' => $r->route('token'), 'email' => $r->query('email')]));
Fortify::authenticateUsing(function (Request $request) {
    $user = User::where('email', Str::lower($request->email))->first();
    // Equalise timing for unknown users [ASSUMED: Laravel skips hashing when user missing]
    $hash = $user?->password ?? '$2y$12$'.str_repeat('.', 53);   // any valid dummy bcrypt string, generated once
    return (Hash::check($request->password, $hash) && $user) ? $user : null;
});
// Source for features/limiters/authenticateUsing/views: https://laravel.com/docs/13.x/fortify [CITED]
```
Also bind `Laravel\Fortify\Contracts\LogoutResponse` to redirect to `/login` with flash `status=signed_out`, and `Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse` (see Pattern 4).
Verify `GET/POST /register` returns 404 (test).

### Pattern 2: Policy layer, deny by default
**What:** one middleware alias with declarative rules plus a fail-closed global guard.
```php
enum Module: string { case Todo='todo'; case Wishlist='wishlist'; case Finance='finance'; case Health='health';
    public function audited(): bool { return in_array($this, [self::Finance, self::Health], true); } }
enum AccessLevel: int { case None=0; case View=1; case Edit=2; }

final class AccessGate {                       // requireAccess(user, module, level)
    public function level(User $u, Module $m): AccessLevel { /* from request-scoped map of module_permissions; missing row = None */ }
    public function allows(?User $u, Module $m, AccessLevel $need): bool { return $u && $this->level($u,$m)->value >= $need->value; }
    public function require(?User $u, Module $m, AccessLevel $need): void { abort_unless($this->allows($u,$m,$need), 403); }
}
// Routes (every route, including Fortify's via config('fortify.middleware') where possible):
Route::get('/todo', ...)->middleware('access:module,todo,view')->name('todo.index');
Route::get('/settings/members', ...)->middleware('access:admin')->name('settings.members');
Route::get('/_style', ...)->middleware('access:public')->name('style');
```
- `RequireAccess` parses rule: `public` (pass), `authenticated` (auth only), `admin` (`is_admin`), `module,<m>,<level>`. Unauthenticated + non-public -> redirect to `/login`; authenticated but denied -> 403 rendered as Inertia `Errors/Forbidden` inside AppShell.
- `EnsureRouteDeclaresAccess` is appended to the `web` group: if `$request->route()` has no `access:*` middleware, respond 403 and log. Fortify routes and any vendor routes: apply `access:public` for guest-facing ones via a route-name map in this middleware (names `login`, `logout`(authenticated), `password.request`, `password.email`, `password.reset`, `password.update`) because Fortify route middleware is config-global. `[ASSUMED: Fortify routes carry names login/logout/password.*; confirm with route:list]`.
- **Do not** use `Gate::before` to let admins pass: admins get settings only; health/finance default none even for admins (locked decision).
- No cross-request caching of permissions (a revoke must apply on the next request); cache only per request in the scoped `AccessGate` instance (bind with `scoped`).
- Shared Inertia prop `auth: {user:{id,name,email,locale}, access:{todo,wishlist,finance,health: 'none|view|edit'}, isAdmin}` drives nav hiding (`MODULES` in `resources/js/Shell/modules.js` keys are `tasks/wishlist/finances/health`: map to `todo/wishlist/finance/health` in one place).

### Pattern 3: Role-by-endpoint matrix test (AUTH-07)
**What:** an explicit table, independent of the middleware, that must cover every route.
```php
// tests/Support/EndpointMatrix.php: route name => [method, params, expectations per role]
// roles: guest, admin(no sensitive), parent, child, grandparent, guestPreset, noGrants
'settings.members' => ['GET', [], ['guest'=>302,'admin'=>200,'parent'=>403,'child'=>403,'grandparent'=>403,'guestPreset'=>403,'noGrants'=>403]],

it('registers every route in the matrix', function () {
    $missing = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($r) => $r->getName() ?? 'UNNAMED:'.$r->uri())
        ->reject(fn ($n) => isset(EndpointMatrix::all()[$n]) || in_array($n, EndpointMatrix::ignored(), true));
    expect($missing->all())->toBeEmpty();   // new route without matrix row fails CI
});
it('enforces the matrix', function (string $role) { /* actingAs(roleUser), call each route, assertStatus */ })->with(roles());
it('declares an access rule on every route', ...);   // route middleware contains 'access:'
```
Ignored list is tiny and explicit (e.g. Laravel `up`, storage). Every route must be named (test fails on unnamed). Data users built by factory states using presets, so the preset definitions are covered too. Add a "mutation" test: remove a grant -> same route flips to 403 (proves matrix is wired to real data).

### Pattern 4: Password reset without enumeration
`PasswordResetLinkController::store` returns `Success...Response` only for `RESET_LINK_SENT` and `Failed...Response` otherwise (unknown email, throttled). Rebind the failed contract to return the same response as success:
```php
$this->app->singleton(FailedPasswordResetLinkRequestResponse::class, fn () => new class implements FailedPasswordResetLinkRequestResponse {
    public function toResponse($request) { return back()->with('status', 'reset-link-sent'); }   // same as success
});
```
Source: `Laravel\Fortify\Http\Controllers\PasswordResetLinkController` and `Contracts\FailedPasswordResetLinkRequestResponse` [VERIFIED: github laravel/fortify 1.x]. The framework `PasswordBroker` already runs a `Timebox` (200 ms) [VERIFIED: laravel/framework 13.x source]; make the notification `ShouldQueue` (override `User::sendPasswordResetNotification`) so SMTP latency never leaks. Token expiry in `config/auth.php` `passwords.users.expire` = 60 (matches UI copy), `throttle` 60 s. On successful reset (override `ResetUserPassword` action or listen to `PasswordReset`): delete the user's `sessions` rows and rotate `remember_token` (UI-SPEC: "signed out on other devices"), audit `password_reset`.

### Pattern 5: Sessions and "revoke means revoke"
```php
// AppServiceProvider::boot  (30 days remember; default is 576000 min = 400 days) [VERIFIED: SessionGuard.php]
Auth::guard('web')->setRememberDuration(43200);   // [ASSUMED: guard resolved lazily here; if not, set in a `Auth::resolved` callback]

final class SessionRevoker {
    public function revokeAll(User $u): void { DB::transaction(function () use ($u) {
        DB::table('sessions')->where('user_id', $u->id)->delete();
        $u->forceFill(['remember_token' => Str::random(60)])->save();      // kills the remember-me cookie
    }); }
    public function revokeOne(User $u, string $sessionId): void { /* delete row where id AND user_id; rotate remember_token too */ }
}
```
- `SESSION_EXPIRE_ON_CLOSE=true` gives "browser session" when not ticking "stay signed in"; remember cookie restores for the 30-day case. Idle `SESSION_LIFETIME=120` min is unchanged.
- Add `$middleware->authenticateSessions()` (`AuthenticateSession`) so a password change invalidates other sessions that hold the old password hash.
- Revoked user's next request: session row gone -> guest -> redirect to `/login` with flash `session_ended` (UI-SPEC). For Inertia XHR make sure the response is 409/302 to login, not a JSON error modal. Also map 419 (TokenMismatch) the same way.
- `sessions.user_id` is written by the handler only for authenticated requests [VERIFIED: DatabaseSessionHandler::addUserInformation]. List query: `where user_id`, order by `last_activity`; "This device" = `session()->getId()`. "Stays signed in" tag: set `session(['stay'=>true])` in a `Login` event listener when `$event->remember` and read it by `unserialize(base64_decode($payload), ['allowed_classes'=>false])`; `[ASSUMED]` that remember-restored logins fire `Login` with `remember=true`, and if `SESSION_ENCRYPT` is turned on the payload is not readable (currently false). If this proves fiddly, drop the tag (cosmetic).
- Never expose `payload` to the frontend; select only `id, ip_address, user_agent, last_activity`.

### Pattern 6: Google OAuth link rules (AUTH-03)
`Socialite::driver('google')->redirect()` / `->user()`; the provider maps `id = sub`, and the raw array contains `email_verified` (also `verified_email`) [VERIFIED: GoogleProvider.php 5.x]. Stateful flow (session state) is fine because the callback is a top-level GET (SameSite=Lax cookie is sent).
```
GET /auth/google/redirect?invite=<token>   (token optional; validated, then stored in session('invite_id'), NOT forwarded to Google)
GET /auth/google/callback:
  g = Socialite::driver('google')->user();   fail/exception -> login page, google_failed
  if !g->user['email_verified']              -> google_failed (never trust unverified)
  email = lower(g->email); sub = g->id
  1. invite in session  -> invitation must be valid and invitation.email == email else google_mismatch;
                           tx+lock: create user(google_id=sub, email_verified_at=now, random password hash), apply preset, mark accepted, login
  2. user where google_id = sub             -> login
  3. user where lower(email)=email, google_id null -> set google_id=sub (link), audit, login
  4. user where lower(email)=email and google_id != sub -> deny (google_failed), audit
  5. otherwise                              -> google_not_invited (no account creation)
```
- Always `Auth::login($user, remember: false)` then `$request->session()->regenerate()` (session fixation); remember flag for Google follows a checkbox only if the UI offers it (UI-SPEC offers none: do not remember).
- Decision recommended: sign-in from the login page never consumes a pending invite (requires the invite link); this keeps one acceptance path. See Open Question 2.
- `users.password` is NOT NULL in the Phase 1 migration: store `Hash::make(Str::random(64))` for Google-created users; they can later use reset-password to set a real one.
- Config: `config/services.php` `google => [client_id, client_secret, redirect => env('APP_URL').'/auth/google/callback']`; separate Google OAuth client (or redirect URIs) per environment; secrets only in `app.env`/`secrets`.
- Do not persist the Google access/refresh token (CLAUDE.md "never store OAuth tokens"): use only identity, drop the token.

### Pattern 7: Invites (AUTH-01)
- Table `invitations`: `id`, `email` (lowercase), `token_hash` char(64) unique (sha256 of a `Str::random(40)` token), `preset` string, `locale` string(5), `invited_by` fk users, `expires_at`, `accepted_at`, `revoked_at`, `mail_failed_at` nullable, timestamps. Partial unique index (raw SQL): `CREATE UNIQUE INDEX invitations_one_open ON invitations (lower(email)) WHERE accepted_at IS NULL AND revoked_at IS NULL;`. Reject if a user with that email exists (UI error "already belongs to a member").
- State is derived (valid/expired/used/revoked) in one `Invitation::state()` method; UI shows the same generic copy for unknown vs revoked.
- Accept (password) and Google accept both: `DB::transaction` + `lockForUpdate()` on the invitation, re-check state, create user, write grants from preset, set `accepted_at`, audit `invite_accepted`, `Auth::login`, `session()->regenerate()`. Prevents double-spend races.
- Lookup is by `hash('sha256', $token)`: no timing issue as the lookup is indexed equality on a hash; never store the plain token. Consequence: "Copy link" and "Resend" on an existing row cannot recover the old link, so they **rotate** the token (new hash, new 7-day expiry) and the previously emailed link stops working. Flag in UI copy or accept (Open Question 3).
- Pages that carry secrets in the URL (`/invite/{token}`, `/reset-password/{token}`) get `Referrer-Policy: no-referrer` and load no third-party assets; throttle `throttle:10,1`.
- Password rules: `Password::min(12)` in `Password::defaults()`; `->uncompromised()` calls HIBP over k-anonymity from the server (ASVS V2.1.7); behaviour on HIBP outage `[ASSUMED]` fail-open; keep it only if staging confirms it does not block registration offline.

### Pattern 8: Append-only audit log in Postgres (AUTH-08)
Migration (runs as `fh_owner` through the `migrate` service):
```php
Schema::create('audit_logs', function (Blueprint $t) {
    $t->id();
    $t->timestampTz('occurred_at')->useCurrent();
    $t->unsignedBigInteger('actor_id')->nullable();     // NO foreign key: FK actions would UPDATE and trip the trigger, and history must outlive users
    $t->string('actor_label', 120)->nullable();         // name snapshot
    $t->string('event', 40);
    $t->string('module', 20)->nullable();
    $t->unsignedBigInteger('target_user_id')->nullable();
    $t->string('subject_type', 40)->nullable(); $t->unsignedBigInteger('subject_id')->nullable();
    $t->ipAddress('ip')->nullable();                    // [ASSUMED] pgsql grammar maps to inet; varchar(45) also fine
    $t->jsonb('meta')->nullable();                      // allowlisted keys only, never content
    $t->index(['occurred_at']); $t->index(['actor_id','occurred_at']); $t->index(['event','occurred_at']);
});
DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs FROM fh_app');
DB::statement("CREATE FUNCTION audit_logs_no_update() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'audit_logs is append-only'; END \$\$");
DB::statement('CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW EXECUTE FUNCTION audit_logs_no_update()');
DB::statement('REVOKE ALL ON SEQUENCE audit_logs_id_seq FROM fh_app; GRANT USAGE ON SEQUENCE audit_logs_id_seq TO fh_app');  // USAGE needed for INSERT; default privileges already grant, keep explicit
```
- Why this works: Phase 1 `01-roles.sh` sets default privileges for tables created by `fh_owner` to grant S/I/U/D to `fh_app`; an explicit later `REVOKE` removes UPDATE/DELETE (TRUNCATE was never granted, repeat is harmless) [VERIFIED: docker/postgres/init/01-roles.sh]. The role `fh_app` and its default privileges exist only on DBs the init script created, so the migration must tolerate a missing role in other environments: wrap in `DO $$ BEGIN IF EXISTS (SELECT FROM pg_roles WHERE rolname='fh_app') THEN ... END IF; END $$`.
- `AuditLog::record(AuditEvent, ?User, array $attrs)` validates `meta` keys against an allowlist per event (`module`, `from`, `to`, `target_name`, `device`), throws on unknown keys or non-scalar values so content cannot leak. The model has no `update`/`delete` and `$timestamps=false`.
- Auto-logging: `RequireAccess` records `<module>_read` (GET/HEAD) or `<module>_write` (others) after the access check passes, before the controller runs, **inside the same request; if the INSERT fails, abort 500** (fail closed, ASVS V7). Also record denied access to audited modules (`access_denied`). Controllers in Phases 5/7 may add `subject_*` via `AuditLog::annotate()` for the current request (insert once, at terminate? simpler: record in middleware, controllers add separate rows with subject).
- Other events: `login_success`, `login_failed` (from `Illuminate\Auth\Events\Failed`; actor only if the email matches a user, never store the typed email or password), `lockout`, `invite_created|resent|revoked|accepted`, `permission_changed` (one row per changed module with `from`/`to`), `session_revoked`, `password_reset`, `admin_created`, `google_linked`.
- Retention 2 years: `audit:prune` command deletes `occurred_at < now() - interval '2 years'` and must run with the owner role: add cron (see `ops/cron`) `docker compose --profile migrate run --rm migrate php artisan audit:prune` monthly; refuses to run when connected as `fh_app` (check `current_user`).
- Read page: `AuditQuery` builds filters (member, event group, date range) with bound parameters, paginate(25), `withQueryString()`; event-type filter maps UI groups to event lists. No export, no row actions.
- Test pitfall: tests run as `fh_owner` (phpunit.xml) so an UPDATE attempt as app role is impossible there. Assert `has_table_privilege('fh_app','audit_logs','UPDATE')` is false for UPDATE/DELETE/TRUNCATE (skip if role absent) and that an UPDATE as owner raises (trigger).

### Pattern 9: Mail via Stalwart on the queue
- Notifications/Mailables implement `ShouldQueue`; worker container already runs `queue:work --tries=3`. `QUEUE_CONNECTION=database`.
- SMTP settings in `app.env`: `MAIL_MAILER=smtp`, host = the mail stack's public name (`mail.vostack.dev`, cert SAN covers it) with `MAIL_PORT=465`, `MAIL_SCHEME=smtps` (implicit TLS, avoids silent STARTTLS downgrade), a dedicated mailbox (e.g. `family-hub@vostack.dev`) created through Stalwart `mail-provision.sh` and its password in `app.env`/secret. `[ASSUMED]` the app containers can reach the VPS's own public mail ports (hairpin); alternative is attaching the worker to the `mail` network, which touches the other stack's network and should be an explicit owner decision. A human must create the mailbox and supply the secret (cannot be automated by the plan).
- Staging must not mail real people: staging uses an allowlist or `MAIL_MAILER=log`; synthetic users are `@example.test`.
- Local/test: `MAIL_MAILER=array` in phpunit.xml; assert with `Mail::fake()`/`Notification::fake()`.
- Invite dialog "could not send email" copy requires immediate failure knowledge, which a queued send cannot give. Recommended: queue it, store `mail_failed_at` via the job `failed()` hook, show a warn tag on the invitation row (the link stays copyable). See Open Question 1.

### Pattern 10: `family:create-admin`
`php artisan family:create-admin {email} {--name=}`: prompt the password with `$this->secret()` (never an argument/history), validate with the same `Password::defaults()`, refuse if any `is_admin` user already exists (message), set `email_verified_at`, `is_admin=true`, grants all `none`, audit `admin_created` (actor null). Runs under `fh_app` (DML only): `docker compose exec app php artisan family:create-admin ...`. Update Phase 1 `StagingSeeder` so synthetic users get preset grants (and one synthetic admin), otherwise staging has users with no access.

### Anti-Patterns to Avoid
- **Admin implies everything** (`Gate::before`, `is_admin ? true`): violates "health and finance default none for admins".
- **Hiding UI as access control** or a controller that forgets the check: the fail-closed global middleware plus matrix exist to make forgetting impossible.
- **Deleting only `sessions` rows to log someone out**: remember cookie resurrects the session.
- **Foreign keys from `audit_logs`** with `ON DELETE SET NULL/CASCADE`: runs UPDATE/DELETE under owner semantics and trips the trigger or erases history.
- **Storing the plain invite/reset token**, or logging it (nginx access log already contains the path; keep tokens single-use and short-lived).
- **Revealing existence**: distinct messages for unknown email at login, reset, or Google ("not invited" copy is allowed by UI-SPEC because Google already proved the mailbox).
- **Caching permissions across requests**, or caching anything authenticated in the service worker (unchanged Phase 1 rule).
- **Registering Fortify `registration` or `emailVerification`** features.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Password hashing/rehash | custom hashing | `Hash`, `'password' => 'hashed'` cast | already in `User` |
| Login throttling store | counters in DB | `RateLimiter::for('login')` + cache(database) store | atomic, expiry, Retry-After |
| Reset tokens | own token table | `Password` broker (table `password_reset_tokens` exists) | hashing, expiry, throttle, timebox built in |
| OAuth state/CSRF for Google | manual `state` | Socialite stateful driver | handled |
| Session storage | own session table | `SESSION_DRIVER=database` | `user_id/ip/UA` columns already written |
| Mail transport | raw SMTP code | Laravel Mail (Symfony Mailer), queued Notification | retries, TLS |
| CSRF | own tokens | Inertia + Laravel XSRF | already wired |
| Audit/permissions packages | spatie/activitylog, laravel-permission | the small custom layer described | content leakage, mutability, scope |

**Key insight:** the only things to build are the policy registry, the audit insert-only discipline, and invite state; everything cryptographic or protocol-level comes from the framework or Fortify/Socialite.

## Runtime State Inventory

Not a rename/refactor phase, but it changes existing runtime state:

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | Phase 1 `users` rows (staging seeder `@example.test`), `sessions`, `password_reset_tokens`, `cache`, `jobs` tables exist | Migration adds `users.is_admin`, `locale`, `google_id`, lower(email) unique index; data migration: none in prod (no users yet), staging reseed must create grants |
| Live service config | Google Cloud OAuth client (redirect URIs per env), Stalwart mailbox for sending | Manual: create OAuth client + mailbox; secrets into server `app.env` |
| OS-registered state | host cron for backups/certs | Add monthly `audit:prune` cron entry in `ops/cron` |
| Secrets/env vars | none exist for Google/SMTP | add `GOOGLE_CLIENT_ID/SECRET`, `MAIL_*` to `ops/env/app.env.example` and deploy docs; `scripts/check-secrets.sh` may need the new names |
| Build artifacts | Composer vendor in image | rebuild images after `composer require` |

## Common Pitfalls

### Pitfall 1: Remember-me survives revoke and lasts 400 days
**What goes wrong:** admin "revokes" but the device logs back in, and "30 days" is actually 400.
**Why:** `SessionGuard::$rememberDuration = 576000` min [VERIFIED]; remember cookie is independent of session rows.
**Avoid:** `setRememberDuration(43200)`; revoke rotates `remember_token`. **Detect:** feature test: login with remember, revoke, drop the session cookie only, request -> must be guest.

### Pitfall 2: Fortify throttle key and 429 UX
**What:** default throttle is per email+IP (5/min), a distributed attacker is not slowed per account, and Inertia shows a raw 429 modal.
**Avoid:** named limiter returning two limits (`perMinute(5)->by(email|ip)`, `perMinutes(15,10)->by(email)`), plus a `->response()` callback or exception rendering that redirects back with a `throttle` error carrying `minutes` (UI plural copy). Real client IP is correct because nginx rewrites `X-Forwarded-For` from `CF-Connecting-IP` and Laravel trusts only private ranges [VERIFIED: nginx snippet + bootstrap/app.php]. Forgot-password and invite/accept routes need their own limiter: Fortify routes cannot take per-route middleware from config, so use a small global `ThrottleAuthRoutes` middleware keyed by route name (runtime, survives `route:cache`; mutating routes after boot would be lost in cached routes).

### Pitfall 3: Enumeration through side channels
Reset: success/failed identical (Pattern 4), queued mail, same status text. Login: same `auth.failed` error key, dummy hash. Invite page: same copy for unknown/revoked. Registration endpoints absent (404, not 403). Google: "not invited" reveals only that the Google account lacks access.

### Pitfall 4: Test DB runs as owner
Tests (`fh_owner`) cannot prove the app role is restricted; use `has_table_privilege`. Also `RefreshDatabase` + the `audit_logs` trigger: do not UPDATE in tests, only assert it raises inside a savepoint-safe `expect(fn)->toThrow(QueryException::class)` (transaction becomes aborted afterwards in Postgres, so isolate the throwing statement in its own test).

### Pitfall 5: Existing user model and migrations
`Fillable` attribute lists only `name,email,password`; do **not** add `is_admin`/`google_id` to fillable (mass-assignment privilege escalation); set via `forceFill` in services. `users.email` unique is case-sensitive: add a unique index on `lower(email)` and lowercase on write (Fortify `lowercase_usernames`).

### Pitfall 6: Inertia error and redirect handling
403/419/401/404 need Inertia error pages inside AppShell (use `withExceptions` `respond`), 419 maps to `/login` with `session_ended`; non-GET redirects after Inertia PUT/PATCH/DELETE must be 303 (the Inertia middleware does this when you `return redirect()`; do not return 302 from custom `Response` objects).

### Pitfall 7: Audit insert failure policy
If audit INSERT throws on a health/finance read, the request must fail; a "log and continue" `try/catch` silently drops evidence. Conversely do not audit static assets (nginx serves them) or Inertia partial-reload noise (one row per request is acceptable).

### Pitfall 8: Matrix test becomes tautological
If expected statuses are derived from the middleware declaration, a wrong middleware passes. Keep expectations hand-written in `EndpointMatrix`, one reviewed place.

### Pitfall 9: Google subtleties
Gmail dot/plus aliasing and Google Workspace accounts: compare lower-cased full email as returned, link by `sub` after first link; refuse when `email_verified` false; Google `sub` is the stable identity, email can change (a later email change at Google must not re-link a different account: step 2 matches `google_id` first).

### Pitfall 10: Session and caching headers
Keep `NoStoreDynamicResponses`; Phase 1 SW must not cache `/login`, `/invite/*`, `/settings/*` (precache is assets only, `[VERIFIED: Phase 1 research]`). Re-run `scripts/check-sw-precache.mjs` in CI.

## Code Examples

### Named login limiter
```php
// AppServiceProvider::boot  [CITED: laravel.com/docs/13.x/fortify#authentication-throttling]
RateLimiter::for('login', function (Request $request) {
    $email = Str::lower((string) $request->input('email'));
    return [
        Limit::perMinute(5)->by($email.'|'.$request->ip()),
        Limit::perMinutes(15, 10)->by('email:'.$email),   // lockout across IPs
        Limit::perMinute(30)->by('ip:'.$request->ip()),
    ];
});
```
### Permission save (single path, audited, atomic)
```php
DB::transaction(function () use ($member, $levels, $actor) {
    foreach (Module::cases() as $m) {
        $new = AccessLevel::from($levels[$m->value]); $old = $access->level($member, $m);
        if ($new === $old) continue;
        ModulePermission::updateOrCreate(['user_id'=>$member->id,'module'=>$m->value], ['level'=>$new->value]);
        AuditLog::record(AuditEvent::PermissionChanged, $actor, ['module'=>$m->value,'from'=>$old->name,'to'=>$new->name], target: $member);
    }
});
```
Table `module_permissions`: `user_id` fk cascade, `module` string with CHECK in (todo,wishlist,finance,health), `level` smallint CHECK 0..2, unique(user_id, module). Validate request with `Rule::enum(AccessLevel)` over int-backed values.

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Fortify only password+2FA | Fortify 1.4x adds passkeys (hard dependency `laravel/passkeys`) | 2026 | extra transitive packages now; Phase 6 can use `Features::passkeys()` |
| Pest 5 assumed in Phase 1 research | repo has Pest ^4.7 | actual | use Pest 4 APIs |
| `Password::uncompromised()` optional | ASVS V2.1.7 expects breached-password check | n/a | recommended, with offline-failure check |

**Deprecated/outdated:** none relevant; Laravel's `registration` Fortify feature must stay off.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `php artisan fortify:install` exists in 1.42 and publishes provider/actions/config (and possibly 2FA migrations to remove) | Standard Stack | Planner step differs; use `vendor:publish --provider` |
| A2 | Fortify route names are `login`, `logout`, `password.request|email|reset|update` | Pattern 2 | Route-name map in guard/matrix wrong; confirm with `route:list` |
| A3 | `Auth::guard('web')->setRememberDuration()` can be called in a provider `boot` | Pattern 5 | Cookie stays 400 days; test that cookie expiry is ~30 days |
| A4 | Remember-restored logins fire `Login` with `remember=true` | Pattern 5 | "Stays signed in" tag wrong; drop tag |
| A5 | Laravel skips hashing when the user is missing (timing leak), hence dummy hash | Pattern 1 | Harmless if wrong |
| A6 | pgsql grammar maps `ipAddress()` to `inet` | Pattern 8 | Cosmetic |
| A7 | `Password::uncompromised()` fails open when HIBP is unreachable | Pattern 7 | Signup blocked offline; test on staging |
| A8 | App containers can reach `mail.vostack.dev:465` on the same VPS | Pattern 9 | Mail does not send; need `mail` network attach or host-gateway |
| A9 | `vitest-axe` is the right package and safe | Supporting | Swap a11y tooling |
| A10 | Stalwart accepts authenticated submission on 465 for a dedicated mailbox | Pattern 9 | Use 587 + `smtp` scheme |
| A11 | CF-Connecting-IP in nginx gives the true client IP for throttle keys and audit IPs | Pitfall 2 | Throttle keys collapse to Cloudflare IPs |
| A12 | Fortify's published/`vendor` composer scripts have no unusual install hooks | Package audit | Supply-chain risk, check before install |

## Open Questions

1. **Invite email: queue vs immediate failure feedback**
   - Known: owner wants mail on the queue; UI-SPEC shows a "could not send, copy the link" alert.
   - Unclear: a queued send cannot report failure to the dialog.
   - Recommendation: queue + `mail_failed_at` flag and warn tag on the row; the dialog always shows the copyable link. Alternative (needs owner OK): send invites synchronously with a 10 s timeout and try/catch, queue only reset mails.
2. **Can Google sign-in from the login page consume a pending invite?** Recommendation: no, invite link required (single acceptance path); show `google_not_invited`.
3. **Copy link/Resend rotates the token** (hash-only storage). Recommendation: accept, and say "previous link no longer works" in a tooltip. Alternative: store the token encrypted (`Crypt`) so the same link can be re-shown.
4. **Staging mail policy**: real SMTP to invitees or `log` only? Recommendation: `log` on staging by default, real send only with a recipient allowlist.
5. **Audit of "denied" attempts** on sensitive modules is added beyond the spec; confirm acceptable volume (low at family scale).

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP | local tooling | yes | 8.3.6 | tests/DB run in Docker |
| Postgres test DB `family_hub_test` | Pest feature tests (host `postgres`) | via docker compose only | 18 | none: run `docker compose` test service |
| Docker Compose | tests, migrate, audit:prune | assumed (Phase 1 scripts) | — | — |
| Stalwart SMTP (VPS) | invite/reset mail | server-side only (not probed this session) | 1.0.1 per global notes | `MAIL_MAILER=log` |
| Google OAuth client | AUTH-03 | no (manual console step) | — | password-only until configured; feature tests fake Socialite |
| slopcheck / pip | package audit | no | — | `[ASSUMED]` tags |

**Missing with no fallback:** none blocking code; OAuth client and SMTP mailbox are human tasks (checkpoint:human-action) before staging verification.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest ^4.7 (PHPUnit 12) + Vitest (existing); `vitest-axe` to add |
| Config file | `phpunit.xml` (Postgres `family_hub_test`, owner role, `SESSION_DRIVER=array`), `vitest.config.js` |
| Quick run command | `docker compose run --rm app php artisan test --filter=<Name>` `[ASSUMED service name]`; `npx vitest run <file>` |
| Full suite command | `composer test` (in container) and `npm run test` (confirm script name in package.json) |

Feature tests use `SESSION_DRIVER=array`, so session list/revoke tests must set `config(['session.driver'=>'database'])` for those tests or use a dedicated `phpunit` override (`withSession` + DB handler).

### Phase Requirements -> Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| AUTH-01 | `/register` 404; invite create/revoke/resend/expiry/single-use/race; admin command refuses 2nd admin; mail queued | feature | `php artisan test --filter=Invite` | Wave 0 |
| AUTH-02 | login ok/fail generic message; lockout after N; logout; reset flow; no enumeration | feature | `--filter=Login` / `Password` | Wave 0 |
| AUTH-03 | Google: verified email required; mismatch; link by sub; no self-signup; token not stored (Socialite faked) | feature | `--filter=Google` | Wave 0 |
| AUTH-04 | remember 30 d cookie expiry; revoke deletes rows + rotates remember_token; revoked session -> guest | feature | `--filter=Session` | Wave 0 |
| AUTH-05 | save permissions atomically, audited, defaults none, admin has no health/finance | feature | `--filter=Permission` | Wave 0 |
| AUTH-06 | preset grants exactly as locked decisions; invite preset applied at accept | unit+feature | `--filter=Preset` | Wave 0 |
| AUTH-07 | matrix covers every named route; per-role statuses; unnamed route fails; global guard 403 on undeclared route | feature | `php artisan test tests/Feature/Access` | Wave 0 |
| AUTH-08 | health/finance read/write rows written; denied logged; meta allowlist; `has_table_privilege` false; UPDATE trigger raises; prune only >2y; filter page admin-only | feature | `--filter=Audit` | Wave 0 |
| UI | pages render in 3 locales with axe clean; 403 state; lockout alert | vitest | `npx vitest run resources/js` | Wave 0 |

### Sampling Rate
- **Per task commit:** targeted `--filter` Pest run (< 30 s) plus Pint.
- **Per wave merge:** full Pest + Vitest.
- **Phase gate:** full suite green incl. matrix test, `scripts/check-sw-precache.mjs`, before `/gsd-verify-work`.

### Wave 0 Gaps
- [ ] `tests/Support/EndpointMatrix.php` and role factories (`UserFactory` states per preset, admin)
- [ ] `tests/Feature/Access/EndpointMatrixTest.php` (and stub rows for Phase 1 routes: `home`, `heartbeat.store`, `locale.update`, `style`)
- [ ] Feature test files per requirement above; Socialite fake helper
- [ ] `vitest-axe` install + a11y test helper (after legitimacy check)
- [ ] `phpunit.xml` mail/queue settings (`MAIL_MAILER=array`, `QUEUE_CONNECTION=sync`)
- [ ] Manual-only: Google consent screen on staging, real SMTP delivery, visual checks (human_verify_mode is end-of-phase)

## Security Domain

### Applicable ASVS Categories (Level 1)

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes | Fortify; 12-char minimum, `uncompromised`, generic errors, throttling + lockout, no default accounts (`family:create-admin`), reset tokens hashed/expiring 60 min, single-use invites |
| V3 Session Management | yes | DB sessions, `regenerate()` on login, `Secure; HttpOnly; SameSite=Lax` cookies (`SESSION_SECURE_COOKIE=true` in `app.env`), logout and admin revoke invalidate server-side, remember token rotation, `AuthenticateSession` |
| V4 Access Control | yes | `access` middleware fail-closed, matrix test, server-side only, least privilege (health/finance none by default, admin not implicit) |
| V5 Input Validation | yes | Form Requests, `Rule::enum`, bound parameters in audit filters (no raw interpolation), email normalisation |
| V6 Cryptography | yes | bcrypt/Argon via `Hash`, `Str::random` + sha256 for tokens, `Crypt` never hand-rolled |
| V7 Logging | yes | audit log content-free, fail-closed, append-only DB protections; do not log passwords/tokens/typed emails |
| V8 Data Protection | partial | `no-store` headers; `Referrer-Policy: no-referrer` on token URLs; no secrets in props (`$hidden` password/remember_token) |
| V13/V14 API & Config | partial | CSRF, SameSite; trust proxies only private ranges (existing) |

### Known Threat Patterns for Laravel + Inertia + Fortify

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Credential stuffing / brute force | Spoofing | named limiter by email+IP, per-email lockout, per-IP cap, generic error |
| Account enumeration (login, reset, invite) | Info disclosure | identical responses, queued mail, dummy hash, timebox |
| Session fixation | Spoofing | `session()->regenerate()` after every login path including Google/invite |
| Revoked session still valid via remember cookie | Elevation | rotate `remember_token` on revoke/reset/password change |
| Invite token leakage (logs, Referer) | Info disclosure | hash at rest, single-use, 7 d, `no-referrer`, throttle |
| Invite double-accept race | Tampering | `lockForUpdate` in transaction + partial unique index |
| Google account takeover via unverified email / email change | Spoofing | require `email_verified`, link by `sub`, never auto-create |
| OAuth callback CSRF | Tampering | Socialite state in session |
| Missing authorization on new route | Elevation | fail-closed middleware + matrix test fails CI |
| Mass assignment of `is_admin`/`google_id` | Elevation | not in `$fillable`, `forceFill` in services |
| IDOR on member/session ids in settings | Elevation | `access:admin`; session revoke queries scope by `user_id` and id |
| Audit tampering / deletion | Repudiation | REVOKE UPDATE/DELETE/TRUNCATE for `fh_app`, UPDATE trigger, owner-only prune |
| Audit content leakage | Info disclosure | meta key allowlist, no content columns |
| Open redirect after login | Tampering | use `redirect()->intended()` only (same-host), never `?next=` free URLs |
| Cached authenticated pages | Info disclosure | `no-store` middleware (existing), SW precache only |

## Sources

### Primary (HIGH confidence)
- https://laravel.com/docs/13.x/fortify : features, views, `authenticateUsing`, authentication throttling/limiters config, password reset flow, headless usage
- https://repo.packagist.org/p2/laravel/fortify.json, /laravel/socialite.json, /laravel/passkeys.json, pragmarx/google2fa, bacon/bacon-qr-code : versions, dates, constraints (2026-11-13)
- github.com/laravel/fortify 1.x: `PasswordResetLinkController`, `Contracts/FailedPasswordResetLinkRequestResponse`, `config/fortify.php` (keys `views, prefix, domain, lowercase_usernames, limiters, paths`)
- github.com/laravel/socialite 5.x `GoogleProvider.php` : userinfo endpoint, `sub`->id, `email_verified` raw field
- github.com/laravel/framework 13.x: `SessionGuard` (`$rememberDuration = 576000`, `setRememberDuration`), `DatabaseSessionHandler` (`addUserInformation`), `PasswordBroker` (Timebox)
- Repo files read: `docker/postgres/init/01-roles.sh`, `bootstrap/app.php`, `routes/web.php`, `app/Models/User.php`, `database/migrations/0001_01_01_000000_create_users_table.php`, `phpunit.xml`, `tests/Pest.php`, `compose.server.yaml`, `docker/nginx/snippets/laravel-locations.conf`, `.env.example`, `ops/env/app.env.example`

### Secondary (MEDIUM confidence)
- Phase 1 research (`01-RESEARCH.md`) for deployment, nginx proxy and SW conventions

### Tertiary (LOW confidence)
- Items tagged `[ASSUMED]` above (training knowledge, not re-verified)

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH on versions (registry read), MEDIUM on legitimacy (slopcheck unavailable)
- Architecture: MEDIUM-HIGH: policy/matrix/audit design is firm; Fortify route-name and remember-duration details need a quick spike
- Pitfalls: HIGH for remember-me, enumeration, privilege/trigger; MEDIUM for Google and mail reachability

**Research date:** 2026-11-13
**Valid until:** 2026-12-13 (Fortify/Socialite moving quickly: passkeys dependency at 0.x)
