---
phase: 11-shopping-lists-section-n-kupy-issue-8
plan: 07
subsystem: shopping-mail
tags: [mail, rate-limit, audit, shopping]
requires: ["11-03"]
provides:
  - "POST /shopping/lists/{list}/mail (shopping.lists.mail): body {addresses: string[1..5]}, 303 back with toast key shopping.mail.toast_sent and flash_data.mail_sent_count"
  - "ShoppingListText::for(list, locale), ShoppingListMail (queued, text only), ShoppingListMailer::send and ::limitResponse"
  - "Limiter shopping-mail (10/hour, 30/day per user), per-address cap 5/day, 429 JSON body with error key shopping.mail.err_limit"
  - "AuditEvent::ShoppingListMailed (shopping_list_mailed, group account, meta recipient_count)"
affects: [11-12]
key-files:
  created:
    - app/Shopping/Mail/ShoppingListText.php
    - app/Shopping/Mail/ShoppingListMailer.php
    - app/Mail/ShoppingListMail.php
    - app/Http/Controllers/Shopping/MailController.php
    - app/Http/Requests/Shopping/MailListRequest.php
    - routes/web/shopping-mail.php
    - tests/Support/EndpointMatrix/shopping-mail.php
    - tests/Feature/Shopping/ShoppingMailTest.php
  modified:
    - app/Providers/AppServiceProvider.php
    - app/Audit/AuditEvent.php
    - lang/en/mail.php
    - lang/sk/mail.php
    - lang/pl/mail.php
metrics:
  tasks: 2
  completed: 2026-11-17
---

# Phase 11 Plan 07: E-mailing a shopping list Summary

The creator, responsible person and contributors e-mail a list to 1 to 5 typed addresses; each address gets its own queued plain-text mail in the sender's language, within per-user and per-address limits, with an audit row that holds only the recipient count.

## Commits
- 0ca93d0 plain-text builder, queued Mailable, `mail.shopping_list` strings (`Refs #8`)
- 9134e55 send endpoint, request, limiter, audit event, matrix rows, ShoppingMailTest (`Refs #8`)

## Tests
Isolated compose project `fh1107` (own Postgres, scratch folder over storage/logs via an override file), serial, removed with `down -v`. Pint on all touched PHP: pass. ShoppingMailTest: 23 passed (body unescaped, sk and pl plurals, CRLF subject, text-only message via the array mailer, role matrix, 422 keys, hourly 429, per-address 429 with nothing queued or audited). Full serial Pest suite: 3240 passed, 1 skipped (includes EndpointMatrix with the new row, SensitiveGateMatrix, Audit, Security, Manual, Help). Vitest not run: no JS, JSON lang, help or manual file touched.

## User documentation
No user-visible change in this plan: backend only, no page or dialog. The mail dialog, the manual section `email`, help tips, JSON lang strings and the audit label for `shopping_list_mailed` belong to 11-12. No `resources/manual/` file touched. sk and pl strings in `lang/sk/mail.php` and `lang/pl/mail.php` (subject, store/responsible labels, unit plural forms, bought/unavailable markers) are drafts for native review.

## Decisions and notes for later plans
- 429 is a JSON body `{message, error: "shopping.mail.err_limit"}` with Retry-After, for both the hourly/daily limiter and the per-address cap. The dialog (11-12) must read it from the failed response, not from a flash.
- Validation errors arrive under `errors.addresses` (list-level, key `shopping.mail.err_too_many` for more than 5, `shopping.mail.err_invalid` for anything malformed, empty or a CRLF payload) and `errors.addresses.N`.
- Duplicates (case-insensitive, trimmed) collapse before the count check, so six identical addresses are one send.
- The per-user throttle middleware counts every request that reaches it, including 403 and 422 refusals.
- Quantities print without trailing zeros (2.50 as 2.5); a fractional quantity uses the "many" unit form. Administrator units print their name in the sender's language.
- The audit label key for `shopping_list_mailed` is not added here (11-12 owns the JSON lang and AuditTable).

## Deviations from Plan
None of rule weight. The plan said the Mailable renders from the string "through HtmlString or {!! !!}"; HtmlString passed to `Mailable::text()` is used, so no Blade view file was needed. The two tasks were committed as builder-first, endpoint-second, with the shared test file in the second commit.

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model; T-11-28 to T-11-32 are covered by ShoppingMailTest and the matrix row.

## Self-Check: PASSED
Both commits and all created files verified present.
