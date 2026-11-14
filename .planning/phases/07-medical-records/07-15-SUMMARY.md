---
phase: 07-medical-records
plan: 15
subsystem: medical-reminders
tags: [outbox, notifications, vaccinations, privacy]
requires: [07-09, 07-13]
provides: [vaccination_due outbox kind, medical:enqueue-reminders, PlainTextMail trait]
affects: [notifications, scheduler]
key-files:
  created:
    - app/Medical/Reminders/VaccinationReminderPlanner.php
    - app/Medical/Reminders/VaccinationDueHandler.php
    - app/Medical/Reminders/VaccinationDueNotification.php
    - app/Console/Commands/EnqueueVaccinationReminders.php
    - app/Notifications/Concerns/PlainTextMail.php
    - tests/Feature/Health/VaccinationReminderTest.php
  modified:
    - app/Medical/Queries/SystemQuery.php
    - app/Notifications/Outbox/OutboxHandlers.php
    - app/Notifications/WishOccasionNotification.php
    - routes/console.php
    - lang/en|sk|pl/notifications.php
    - tests/Unit/MedicalLangTest.php
requirements-completed: [MED-05]
metrics:
  completed: 2026-11-14
---

# Phase 7 Plan 15: Vaccination due reminders Summary

Hourly `medical:enqueue-reminders` writes `vaccination_due` outbox rows only for members who can edit the person; the handler re-checks everything at send time with booleans and never throws.

## What was built
- SystemQuery: `dueVaccinations`, `vaccinationDue`, `reminderCandidateIds` (level-2 members UNION linked user with self access), `isEditor`, `subjectDisplayName`.
- Planner (lead 14 and 0, one grace day, never past) filters candidates by `AccessGate::allows(Health, Edit)`, channels per preferences and push subscriptions, dedupe key per vaccination, date, lead, user and channel; payload holds ids and dates only.
- Command gated on the family-local `medical.reminder_hour` (`--force` override), scheduled hourly with `withoutOverlapping`.
- Handler `stillRelevant`: vaccination exists, due date equals payload and is not past (family date), lead still configured, subject exists, gate Edit and `isEditor`; all wrapped so any error returns false. Gate cache is forgotten first (long-lived worker).
- Push is title-only; mail names the person and links to `/health/people/{uuid}`.
- `PlainTextMail` trait extracted from `WishOccasionNotification` (WishOccasionOutboxTest still green).

## Decisions / deviations
- **Date removed from the mail (orchestrator instruction, UI-SPEC privacy):** `:date` dropped from `vaccination_body` in en/sk/pl; the `vaccination_due_in` relative phrase is no longer used in the mail either, because it would reveal the date indirectly (the key is kept in the lang files). `MedicalLangTest` now expects only the `person` placeholder and no date. This supersedes the plan's "email names the date".
- "Active accounts": the app has no deactivation flag, so an existing user is active.
- Flaky assertion found in the full run (random uuid in the link contained "15"); the test now strips URLs before checking digits.

## Verification
VaccinationReminderTest (27), lang, arch and Notifications tests pass; full Pest, Vitest and build pass. The first full run failed only in sibling-owned export matrix tests (07-14 in progress); the re-run was clean apart from the flaky assertion fixed above.

## Commits
- c404144 planner, system queries, command, schedule
- c78a022 handler, notification, trait, lang, tests
- test fix commit follows (see git log)
