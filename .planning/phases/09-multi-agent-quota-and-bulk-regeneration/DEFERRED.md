# Phase 9 deferred live-ops work (owner decision 2026-11-14: no live work during the phase)

Plan 09-20 holds all live work: owner decisions, the Copilot data-use check, staging release, the real Copilot device login per stack, the tool-denial gate (L3), one real run per agent, the live checklist L1-L8, a real bulk dry run and a tiny bulk run on staging, native SK/PL review and the production release.
Plans 09-01..09-19 are built and tested against fake CLIs (`tests/Support/FakeCodex.php`, `tests/Support/FakeCopilot.php`). No plan before 09-20 pushes, logs in to any CLI or touches a server. AI-04..AI-07 stay unticked in REQUIREMENTS.md until 09-20. Do not push `develop` or `main` before that session (a push deploys, and CI builds the ai image, now about 490 MB heavier with the Copilot binary and its baked cache).

Prerequisite: the Phase 8 live plan 08-22 has run (Codex login per stack, ai-worker live). If it has not, run it first.

## Live work (09-20)
- Server: ai-worker healthy on the new image, `ai:preflight` OK (no GH_TOKEN, GITHUB_TOKEN, COPILOT_GITHUB_TOKEN, COPILOT_PROVIDER_* in the env), copilot-home mounted only in ai-worker and never in backups, `copilot --version` 1.0.112, `staging:audit` passes.
- Login: `docker compose exec ai-worker copilot login --device-code` per stack as the app user; the owner signs in to GitHub and answers yes to plaintext storage; list `config.json` keys only (never values) and compare with `ai.copilot.login_keys`; record the OAuth scopes; never copy credentials between stacks.
- L3 tool-denial gate on a synthetic injection document BEFORE Copilot is enabled for real use; if any tool ran, Copilot stays off.
- Real runs: one per agent (image and 10-page PDF), fallback with one agent made unusable, attempt rows in AI calls, "Read by" provenance.
- Measurements: per-run home size and ai-worker memory peak (MEM_AI 768m, tmpfs 192m), `config.json` hash before/after (token rotation, A5), `usage.json` model field (A6), `max_ai_credits` 60 sufficiency (A14).
- Real usage-limit and expired-login transcripts per agent if observed (L4): freeze `ai.quota.signatures` and `ai.copilot.auth_failure_patterns` in a quick task; otherwise keep the ASSUMED values.
- Bulk on staging: dry run counts only, accepted-proposal and consent-off documents skipped, start with cap 1, pause, resume, cancel, history counts only.

## Owner decisions (ask A10, G, A9 first)
- **A10: bulk without membership (prominent).** Bulk may process documents of persons the admin is not a member of; each person's AI consent is the only gate (research C6). Alternative: restrict bulk eligibility and counts to persons the admin edits (quick task).
- **G: documents to GitHub.** Copilot plan data-use and training settings, the Copilot terms and the GitHub AUP ("excessive automated bulk activity") for a private family server; Additional usage budget $0. The owner confirms that Copilot's one repair retry re-sends the same page images to GitHub a second time (issue codes only, never the earlier reply).
- **A9: bulk policy and ceilings.** Bulk uses only the first enabled agent (`ai.bulk.fallback_agents` false); Copilot daily ceiling 30 runs; bulk default cap 20, max cap 30; spacing 10 minutes; start hour 01:00 Europe/Bratislava; global headroom 10.

## PROVISIONAL defaults built in this phase (confirm or change in 09-20)
- Per-run `COPILOT_HOME` on the tmpfs, no copy-back of `config.json` (research Pattern 1, A5).
- Quota ladder 60/180/720/1440 minutes, 48 h ladder reset window, 24 h wait cap, reset buffer 60 s, reset clamp 8 days.
- Dry-run validity 15 minutes; one active bulk run at a time; history of 10 counts-only runs.
- Enabling an agent while not logged in is allowed with a warning, for every non-logged-in state (UI-SPEC PROVISIONAL 3, UI checker follow-up).
- The last enabled agent cannot be switched off (UI-SPEC PROVISIONAL 2); cooldown is read-only (PROVISIONAL 4).
- Model id rule `^[A-Za-z0-9][A-Za-z0-9._:-]{0,59}$` (no slashes, 60 characters), tightened from the UI-SPEC PROVISIONAL slash/64 pattern to equal the existing server rule; error copy changed accordingly (09-01).
- A Copilot-only notice text (`health.ai.notice.github_*`) added by the planner, since the UI-SPEC had no wording for "only Copilot enabled".
- Bulk-row person masking in AI calls and the copy "Not shown (bulk run)" (plan checker revision, see above).
- "Stopped" bulk display derived from `ai_bulk_runs.blocked_since` after 24 h without a usable agent; the run stays active and resumes on its own (UI-SPEC "Stopped" tag vs PROVISIONAL 12).
- Copilot health from the local credential marker plus run outcomes (no status command exists, research C1).
- MEM_AI 768m, tmpfs /ai-work 192m (A4); `ai.copilot.max_ai_credits` 60 (A14); job budget 660 s, worker timeout 720 s, retry_after 900 s.
- Admin sections 7 -> 8, `AiAgentsController` arch allowlist with a pinned reference set, `zz_ai_agents.php` area name, `AiBulkJobQuery` added to the mutation-scan allowlist.
- Agent update/move as JSON endpoints through `securityFetch`; login check keeps `settings.ai_calls.check`.
- UI-SPEC PROVISIONAL 1-14 (the login card moves into the agent cards and the AI calls page keeps a pointer line, which changes the 08-17 output; waiting poll 60 s with a 60-minute stop; bulk poll 15 s; bulk calls not labelled in AI calls; newer waiting run beside an older reviewable proposal).

## Plan checker revisions (2026-11-14)
- **Call log masking for bulk rows (deliberate tightening of the UI-SPEC).** The UI-SPEC let Settings > AI calls keep person names for every row under the Phase 8 admin exception. Rows with a `bulk_run_id` now show "Not shown (bulk run)" in the person cell and a person filter never returns them (09-15), so the admin learns nothing about which people bulk processed (CONTEXT "counts and status only", A10). This supersedes UI-SPEC PROVISIONAL 10 ("bulk calls not labelled"). Confirm under A10.
- **Provider acknowledgment at run time.** Every attempt, including runs resumed from `waiting`, is restricted to the providers the starter acknowledged (`ai_notice_acks.providers`); with none enabled the run fails `notice_required` and nothing is sent (09-10). Bulk runs use the starting admin's acknowledgment, recorded at Start from the providers named in the confirm dialog (09-13).
- **Leaving `waiting`.** Resume, reaper, user cancel and bulk cancel all set `available_at` to NULL in the same UPDATE; `waiting_since` is written only by the first park and kept as history.
- **Bulk completion.** Counts come from item rows only (each skip once); a run completes when no eligible document is left and no item is open; documents that became ineligible after the dry run drop out and are added to `skipped_count` once at completion; `eligible_total` is display only (09-13, 09-14).
- **Terminal-code detail lines** for `quota_exhausted`, `no_agent` and `notice_required` added (09-01 copy, 09-17), native review pending.

## Research corrections that override CONTEXT (recorded in the plans)
- C1: no Copilot login/status command; health = local credential marker + run outcomes (09-07, 09-09).
- C2: no queue `release()` with delay; a DB `waiting` status plus the scheduled `ai:resume-waiting` (09-03, 09-10).
- C3: failure codes `quota_timeout` (24 h cap), `quota_exhausted`, `no_agent` (09-04, 09-10).
- C4: fall through to the next agent on `auth` as well as quota; everything else terminal (09-10).
- C5: stored single-use dry-run row with 15-minute validity and 409/410 semantics (09-03, 09-13).
- C6: bulk-specific run-time recheck (admin still admin, run not cancelled, consent on) instead of the member-editor check (09-10), owner decision A10.
- C7: per-member provider notice version (`ai_notice_acks.providers`, existing acknowledgments become the Codex-only first version), provider-aware disclosure lines, `availableAt`/`failureCode` in the status payload, "Usage limit reached" call filter (09-03, 09-11, 09-15, 09-17).

## Native-review copy
All new SK and PL strings from 09-01 (agents section, waiting and quota-timeout status, provenance, provider lines, provider-aware and Copilot-only notices, bulk panel, AI calls additions, audit details) are drafts flagged for a native reader in 09-20.

Two native-review copy tasks for 09-20 (recorded by 09-19, from the 09-01 summary):
1. **Riskiest SK/PL strings (no UI-SPEC draft, written by the executor):** `settings.ai_agents.model_error` (tightened rule), `settings.ai_agents.open_calls`, `settings.ai_bulk.already_active`, `settings.ai_bulk.try_again`, `settings.ai_bulk.pause_name`, `settings.ai_bulk.resume_name`, `settings.ai_bulk.valid_minutes`, `settings.ai_bulk.row_*`, `health.ai.status.quota_exhausted_detail`, `health.ai.status.no_agent_detail`, `health.ai.status.notice_required_detail`, `health.ai.notice.github_title`, `health.ai.notice.github_body`, `settings.ai_calls.person_bulk`, and every `audit.detail.ai_*` line.
2. **Polish plural agreement:** `settings.ai_bulk.confirm_body`, `settings.ai_bulk.progress` and `settings.ai_bulk.valid_minutes` use four pipe segments in SK and PL; a native Polish reader must check noun and numeral agreement for every count class (1, 2-4, 5-21, 22-24 and 25+), which the parity test only proves structurally.

## Carried hardening items (from Phase 8 DEFERRED.md, now covering two CLIs)
- WR-06: run the CLIs under their own uid. With Copilot the www-data uid can read both `/codex/auth.json` and `/copilot/config.json`; the same `setpriv` approach applies to both runners and both credential volumes.
- IN-06: ai-worker container hardening (cap_drop ALL, no-new-privileges, read_only, pids_limit), together with WR-06.
- No compose-level egress allowlist is available; the Copilot CLI must reach GitHub (research Q8).

## Requirements after the live session
AI-04 is ticked in 09-20 only when both the Codex part (08-22) and the Copilot part are live; AI-05, AI-06 and AI-07 are ticked in 09-20 (under Decision G2, AI-06 and AI-04 are annotated that Copilot is installed but disabled by owner decision).

## Review findings left for owner decision (Phase 9 code review, 2026-11-14)
These came out of `09-REVIEW.md`. They are product or infrastructure questions, so the review-fix pass did not change them.

- **WR-02: should consent be provider-specific?** `ai_consent` on a person is one boolean. Enabling a further provider (for example GitHub Copilot) silently extends every existing consent to it. An interactive run is protected, because an editor of that person has to acknowledge the new provider. A bulk run is not: one admin acknowledgment at Start releases all consenting people's documents to whichever acknowledged provider runs first. Options: (a) store the provider set (or a notice version) on the consent row and let bulk process a person only when the consent covers the provider that would run; (b) restrict bulk to providers that were enabled when the person consented; (c) keep today's behaviour and record it in decision A10. The review-fix pass did bind the acknowledgment itself to the set the member or admin actually saw (CR-01) and froze nothing per person.
- **Should `access:recent` be re-checked during a multi-day bulk run?** It is checked once, at Start. The tick runs for days afterwards, and the admin's Health module level is not looked at per item (the job checks only that the starter is still an admin, that the run is not cancelled and that the person's consent is on; the review-fix pass added the same admin check to the tick). Options: re-check the Health module (and recent re-authentication) on every tick and pause the run visibly when it fails, or keep Start as the only gate.
- **Should the AI calls person filter dropdown be trimmed?** The filter on Settings > AI calls lists people. The call log masks the person for bulk rows and the filter never matches them, but the dropdown itself still names every person to an admin who may not be a member of them. Decide whether the list should only offer people the admin belongs to (or be removed in favour of a free-text document-free filter).
- **WR-04 follow-up: `/copilot` is readable from the Codex process.** Both CLIs run as the same uid (www-data, uid 82) in the one `ai-worker` container, and both credential volumes are mounted there (`/codex` and `/copilot`). A prompt-injected Codex run can therefore read `/copilot/config.json` (the GitHub login) as well as `/codex/auth.json`. The review-fix pass removed the per-run copy of the login from the work dir between attempts, but the persistent volume is the root cause. It is the same fix family as Phase 8 WR-06 (a separate uid for the CLIs, see `08-ai-extraction-platform/DEFERRED.md`), and should be done together with it and with IN-06 container hardening. Approach, extending the Phase 8 plan to two uids:
  1. Dockerfile `ai-runtime` stage: create `codexrun` (uid 1001) and `copilotrun` (uid 1002), each with its own group; add `www-data` to both groups only for the shared work dir; install `setpriv` with `cap_setuid,cap_setgid` as in Phase 8.
  2. compose: `cap_drop: [ALL]` plus `cap_add: [SETUID, SETGID]`; `chown` the `codex-home` volume to `codexrun:codexrun` and the `copilot-home` volume to `copilotrun:copilotrun`, both mode 0700, so neither CLI uid can read the other's login nor the app's medical files.
  3. `CodexRunner::wrap()` runs `setpriv --reuid=1001 --regid=1001 --clear-groups` and `CopilotRunner::wrap()` runs `setpriv --reuid=1002 --regid=1002 --clear-groups`, between `env -i` and `setsid`. The copy of `config.json` into the per-run home is then made by a helper running as `copilotrun` (the app uid can no longer read the volume), or the CLI is pointed at the volume read-only; `loginStatus()` and `Preflight` must read it through the same uid.
  4. The per-run work dir stays on the `/ai-work` tmpfs, mode 0770 with the shared group, and each runner's scratch (`copilot-home`, `tmp`) is created under the CLI uid. `check-compose.sh` should assert both volumes are mounted only on the `ai-worker` and that the capability set is exactly SETUID and SETGID.
  5. Alternative if the capability change stays untestable: one container per provider (a `copilot-worker` serving only a `copilot` queue), which separates the volumes without any uid work but doubles the worker footprint.
