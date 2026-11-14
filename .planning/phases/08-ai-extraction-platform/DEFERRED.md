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

## Native-review copy tasks (recorded by 08-21)
Both belong with the native SK/PL review of the AI copy in 08-22 (and need new lang keys in EN, SK and PL, so a native reader should write them).
- **Discard-edit dialog body (08-19).** `UiConfirmDialog` in the review edit discard flow shows the entry name as its body, because no lang key exists for a body sentence. Add a real body sentence key (for example "Your changes to this suggestion will be lost.") in en, sk and pl and use it as the body, with the entry name moved to the title or an `aria-describedby` line.
- **Entry accessible names (08-18).** Entry accessible names ("Accept Medications: Ibuprofen") use the plural section heading as the type word, because the lang files have no singular type nouns. Add singular nouns (visit, medication, allergy, vaccination) in en, sk and pl so the names read "Accept medication: Ibuprofen". Polish and Slovak need the noun in the right case for each action, which is why this is a native task.

## Hardening items left from the code review fix pass (see 08-REVIEW-FIX.md)

- **WR-06: run the Codex CLI under its own uid (not attempted).** The CLI currently runs as www-data, the same uid as `queue:work`, so a prompt-injected document is separated from `/var/www/html/storage/app/medical`, `/proc/<php-pid>/environ` and `/codex/auth.json` only by `--sandbox read-only` and `features.shell_tool=false`. It was not attempted because the worker runs unprivileged (no `CAP_SETUID`) and the change cannot be tested here without a root-capable container, and it interacts with IN-06. Exact approach for the live session:
  1. Dockerfile `ai-runtime` stages: `addgroup -g 1001 codexrun && adduser -D -u 1001 -G codexrun codexrun`; add `www-data` to group `codexrun`; `apk add util-linux-misc` (for `setpriv`) and `setcap cap_setuid,cap_setgid+ep /usr/bin/setpriv`.
  2. compose.server.yaml and compose.yaml `ai-worker`: `cap_drop: [ALL]`, `cap_add: [SETUID, SETGID]` (file capabilities do not work under `no-new-privileges`, so that option stays off for this service); chown the `codex-home` volume to `codexrun:codexrun` (mode 0700, auth.json refreshed in place by the CLI uid; `Preflight` and `CodexRunner::loginStatus` must then run through the same wrapper); tmpfs `/ai-work` `uid=82,gid=1001,mode=0770`.
  3. `CodexRunner::wrap()`: insert `/usr/bin/setpriv --reuid=1001 --regid=1001 --clear-groups` between `env -i ...` and `setsid`, and `umask 007` handling so `WorkDir` job dirs are `0770` group `codexrun` (images copied as `0640`, `result.json` and `tmp/` written by the CLI uid). The medical volume stays `0750 www-data` so the CLI uid cannot read it, which is the point.
  4. Add `hidepid=2` for `/proc` (`--proc-opts`/compose `security_opt` is not available; use a mount in the entrypoint only if the container may mount, otherwise rely on the uid split: `/proc/<pid>/environ` of another uid is unreadable).
  5. Tests: a fake CLI that tries to read `storage/app/medical`, `/proc/1/environ` and the PHP worker's `environ` and asserts permission denied; `check-compose.sh` assertions for the caps and the codex-home owner; live: `docker compose exec -u codexrun ai-worker ls /var/www/html/storage/app/medical` must fail.
- **IN-01: pgid recycle after a normal exit.** `CodexRunner::killGroup` also runs after the child was reaped. The window is tiny and the exact fix needs a pidfd or a pre-reap sweep; revisit with WR-06.
- **IN-03: auth failure patterns** (`401`, `sign in` are broad). Freeze narrower patterns from the real expired-login transcript in the 08-22 quick task, as already planned, and anchor them to structured error events.
- **IN-04: validator string limits** (4000 vs the 120-2000 record rules) and silent date nulling. Owner choice: fail the entry or show a per-entry warning; needs reviewer copy in SK/PL.
- **IN-05: `ai_calls` after a person is deleted.** The rows keep ids for 12 months by design and the guard triggers forbid editing identity columns. Decide with owner decision C: either state the 12-month call log in the privacy notice, or add a definer function that removes the rows of one deleted person (note that this weakens the "history cannot be removed by the runtime role" property, so it needs the same floor logic as `ai_calls_prune`).
- **IN-06: ai-worker container hardening** (`cap_drop: ALL`, `no-new-privileges`, `read_only: true`, `pids_limit`). Apply in the live session together with WR-06 (see the capability conflict above) and extend `check-compose.sh` to assert them.
- **Tune live (from the fix pass):** `ai.pdf.ulimit_kb` (131072 app, 262144 worker) is virtual memory and was only exercised on small PDFs: raise it if real 10-page scans fail to render. `ai.limits` (3 active per user, 20 global, 10 minute cool-down) and `medical.max_records_per_subject` (1000) are assumptions. A `busy` refusal currently shows the generic "could not start" message; a dedicated SK/PL/EN sentence is a native copy task.

## Requirements after the live session
AI-01..AI-03 get ticked in 08-22. AI-04 is NOT ticked: it is annotated "Codex part complete in Phase 8; Copilot part in Phase 9". The requirement wording is left unchanged.

## Notes
- Verify action for AI records did not exist in Phase 7 (UI-SPEC assumed 07-16 had one); 08-06/08-09/08-20 build it.
- Package approval for `@openai/codex` 0.169.0 and poppler-utils happens in 08-02 Task 1 (local build only).
