---
phase: 06-sensitive-data-account-hardening
verified: 2026-11-14T00:50:00Z
status: human_needed
score: 2/2 roadmap truths verified locally (live/real-device parts pending 06-13)
overrides_applied: 0
human_verification:
  - test: "Authenticator app enrol and sign-in on a real phone"
    expected: "QR/key enrols, challenge at sign-in accepts code and recovery code"
    why_human: "real authenticator app and device"
  - test: "Passkeys on desktop, iPhone/iPad (incl. installed PWA), Android"
    expected: "Register, sign in, re-auth, remove; per-environment rp id/origin and PASSKEYS_USER_HANDLE_SECRET set"
    why_human: "real WebAuthn authenticators; tests use fakes"
  - test: "Idle expiry after 15 min and enrolment re-auth after 10 min on a real device"
    expected: "Finance/health ask to confirm; other modules never interrupted"
    why_human: "wall-clock wait in a real browser"
  - test: "Google re-auth fallback with SECURITY_GOOGLE_REAUTH on in staging"
    expected: "Google prompt/max_age forces fresh sign-in for eligible members"
    why_human: "live Google behaviour (ASSUMED)"
  - test: "Admin requirement switch and reset across devices"
    expected: "Sessions revoked on all devices"
    why_human: "multi-device, live deployment"
  - test: "Owner decisions (Google flag in prod, prod 2FA requirement, password_set backfill, provisional allowlist/keepalive)"
    expected: "Owner confirms"
    why_human: "owner decision; plan 06-13 deferred (DEFERRED.md)"
---

# Phase 6 Verification

**Goal:** Medical and finance data protected by a second factor and idle re-authentication.
**Status:** human_needed. Automated goal evidence complete; plan 06-13 (live ops) deliberately deferred, so AUTH-09/AUTH-10 stay unticked.

## Local runs (own execution)
- Pest (container, fh06ver): 1591 passed, 16966 assertions.
- Vitest: 103 files, 2217 tests passed.
- npm run build: OK, SW-PRECACHE OK.

## Truths
| # | Truth | Status | Evidence |
|---|---|---|---|
| 1 | Enable TOTP/passkey and must use at sign-in | VERIFIED (local) | tests/Feature/Security: TwoFactorEndpointsTest (enable w/ confirmation, recovery codes, disable/regenerate), ChallengeTest (authenticator, passkey-only member challenged, pending secret not challenged, recovery code once, remember cookie after challenge), PasskeyManagementTest/PasskeyChallengeTest, GoogleSecondFactorTest, PolicyAndResetTest (requirement switch audited, admin reset revokes sessions, stale window refused) |
| 2 | Idle re-auth before medical/finance; other modules not interrupted | VERIFIED (local) | SensitiveGateMatrixTest (stale session refused on every sensitive route, fresh passes, naming safety net/mutation check), SensitiveSessionTest, IdleTimeoutTest, ReauthTest, RecentRuleTest, SignInConfirmationTest (remember-cookie restore does not open windows), SignInToFinanceTest, GoogleReauthTest (flag-gated, eligibility, forged flag rejected) |

Cross-cutting: SecurityAuditContentTest canary (secrets never in audit/logs), SecurityCacheHeadersTest (no-store), SecurityArchTest, axe/SW checks in Vitest. No TBD/FIXME markers in security middleware.

## Requirements
AUTH-09, AUTH-10: implemented and test-proven locally; remain Pending in REQUIREMENTS.md by owner decision until 06-13.

## Gaps
None blocking. Real-device, live-Google and owner-decision items above are human_needed.
