# Phase 2 deferred live-ops work (owner decision 2026-11-13)

Plan 02-10 (Stalwart mailbox + reachability check, Google OAuth clients per environment, staging then production rollout, first admin creation on servers, audit prune cron, owner sign-off on the human checks) is deferred to the end together with Phase 1 plans 01-10..01-12, which it depends on.
AUTH-01 (real email delivery) and AUTH-08 (append-only privileges on the real DB role, 2-year prune) stay unticked until then. End-of-phase manual UI checks in each plan SUMMARY were not run.

Owner to confirm at the end: login lockout policy (WR-04): strict limits 5/min and 10/15min keyed on email+IP, plus a cross-IP per-email ceiling of 50/hour that raises an audited Lockout. Also: the audit_logs migration was edited in place (extra DELETE/TRUNCATE triggers); any DB that already ran it needs migrate:fresh or manual triggers.
