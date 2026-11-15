---
phase: 08-ai-extraction-platform
verified: 2026-11-14T14:00:00Z
status: human_needed
score: 4/4 roadmap success criteria verified in code and tests (fake CLI); live Codex run deferred
gaps: []
human_verification:
  - test: "Plan 08-22 live ops: owner decisions A, B, C, ChatGPT data-controls check, ai-worker.env per stack, real `codex login --device-auth` per stack, one real run on a synthetic image and multi-page PDF, live-session checklist 1-8"
    expected: "Login health shows Logged in; extraction returns a schema-valid proposal; accept, edit-accept, reject/undo and verify work against real Codex output; tools really disabled; memory fits 384m"
    why_human: "Needs the live server, a real subscription login and the owner; deferred by owner (DEFERRED.md). Only the fake CLI (tests/Support/FakeCodex.php) has been exercised."
  - test: "Run the Codex CLI under its own uid (WR-06), container hardening (IN-06), narrower auth-failure patterns (IN-03)"
    expected: "docker compose exec -u codexrun ai-worker ls /var/www/html/storage/app/medical fails; caps dropped"
    why_human: "Needs a root-capable container and a real expired-login transcript; recorded in DEFERRED.md"
  - test: "Real-device review screen (phone, iPad, desktop) with PDF pager; native SK/PL review of AI copy and the two copy tasks in DEFERRED.md"
    expected: "Side-by-side layout usable on each device; copy reads correctly"
    why_human: "Real devices and native-language review"
---

# Phase 8: AI Extraction Platform Verification Report

**Phase Goal:** A user can have a medical document read by AI into structured data and review it side by side with the document before anything is saved to the record.
**Status:** human_needed (no code gaps; live-server, real-login and device items deferred by owner)
**Re-verification:** No, initial verification

## Automated runs (executed by the verifier, not taken from SUMMARY)

| Check | Command | Result |
|---|---|---|
| Full Pest | `docker compose -p fh0823v run --rm app php -d memory_limit=1G ./vendor/bin/pest` | 2309 passed (26081 assertions), 224.7 s, exit 0, no failures |
| Full Vitest | `node:22-alpine`, `npx vitest run --testTimeout=30000` | 130 files, 4288 tests passed, exit 0 |
| Build | `npm run build` (node:22-alpine) | exit 0, SW-PRECACHE OK |
| Compose check | `scripts/check-compose.sh` | CHECK-COMPOSE OK, exit 0 |
| Deploy guards | `scripts/test-deploy-guards.sh` | 25 cases passed, 0 failed, GUARDS OK, exit 0 |
| Cleanup | `docker compose -p fh0823v down -v` | containers, network and volumes removed; working tree clean |

## Observable Truths (ROADMAP success criteria)

| # | Truth | Status | Evidence |
|---|---|---|---|
| 1 | On-demand run, only with consent, background, locked-down worker, Codex subscription login, no per-token API | VERIFIED in code (live login human_needed) | `routes/web/health-ai.php` POST `/health/people/{person}/documents/{document}/ai` with `access:module,health,edit` and throttle. `AiRunController::run` calls `AiRunQuery::start`, which in one transaction locks the subject FOR SHARE, applies the Edit scope, and returns `consent_off` unless `AiConsent::allows($subjectId)` (reads DB, fail-closed). Also checks document kind, agent availability, one-time OpenAI notice, active run and busy limits. Job is dispatched after commit (`Bus::dispatch(RunExtractionJob)`). `RunExtractionJob::recheck` re-verifies starter, Health Edit, membership and `AiConsent::allows` before the CLI and again after it, and during the run. `CodexRunner` builds the command under `env -i` allowlist, `setsid`, `--sandbox read-only --ephemeral --ignore-user-config`, `features.shell_tool=false`, `--output-schema`, `-o`. `Preflight::envKeysClean` fails the run if any `*_API_KEY`/foreign secret is present. compose `ai-worker` is a separate service with its own `ai-worker.env`, `CODEX_HOME=/codex` volume mounted only there, `queue:work --queue=ai-control,ai`, `mem_limit 384m`, `ai:preflight` at start. Only the fake CLI was run; a real Codex login is deferred (08-22). |
| 2 | Exact JSON, validated against a versioned schema, stored as a proposal not applied to the record | VERIFIED | `ExtractionSchema::SCHEMA_VERSION`/`PROMPT_VERSION` written on the `medical_ai_extractions` row at start. `CodexAgent` passes the CLI output through `ExtractionValidator::fromFile`. `RunExtractionJob` calls `finishReady` only for a valid payload; invalid output maps to a `FailureCode`. The job writes only `medical_ai_extractions` (proposal payload) and `ai_calls`; no record table is touched. |
| 3 | Side-by-side review with accept, edit or reject; only accept enters the record | VERIFIED | `AiReviewController` routes `review` (page), `accept` (with optional edited `values`), `reject`, `undo`. `AiReviewQuery::accept` re-validates the (possibly edited) fields with the same rule set as manual entry, locks the person, de-duplicates, then creates the record through `visits/medications/allergies/vaccinations->create(..., 'ai')` and writes `medical_ai_decisions`, all inside `decide()` transaction with audit. Reject and undo write decisions only. Records created with source `ai` are unverified until "Mark as verified" (`health-verify.php`). `resources/js/Pages/Health/AiReview.vue` exists with PDF page-image route; covered by Pest and Vitest suites. |
| 4 | Every external AI call recorded (who, document, agent, model, time) and visible to admins | VERIFIED (document shown to admins by person name only, by design) | `AiRunQuery::start` inserts an `ai_calls` row in the same transaction as the queued extraction with `started_by`, `document_id`, `subject_id`, `agent`, `attempt`, `queued_at`; `model` is filled at finish (CLI-reported, else `default`). Migration revokes DELETE/TRUNCATE from the app role; prune goes through a definer function with a 12-month floor. `GET /settings/ai-calls` is `access:admin`; `AdminAiCallQuery` lists time, started by, person, agent, model, status, duration with member/person/status filters. Per 08-CONTEXT ("admins see person names only there, Phase 7 rule") the admin table does not show the document title; the document id is retained in the log. Content-free audit events `MedicalAiRunRequested/Finished` also written. |

**Score:** 4/4 truths verified in code

## Integration points

| Check | Status | Evidence |
|---|---|---|
| Phase 7 consent flag gates Run AI server-side | VERIFIED | `AiRunQuery::start` (consent_off), re-checked in `RunExtractionJob::recheck` (pre-run, mid-run every `ACCESS_RECHECK_SECONDS`, post-run), and inside the accept path (`consentOn`). Not UI-only. |
| "AI reading is not available yet" line removed | VERIFIED | No matching string in `lang/*/health.php`, `resources/js`, or `app`; git history shows commit 4bd0de9 "remove the obsolete AI not-available consent line" (08-23). |
| Phase 7 export "unverified" labels populated by AI records | VERIFIED | Accepted records are created with source `ai`, `verified_at` null; `Provenance::present` returns `unverified`; `Exporter` adds `verification` and `unverified_notice`, `MarkdownFormat` prefixes `[UNVERIFIED]`. `ExportTest` seeds `source='ai'` rows and asserts exclusion by default and `[UNVERIFIED] Latex` when included. |

## Requirements Coverage

| Requirement | Status | Evidence |
|---|---|---|
| AI-01 on-demand run, prepared prompt, exact JSON | SATISFIED in code (live run human_needed) | Truths 1 and 2; static untrusted-data prompt in `PromptBuilder`, `--output-schema` |
| AI-02 versioned schema, proposal, side-by-side accept/edit | SATISFIED | Truths 2 and 3 |
| AI-03 every external AI call recorded | SATISFIED | Truth 4 |
| AI-04 background locked-down worker, Codex and Copilot via subscription, no per-token API | PARTIAL | Codex part satisfied in code (separate `ai-worker`, subscription login volume, env-key preflight, no API key path). Copilot part belongs to Phase 9 by owner decision. REQUIREMENTS.md rightly leaves AI-01..AI-04 unticked until 08-22. |
| Orphaned requirements | none | REQUIREMENTS.md maps only AI-01..AI-04 to Phase 8 |

## Anti-patterns

No TBD/FIXME/XXX markers in Phase 8 files. Known accepted limitations (not gaps, recorded in DEFERRED.md): WR-06 CLI runs as www-data rather than its own uid, so isolation from the medical volume rests on the read-only sandbox and disabled shell tool; IN-01, IN-03, IN-04, IN-05, IN-06 hardening items. These are documented and routed to the live session, so they are warnings for the human review, not blockers.

## Gaps Summary

No code gaps. Code review (08-REVIEW) findings were fixed (9 of 9 in scope, WR-06 documented as not attempted). All automated checks ran green in this verification. Status is `human_needed` because plan 08-22 (real Codex login, one real run, live deployment, owner decisions A/B/C, WR-06 uid split, device and native-language review) is deliberately deferred and cannot be verified from code. Do not push `develop` or `main` before that session.

---

_Verified: 2026-11-14_
_Verifier: Claude (gsd-verifier)_
