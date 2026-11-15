---
phase: 03
slug: to-do
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-11-13
---

# Phase 03 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4 (PHP, in app container) + Vitest (frontend) |
| **Config file** | phpunit.xml, vitest.config.js |
| **Quick run command** | `docker compose run --rm app ./vendor/bin/pest --compact` |
| **Full suite command** | `docker compose run --rm app ./vendor/bin/pest && npx vitest run` |
| **Estimated runtime** | ~03 seconds |

---

## Sampling Rate

- **After every task commit:** Run `docker compose run --rm app ./vendor/bin/pest --compact`
- **After every plan wave:** Run `docker compose run --rm app ./vendor/bin/pest && npx vitest run`
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** 03 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 03-01-01 | 01 | 1 | REQ-{XX} | T-03-01 / — | {expected secure behavior or "N/A"} | unit | `{command}` | ✅ / ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `{tests/test_file.py}` — stubs for REQ-{XX}
- [ ] `{tests/conftest.py}` — shared fixtures
- [ ] `{framework install}` — if no framework detected

*If none: "Existing infrastructure covers all phase requirements."*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Not-installed iPhone Safari shows the install hint and no enable button (03-12 check 1) | TODO-06 | iOS Web Push needs a real device and the Home Screen install | Staging, synthetic data: open Settings > Notifications in Safari without installing; expect the install hint and no enable button |
| Installed iPhone PWA enables push and receives the test notification with title only (03-12 check 2) | TODO-06 | Push on an installed iOS PWA cannot be automated | Share > Add to Home Screen, open the app, sign in, Settings > Notifications > Enable push on this device, allow, Send test notification |
| Timed reminder push arrives on a locked phone without notes, tap opens the task (03-12 check 3) | TODO-06 | Real push delivery and lock-screen behaviour | Create a task due in 3 minutes, reminder "At due time", assigned to yourself; lock the phone; push "Task due: {title}" arrives within about 2 minutes of due, shows no notes, tap opens the panel |
| Same push checks on Android Chrome or desktop (03-12 check 4) | TODO-06 | Real browser push services | Repeat checks 2 and 3 on Android Chrome or a desktop browser |
| Board, quick add, panel edit, card checkbox, Personal privacy and monthly-on-31 clamp on phone, iPad and desktop (03-12 check 5) | TODO-01, TODO-02, TODO-03, TODO-04, TODO-05 | Touch layout and real-device rendering | Quick add from the Overview, edit in the panel, tick a card; a Personal task is invisible to a second member and the admin; complete a monthly-on-31 task and see the clamped next date |
| Reminder email rendered through the staging log mailer (03-12 check 6) | TODO-06 | Staging mail uses the log driver on the server | Check the worker log shows the reminder mail sent through the log mailer for the recipient; do not print secrets |

*If none: "All phase behaviors have automated verification."*

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 03s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** {pending / approved YYYY-MM-DD}
