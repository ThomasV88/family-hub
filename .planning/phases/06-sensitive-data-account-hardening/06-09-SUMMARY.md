---
phase: 06-sensitive-data-account-hardening
plan: 09
subsystem: ui
tags: [passkeys, webauthn, vue, settings, reauth, vitest]
requires: [06-06, 06-08]
provides:
  - Passkeys card on Settings > Security (add, name, rename, remove)
  - "Use a passkey" on the sign-in challenge and in ReauthForm (page and dialog modes)
affects: [06-13]
key-files:
  created:
    - resources/js/Components/Security/PasskeysCard.vue
    - resources/js/Components/Security/PasskeyRow.vue
    - resources/js/Components/Security/PasskeyNameDialog.vue
    - resources/js/Components/Security/PasskeyRemoveDialog.vue
    - tests/js/security/PasskeysCard.test.js
    - tests/js/security/PasskeyDialogs.test.js
  modified:
    - resources/js/Pages/Settings/Security.vue
    - resources/js/Pages/Auth/TwoFactorChallenge.vue
    - resources/js/Components/Security/ReauthForm.vue
    - resources/js/Components/Security/ReauthPrompt.vue
    - tests/js/security/SecurityPage.test.js
    - tests/js/security/TwoFactorChallenge.test.js
    - tests/js/security/ReauthForm.test.js
requirements-completed: [AUTH-09, AUTH-10]
completed: 2026-11-14
---

# Phase 6 Plan 09: Passkey UI Summary

Members can add, name, rename and remove passkeys from Settings > Security and use one at the sign-in challenge and on "Confirm it's you". No new npm packages.

## Commits
- 28998a8: passkeys card, row, name and remove dialogs, Security.vue wiring, ReauthPrompt
- 93efbe6: passkey on the challenge page and in ReauthForm

## Behaviour
- Add flow: with a stale window the confirm dialog (reauth.title, security.confirm_first, ReauthPrompt) opens BEFORE any options are fetched and focuses its first field. A 423 from options, or from store (credential dropped), reopens it and, after confirming, restarts from fresh options and a new device prompt; the old credential is never resubmitted (tested). Confirming once marks the window fresh locally. No-method members get reauth.no_method plus Sign in again. Nothing navigates to /confirm-access.
- Name dialog (add: not dismissable, Save or Skip naming with a device guess; rename: dismissable). A name error stays on the field and keeps the credential for a retry. Guidance state posts /settings/security/continue after the first passkey is saved.
- Cancelled prompt gives the calm info message; unsupported devices show no add button, only passkey.unsupported. Names render as escaped text, never in a title attribute.
- Remove dialog: Keep passkey is default focus, last-factor warning, direct DELETE when fresh, in-place confirm on 423 then retry.
- Challenge: passkey button under an "or" divider (secondary) or primary and full width for passkey-only members; 410 goes to /login, 429 locks both buttons through the shared wait timer, 422 gives the generic alert, cancel is quiet. Redirects are followed only if same-origin relative.
- ReauthForm: passkey button after the form divider and before the alternatives slot (a second divider separates it from slot content), mode page|dialog posted, reauth.passkey_waiting status line, generic error copy.
- Security.vue keeps a local passkey list (v-model from the card) so the status card switches to On immediately.

## Deviations from Plan
- **[Rule 3] ReauthPrompt** (06-06 file) counted only password or TOTP as a usable method, so a passkey-only member would have seen the no-method block. It now counts passkey too.
- **Test-expectation drift** (in the 28998a8 commit): SecurityPage.test.js expected an empty passkeys region; it now expects the card.
- **Challenge `passkey` slot removed** from TwoFactorChallenge.vue (it was a placeholder inside the page itself); its test was rewritten.
- UiDialog initial focus resolves `$el`, so the name dialog passes the real input element rather than the UiInput instance.
- Passkey-only nudge reuses security.intro (no new keys).

## Verification
- Full Vitest: 100 files, 1815 tests pass. `npm run build` OK. Full PHP suite (docker compose -p fh0609): 1576 passed, 0 failed (earlier "Cannot redeclare addPasskey()" issue from 06-08 no longer occurs). Stack torn down with `down -v`.
- Greps: no `visit('/confirm-access` in Security components; ReauthForm referenced via ReauthPrompt in PasskeysCard; endpoint strings present in both pages.
- Real authenticator behaviour is untested here (06-13 device UAT).

## Known Stubs
None.

## Self-Check: PASSED
