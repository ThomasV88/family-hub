---
phase: 11-shopping-lists-section-n-kupy-issue-8
plan: 12
subsystem: shopping-list-settings-ui
tags: [shopping, vue, people, reminder, mail, audit, help, manual]
requires: ["11-07", "11-08", "11-11"]
provides:
  - "Shopping/ListSettingsPanel.vue, PeopleField.vue, ListReminderField.vue, EmailListDialog.vue"
  - "Show.vue overflow menu: List settings, Send by e-mail (abilities.mail), Copy list, Delete list; takeover alert for the creator"
  - "Lang shopping.panel.*, shopping.reminder.*, shopping.mail.*, shopping.error.{member_not_eligible,member_overlap,reminder_past,reminder_invalid}, shopping.toast.{settings_saved,responsible_changed}, audit.detail.shopping_list_mailed, reworded notifications.intro"
  - "Help topics shopping.roles, shopping.reminder, shopping.email; manual sections people, email, reminder"
affects: [11-13, 11-14]
key-files:
  created:
    - resources/js/Shopping/ListSettingsPanel.vue
    - resources/js/Shopping/PeopleField.vue
    - resources/js/Shopping/ListReminderField.vue
    - resources/js/Shopping/EmailListDialog.vue
    - tests/js/shopping/ListSettingsPanel.test.js
    - tests/js/shopping/EmailListDialog.test.js
  modified:
    - resources/js/Pages/Shopping/Show.vue
    - resources/js/Shopping/ListHeader.vue
    - resources/js/Components/Settings/AuditTable.vue
    - app/Http/Controllers/Shopping/ListController.php
    - app/Http/Controllers/Shopping/MailController.php
    - lang/en.json
    - lang/sk.json
    - lang/pl.json
    - resources/help/topics.json
    - resources/help/en.json
    - resources/help/sk.json
    - resources/help/pl.json
    - resources/manual/en/shopping.json
    - resources/manual/sk/shopping.json
    - resources/manual/pl/shopping.json
    - resources/manual/en/getting-started.json
    - resources/manual/sk/getting-started.json
    - resources/manual/pl/getting-started.json
    - tests/js/shopping/ShowPlanMode.test.js
    - tests/js/settings/AuditPage.test.js
    - tests/js/i18n-phase11.test.js
    - tests/Feature/Shopping/ListHttpTest.php
    - tests/Feature/Shopping/ShoppingMailTest.php
metrics:
  tasks: 2
  completed: 2026-11-17
---

# Phase 11 Plan 12: List settings, people, reminder and e-mail Summary

From the list menu people manage who is responsible, who may add items and who may only view, set or remove the reminder and send the list by e-mail to up to 5 typed addresses, with the audit log showing only the recipient count and the notifications intro no longer limited to tasks.

## Commits
- dbb9344 panel, people and reminder fields, e-mail dialog, menu wiring, audit label, lang, help, manual, tests (`Refs #8`). Tasks 1 and 2 are one commit: both edit `Show.vue` and the three lang files, and ManualCoverage and the help coverage need the manual, help and lang parts together.

## Behaviour
- Panel (`UiDetailPanel`, overlay on the page): fields editable per `abilities.editSettings`, `manageMembers`, `changeResponsible`, `setReminder`; others `readonly` or disabled and not sent. Save is disabled until dirty and posts only the changed groups (one PUT: `name`, `store_id`, `responsible_id`, `contributor_ids` plus `viewer_ids`, `remind_at`). People who may not edit anything see no Save and a read-only note, people and chips still visible.
- One role per person: adding someone from the other group moves them with the hint "{name} moved to viewers." (or contributors); choosing a new responsible person drops them from both groups client side (the server would otherwise answer `member_overlap`); the creator and the responsible person are never offered. Children carry the Child tag.
- Reminder: `datetime-local` in the family time zone (the first 16 characters of the family-zone ISO string), "Remove reminder" sends null, a past time shows the server key `shopping.error.reminder_past` as the field error, the push-off info alert appears only when `responsiblePushEnabled === false`. Header shows "Reminder: date (sent)" once consumed.
- Takeover: the info alert "You are now responsible for this list because {name} was removed." (generic variant without a name) shows in the page above the list while `responsibleFallback` is present (the server sends it to the creator only).
- E-mail dialog: chips committed on Enter, comma, space, semicolon, paste and blur; client pattern plus server `email:rfc`; invalid text stays in the field with "“foo” is not a valid e-mail address."; the sixth address is not accepted, the field hides and the hint shows; Send is disabled with zero valid addresses and shows a spinner; per-address 422 errors mark the chip; 429 JSON shows the warning alert (the visit's `onHttpException` returns false so Inertia's error window never opens). Menu entry only when `abilities.mail`, in every status.
- Audit: `shopping_list_mailed` renders "Shopping list e-mailed to {n} addresses" from `meta.recipient_count` only (plural forms in en, sk, pl); a test proves an address in the meta never prints.

## Tests
Isolated compose project `fh1112` (own Postgres, scratch folder over storage/logs via an override file), serial, removed with `down -v`. Pint on touched PHP: pass. Full Vitest: 163 files passed (new ListSettingsPanel with 12 cases, EmailListDialog with 14, ShowPlanMode extended to 26, AuditPage, i18n-phase11, ManualCoverage, HelpCoverage, HelpPlacement; axe for the panel editable and read-only and for the e-mail dialog with chips, error and limit, in en, sk, pl, light and dark, pseudo-long text). `tests/Feature/Manual`, `Help` and `Shopping`: 335 passed. Full serial Pest suite: 3265 passed, 1 skipped.

## User documentation
Manual: new sections `people` (after `add-items`), `email` and `reminder` (after `copy`) in `shopping.json`, topic summary extended; `getting-started.json` section `notifications` now says reminders come for tasks, shopping lists and gift occasions and that push shows only a short title. Contextual help: topics `shopping.roles` and `shopping.reminder` (screen `shopping_list_panel`), `shopping.email` (screen `shopping_email`), texts in en, sk, pl under 320 characters; `HelpTip` placed on the Responsible person field, the Reminder field and next to the preview line of the dialog.

Touched `resources/manual/` files; sk and pl are drafts for native review:
- resources/manual/en/shopping.json, resources/manual/sk/shopping.json, resources/manual/pl/shopping.json
- resources/manual/en/getting-started.json, resources/manual/sk/getting-started.json, resources/manual/pl/getting-started.json

Touched help files (sk and pl drafts for native review): resources/help/topics.json, resources/help/en.json, resources/help/sk.json, resources/help/pl.json.

Also for native review: `shopping.panel`, `shopping.reminder`, `shopping.mail` subtrees, the four `shopping.error` and two `shopping.toast` texts, `audit.detail.shopping_list_mailed` (three-form plurals) and `notifications.intro` in `lang/sk.json` and `lang/pl.json`. The manual says the push "says only Time to shop and opens the list", matching the 11-08 decision that the body does not reach the lock screen.

## Deviations from Plan
- **[Rule 1/2 - toasts] Two controllers modified (not in `files_modified`).** `ListController::update` flashed `shopping.toast.list_saved`, a key nobody defined; it now flashes `settings_saved`, or `responsible_changed` with the new responsible person's name when only the responsible person was sent. `MailController` flashed the toast without the top-level `count` AppShell needs for the plural, so sk and pl would pick a wrong form; it now carries `count` and `params.count`. `ListHttpTest` and `ShoppingMailTest` assert both.
- **Header "sent" marker:** `ListHeader.vue` (not in `files_modified`) shows "(sent)" through the new key `shopping.reminder.header_sent`.
- **Help tip for the e-mail dialog sits beside the preview line, not in the title:** `UiDialog` takes a plain-string title with no slot; the tip is labelled with the dialog title.
- **Takeover alert lives on the page, not inside the panel,** to avoid two identical alerts; it is rendered for the creator from `responsibleFallback`.
- **Lang keys beyond the owned list:** `shopping.error.reminder_invalid` (returned by the 11-05 request), `shopping.mail.{failed,invalid_token,remove,hint_max,placeholder}`, `shopping.reminder.sent_note`, and `shopping.mail.err_invalid` reads "Enter valid e-mail addresses." (the “{text}” form is the client-side `invalid_token`, because the server list-level key carries no address).
- The overflow menu is now always rendered (List settings is always present), so the 11-11 test "no menu without abilities" was updated.

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model. T-11-45: read-only inputs mirror abilities and unchanged or forbidden groups are never sent (server re-validates, 11-03); T-11-46: one push boolean is rendered only when the server sent it; T-11-47: the audit label prints the count only (AuditPage test with an address in the meta); T-11-48: the UI caps at 5 addresses and shows the 429 message. Names render by text interpolation (test with `<img onerror>`).

## Self-Check: PASSED
