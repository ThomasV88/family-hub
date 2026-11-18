---
phase: 14-rebranding-issue-13
plan: 04
subsystem: i18n-branding
tags: [rename, lang, mail, env]
requires: []
provides: [FamilyHUB product name in strings, mails, env defaults, README]
affects: [lang, config, env templates]
key-files:
  created: [tests/Feature/Notifications/BrandNameMailTest.php]
  modified: [lang/*.json, lang/*/mail.php, lang/*/notifications.php, config/app.php, phpunit.xml, .env.example, ops/env/app.env.example, scripts/server-provision.sh, README.md, resources/js/Components/Security/RecoveryCodesPanel.vue, tests]
decisions:
  - Download file name is familyhub-recovery-codes.txt
metrics:
  completed: 2026-11-18
---

# Phase 14 Plan 04: FamilyHUB rename in strings and mails Summary

User-visible "Family Hub" became "FamilyHUB" in SK, PL and EN lang files, mail templates and notifications, the APP_NAME defaults (config, phpunit, env templates, provisioning script) and README. Internal identifiers are unchanged.

## Commits
- 4f04856: lang files, mail templates and BrandNameMailTest (invite and reset mails in en/sk/pl, plus a scan of lang/ for the old name)
- 5711e86: app-name defaults, README, recovery-codes file name, test assertions

## Deviations
- The RED step was skipped for BrandNameMailTest: the lang edit and the phpunit.xml APP_NAME change were made before the first run. The mail footer reads config app.name, so the test needs phpunit.xml too. phpunit.xml and config/app.php were committed in task 2.
- No `Fixes #13` trailer: the plan does not call for one.

## User documentation
No resources/manual or resources/help file touched (plan 14-05 owns them). The manual intro string in lang/*.json (`manual.intro`) was renamed. SK and PL string changes (name only, no grammar changes beyond the plan's note) need native review.

## Verification
Pest (BrandNameMailTest, TaskReminderMailTest, HomePageTest, Invites) and the listed vitest files pass.

## Self-Check: PASSED
