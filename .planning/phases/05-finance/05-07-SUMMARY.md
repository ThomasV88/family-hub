---
phase: 05-finance
plan: 07
subsystem: finance
tags: [admin, privacy, audit, settings, inertia]
requires: [05-01, 05-04]
provides:
  - Settings > Finance accounts (admin, metadata only) with create/update and per-account member visibility
  - AuditEvent::FinanceVisibilityChanged, one content-free row per added/removed member
  - App\Finance\Queries\AccountAdminQuery, App\Finance\Accounts\AccountAdmin
requirements: [FIN-03]
key-files:
  created:
    - routes/web/settings-finance-accounts.php
    - app/Http/Controllers/Settings/FinanceAccountsController.php
    - app/Http/Requests/Settings/FinanceAccountRequest.php
    - app/Finance/Accounts/AccountAdmin.php
    - app/Finance/Queries/AccountAdminQuery.php
    - tests/Support/EndpointMatrix/settings-finance-accounts.php
    - tests/Feature/Finance/AccountAdminTest.php
    - resources/js/Pages/Settings/FinanceAccounts.vue
    - resources/js/Finance/AccountPanel.vue
    - tests/js/settings/FinanceAccountsPage.test.js
    - tests/js/finance/AccountPanel.test.js
  modified:
    - app/Audit/AuditEvent.php
    - app/Support/SettingsSections.php
    - resources/js/Components/Settings/AuditTable.vue
    - resources/js/Layouts/SettingsLayout.vue
    - resources/js/Pages/Settings/Index.vue
decisions:
  - "hasTransactions and earliestBookedOn take a Viewer and go through Visibility (checker note), so every finance_transactions statement carries finance_account_members; opening() is Visibility-scoped too."
  - "Money-shaped fields (currency, opening_balance, balance_as_of) are `prohibited` on update unless the admin is a member AND has finance view/edit; identical 422 regardless of data."
  - "Account writes live in AccountAdminQuery (change/syncMembers/create) because the scan tests forbid save/update calls and finance table names outside the query layer."
  - "A member who lost finance access but is still ticked stays untickable-off (checkbox enabled while checked), otherwise the admin could never save."
metrics:
  completed: 2026-11-13
  tasks: 3
---

# Phase 5 Plan 07: Admin finance accounts Summary

Admins create accounts and choose which finance-enabled members see each one from Settings > Finance accounts. The screen shows metadata only (name, type, member count); opening balance and the currency-lock hint appear only for accounts the admin is ticked on and has finance access to. Every share/unshare is audited as `finance_visibility_changed` naming the member, with meta `{change: added|removed}` and no account name or id.

## Commits
- fb1f611: backend (routes, controller, request, AccountAdmin, AccountAdminQuery, audit event, section, matrix rows, AccountAdminTest) plus harness adjustments below
- 61b5c29: page, AccountPanel, AuditTable case, Wallet icon, Vitest files
- f5069e0: section-order assertion in NotificationSettingsTest

## Verification
- Full Pest 1175 passed (memory_limit 1G), Vitest 1144 passed, `npm run build` OK (SW-PRECACHE OK), Pint clean on this plan's files. Compose project fh0507 torn down.
- Acceptance greps: FinanceVisibilityChanged in AuditEvent and AccountAdmin; no balance/amount mention in the controller beyond opening fields; no MoneyAmount/formatMinor in FinanceAccounts.vue.

## Deviations from Plan
- [Rule 3] `FinancePrivacy::normalise` (05-04 harness) now blanks the Inertia `"url"` field: the write-parity test compared a hidden vs random id on the admin-only update route, and the 403 page legitimately echoes the requested path. The URL is request-controlled, so nothing is hidden by ignoring it.
- [Rule 3] `FinanceTableScanTest` allows `Support/SettingsSections.php`, which must name the route, section key and label containing `finance_accounts`.
- [Rule 1] Section counts changed for admins (4 to 5): updated `AuditPageTest`, `MembersPageTest` (Phase 2) and `NotificationSettingsTest` (Phase 3) order assertions. Added one AuditPage Vitest case for the new event.
- Query method is named `change` (not `update`) so the mutation scan does not flag `AccountAdmin::update`.
- Validation messages for money-field errors reuse `finance.acc.err_save`/`finance.error.*` keys already added in 05-01; no new translation keys.

## Known Stubs
None.

## Threat Flags
None beyond the plan's register. T-05-21..25 mitigated: `access:admin` plus literal matrix rows, no money data for unticked admins, per-change audit rows, forceFill with FormRequest-validated fields and finance-access-filtered member ids, `?acct=` unknown/malformed ids render the 403 page.

## Self-Check: PASSED
