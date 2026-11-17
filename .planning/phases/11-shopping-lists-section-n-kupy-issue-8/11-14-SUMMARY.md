---
phase: 11-shopping-lists-section-n-kupy-issue-8
plan: 14
subsystem: release-gate
tags: [i18n, a11y, gate, shopping]
requires: ["11-09", "11-13"]
provides:
  - "Final locale parity for the phase (lang JSON, PHP mail and notification strings, help registry and limits, manual section order)"
  - "Pseudo-long layout test for the three Shopping pages"
  - "Shopping pages in the accessibility sweep"
key-files:
  modified:
    - tests/js/i18n-phase11.test.js
    - tests/js/a11y-sweep.test.js
  created:
    - tests/js/shopping/PseudoLong.test.js
status: PARTIAL - Tasks 1 and 2 done; Task 3 (staging browser verification, 14 steps) pending the orchestrator
---

# Phase 11 Plan 14: Final parity, a11y sweep and full gate (Tasks 1-2 only)

Tasks 1 and 2 are done. Task 3 (the 14-step staging check on synthetic data) is NOT done: the orchestrator pushes `develop`, waits for the staging deploy and runs it. This plan is not complete until then.

## Commits
- 41bdc4f test(11-14): final locale parity and pseudo-long layout check for Shopping (Refs #8)
- 09eac99 test(11-14): add Shopping pages to the accessibility sweep (Fixes #8) - the last code commit of the phase

## What was built
- `i18n-phase11.test.js`: added PHP string parity by key extraction (`shopping_list` block in `lang/{en,sk,pl}/mail.php` with the same keys, non-empty, same `:placeholders`; `shopping_reminder_title|body|body_no_store` in `notifications.php`), the eight help topics registered in `topics.json` and every help text at most 400 characters in all three languages, and the manual `shopping.json` section ids in the final order (overview, create-list, add-items, people, lock-and-shop, finish, copy, email, reminder, stores-catalog, units) in en, sk, pl. The earlier lang JSON parity (whole `shopping` subtree, explicit keys, plural placeholders) stays.
- `PseudoLong.test.js`: Shopping/Show (open and locked, 390 px), Shopping/Index and Settings/Shopping mounted with every string at least 40 percent longer; long item, list, catalog and store names render whole (no truncate, ellipsis or line-clamp element holds them) and buttons carry no `whitespace-nowrap`.
- `a11y-sweep.test.js`: added Shopping/Index (lists and empty), Shopping/Show (open, locked, done) and Settings/Shopping (admin) with mocked props, in en, sk, pl x light, dark x 390, 1280 px (the sweep grew to 158 cases, all with no axe violations). The shared Inertia mock now also provides `flash`, `availableLocales`, `isAdmin` and the Shopping grant, plus a `matchMedia` stub.
- No lang, help or manual file needed a fix: no key was missing in any language.

## Gate results (isolated compose project fh1114, own Postgres, scratch folder over storage/logs, serial; removed with `down -v`, no containers left)
- Pint `--test`: pass (1057 files)
- Full Pest: 3265 passed, 1 skipped (32537 assertions)
- Full Vitest: 5465 tests passed (166 files, includes ManualCoverage, HelpCoverage, HelpPlacement, i18n-phase11, PseudoLong, a11y-sweep)
- `npm run build` with SW-PRECACHE OK (built into a scratch folder bind-mounted over the root-owned `public/build`; `public/sw.js`, which is gitignored, was rewritten and references the new asset names)
- check-nginx-template.sh: OK; check-compose.sh: OK; check-upload-limit.sh: OK (upload 302, profile 302, shopping 302, other 413, medical 302, medicalother 413)

## Deviations
None of rule weight. Note: the plan listed only `a11y-sweep.test.js` for Task 2 and the two other files for Task 1; this is what was committed.

## User documentation
No user-visible change in this plan (tests only). Files touched in Phase 11 (all sk and pl texts are drafts for native review):
- Manual: `resources/manual/{en,sk,pl}/admin.json` (11-01), `shopping.json` (11-06, 11-10, 11-11, 11-12, 11-13), `who-sees-what.json` (11-10), `getting-started.json` (11-12)
- Help: `resources/help/topics.json`, `resources/help/{en,sk,pl}.json` (11-06 topics catalog/store/units, 11-12 roles/reminder/email, 11-13 lock/carry_over)
- Lang JSON: `lang/{sk,pl}.json` subtrees `shopping.*` (settings, image, unit, overview, create, copy, status, toast, error, detail, item, delete, picker, panel, reminder, mail, lock, mark, finish, archived, progress), `nav.shopping`, `module.shopping`, `settings.section.shopping`, `permissions.module.shopping`, `permissions.preset.*_hint`, `notifications.intro`, `audit.detail.shopping_list_mailed`
- PHP strings: `lang/{sk,pl}/mail.php` (`shopping_list`: subject, store and responsible labels, unit plural forms, bought and unavailable markers), `lang/{sk,pl}/notifications.php` (`shopping_reminder_*`)

### Drafts needing native review (sk and pl)
1. Manual: `resources/manual/{sk,pl}/shopping.json`, `admin.json`, `who-sees-what.json`, `getting-started.json`
2. Help: `resources/help/sk.json`, `resources/help/pl.json` (eight `shopping.*` topics, area and screen names)
3. `lang/sk.json`, `lang/pl.json` (terms used: sk "Nákupy", pl "Zakupy"; three-form plurals of "Add {n} items", "{count} items added", `progress.left`, `audit.detail.shopping_list_mailed`)
4. `lang/{sk,pl}/mail.php` and `lang/{sk,pl}/notifications.php`

## Known leftovers from earlier plans (no action taken, plan does not ask for removal)
- Defined but unused or unwired lang keys: `shopping.detail.archived` (superseded by `shopping.archived.banner`), `shopping.error.forbidden` (a 403 uses the standard error page); the planned `shopping.image.remove_*` strings were never added (removal applies on Save).
- 11-08: the reminder push shows only the title "Time to shop" and opens the list; the body stays at handler level because `SendOutboxItem::sendPush` forwards no body by privacy rule. Showing it needs a sender and service worker change (owner decision).
- 11-10: six modules fit the phone bottom bar for a member with every grant; the 360 px visual check is part of Task 3 step 1.

## Decisions record for the owner (issue section 8, GH8-SC10; for the PR body)

| Topic | Decision |
|---|---|
| Archived lists | Read-only; copy and e-mail stay allowed; no delete |
| Delete a list | Only while open, by the creator or the responsible person |
| Responsible person removed or lost Shopping edit | Creator acts as responsible (owner default) and gets an alert; the stored person still sees the list read-only |
| Catalog item deleted | List items stay as plain text |
| Store deleted | Lists lose the store; finished lists keep its name |
| Custom unit deleted | Quantity is shown without a unit |
| Copy | Always keeps the store; with the default checkbox also keeps responsible person and access; unchecked: the copier is responsible, no contributors or viewers, items unmarked |
| Carry-over (finish with unbought items) | Keeps store, responsible person and access; not the reminder |
| E-mail | Only creator, responsible and contributors (not viewers); max 5 addresses; 10 per hour and 30 per day per user, 5 per day per address; plain text without links or member addresses; audit log stores the count only |
| Reminder | Push only; the responsible person must have push turned on (alert otherwise); a past time is refused; the push shows only a short title |
| Starter catalog | None on production (staging seed only, synthetic data) |
| Phone navigation | Six items in the bottom bar |
| Live refresh while shopping | None; a reload shows the marks |
| Quantity | 0 < q <= 999.99, two decimals, decimal comma accepted |
| Image upload | 8 MB nginx limit on catalog and store image routes; private, no-cache, never publicly served |

Deferred owner suggestions, not built: starter catalog, "add again" from a finished list, store aisles, a "needed soon" list, prices.

## Known Stubs
None.

## Pending
Task 3 (orchestrator): push `develop`, wait for the staging deploy of this HEAD, run browser steps 1-11 (360, 390, 820, 1280 px; en plus one pass in sk and pl) and read-only server checks 12-14 (status counts and a carry list, `schedule:list` shows `shopping:enqueue-reminders`, `staging:audit`), recording pass or fail per step. Any fix after that carries `Refs #8`.

## Self-Check: PASSED
Both commits and the three test files verified present.
