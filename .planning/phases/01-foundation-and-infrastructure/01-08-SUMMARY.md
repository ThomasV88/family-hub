---
phase: 01-foundation-and-infrastructure
plan: 08
status: partial
requirements: [FOUND-01, FOUND-04, FOUND-05]
---

# Plan 01-08 Summary: CI workflow and provisioning script

Done: Task 1 (`.github/workflows/ci.yml`, `scripts/check-secrets.sh`, commit c0563bd) and Task 2 (`scripts/server-provision.sh` + dry-run test, commit 6475ca0).

**Deferred by owner (2026-11-13):** Task 3 (owner GitHub setup: deploy key, `staging`/`production` environments, DEPLOY_* secrets, Actions settings). Owner: "staging a produkciu teraz preskoc, poriesime to spolu na konci". Do NOT push `main`/`develop` until this is done, because the push triggers the deploy job.
