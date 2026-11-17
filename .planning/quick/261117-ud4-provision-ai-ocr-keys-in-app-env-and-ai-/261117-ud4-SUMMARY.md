# Quick 261117-ud4 Summary

Provisioning now writes `AI_OCR_ENABLED=false` and `AI_OCR_TEXT_RELIABILITY=reliable` into app.env and `AI_OCR_ENABLED=false` into ai-worker.env, fixing the CI "Provisioning dry run" failure and the ai-worker runtime gap.

## Changes (commit b4dd0a6)
- `scripts/server-provision.sh`: keys added to generated app.env and ai-worker.env.
- `ops/env/ai-worker.env.example`: `AI_OCR_ENABLED=false` with the equal-value comment.
- `scripts/test-server-provision.sh`: asserts the defaults in both files (the existing `check_keys` now passes).
- `docs/ops/server.md`: both files must carry the same `AI_OCR_ENABLED` and why; manual step for existing servers; the old "never in ai-worker.env" statement corrected.

## Findings
- `AI_OCR_TEXT_RELIABILITY` is read only by `PersonPage` (web), so it is not added to the ai-worker.
- `check-compose.sh`, `Preflight` and the dev ai-worker env needed no change (they list forbidden names only; a missing key means false).

## Verification
- `scripts/test-server-provision.sh`: PROVISION-DRYRUN OK. `check-compose.sh`: OK. `check-nginx-template.sh`: OK.
- Full Pest serial (own project `fhud4`, scratch logs): 4005 passed, 1 skipped, 0 failed. Pint `--test`: pass (1240 files). Project and volumes removed.

## Deviations
None. No user-visible change (ops only), no `resources/manual/` file touched.
