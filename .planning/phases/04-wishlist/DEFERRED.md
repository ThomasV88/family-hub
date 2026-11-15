# Phase 4 deferred live-ops work (owner decision 2026-11-13)

Plan 04-13 (staging/production release, server checks of gd/uploads volume/backups/schedules, real shop image fetches, iPhone upload, device privacy check, real reminders, PL name-day spot check) is deferred to the end with Phase 1 plans 01-10..01-12, Phase 2 plan 02-10 and Phase 3 plan 03-12.
All end-of-phase manual UI checks in the plan SUMMARIES were not run.
Notes: app image gained gd/exif; staging/prod images need rebuild via deploy; uploads volume + uploads snapshots in backup must be confirmed on the server. Polish name days come from Polish Wikipedia (CC BY-SA), attribution shown in the app. Create-mode image upload is disabled until the wish exists (reopen to add image).

Review-fix notes (owner/at the end): consider raising app/worker mem_limit on the server (image decodes are now serialized; measured 59 MB peak for a 13 MP image); check the gift-reminder plain-text email in a real mail client; no two-connection test of the manager-designation vs reserve race (lock-based design only).
