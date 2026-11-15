---
phase: 03-to-do
plan: 11
subsystem: notifications
tags: [settings, web-push, ios-hint, inertia]
requires: [03-10, 03-07]
provides:
  - GET /settings/notifications and PUT /settings/notifications/{channel} (mail|push)
  - Settings > Notifications section for every member
  - push.js helpers, PushDeviceRow, Notifications page
affects: [03-12 device checks]
key-files:
  created: [app/Http/Controllers/Settings/NotificationSettingsController.php, app/Http/Requests/Settings/UpdateNotificationPreferenceRequest.php, resources/js/Pages/Settings/Notifications.vue, resources/js/Components/Settings/PushDeviceRow.vue, resources/js/push.js, tests/Feature/Notifications/NotificationSettingsTest.php, tests/js/push.test.js, tests/js/settings/Notifications.test.js]
  modified: [app/Support/SettingsSections.php, routes/web/notifications.php, tests/Support/EndpointMatrix/notifications.php, resources/js/Layouts/SettingsLayout.vue, resources/js/Pages/Settings/Index.vue, tests/Feature/Settings/SettingsShellTest.php, tests/js/settings/SettingsShell.test.js]
decisions:
  - Preference saves rely on the server flash toast (notifications.saved) rather than a second client toast
  - Enabling push on a device also PUTs the push preference to true; turning the device off leaves it alone
metrics:
  tasks: 2
  commits: [7f101c5, plus test commit "test(03-11)" and docs]
---

# Phase 3 Plan 11: Notification settings Summary

Members choose email and push reminders in Settings > Notifications (saved immediately, optimistic with revert) and enable, test and disable push per device, with the iOS install hint.

## What was built
- Preferences endpoints write only the signed-in member's own row; channel restricted by route (`sms` gives 404); body validated as strict boolean; only the public VAPID key reaches props.
- Section `notifications` (bell icon) for all members, ordered members, audit, notifications, appearance.
- push.js: support and iOS-not-installed detection (including iPadOS as Macintosh with touch), enable (the only `requestPermission` call), disable, sendTest, resync (drops a subscription made with another VAPID key, else re-posts; never prompts), XSRF header from cookie.
- PushDeviceRow shows one state at a time in a polite live region: unsupported, iOS hint (no button), push unavailable, denied, off, on (test and turn off).
- Tests: Pest (props, defaults, public key only, own-row writes, 404, validation, section order, matrix rows), Vitest (push.js, page states, axe in en/sk/pl light/dark across device states).

## Deviations
- [Rule 1] Adding a section changed the section count asserted in `tests/Feature/Settings/MembersPageTest.php` and `tests/Feature/Audit/AuditPageTest.php` (3 to 4). Updated those expectations; both files are outside the plan's list.
- axe was run in jsdom at one viewport; the 390/1280 px visual matrix was not run in a browser.

## Verification
Full Vitest (596 passed), build SW-PRECACHE OK, full Pest (546 passed), Pint clean on this plan's files (whole-repo Pint has known pre-existing failures in other files, not run repo-wide). No package.json change.

## Pending human checks (for 03-12)
- Real permission prompt on desktop Chrome/Firefox and Android.
- iPhone/iPad: hint shown in Safari tab; after Add to Home Screen the Enable button prompts and a test notification arrives; iPadOS desktop-mode user agent detection.
- Denied state recovery after re-allowing in browser settings.

## Known Stubs
None.

## Self-Check: PASSED
