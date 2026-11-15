# Phase 3 deferred live-ops work (owner decision 2026-11-13)

Plan 03-12 (VAPID keys per environment, staging then production release, real iPhone/iPad PWA push checks, real Stalwart email check, desktop/Android permission prompt, denied-state recovery) is deferred to the end, together with Phase 1 plans 01-10..01-12 and Phase 2 plan 02-10, which it depends on.
All "end of phase" manual UI checks in the plan SUMMARIES (phone/desktop layouts, panel behaviour, quick add, reminders) were not run.
Note: toast "Open task" action is not implemented (shared toast has no action support); flash_data.taskId is sent for a later plan.

Review-fix notes (owner/at the end): app image gained bcmath (needed by web-push); staging/production images must be rebuilt through the normal deploy and push delivery confirmed on staging. Owner to sanity-check behaviour changes: closed recurring occurrences are immutable (409), outbox retry/stuck-row handling, overdue recurrence beyond the 1000-occurrence cap, reminder staleness check.
