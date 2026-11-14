# Phase 8 deferred live-ops work (owner decision 2026-11-14: no live work during the phase)

Plan 08-22 holds all live work: owner decisions, the ChatGPT data-controls check, staging release, the real Codex login per stack, one real run on synthetic documents with the live-session checklist, native SK/PL review and the production release.
Plans 08-01..08-21 are built and tested against the fake CLI (`tests/Support/FakeCodex.php`). AI-01..AI-04 stay unticked in REQUIREMENTS.md until 08-22. Do not push `develop` or `main` before that session (a push deploys, and CI now also builds the ai-worker image).

## Live work (08-22)
- Server: `ai-worker.env` per stack (fh_app creds, no APP_KEY/mail/Google/VAPID/API keys), ai-worker healthy, `ai:preflight` OK, codex-home mounted only in ai-worker, never in backups, `staging:audit` passes.
- Login: `docker compose exec ai-worker codex login --device-auth` per stack (owner enters the code), `codex login status; echo $?`, admin card shows Logged in. Never copy auth.json between stacks; never print it.
- Real run on a synthetic image and a multi-page PDF; review, accept, edit-accept, reject/undo, verify.
- Research live-session checklist 1-8 and assumptions A1, A2, A4, A5, A7, A8, A9, A13: tools really disabled, schema accepted, model, voice/ removal, memory vs 384m, timings, HEIC/PDF input, CODEX_HOME canary grep, optional hardening, refresh metadata, expired-login transcript (freeze `ai.auth_failure_patterns` in a quick task).

## Owner decisions (ask A, B, C first)
- **A: data leaves the server.** ChatGPT plan data-controls/training setting and the OpenAI terms reading for personal-subscription use on a private server.
- **B: WR-07, who may switch AI consent on.** Consent now triggers sending documents to OpenAI (options in 07 DEFERRED.md).
- **C: append-only reading of ai_calls** (lifecycle-guarded row vs terminal-only inserts).
- PROVISIONAL defaults built in this phase: ai-worker uses fh_app + own env file (no fh_ai role); no weekly keep-alive run; proposals kept until superseded or the document is deleted (no time prune); supersede on ready; HEIC not sent to AI; PDF page cap 10 with a pages-read line; admin sections 6 -> 7; AiCallsController arch allowlist extension; `zz_ai.php` area name; Run AI allowed while health is "Not checked yet"; dedicated JSON status route for polling; single accept endpoint for edit-accept; CLI default model; ai-worker mem_limit 384m; UI-SPEC PROVISIONAL 1-9.
- Explicit questions added by the plan checker: convert HEIC to JPEG server-side (would need a decoder and a new package approval) or keep refusing it; keep the 10-page PDF cap or raise it.
- ai_calls.model and medical_ai_extractions.model are NULL while queued/running and set once at finish (CLI-reported model, else 'default').

## Requirements after the live session
AI-01..AI-03 get ticked in 08-22. AI-04 is NOT ticked: it is annotated "Codex part complete in Phase 8; Copilot part in Phase 9". The requirement wording is left unchanged.

## Notes
- Verify action for AI records did not exist in Phase 7 (UI-SPEC assumed 07-16 had one); 08-06/08-09/08-20 build it.
- Package approval for `@openai/codex` 0.169.0 and poppler-utils happens in 08-02 Task 1 (local build only).
