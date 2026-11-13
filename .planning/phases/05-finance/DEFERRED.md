# Phase 5 deferred live-ops work (owner decision 2026-11-13)

Plan 05-21 (rebuild images incl. stale bcmath, staging/production release, device checks, the owner's anonymised bank CSVs for real-layout verification, SK/PL wording review by a native speaker) is deferred to the end with Phase 1 plans 01-10..01-12, Phase 2 plan 02-10, Phase 3 plan 03-12 and Phase 4 plan 04-13.
FIN-01..FIN-08 stay unticked in REQUIREMENTS.md until then. All end-of-phase manual UI checks in the plan SUMMARIES were not run.
Notes: `toast_kept` (import undo kept-count toast) is flashed but not shown (needs a shared prop + AppShell watcher); the Done step shows the kept count. Finance ModuleCard on Home falls back to the coming-soon panel when no financeCard prop is sent. Only generic CSV profiles ship; bank-named profiles need real statements.
