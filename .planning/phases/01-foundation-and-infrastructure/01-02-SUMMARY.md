---
phase: 01-foundation-and-infrastructure
plan: 02
status: complete
requirements: [FOUND-01, FOUND-04]
---

# Plan 01-02 Summary: Owner decisions and DNS

- Owner accepted recommended defaults (hostnames `family-hub.vostack.dev` / `family-hub-stage.vostack.dev`, memory limits, retention, restic password handling, pre-migrate dumps in restic, no off-site copy in Phase 1).
- Decision changed by owner: repo stays **public** (no GitHub paid plan; required-reviewer environments need public repo on Free). Mitigation: secret scan in CI, no secrets/real data in git.
- Owner created DNS records; `dig` shows Cloudflare IPs for both hosts.
- Runbook: `docs/ops/server.md`.
