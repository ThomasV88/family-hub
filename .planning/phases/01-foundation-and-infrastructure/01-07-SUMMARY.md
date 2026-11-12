---
phase: 01-foundation-and-infrastructure
plan: 07
subsystem: i18n
tags: [vue-i18n, laravel-localization, inertia, intl, pest, vitest]
requires: [01-03]
provides:
  - SetLocale middleware (cookie -> Accept-Language -> en), POST /locale (throttle 30/min, allowlist)
  - Inertia shared props locale, availableLocales, messages (current locale only)
  - lang/{en,sk,pl}.json with key parity, lang/{en,sk,pl}/common.php trans_choice strings
  - resources/js/i18n.js (createAppI18n, setAppLocale, Intl.PluralRules plurals, date/number formats)
  - LanguageSwitcher component in BaseLayout, localized Home
affects: [01-09]
key-files:
  created: [app/Http/Middleware/SetLocale.php, app/Http/Controllers/LocaleController.php, lang/en.json, lang/sk.json, lang/pl.json, lang/en/common.php, lang/sk/common.php, lang/pl/common.php, resources/js/i18n.js, resources/js/Components/LanguageSwitcher.vue, tests/Feature/LocaleTest.php, tests/Unit/TransChoiceTest.php, tests/js/i18n.test.js, tests/js/LanguageSwitcher.test.js]
  modified: [app/Http/Middleware/HandleInertiaRequests.php, bootstrap/app.php, routes/web.php, resources/js/app.js, resources/js/Layouts/BaseLayout.vue, resources/js/Pages/Home.vue, tests/js/Home.test.js]
key-decisions:
  - "vue-i18n instance is keyed by Intl locales (en-GB, sk-SK, pl-PL) because vue-i18n passes its locale key straight to Intl.*Format"
  - "locale cookie stays Laravel-encrypted; tests use withCookie"
duration: ~25min
completed: 2026-11-13
---

# Phase 1 Plan 07: Language slice Summary

Slovak, Polish and English UI with a native-name language switcher, cookie persistence, Accept-Language first-visit detection, Intl-based plurals, dates (Europe/Bratislava) and numbers, all verified with exact-string tests.

## Tasks

| Task | Commit | Result |
|------|--------|--------|
| 1 Failing tests | 62eea1e | Vitest and Pest RED |
| 2 Server locale | adbe126 | Pest 32/32 |
| 3 Frontend i18n | b39e123 | Vitest 27/27, build ok, Pest 32/32, SMOKE-LOCAL OK |

## Deviations from Plan

**1. [Rule 1 - Bug] Accept-Language fallback.** `Request::getPreferredLanguage()` returns the first supported entry when nothing matches (so `de-DE` gave `sk`). SetLocale now first checks that the browser listed a supported language, else `en`. Still calls `getPreferredLanguage`.

**2. [Rule 3 - Blocking] vue-i18n locale keys.** `d()`/`n()` hand the instance's locale key directly to Intl, so keying by `en` would give en-US formats ("Mar 5, 2026"). The instance is therefore keyed by `INTL_LOCALES` values (`en-GB`, `sk-SK`, `pl-PL`) with `fallbackLocale` `en-GB`; `createAppI18n(locale, messages)` still takes `sk|pl|en`. Added `setAppLocale()` export used on Inertia navigation.

**3. Plural strings.** Laravel's MessageSelector already has sk and pl rules (verified in vendor); sk uses explicit ranges, pl the built-in three-form rule. Added message key `home.checks_24h` (sentence with rendered plural as a parameter) and `home.coverage`.

**4. Test env.** Vitest jsdom breaks `new URL(..., import.meta.url)`, so tests read lang files via `process.cwd()`. Verification ran under compose project `fh0107` with a scratchpad port override (18087); torn down with volumes.

## Known Stubs

None.

## Threat Flags

None. T-01-33 to T-01-35 mitigated (allowlist validation, throttle, only allowlisted codes reach `lang_path`, tampered-cookie test, no `v-html`).

## Self-Check: PASSED

Commits 62eea1e, adbe126, b39e123 exist; all listed files present.
