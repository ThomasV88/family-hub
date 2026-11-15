---
phase: 06-sensitive-data-account-hardening
plan: 03
subsystem: ui
tags: [i18n, vue, webauthn, passkeys, vitest]
requires: []
provides:
  - Phase 6 copy (156 keys) in SK/PL/EN
  - OtpInput, RecoveryCodeInput, PasskeyButton components
  - webauthn.js (passkeysSupported, getAssertion, createCredential, b64uToBuf, bufToB64u)
  - securityFetch (JSON client with 423/429/422 mapping)
affects: [06-04, 06-05, 06-06, 06-07, 06-08, 06-09, 06-10, 06-11]
tech-stack:
  added: []
  patterns: [pipe plurals with t(key,{n},n), single native one-time-code input]
key-files:
  created:
    - tests/js/i18n-phase6.test.js
    - resources/js/Components/Security/OtpInput.vue
    - resources/js/Components/Security/RecoveryCodeInput.vue
    - resources/js/Components/Security/PasskeyButton.vue
    - resources/js/Security/webauthn.js
    - resources/js/Security/securityFetch.js
    - tests/js/security/primitives.test.js
    - tests/js/security/webauthn.test.js
    - tests/js/security/securityFetch.test.js
  modified: [lang/en.json, lang/sk.json, lang/pl.json]
key-decisions:
  - "members.security.reset_body_required instead of reset_suffix keys (no empty strings)"
  - "securityFetch refuses non-relative URLs and reads Retry-After (seconds) for 429 minutes, min 1"
requirements-completed: [AUTH-09, AUTH-10]
duration: 12min
completed: 2026-11-13
---

# Phase 6 Plan 03: Copy and client primitives Summary

All Phase 6 strings in SK/PL/EN with a parity and plural test, plus the shared inputs, passkey button, hand-written WebAuthn helper and security fetch helper (no new packages).

## Tasks

| Task | Commit | Result |
|------|--------|--------|
| 1. Copy + parity test | f1a5c44 | 156 keys x 3 languages; plurals verified at 0,1,2,4,5,12,22,25 for all 5 pipe keys |
| 2. Inputs, button, helpers | a6999fa | 26 tests; full Vitest 90 files / 1635 tests green |

## Notes

- Amendment keys included: reauth.google, reauth.google_failed, security.confirm_first, members.security.confirm_first, reauth.use_password, passkey.error.name, passkey.default_name.
- `i18n.js` unchanged; existing Intl-based pluralRules produce the specified indices.
- OtpInput/RecoveryCodeInput show the error via UiField (visible, aria-describedby) plus a visually-hidden `role="alert"` copy, because UiField's own error line is not a live region and UiField was not modified.
- PasskeyButton wraps UiButton; busy sets `aria-busy` without the loading spinner so the label stays; clicks are ignored while busy.
- securityFetch result: `{ok, status, data}` or `{ok:false, status, error, data, minutes?, errors?}` with error in confirm_required | reauth_required | second_factor_required | throttle | invalid | network | failed.

## Deviations from Plan

None - plan executed as written.

## Known Stubs

None.

## Self-Check: PASSED
