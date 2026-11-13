# Phase 6 deferred live-ops work (owner decision 2026-11-13)

Plan 06-13 (staging/production release, per-environment PASSKEYS_USER_HANDLE_SECRET and rp id/origin, real-device authenticator and passkey UAT incl. the iPad PWA, Google re-auth behaviour check with SECURITY_GOOGLE_REAUTH temporarily on staging, owner decisions) is deferred to the end with the earlier deferred live plans.
AUTH-09 and AUTH-10 stay unticked in REQUIREMENTS.md until then.
Owner confirmations needed there: (1) Google re-auth fallback flag value for production (default off); (2) turning on the production second-factor requirement; (3) password_set backfill assumption (audit_logs never pruned); (4) PROVISIONAL decisions: Home still-to-pay finance card visible when the session is stale, admin finance-account settings not gated, no import-wizard keepalive.
Note: NoStoreDynamicResponses now runs first in the web middleware append list (bootstrap/app.php) so access-rule refusals are no-store.
