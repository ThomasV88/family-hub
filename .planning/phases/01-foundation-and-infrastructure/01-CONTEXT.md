# Phase 1: Foundation and Infrastructure - Context

**Gathered:** 2026-11-12
**Status:** Ready for planning

<domain>
## Phase Boundary

The app runs on the owner's VPS in separate staging and production environments, in three languages (SK/PL/EN), installable as a PWA, with encrypted backups that are proven restorable. Staging holds only synthetic data.
</domain>

<decisions>
## Implementation Decisions

### Claude's Discretion
Infrastructure phase — all implementation choices are at Claude's discretion, within the locked stack in CLAUDE.md (Laravel, Inertia + Vue, vite-plugin-pwa, PostgreSQL, database queue with separate worker, Docker Compose services app/nginx/worker/scheduler/postgres, nginx joins `edge` network with aliases fh-prod-nginx / fh-stage-nginx, Laravel localization + vue-i18n). Deployment to the VPS follows the work-tracker pattern (GitHub CI: `develop` to staging, `main` to production). Service worker caches only the static app shell. Actions on the live server (edge nginx.conf edits, DNS) must be backed up first, staged first, and surfaced to the owner rather than done silently.
</decisions>

<code_context>
## Existing Code Insights

Greenfield: repository contains only planning artifacts and GSD tooling. No application code yet.
</code_context>

<specifics>
## Specific Ideas

Follow the existing work-tracker deployment conventions described in the global server notes.
</specifics>

<deferred>
## Deferred Ideas

None.
</deferred>
