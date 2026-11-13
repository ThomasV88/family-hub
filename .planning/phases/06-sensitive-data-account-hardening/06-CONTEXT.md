# Phase 6: Sensitive-Data Account Hardening - Context

**Gathered:** 2026-11-13
**Status:** Ready for planning

<domain>
## Phase Boundary

Medical and finance data are protected by a second factor and idle re-authentication before the most sensitive module (medical, Phase 7) goes live. Delivers TOTP and passkey second factors with recovery, an optional admin-enforced requirement, idle re-authentication gating on health and finance routes, and audit of all security events. Finance (Phase 5) is already built and gets the gate retroactively.
</domain>

<decisions>
## Implementation Decisions

### Second factor
- TOTP app (Fortify's two-factor with recovery codes) and passkeys (WebAuthn via `laravel/passkeys`, already pulled in by Fortify); members may enable either or both in their settings.
- Optional by default. Admin can require a second factor for members who have health or finance access (setting, default off). Enforcement: members under the requirement without a factor are guided to set one up before opening sensitive modules.
- Sign-in: after password (or Google), a challenge page accepts the TOTP code, a recovery code or a passkey. Passkeys are offered as a challenge option; passkey-only first-factor sign-in is out of scope.
- Recovery: 8 one-time recovery codes shown once at setup, regenerate on demand (needs re-auth); admin can reset a member's second factor (audited, that member's sessions revoked).

### Idle re-authentication
- After 15 minutes without activity in a sensitive module (health, finance), opening sensitive data asks for password, TOTP or passkey ("confirm it's you"); other modules are never interrupted. Only activity inside sensitive modules extends the timer.
- Enforced server-side by a route middleware on all health and finance routes including JSON and file routes; Inertia navigations redirect to a confirm page and return to the original location afterwards. An endpoint-matrix style test proves every sensitive route enforces it (new routes in later phases fail the test if they forget).

### Audit
- Enable/disable of a factor, failed challenges, recovery code use, admin resets and re-auth confirmations are audited (never secrets or codes).

### Claude's Discretion
Table/class names, UI details using the Phase 01.1 design system and the Phase 2 settings shell, timer storage (session-based), WebAuthn flow details per `laravel/passkeys` docs, test organisation.
</decisions>

<code_context>
## Existing Code Insights

### Reusable Assets
- Phase 2: Fortify (headless, features `[]` plus resetPasswords; unused two-factor/passkey migrations were deleted and may need re-adding), settings shell, AccountMenu, AccessGate/access middleware (finance_read/write audit), audit logger and page, SessionRevoker, login throttling; the unused `password.confirm` Fortify routes (404/unused).
- Phase 5 finance routes (to be gated), endpoint matrix harness, leak suites.

### Established Patterns
- Inertia pages, per-feature route files and matrix files, lang keys added up front in one plan (pipe plurals), Vitest + axe sweeps in SK/PL/EN and light/dark, no-store dynamic responses, service worker never caches private data.

### Integration Points
- routes/web/*.php (apply a `sensitive` middleware group), RequireAccess middleware, settings sections (Security), login flow, admin members panel (reset 2FA, require toggle), audit events.
</code_context>

<specifics>
## Specific Ideas

Requirement text: AUTH-09 (TOTP or passkey second factor), AUTH-10 (re-auth before medical or finance data after inactivity).
</specifics>

<deferred>
## Deferred Ideas

Passkey-only first-factor sign-in, hardware key attestation policies, per-device trust ("remember this device"), SMS/email codes.
</deferred>
