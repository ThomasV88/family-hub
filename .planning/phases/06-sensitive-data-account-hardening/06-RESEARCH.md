# Phase 6: Sensitive-Data Account Hardening - Research

**Researched:** 2026-11-13
**Domain:** Laravel 13 / Fortify 1.42 headless TOTP, WebAuthn passkeys as second factor, session-based idle re-authentication, admin policy, audit
**Confidence:** HIGH on installed-vendor behaviour (read from `vendor/`), MEDIUM on browser/Google re-auth details (flagged `[ASSUMED]`)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Second factor**
- TOTP app (Fortify's two-factor with recovery codes) and passkeys (WebAuthn via `laravel/passkeys`, already pulled in by Fortify); members may enable either or both in their settings.
- Optional by default. Admin can require a second factor for members who have health or finance access (setting, default off). Enforcement: members under the requirement without a factor are guided to set one up before opening sensitive modules.
- Sign-in: after password (or Google), a challenge page accepts the TOTP code, a recovery code or a passkey. Passkeys are offered as a challenge option; passkey-only first-factor sign-in is out of scope.
- Recovery: 8 one-time recovery codes shown once at setup, regenerate on demand (needs re-auth); admin can reset a member's second factor (audited, that member's sessions revoked).

**Idle re-authentication**
- After 15 minutes without activity in a sensitive module (health, finance), opening sensitive data asks for password, TOTP or passkey ("confirm it's you"); other modules are never interrupted. Only activity inside sensitive modules extends the timer.
- Enforced server-side by a route middleware on all health and finance routes including JSON and file routes; Inertia navigations redirect to a confirm page and return to the original location afterwards. An endpoint-matrix style test proves every sensitive route enforces it (new routes in later phases fail the test if they forget).

**Audit**
- Enable/disable of a factor, failed challenges, recovery code use, admin resets and re-auth confirmations are audited (never secrets or codes).

### Claude's Discretion
Table/class names, UI details using the Phase 01.1 design system and the Phase 2 settings shell, timer storage (session-based), WebAuthn flow details per `laravel/passkeys` docs, test organisation.

### Deferred Ideas (OUT OF SCOPE)
Passkey-only first-factor sign-in, hardware key attestation policies, per-device trust ("remember this device"), SMS/email codes.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| AUTH-09 | User can enable TOTP or passkey as a second factor | Fortify `Features::twoFactorAuthentication` (actions, events, challenge pipeline) + `laravel/passkeys` 0.2.1 actions called from own thin controllers; migrations to re-add; login pipeline and Google branch changes; throttles; audit events (sections Standard Stack, Architecture Patterns 1-4) |
| AUTH-10 | User is asked to re-authenticate before opening medical or finance data after a period of inactivity | Session timestamp gate living in `RequireAccess` for `Module::sensitive()`; confirm page with password / TOTP / recovery / passkey; matrix-driven stale-session test; test seam (Architecture Patterns 5-7, Validation Architecture) |
</phase_requirements>

## Summary

Everything this phase needs is already installed. `composer.lock` has laravel/fortify v1.42.0, laravel/passkeys v0.2.1, web-auth/webauthn-lib 5.3.12, pragmarx/google2fa v9.1.0, bacon/bacon-qr-code v3.1.1 [VERIFIED: composer.lock]. **No new composer or npm package is required.** Passkeys need ~60 lines of browser JS (`navigator.credentials` + base64url) instead of the `@laravel/passkeys` npm client (0.4.1, depends on `@simplewebauthn/browser`) [VERIFIED: npm view]. `laravel/passkeys` 0.3.0 exists on Packagist (2026-11-10) but Fortify 1.42 pins `^0.2.0`, so 0.2.1 stays; do not try to force 0.3 [VERIFIED: repo.packagist.org p2].

The existing Phase 2 shape dictates the design. Three facts from the installed code matter most. (1) Fortify's built-in login pipeline only issues a 2FA challenge when `two_factor_secret` is set, so passkey-only users would silently skip the challenge: bind a custom `RedirectsIfTwoFactorAuthenticatable` that tests "has any second factor". (2) Google sign-in (`GoogleController::callback`) calls `Auth::login` directly and never touches Fortify's pipeline; it must be changed to park `login.id` in the session and redirect to the challenge page. (3) Google-created accounts get a random 64-char password (`InviteService` line 149) so **password cannot be the re-auth method for Google-only members without a factor**; this is the one real design gap in CONTEXT (see Open Questions Q1).

The idle gate should live inside `RequireAccess` (for `Module::Finance` and `Module::Health`), between the allow/deny decision and the audit write. That single choke point makes every present and future `access:module,finance|health,*` route gated by construction, keeps 403 for people without access (no redirect leak), and avoids writing a `finance_read` audit row for a request that is bounced to the confirm page. The CI proof is the existing matrix harness re-run with a stale session for every route found by enumerating route middleware. Enabling the gate breaks ~270 `actingAs` calls in finance tests unless a test seam is added; use the same pattern as the existing `FakeDnsResolver` binding in `tests/TestCase.php` (default "always fresh" double, real implementation opt-in).

**Primary recommendation:** Use Fortify's 2FA actions/events/challenge routes for TOTP, own routes + `laravel/passkeys` actions (never Fortify's `Features::passkeys()`, which also registers passwordless login) for WebAuthn, one session timestamp `sensitive.active_at` slid only by passing the gate, and a new `access` rule path through `RequireAccess` rather than a separate per-route middleware that can be forgotten.

## Project Constraints (from CLAUDE.md)

- Stack is Laravel (PHP) + Inertia + Vue + Postgres; Fortify + Socialite for auth, custom policy layer for permissions; Pest + Vitest. Ignore the Node/Next.js recommendations in CLAUDE.md PART 2.
- Per-module permissions enforced server-side; health and finance default restricted; UI hiding is never enforcement.
- Languages SK, PL, EN from the start; devices phone, desktop, iPad (PWA); service worker never caches private data.
- Deployment via Docker Compose behind the existing edge proxy; no per-token AI billing (not relevant here).
- Work must start through a GSD command (planning artifacts stay in sync); no direct repo edits outside a GSD workflow.
- Project skill: `sketch-findings-family-hub` (design decisions/CSS patterns) for the new Confirm, Challenge and Security pages.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| TOTP secret generation, verification, replay guard, recovery codes | API / Backend (Fortify actions, Google2FA) | Database (encrypted columns) | Secrets never leave the server except one-time setup display |
| Passkey ceremony (challenge, verify, counter) | API / Backend (`laravel/passkeys` actions, webauthn-lib) | Browser (`navigator.credentials`) | Browser only signs; server validates origin, rpId, UV, counter |
| Second-factor challenge at sign-in (password and Google) | API / Backend (login pipeline, session `login.id`) | Browser (Inertia challenge page) | Authentication state is server session only |
| Idle re-auth gate | API / Backend (`RequireAccess` + session timestamp) | Browser (handle 302 to confirm and 423 JSON) | UI hiding is never enforcement (project rule) |
| Confirm page / return to origin | Frontend Server (Inertia redirect + intended URL, sanitised) | API / Backend | Redirect target must be a server-held, same-origin path |
| Require-2FA policy | Database (`app_settings`) + API | Browser (admin toggle) | Enforced in the same gate, not in the UI |
| Admin reset of a member's factors | API / Backend (`SessionRevoker` + audit) | Browser (members panel) | Reuses Phase 2 revoke semantics |
| Audit of security events | API / Backend (`AuditLogger`, event listeners) | Database (append-only `audit_logs`) | Content-free meta allowlist already enforced |
| Service worker | CDN / Static | — | Must keep not caching `/confirm` or any JSON (already precache-only) |

## Standard Stack

### Core (all already installed; nothing to add)
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| laravel/fortify | v1.42.0 (2026-10-21) | 2FA actions/events, challenge routes, login pipeline | Owner-locked headless auth; ships `TwoFactorAuthenticatable`, `EnableTwoFactorAuthentication`, `ConfirmTwoFactorAuthentication`, `GenerateNewRecoveryCodes`, `RedirectIfTwoFactorAuthenticatable` [VERIFIED: vendor/laravel/fortify] |
| laravel/passkeys | v0.2.1 (2026-05-18) | WebAuthn actions + `Passkey` model + `PasskeyUser` contract | Pulled by Fortify; actions are public and reusable [VERIFIED: vendor/laravel/passkeys] |
| web-auth/webauthn-lib | 5.3.12 | Attestation/assertion validation, counter check, UV enforcement | Underlies laravel/passkeys [VERIFIED: composer.lock] |
| pragmarx/google2fa | v9.1.0 | TOTP | Underlies Fortify `TwoFactorAuthenticationProvider` [VERIFIED: composer.lock] |
| bacon/bacon-qr-code | v3.1.1 | SVG QR from `twoFactorQrCodeSvg()` | Underlies Fortify [VERIFIED: composer.lock] |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| spomky-labs/cbor-php | 3.4.2 (installed) | Build fake attestation/assertion in one optional "software authenticator" test helper | Only if the planner budgets the real-crypto test (see Validation) |
| `navigator.credentials` + hand-written base64url helper | browser API | Client side of registration, login challenge, re-auth | Replace `@laravel/passkeys` JS client (adds a dependency for ~60 lines) |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Own passkey routes | Fortify `Features::passkeys()` | Also registers `passkey.login*` (passwordless first-factor login), explicitly out of scope; its management routes use `password.confirm` (password only). Rejected. |
| Hand-written JS | `@laravel/passkeys` 0.4.1 npm | Extra transitive `@simplewebauthn/browser`; not needed. Rejected to keep zero new packages. |
| Gate inside `RequireAccess` | Separate `sensitive` middleware alias on each route | Forgettable, and runs either before access (redirect leak for unauthorised users) or after (audit row for bounced request). Inside `RequireAccess` is one edit that covers all routes. |

**Installation:** none. Do not run `fortify:install` again.
**Migrations to (re)add** (published copies were deleted in Phase 2): `php artisan vendor:publish --tag=fortify-migrations` yields the 2FA column migration (`two_factor_secret` text, `two_factor_recovery_codes` text, `two_factor_confirmed_at` timestamp, all nullable) and the `passkeys` table (`user_id` FK cascade, `name`, `credential_id` unique string, `credential` json, `last_used_at`) [VERIFIED: vendor database/migrations]. Rename to the project's `2026_11_17_...` prefix style. `fh_app` gets privileges automatically through `ALTER DEFAULT PRIVILEGES` in `docker/postgres/init/01-roles.sh` [VERIFIED].

## Package Legitimacy Audit

| Package | Registry | Age | Source Repo | slopcheck | Disposition |
|---------|----------|-----|-------------|-----------|-------------|
| laravel/fortify, laravel/passkeys, web-auth/webauthn-lib, pragmarx/google2fa, bacon/bacon-qr-code | Packagist | installed since Phase 2, owner-approved | github.com/laravel/fortify, laravel/passkeys-server, web-auth/webauthn-framework, antonioribeiro/google2fa, Bacon/BaconQrCode | not run (no install) | Approved (already in `composer.lock`, owner approval recorded in 02-01-SUMMARY) |
| @laravel/passkeys (npm 0.4.1) | npm | — | github.com/laravel/passkeys | not run | NOT recommended, not installed |

**Packages removed due to slopcheck [SLOP]:** none. **Flagged [SUS]:** none. This phase installs no external packages, so the install gate does not apply.

## Architecture Patterns

### System Architecture Diagram

```
Password login POST /login ──► throttle:login ─► Fortify pipeline
   (Fortify, custom authenticateUsing)            └─ CUSTOM RedirectsIfTwoFactorAuthenticatable
                                                       user.hasSecondFactor()? ──no──► AttemptToAuthenticate ─► LoginResponse(custom)
                                                              │yes                                              sets auth.password_confirmed_at
Google callback ─► resolve user ─► hasSecondFactor? ─yes──┐   │                                                  + sensitive.active_at, redirect intended
                                           │no            ▼   ▼
                                     Auth::login        session: login.id, login.remember, login.at
                                     (+ set both keys)       │
                                                             ▼
                                   GET /two-factor-challenge (Inertia: totp | recovery | passkey tabs)
                                    │ POST code/recovery_code ─► Fortify controller (throttle:two-factor, audit via events)
                                    │ GET/POST passkey options/verify ─► OWN controller (VerifyPasskey bound to login.id user)
                                    ▼
                         guard->login + session regenerate + set both keys ─► intended

Any request to access:module,finance|health,* ─► RequireAccess
   1 authenticated?  no ─► login (302 / 401 JSON)
   2 AccessGate allows? no ─► 403 (+ access_denied audit)
   3 policy: require-2FA on AND no factor? ─► redirect settings.security (HTML) / 423 JSON {code: two_factor_setup_required}
   4 idle gate: now - sensitive.active_at <= 900 ?
        no  ─► HTML navigation: store sanitised intended, 302 /confirm ; JSON/other: 423 {error: reauth_required}
        yes ─► (unless passive request) slide sensitive.active_at = now
   5 audit finance_read/finance_write (unchanged) ─► controller

GET /confirm (Inertia) ─► POST /confirm {password | code | recovery_code} or passkey options+verify
   throttle:reauth (per user) ─► success: set auth.password_confirmed_at + sensitive.active_at ─► redirect to sanitised intended
```

### Recommended Project Structure
```
app/Security/
├── SensitiveSession.php        # interface-like service: fresh(), touch(), confirm() (session keys live ONLY here)
├── SecondFactorPolicy.php      # required(User): bool  (setting on + has finance/health access), hasFactor(User)
├── AppSettings.php             # tiny key/value store over app_settings table, scoped per request
├── SafeIntended.php            # sanitise url.intended -> same-origin path or '/'
└── Passkeys/                   # thin wrappers if needed (options -> session, verify -> catch Throwable)
app/Http/Controllers/Security/
├── ReauthController.php        # show, store (password/code/recovery), passkeyOptions, passkeyVerify
├── ChallengePasskeyController.php  # login-challenge passkey options/verify (guest + login.id)
├── SecuritySettingsController.php  # Settings > Security page (status, passkey CRUD, TOTP setup state)
├── PasskeyController.php       # options/store/destroy for the signed-in user
└── Settings/SecurityPolicyController.php, MemberSecondFactorController.php  # admin toggle + reset
app/Listeners/RecordSecurityEvents.php   # Fortify + passkey events -> AuditLogger
app/Http/Responses/             # LoginResponse, TwoFactorLoginResponse, FailedTwoFactorLoginResponse (i18n keys, session keys)
routes/web/security.php, settings-security.php, settings-security-admin.php
tests/Support/EndpointMatrix/{security,settings-security,...}.php
resources/js/Pages/Auth/{TwoFactorChallenge,Confirm}.vue, Pages/Settings/Security.vue
resources/js/Security/webauthn.js        # base64url + navigator.credentials (no npm package)
```

### Pattern 1: Enable Fortify 2FA features, headless, with the project's fail-closed routing
**What:** `config/fortify.php`:
```php
'features' => [
    Features::resetPasswords(),
    // confirm: secret stays "pending" until a code is entered. confirmPassword MUST stay false:
    // it hard-wires Laravel's password-only `password.confirm` middleware onto every 2FA route.
    Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => false, 'secret-length' => 32]),
],
'limiters' => ['login' => 'login', 'two-factor' => 'two-factor'],
```
Do NOT add `Features::passkeys()` [VERIFIED: vendor routes.php registers `passkey.login` guest routes under that feature]. Fortify's own provider already calls `Passkeys::ignoreRoutes()` in `register()`, so with the feature off there are no passkey routes today [VERIFIED: FortifyServiceProvider::configurePasskeys].
**Routes this registers** (names, all need an entry in `EnsureRouteDeclaresAccess::FORTIFY_RULES` and a matrix row, otherwise the fail-closed guard answers 403 and `EndpointMatrixTest` fails): `two-factor.login` (GET page, guest), `two-factor.login.store` (POST, guest), `two-factor.enable` (POST), `two-factor.confirm` (POST), `two-factor.disable` (DELETE), `two-factor.qr-code` (GET JSON), `two-factor.secret-key` (GET JSON), `two-factor.recovery-codes` (GET JSON), `two-factor.regenerate-recovery-codes` (POST). Map the guest ones to `['guest']`: the `RequireAccess` guest rule only redirects *signed-in* users to `/`, which is correct for the challenge (the user is not authenticated yet). Map the management ones to a new rule (Pattern 6, `recent`).
**Views:** `Fortify::twoFactorChallengeView(fn () => Inertia::render('Auth/TwoFactorChallenge', [...]))` in `FortifyServiceProvider::boot`. Pass only booleans `{totp: bool, passkey: bool}` for the challenged user, never ids or counts.
**`User` model:** `use Laravel\Fortify\TwoFactorAuthenticatable; use Laravel\Fortify\PasskeyAuthenticatable;` implement `Laravel\Passkeys\Contracts\PasskeyUser`; add `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at` to `#[Hidden([...])]`. Add `hasSecondFactor(): bool` = `hasEnabledTwoFactorAuthentication() || passkeys()->exists()`. `hasEnabledTwoFactorAuthentication()` already honours `confirm` (secret AND confirmed_at) [VERIFIED].
**Named limiter** (Fortify's `throttle:two-factor` needs it defined or the route breaks): key on the challenged user id from the session (`login.id`), not the IP, 5/min plus ~10 per 15 min; `->response()` returning the same `back()->withErrors(['throttle' => minutes])` shape as the `login` limiter and firing `Lockout`-style audit.

### Pattern 2: Challenge covers passkey-only users and Google sign-in
**What:** Fortify's `RedirectIfTwoFactorAuthenticatable::handle` challenges only if `two_factor_secret` is set [VERIFIED]. Bind a subclass (the contract is `scoped` in Fortify's provider; rebinding in `AppServiceProvider::register` overrides because app providers register after package providers):
```php
class RedirectIfSecondFactor extends \Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable
{
    public function handle($request, $next)
    {
        $user = $this->validateCredentials($request);   // fires Failed + increments limiter on wrong password (existing behaviour)
        if ($user instanceof User && $user->hasSecondFactor()) {
            $request->session()->put('login.at', now()->getTimestamp());
            return $this->twoFactorChallengeResponse($request, $user); // puts login.id + login.remember
        }
        return $next($request);
    }
}
```
`validateCredentials` uses the app's `authenticateUsing` callback (dummy-hash timing equalisation stays) [VERIFIED].
**Google:** in `GoogleController::callback`, replace `Auth::login($user,false)` with: if `$user->hasSecondFactor()`, put `login.id`, `login.remember=false`, `login.at` and `redirect()->route('two-factor.login')`; else login as today and call `SensitiveSession::confirm()`. Fortify's `store` then logs in and regenerates the session, so the `Login` event (and the existing `login_success` audit row) fires only after the factor passes [VERIFIED].
**TTL for the pending challenge:** Fortify never expires `login.id` (only the session lifetime). Add a name-keyed branch in `ThrottleAuthRoutes` (already the home of name-keyed auth throttling) for `two-factor.login*` and the passkey challenge routes: if `now - login.at > 600`, forget `login.id/login.remember/login.at` and redirect to `/login`.
**Passkey on the challenge page:** own controller, session-bound to `login.id`, mirrors package `PasskeyConfirmationController` (which cannot be used because it needs `Auth::user()`):
```php
// options (GET, guest): user = User::find(session('login.id')) ?? abort(403)
$options = app(GenerateVerificationOptions::class)($user);               // allowCredentials limited to this user's passkeys
$request->session()->put('passkey.verification_options', WebAuthn::toJson($options));
return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
// verify (POST, guest): wrap in try/catch Throwable -> audit second_factor_failed(method=passkey) + 422 key error
$passkey = app(VerifyPasskey::class)($request->credential(), $request->verificationOptions(), $user);
$guard->login($user, (bool) session()->pull('login.remember', false)); session()->regenerate(); SensitiveSession::confirm();
```
`PasskeyVerificationRequest` (package) is reusable: it pulls `passkey.verification_options` from the session and validates the credential shape [VERIFIED]. Catch `Throwable`: `VerifyPasskey` throws `InvalidPasskeyException` for unknown credential but webauthn-lib throws its own exception types (e.g. `CounterException`, `AuthenticatorResponseVerificationException`) that would otherwise surface as a 500 [VERIFIED by reading the actions; no catch inside].

### Pattern 3: Passkey registration and management (own routes, `access:authenticated` + `recent`)
Routes under `/settings/security/passkeys`: `GET options`, `POST` (store), `DELETE {passkey}`. Use `GenerateRegistrationOptions` + `StorePasskey` + `DeletePasskey` and the package `PasskeyRegistrationRequest` (note: it only validates `name` as `max:255`; add your own FormRequest that trims and caps at 60 and rejects control characters, then pass the validated name). The package defaults are right for us: `userVerification=required`, `residentKey=required`, attestation `none`, ES256/RS256, `excludeCredentials` = the user's existing passkeys [VERIFIED: GenerateRegistrationOptions]. Authorise `destroy` by `passkey.user_id === auth()->id()` (the package controller does `abort_unless`; keep the same check) and bind the `{passkey}` route parameter with `whereNumber`. Block deleting the last factor when the require-2FA setting is on and the user is under the requirement (or confirm explicitly in UI).
**Where the flow lives:** `Passkeys::ignoreRoutes()` is already active (Fortify's provider), so the package's `/user/passkeys*` routes do not exist; nothing to remove.
**Config per environment:** rpId = host of `APP_URL`; `allowed_origins` = `[APP_URL]` [VERIFIED: Fortify `configurePasskeys`, which writes `config('passkeys.*')` from `fortify.passkeys.*` falling back to `APP_URL`]. Dev `.env.example` has `APP_URL=http://localhost:8080` so origin `http://localhost:8080` and rpId `localhost` work over plain http (localhost is a secure context) [ASSUMED for the origin string check; verify in the dev UAT]. Staging and prod must have `APP_URL=https://<their host>`; passkeys are bound to the rpId so staging and prod credentials are separate by construction. Set `fortify.passkeys.user_handle_secret` (env `PASSKEYS_USER_HANDLE_SECRET`) explicitly so an APP_KEY rotation does not change new handles [VERIFIED: trait default is `config('app.key')`].
**Counters / clone detection:** `CheckCounter` runs unless both stored and response counters are 0 (synced passkeys always report 0); a non-increasing counter throws `CounterException` [VERIFIED: vendor webauthn-lib]. So hardware keys get clone detection and iCloud/Google synced passkeys cannot (nothing to do; just treat any throw as a failed challenge and audit it).
**`last_used_at`** is updated by `VerifyPasskey::updatePasskey` and can be shown in the UI ("last used"). `Passkey::authenticator` accessor gives an AAGUID label (e.g. "iCloud Keychain") for display [VERIFIED].

### Pattern 4: Does a passkey count as a second factor in Fortify's challenge?
No. Fortify's `TwoFactorAuthenticatedSessionController::store` only knows `code` and `recovery_code`. The passkey branch is custom (Pattern 2) and shares only the `login.id` session contract. A user with passkeys but no TOTP has **no recovery codes**; losing the passkey is an admin reset. Show a UI nudge ("add a second passkey or an authenticator app") and make the empty-state copy explicit. UV is required server-side, so a passkey proves possession plus biometric/PIN, which is why it also counts for step-up.

### Pattern 5: Idle gate, session timestamp
```php
final class SensitiveSession
{
    public function __construct(private readonly Session $session) {}
    public function fresh(): bool { $t = (int) $this->session->get('sensitive.active_at', 0); return $t > 0 && now()->getTimestamp() - $t <= config('security.idle_seconds', 900); }
    public function touch(): void { $this->session->put('sensitive.active_at', now()->getTimestamp()); }
    /** A successful interactive proof of identity (password, TOTP, recovery code, passkey). */
    public function confirm(): void { $this->touch(); $this->session->put('auth.password_confirmed_at', now()->getTimestamp()); }
}
```
- **Slide only on gate pass.** `touch()` is called by the gate after a fresh pass and by `confirm()`. Nothing else calls it. Home, To-do, Wishlist and `/heartbeat` never touch it [VERIFIED by design: Home is `access:authenticated`].
- **Passive requests** (check, do not slide): `Purpose: prefetch` (Inertia 3 sets this header on prefetch [VERIFIED: node_modules/@inertiajs/core dist/index.js lines 1055, 4068]) and a client-set `X-Fh-Passive: 1` for any future poller. A client can only use the header to extend its own session *less*, so trusting it is safe. A stale passive request must not store an intended URL and must answer 423 instead of redirecting.
- **Where `confirm()` is called:** `LoginResponse` and `TwoFactorLoginResponse` (custom bindings), Google callback when no factor, and the confirm controller. **Not** on remember-cookie session restore: the `Login` event also fires for `viaRemember` (`$event->remember` is true), so do not hook the event, otherwise a stolen 30-day remember cookie would grant a fresh sensitive window. A remember-restored session has no `sensitive.active_at`, so the first sensitive visit prompts. [VERIFIED: framework `SessionGuard` fires Login with remember=true for recaller logins; ASSUMED from memory of the source, confirm with a test: restored session hits `/finance` -> 302 confirm].
- A logged-in-but-unconfirmed session is normal; the gate is the only consumer.
- Session driver is `database`; the timestamp lives in the session payload; `SessionRevoker::revokeAll` deleting rows automatically kills it [VERIFIED].
- **Management actions** (set up/disable factor, regenerate codes, delete passkey) use the same helper `recentlyConfirmed(window)` over `auth.password_confirmed_at`. Use the same 900 s window; set `AUTH_PASSWORD_TIMEOUT` default to 900 in `config/auth.php` only if you also keep Laravel's `password.confirm` middleware somewhere (do not; use the new `recent` rule).

### Pattern 6: Gate placement and rules in `RequireAccess`
Add `Module::sensitive()` (Finance, Health) next to `audited()`. In `RequireAccess::handle`, after `$allowed` is computed and true, before `afterDecision`:
```php
if ($moduleEnum?->sensitive()) {
    if ($response = $this->sensitive->gate($request, $user)) { return $response; } // 423 / redirect, no audit row
}
```
`gate()` order: (a) require-2FA policy unmet -> setup redirect; (b) stale -> confirm; (c) slide unless passive. Add a second rule `recent` (authenticated + `auth.password_confirmed_at` within window, else 423 JSON for `expectsJson`, else `redirect()->guest(route('reauth.show'))`) used via `FORTIFY_RULES` for the Fortify 2FA management route names and via `access:recent` on the own security management routes.
**Response contract (stale):**
| Request kind | Response |
|---|---|
| Inertia XHR or full navigation (GET) | `redirect()->guest(route('reauth.show'))` (stores intended = full URL of this GET) |
| Inertia non-GET (form) | same redirect; Laravel stores `previous()` (the referring page) so the user returns to the page, not to a POST URL [VERIFIED: Redirector::guest] |
| `fetch` JSON (`expectsJson()` and no `X-Inertia`) | 423 `{"error":"reauth_required"}` (matches Laravel's own `RequirePassword` JSON status [VERIFIED]); never 401 (that means "sign in") or 403 (means "no permission") |
| Non-navigational file load (img/iframe, `Sec-Fetch-Mode` not `navigate`) | 423 no body [ASSUMED: Sec-Fetch-* sent by all current browsers] |
| Prefetch / passive | 423, no intended stored, no slide |
**Sanitise intended.** `Redirector::previous()` honours any `Referer` verbatim and `redirect()->intended()` redirects anywhere [VERIFIED]. `SafeIntended::path($url)`: `parse_url`; if host is set it must equal `$request->getHost()`; keep only path+query; reject anything not starting with a single `/`, containing `\`, or starting with `/confirm`, `/login`, `/two-factor-challenge`; fall back to `/`. Call it when pulling `url.intended` in the confirm controller.
**Order inside RequireAccess for the new policy step:** decision first (403 for no-access), then policy, then idle, then audit. A user without finance access therefore never learns anything and the matrix 403 expectations are unchanged.

### Pattern 7: Admin policy and reset
- **Setting storage:** new `app_settings` table (`key` string PK, `value` jsonb, timestamps) plus `AppSettings` (scoped service, reads once per request). No settings table exists today [VERIFIED: migrations list]. One row `require_second_factor` -> `true|false`, default false when absent. Admin toggle route `PUT /settings/security/policy` (`access:admin`), audited `security_policy_changed` meta `['setting','value']` (allowlisted).
- **Who is "under the requirement":** `is a user with AccessLevel >= view on Finance or Health` via `AccessGate::level`. Admin-only users with no sensitive grants are not affected.
- **Enforcement (decision: guide, not hard block):** in `gate()` step (a). HTML -> `redirect('/settings/security')->with('status','second_factor_required')`; JSON -> 423 `{"error":"second_factor_required"}`. The rest of the app stays usable. Remember-restored and existing sessions are covered because the check is per request.
- **Admin reset:** `DELETE /settings/members/{user}/second-factor` (`access:admin`, `whereNumber('user')`), one DB transaction: null the three 2FA columns, delete the user's `passkeys` rows, `SessionRevoker::revokeAll($user)` (deletes `sessions` rows and rotates `remember_token` [VERIFIED]), write `second_factor_reset` with actor=admin, target=user. An admin must not be able to reset themselves through this route (use self-service) and a reset must not be possible on a member while an unrelated request is not re-authenticated: put `access:admin` plus `recent` on the route. Optionally send a "your second factor was removed" notification mirroring `GoogleLinkedNotification` (SK/PL/EN lang already patterned) [Claude's discretion].
- **Who can see factor status:** member sees own status (methods, passkey names and last used, no secrets). Admin sees per member a content-free `{totp: bool, passkeys: int}` in the members panel (needed for reset and for compliance with the requirement). Never expose `two_factor_*` columns or credential JSON in any prop; `HandleInertiaRequests::share` maps user fields explicitly, keep it that way and add the arch test below.

### Pattern 8: Hardening of Fortify routes that become reachable
- `password.confirm.store` (POST `/user/confirm-password`) is registered regardless of features, is `authenticated` only, and is **not throttled or audited** [VERIFIED: routes.php, ThrottleAuthRoutes covers only reset routes]. It is a password oracle for a hijacked session and is replaced by `/confirm`. Map `password.confirm`, `password.confirm.store` to a denying rule in `FORTIFY_RULES` (e.g. `['disabled']`, which `RequireAccess` denies as unknown) and update `tests/Support/EndpointMatrix/password.php` expectations. Keep `password.confirmation` (status JSON) as is or deny as well.
- `two-factor.qr-code` and `two-factor.secret-key` return the TOTP secret on every call while the user has any secret [VERIFIED]. Recommended small hardening: bind subclasses of `TwoFactorQrCodeController` / `TwoFactorSecretKeyController` (container binding of the controller class name works for route actions) that `abort(404)` once `two_factor_confirmed_at` is set. Recovery codes: Fortify's GET returns the plaintext codes at any time; the UI should only call it right after confirm/regenerate (no "view codes" button). Both measures are [Claude's discretion]; the first is cheap and removes a standing secret exposure.

### Anti-Patterns to Avoid
- **Separate per-route `sensitive` alias only:** can be forgotten, wrong audit ordering. Use the `RequireAccess` choke point, keep a CI test.
- **Hooking `Login` event to set the confirmation timestamp:** fires on remember-cookie restores.
- **Enabling `Features::passkeys()`:** adds `POST /passkeys/login` (first-factor login), out of scope and bypasses the login limiter and audit.
- **`confirmPassword => true`:** routes use stock `password.confirm`, password-only, 3 h window (`auth.password_timeout` 10800), unusable for Google-created accounts.
- **Trusting `Passkeys::allowsLogin`/package controllers for the challenge:** they `Auth::login` directly.
- **Storing a decrypted TOTP secret, code or credential in logs, props, flash or Inertia history.**

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| TOTP generation/verification, QR | own HMAC/base32 | Fortify actions + `TwoFactorAuthenticationProvider` | Secret encrypted with APP_KEY (`Crypt`), `verifyKeyNewer` blocks replay |
| Recovery codes | own generator/format | `RecoveryCode::generate()` (`Str::random(10).'-'.Str::random(10)`, about 119 bits) | Replace-on-use already implemented; entropy is high enough that encrypted (not hashed) storage is acceptable [ASSUMED: ASVS lookup-secret entropy threshold, verify wording] |
| WebAuthn parsing/validation, CBOR, COSE, counter, origin, UV | own crypto | `laravel/passkeys` actions + webauthn-lib | Origin/rpId/UV/counter checks are subtle |
| Session revoke + remember rotation | own SQL | `SessionRevoker::revokeAll` | Phase 2 contract ("revoke means revoke") |
| Audit writes | direct inserts | `AuditLogger::record` with allowlisted meta | Append-only triggers + content-free rule |
| Rate limiting | custom counters | named limiters via `RateLimiter::for` | Same pattern as `login` limiter |
| Redirect-back after confirm | raw `redirect()->intended()` | `SafeIntended` wrapper around `url.intended` | Referer-derived URL is unvalidated |

**Key insight:** the crypto and protocol parts are all vendor-provided; the risk in this phase is wiring (which pipeline a login takes, where the gate sits, what resets the timer), not primitives.

## Runtime State Inventory

Not a rename/refactor phase; but data-state items to be explicit about:

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | `users` has no 2FA columns and no `passkeys` table (deleted in Phase 2). Existing members have no factor. | Add migrations (code); no data migration |
| Live service config | None (no external service holds factor state) | None |
| OS-registered state | None | None |
| Secrets/env vars | `APP_KEY` encrypts `two_factor_secret` and `two_factor_recovery_codes` via `Crypt`. Rotating it breaks all enrolled TOTP unless `APP_PREVIOUS_KEYS` is set. New optional `PASSKEYS_USER_HANDLE_SECRET`. | Document in ops README: keep APP_KEY in backups; use `APP_PREVIOUS_KEYS` when rotating [ASSUMED: Laravel supports APP_PREVIOUS_KEYS since 11] |
| Build artifacts | Service worker precache manifest changes with new pages (build step `check-sw-precache.mjs` exists) | Run `npm run build` in verification |

## Common Pitfalls

### Pitfall 1: Passkey-only user skips the challenge
**What goes wrong:** user with passkeys but no TOTP logs in with password only. **Why:** stock `RedirectIfTwoFactorAuthenticatable` tests `two_factor_secret`. **Avoid:** bind the subclass of Pattern 2; test "passkey-only user gets challenge page".

### Pitfall 2: Google sign-in bypasses the second factor
**Why:** `GoogleController` calls `Auth::login` itself. **Avoid:** branch as in Pattern 2; matrix/feature test: Google callback for a user with TOTP lands on `/two-factor-challenge` and `Auth::check()` is false.

### Pitfall 3: Enabling the gate makes ~270 existing finance test calls redirect
**Why:** `actingAs` creates a session without `sensitive.active_at`. **Avoid:** test seam (Validation Architecture); do not edit each test.

### Pitfall 4: Gate placed after `access` writes a `finance_read` audit row for a bounced request, or before it leaks redirects to unauthorised users
**Avoid:** inside `RequireAccess` between decision and audit (Pattern 6). Add an audit test: stale request writes no `finance_read` row.

### Pitfall 5: Timer extended by something that is not user activity
Inertia prefetch, polling, `router.reload` timers. **Avoid:** passive handling; never `prefetch` on finance links without it; grep for `setInterval` shows none in finance today [VERIFIED].

### Pitfall 6: Throttle keyed by IP or by session on the challenge
A fresh session resets a session-keyed counter. **Avoid:** key by the challenged user id; hourly cap; audit the lockout.

### Pitfall 7: Fortify default failure/response strings are raw English `__()` keys
`FailedTwoFactorLoginResponse` and the enabled/confirmed responses use English strings and `status` constants such as `two-factor-authentication-enabled`. The app's error contract is i18n keys. **Avoid:** bind custom `FailedTwoFactorLoginResponse` returning `redirect()->route('two-factor.login')->withErrors(['code' => 'auth.error.code_invalid'])` (303 not needed for POST), and map Fortify `status` constants in the Vue pages. Add all lang keys (SK/PL/EN, pipe plurals) in one early plan per project convention.

### Pitfall 8: TOTP replay cache is global and short
`TwoFactorAuthenticationProvider` caches `fortify.2fa_codes.md5(code)` for `window*60` s with the cache store (database in prod) [VERIFIED]. Key is not user-scoped (two users with the identical 6-digit code in one minute: one gets a harmless failure) and TTL 60 s is shorter than the worst-case 90 s validity of a code under window 1. Risk is negligible at family scale. Keep window at the default 1 (clock drift +/- 30 s); do not set `window => 0`. [ASSUMED acceptable; no change recommended]

### Pitfall 9: `AuditEntry::sole()` style tests
Existing auth audit tests assert exactly one row after login. **Avoid:** do not write a separate `reauth_confirmed` row on login completion; audit `reauth_confirmed` only from `/confirm`.

### Pitfall 10: WebAuthn exceptions become 500s
See Pattern 2. Wrap verify/store in `catch (Throwable)` and answer with a key error; log only the exception class, never the payload.

### Pitfall 11: File (`<img>`) and fetch endpoints get an HTML redirect when stale
Return 423 for non-navigational requests; client `importApi.preview` must treat `status === 423` as "go to confirm" (the import wizard keeps the file only in memory, so after confirming the user re-selects it; acceptable, state it in UX copy).

### Pitfall 12: iOS installed PWA and passkeys
WebAuthn in a home-screen web app should work on current iOS, but verify in UAT on the owner's actual iPad/iPhone [ASSUMED]. The service worker is precache-only and does not touch `/confirm` or JSON [VERIFIED: sw.js].

## Code Examples

### Browser WebAuthn without a package
```js
// resources/js/Security/webauthn.js
const b64uToBuf = (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(s.length / 4) * 4, '=')), (c) => c.charCodeAt(0)).buffer;
const bufToB64u = (b) => btoa(String.fromCharCode(...new Uint8Array(b))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

export const passkeysSupported = () => typeof window !== 'undefined' && !!window.PublicKeyCredential && !!navigator.credentials;

// options = response.options from the server (WebAuthn::toBrowserArray: base64url challenge/ids)
export async function getAssertion(options) {
    const publicKey = { ...options, challenge: b64uToBuf(options.challenge),
        allowCredentials: (options.allowCredentials ?? []).map((c) => ({ ...c, id: b64uToBuf(c.id) })) };
    const cred = await navigator.credentials.get({ publicKey });
    return { id: cred.id, rawId: bufToB64u(cred.rawId), type: cred.type, response: {
        clientDataJSON: bufToB64u(cred.response.clientDataJSON), authenticatorData: bufToB64u(cred.response.authenticatorData),
        signature: bufToB64u(cred.response.signature), userHandle: cred.response.userHandle ? bufToB64u(cred.response.userHandle) : null } };
}
export async function createCredential(options) {
    const publicKey = { ...options, challenge: b64uToBuf(options.challenge), user: { ...options.user, id: b64uToBuf(options.user.id) },
        excludeCredentials: (options.excludeCredentials ?? []).map((c) => ({ ...c, id: b64uToBuf(c.id) })) };
    const cred = await navigator.credentials.create({ publicKey });
    return { id: cred.id, rawId: bufToB64u(cred.rawId), type: cred.type, response: {
        clientDataJSON: bufToB64u(cred.response.clientDataJSON), attestationObject: bufToB64u(cred.response.attestationObject),
        transports: cred.response.getTransports?.() ?? [] } };
}
```
Server shapes verified: the package validates `credential.{id,rawId,type,response}` and deserialises with the webauthn serializer, i.e. the standard WebAuthn JSON (`toJSON()`) shape [VERIFIED: PasskeyVerificationRequest/RegistrationRequest, WebAuthn support class]. Treat `NotAllowedError` (user cancelled/timeout) as a quiet cancel, not an error. [ASSUMED: field details of `getAssertion` userHandle null handling; verify in the real-device UAT]

### Stale-gate response in RequireAccess helper
```php
public function gate(Request $request, User $user): ?Response
{
    if ($this->policy->mustEnrol($user)) {
        return $this->json($request) ? response()->json(['error' => 'second_factor_required'], 423)
            : redirect('/settings/security')->with('status', 'second_factor_required');
    }
    if (! $this->session->fresh()) {
        if ($this->json($request) || $this->passive($request)) { return response()->json(['error' => 'reauth_required'], 423); }
        return redirect()->guest(route('reauth.show'));
    }
    if (! $this->passive($request)) { $this->session->touch(); }
    return null;
}
private function passive(Request $r): bool { return $r->header('Purpose') === 'prefetch' || $r->header('X-Fh-Passive') === '1'; }
private function json(Request $r): bool { return $r->expectsJson() && ! $r->header('X-Inertia'); }
```

### Confirm controller skeleton (all methods share one throttle and one audit)
```php
RateLimiter::for('reauth', fn (Request $r) => [
    Limit::perMinute(5)->by('reauth:'.$r->user()?->id)->response($respond),
    Limit::perHour(20)->by('reauth-h:'.$r->user()?->id)->response($respond),
]);
// store: exactly one of password | code | recovery_code (strings only; reject arrays with 422, like ThrottleAuthRoutes does)
// password: Hash::check against $user->password; code: app(TwoFactorAuthenticationProvider::class)->verify(decrypt(secret), $code);
// recovery: same hash_equals loop as TwoFactorLoginRequest::validRecoveryCode then $user->replaceRecoveryCode($code)
// success: $sensitive->confirm(); audit reauth_confirmed meta ['method'=>...]; return redirect(SafeIntended::path(session()->pull('url.intended')), 303);
// failure: audit second_factor_failed meta ['method'=>..., 'context'=>'reauth']; back()->withErrors(['code' => 'auth.error.code_invalid'])
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Fortify 2FA only | Fortify 1.4x adds passkeys via hard dep on `laravel/passkeys` | 2026 (Fortify 1.4x) | We use the actions, not Fortify's passkey feature routes |
| `@laravel/passkeys` JS client | native `PublicKeyCredential.parse*FromJSON` in new browsers | browser support 2024-25 | We still hand-write base64url for older iPads; the native helpers would need feature detection `[ASSUMED]` |

**Deprecated/outdated:** laravel/passkeys 0.x is pre-1.0 (v0.1.0 2026-04-22 to v0.3.0 2026-11-10) [VERIFIED: Packagist]; 0.3 may change APIs, hence we depend only on the Actions listed in its README ("Available actions") and pin via lock. **Fallback plan:** all passkey code sits behind one adapter (`app/Security/Passkeys`); if passkeys prove unworkable in UAT, ship TOTP only and hide the passkey UI by one flag (`security.passkeys_enabled`, default true). The challenge, gate and policy code do not depend on passkeys.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `localhost:8080` http origin is accepted by webauthn-lib origin check for dev | Pattern 3 | Dev passkeys fail; use https tunnel or adjust `allowed_origins` |
| A2 | `Sec-Fetch-Mode` is sent by all target browsers so non-navigations can get 423 | Pattern 6 | Image/iframe loads receive HTML redirect; low impact (Phase 7 files) |
| A3 | Remember-cookie restore fires `Login` with remember=true and does not set our keys (so first sensitive visit prompts) | Pattern 5 | If wrong, the gate is weaker than designed; covered by a required test |
| A4 | Recovery code entropy (~119 bits) satisfies ASVS lookup-secret rule with encrypted storage | Don't Hand-Roll | Might need hashed codes: custom generator and verifier |
| A5 | `APP_PREVIOUS_KEYS` rotation support for encrypted secrets | Runtime State | Rotation breaks TOTP; ops doc only |
| A6 | WebAuthn works in an iOS installed PWA | Pitfall 12 | Passkeys unusable in PWA mode; TOTP remains |
| A7 | Google `prompt`/`max_age` parameters can force re-authentication in Socialite | Open Question 1 | Google re-auth option cannot prove freshness |
| A8 | Window 1 and global replay cache key acceptable at family scale | Pitfall 8 | Negligible |
| A9 | `$user->two_factor_*` hidden attributes plus explicit mapping suffice (no prop leaks) | Pattern 7 | Add arch test (below) |

## Open Questions

1. **Google-only members cannot use "password" to re-authenticate.**
   - What we know: Google-created accounts have `Hash::make(Str::random(64))` [VERIFIED: InviteService]. They can set a password only via the forgot-password email flow, which also revokes their sessions.
   - What's unclear: what re-auth a Google member with no factor gets when require-2FA is off.
   - Recommendation: (a) when the policy is on they must enrol a factor, which solves it; (b) when it is off, offer on `/confirm` a "Sign in with Google again" button for members with `google_id` (Socialite redirect flagged `reauth`, callback sets `confirm()` only if the Google subject equals the user's `google_id`; try `prompt=select_account` and treat `max_age`/`prompt=login` as unverified [ASSUMED], so this is weaker than a password). Ask the owner; default to (b) with a UAT check, or accept "enrol a factor first" for sensitive access.
2. **Should the Home "Still to pay" card (counts only, from `HomeSummary`) be hidden while the session is stale?** It is finance-derived data on an `access:authenticated` page. Recommendation: leave (Phase 5 reviewed counts-only), list it in the gate test allowlist with a reason; ask owner.
3. **Should admin finance-account settings (`settings.finance_accounts.*`, `access:admin`) be gated?** They show account names and membership, not transactions. Recommendation: not gated; explicit allowlist entry.
4. **Does a fresh login count as confirmation?** Recommendation yes (no immediate prompt after signing in), except remember-cookie restores.
5. **Optional keepalive** for long-lived sensitive pages (import wizard edits column mapping client-side for >15 min). Recommendation: defer; if wanted add an `access:authenticated` `POST /sensitive/touch` fired on real user input (throttled, never revives a stale session).

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| Docker compose (app/postgres) | all PHP tests (host PHP is 8.3.6, vendor needs >= 8.4.1) | yes | postgres:18-alpine containers up | none; always run PHP via `docker compose ... run --rm app` |
| Node + Vitest | frontend tests, build | yes (node_modules present) | vitest ^5.1 | — |
| Browser with WebAuthn / authenticator | real passkey verification | not in CI | — | Manual UAT on owner devices (human_verify end-of-phase); fakes in CI |

**Missing dependencies with no fallback:** none.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 4.7 / PHPUnit 12 (PHP, Postgres `family_hub_test`), Vitest 5 + axe (JS) |
| Config file | `phpunit.xml`, `tests/Pest.php`, `vitest.config.js` |
| Quick run command | `docker compose -p fh06 run --rm app php ./vendor/bin/pest tests/Feature/Security` |
| Full suite command | `npx vitest run && npm run build && docker compose -p fh06 run --rm app php -d memory_limit=1G ./vendor/bin/pest` |

### Phase Requirements -> Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| AUTH-09 | enable TOTP: enable -> confirm with generated code -> factor active; wrong code rejected; recovery code works once; passkey register/delete (fake actions) | feature | `pest tests/Feature/Security/TwoFactorSetupTest.php` | Wave 0 |
| AUTH-09 | password and Google login branch to challenge for TOTP-only, passkey-only, both; no `Auth::check()` before pass; throttle; challenge TTL | feature | `pest tests/Feature/Security/ChallengeTest.php` | Wave 0 |
| AUTH-09 | admin require-2FA toggle, guide-to-setup redirect/423, admin reset revokes sessions + audit | feature | `pest tests/Feature/Security/PolicyAndResetTest.php` | Wave 0 |
| AUTH-10 | stale session: every sensitive route bounced (302 HTML / 423 JSON), fresh session passes and slides, passive does not slide, 403 for no-access precedes gate, no audit row when bounced | feature (matrix-driven) | `pest tests/Feature/Security/SensitiveGateMatrixTest.php` | Wave 0 |
| AUTH-10 | confirm page: password/TOTP/recovery/passkey success returns to sanitised intended; open-redirect attempts fall back to `/`; throttle | feature | `pest tests/Feature/Security/ReauthTest.php` | Wave 0 |
| AUTH-10 | idle boundary 14:59 vs 15:01 with `travel()`; remember-restored session prompts | feature | `pest tests/Feature/Security/IdleTimeoutTest.php` | Wave 0 |
| both | audit events content-free; arch: no `two_factor_*` in props/logs | feature/arch | `pest tests/Feature/Security/SecurityAuditTest.php tests/Unit/Arch/SecurityArchTest.php` | Wave 0 |
| both | Confirm/Challenge/Security pages: i18n SK/PL/EN, light/dark, axe | vitest | `npx vitest run tests/js/security` | Wave 0 |

### Test mechanics (resolved questions)
- **TOTP codes:** `$g = app(\PragmaRX\Google2FA\Google2FA::class); $code = $g->oathTotp($secret, intdiv(time(), 30))`. Google2FA uses `time()`, **not** Carbon, so `travel()` does not move TOTP; use explicit counters (`intdiv(time(),30) +/- 1`) to test the drift window and replay [VERIFIED: Google2FA::getTimestamp]. Replay test: same code twice; cache store is `array` in tests, flush between roles.
- **Secret for a test user:** call `app(EnableTwoFactorAuthentication::class)($user)` then decrypt with `Fortify::currentEncrypter()->decrypt($user->two_factor_secret)`; confirm via `ConfirmTwoFactorAuthentication`.
- **Idle time:** use `$this->travel(15)->minutes()` / `Carbon::setTestNow`; gate uses `now()` so it follows.
- **Passkey ceremonies:** default = bind fakes for `VerifyPasskey`, `StorePasskey`, `GenerateVerificationOptions` (container singletons in the package; the controllers must resolve them from the container, not `new`). One optional real-crypto test with a ~80-line `SoftwareAuthenticator` helper (openssl ES256 key, `clientDataJSON`, CBOR `none` attestation via spomky-labs/cbor-php, `authenticatorData` flags UP|UV, signature over `authData || sha256(clientDataJSON)`), set `passkeys.allowed_origins`/rpId in the test. Budget it as a spike task with a fallback to fakes plus manual UAT on real devices.
- **Test seam for the gate (critical):** `final class SensitiveSession` implements nothing special but is resolved from the container; `tests/TestCase::setUp` binds `Tests\Support\AlwaysFreshSensitiveSession` by default (same pattern as the existing `FakeDnsResolver` binding). Gate tests call `$this->useRealSensitiveSession()` (rebind to the real class, then `withSession(['sensitive.active_at' => ...])`). This keeps ~270 existing finance `actingAs` calls untouched. Because the default hides enforcement, the matrix-driven test is mandatory.
- **Matrix-driven gate proof:** in `SensitiveGateMatrixTest`, enumerate routes from `Route::getRoutes()` whose `gatherMiddleware()` contains `/^access:module,(finance|health),/`; for each, look up the existing `EndpointMatrix` row (already mandatory for every named route), take a role whose expectation is success (2xx/redirect-to-success), replay the row's `params`/`data` closures with the real gate and a stale session, and assert: HTML GET -> 302 to `/confirm`; JSON/POST preview -> 423 for JSON; and that no `finance_read`/`finance_write` audit row was written. Add a **naming safety net**: any route whose URI starts with `finance`, `health` or `medical`, or whose name starts with `finance.`/`health.`/`medical.`, must be in that sensitive set or in an explicit allowlist (with reasons: `settings.finance_accounts.*`, Home) so a future Phase 7/8 route that forgets `access:module,health,...` also fails CI. Route-model binding runs before route middleware (SubstituteBindings is in the web group), so the real params from the matrix rows are required; a request to a non-existent UUID would 404 before the gate.
- **Existing `EndpointMatrixTest`** keeps passing because the default seam is "fresh"; add rows for every new route (`reauth.*`, `two-factor.*` management and login, passkey routes, security settings, admin policy/reset) and add the new route names to `FORTIFY_RULES`.

### Sampling Rate
- **Per task commit:** `pest tests/Feature/Security` (and `npx vitest run tests/js/security` when UI changes)
- **Per wave merge:** full PHP suite via compose + `npx vitest run`
- **Phase gate:** full suite green plus manual UAT checklist (real passkey on desktop and iPad/iPhone PWA, authenticator app, idle expiry, admin reset)

### Wave 0 Gaps
- [ ] `tests/Support/AlwaysFreshSensitiveSession.php` + `TestCase` binding and `useRealSensitiveSession()` helper
- [ ] `tests/Support/EndpointMatrix/{security,settings-security}.php`; update `password.php` rows for the disabled Fortify confirm routes
- [ ] `tests/Support/Totp.php` helper (secret creation, code generation, offsets)
- [ ] Optional `tests/Support/SoftwareAuthenticator.php`
- [ ] `tests/js/security/*.test.js`, extend `i18n-phase*` style test for new lang keys, add pages to the a11y sweep

## Security Domain

`security_enforcement` is enabled, ASVS level 1 per config (`security_asvs_level: 1`); the design below also meets the L2 items that are cheap here. ASVS chapter numbers follow 4.0.3 naming and should be re-checked against the version the project cites [ASSUMED].

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication (MFA, lookup secrets, re-auth, credential recovery) | yes | Fortify TOTP + passkeys (UV required), recovery codes, throttles keyed per user, re-auth on idle, admin reset audited |
| V3 Session Management | yes | session regenerate after challenge (Fortify does), revoke all sessions + remember rotation on admin reset, idle timestamp in server session |
| V4 Access Control | yes | gate inside `RequireAccess`, deny before policy/idle, server-side only; admin routes `access:admin` + `recent` |
| V5 Input Validation | yes | string-only validation for `password/code/recovery_code` (reject arrays like `ThrottleAuthRoutes`), passkey name trim/length/control chars, `whereNumber`/`whereUuid` on params |
| V6 Cryptography | yes | secrets encrypted by `Crypt` (APP_KEY); never hand-roll; passkey public keys are not secret |
| V7 Error handling and logging | yes | audit allowlisted content-free meta; log exception class only for WebAuthn failures |
| V8 Data protection | yes | hidden attributes, no secrets in props/flash/Inertia history, `no-store` already global |
| V13/V14 API and config | yes | JSON 423/422 contract, CSRF stays on all routes, per-env rpId/origin config |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| TOTP brute force on challenge | Spoofing | `two-factor` limiter keyed by `login.id` (user), 5/min + hourly cap, lockout audited |
| Second-factor bypass via Google or passkey-only branch | Elevation | custom `RedirectsIfTwoFactorAuthenticatable`, Google branch, tests per branch |
| Replay of TOTP code | Spoofing | `verifyKeyNewer` + cache (Fortify); keep cache store persistent (database) |
| Stolen remember cookie unlocks finance | Elevation | no confirmation on remember restore; first sensitive visit prompts |
| Hijacked session guesses password at `/user/confirm-password` | Spoofing | deny Fortify's confirm routes, throttled `/confirm` |
| Open redirect via `url.intended`/Referer | Tampering | `SafeIntended` same-origin path only |
| Passkey assertion replay/clone | Spoofing | single-use challenge pulled from session, counter check, UV required, user-bound `allowCredentials` |
| Cross-user passkey use | Elevation | `VerifyPasskey(..., $user)` ownership check; `destroy` checks `user_id` |
| Secret exposure in logs/props | Information disclosure | `#[Hidden]`, explicit prop mapping, arch test, `QR/secret` endpoints only while pending |
| Admin reset abuse | Repudiation | audit actor+target, revoke sessions, optional notification to the member, `recent` re-auth for the admin |
| Enumeration via challenge | Information disclosure | challenge page reachable only after a valid first factor; same failure shape |
| Poller/prefetch keeps session fresh forever | Elevation | passive requests do not slide |

## Audit events (proposal; all meta content-free)

Add to `AuditEvent` (update `group()` match, which is exhaustive, `metaKeys()`, and the UI group list and `audit.event.*`/`audit.detail.*` lang keys; add a `security` group):
`two_factor_enabled` (on Fortify `TwoFactorAuthenticationConfirmed`, not on `Enabled`), `two_factor_disabled`, `recovery_codes_regenerated`, `recovery_code_used` (`RecoveryCodeReplaced`), `passkey_added`, `passkey_removed` (meta none; do not store the user-chosen name), `second_factor_failed` (meta `method` in totp|recovery|passkey, `context` in login|reauth; from `TwoFactorAuthenticationFailed` and own controllers), `reauth_confirmed` (meta `method`), `second_factor_reset` (admin actor, target user), `security_policy_changed` (meta `setting`, `value`). Event column is `string(40)`, all fit [VERIFIED: migration]. Meta keys must be allowlisted per event in `metaKeys()` or `AuditLogger` throws. Register one listener `RecordSecurityEvents` for the Fortify events (`TwoFactorAuthenticationConfirmed`, `…Disabled`, `…Failed`, `RecoveryCodesGenerated`, `RecoveryCodeReplaced`) and the passkey events (`PasskeyRegistered`, `PasskeyDeleted`, `PasskeyVerified` only if wanted) [VERIFIED event classes exist]. Lockouts on the new limiters reuse the existing `lockout` event.

## Impact on Phase 5 finance (item 7)

- All finance routes already carry `access:module,finance,<level>` [VERIFIED: routes/web/finance*.php], so the `RequireAccess` gate covers them with no route edits. The import preview (`POST /finance/import/preview`, JSON via `fetch`) will now get 423 when stale: update `resources/js/Finance/import/importApi.js` to return `{ok:false, error:'reauth_required'}` on 423 and the wizard to `router.visit('/confirm')` (message: confirm, then pick the file again).
- Matrix: finance rows stay valid because the default test double is fresh; the new stale-matrix test reuses them (several rows build records inside `data` closures, which already works for the existing matrix).
- Leak suites / privacy arch tests are unaffected (gate does not touch queries). `HomeController` finance card is a decision (Open Question 2).
- Existing audit tests: a gated bounce writes no finance row, consistent with `AuditMiddlewareTest` expectations of one row per real access.
- Cache headers: `/confirm` and `/two-factor-challenge` are dynamic and inherit `no-store, private` from `NoStoreDynamicResponses` [VERIFIED].

## Sources

### Primary (HIGH confidence, read directly)
- `/home/voslar/projects/family-hub/vendor/laravel/fortify/` (routes/routes.php, src/Features.php, src/FortifyServiceProvider.php, Actions/*, Http/Controllers/*TwoFactor*, Http/Requests/TwoFactorLoginRequest.php, TwoFactorAuthenticatable.php, TwoFactorAuthenticationProvider.php, RecoveryCode.php, database migration)
- `/home/voslar/projects/family-hub/vendor/laravel/passkeys/` (README, routes, config, Actions, Http controllers/requests/responses, Support/WebAuthn.php, migration)
- `/home/voslar/projects/family-hub/vendor/web-auth/webauthn-lib/` (CheckCounter, ThrowExceptionIfInvalid, CheckUserVerification, CeremonyStepManagerFactory)
- `/home/voslar/projects/family-hub/vendor/pragmarx/google2fa/src/Google2FA.php`, `vendor/laravel/framework` (`RequirePassword`, `Redirector::guest/intended`, `UrlGenerator::previous`)
- Project code: `app/Providers/FortifyServiceProvider.php`, `AppServiceProvider.php`, `Http/Middleware/{RequireAccess,EnsureRouteDeclaresAccess,ThrottleAuthRoutes,HandleInertiaRequests}.php`, `Http/Controllers/Auth/GoogleController.php`, `Auth/{GoogleLoginService,SessionRevoker,InviteService}.php`, `Audit/*`, `routes/web/finance*.php`, `tests/Feature/Access/EndpointMatrixTest.php`, `tests/TestCase.php`, `docker/postgres/init/01-roles.sh`
- `node_modules/@inertiajs/core` 3.11.0 (`Purpose: prefetch` header)
- Packagist p2 API and `npm view` (2026-11-13): fortify v1.42.0, laravel/passkeys v0.3.0/v0.2.1, webauthn-lib 5.3.12, google2fa v9.1.0, @laravel/passkeys 0.4.1

### Secondary (MEDIUM)
- Web search on Google OIDC `prompt`/`max_age`: inconclusive, only OIDC spec summaries (authlete.com, auth0.com); Google endpoint behaviour not confirmed

### Tertiary (LOW)
- None used for recommendations

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH, all packages installed and read from vendor
- Architecture: HIGH for Fortify/passkeys wiring and gate placement; MEDIUM for browser/PWA behaviour and Google re-auth
- Pitfalls: HIGH (most derived from reading installed code and existing tests)

**Research date:** 2026-11-13
**Valid until:** 2026-12-13 (laravel/passkeys is 0.x and moving; Fortify may allow 0.3 soon, re-check before upgrading)
