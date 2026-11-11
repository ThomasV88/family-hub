# Family Hub

## What This Is

A shared web app (responsive, installable as PWA) for an extended family to organize everyday life in one place: a shared to-do list, gift wishlists, family finances and family medical records. Used on phone, desktop and iPad by parents, children and wider family, each seeing only what they are permitted to see.

## Core Value

The whole family can track shared tasks together in one trusted place, with per-member, per-module permissions so private data (health, money) stays private.

## Requirements

### Validated

(None yet — ship to validate)

### Active

- [ ] User accounts with email+password and Google sign-in; admin invites family members
- [ ] Per-module, per-member permissions (no access / view / edit), configurable by family admin; extended family supported
- [ ] UI in Slovak, Polish and English (language switcher)
- [ ] **To-do list (first module):** tasks assignable to a member, due dates, recurring tasks, personal and shared tasks
- [ ] **Wishlist:** per-member gift wishes; each wish has multiple URLs, price, notes; product image auto-loaded from the link's meta tags (og:image etc.); reservation ("I'm buying this") visible to others but hidden from the recipient; occasions (birthdays, name days, Christmas) with reminders
- [ ] **Family accounting:** manual income/expense records with categories, budgets, overviews and charts, CSV bank-statement import; strong focus on tracking recurring expenses (rent, savings, services, kindergarten, energy, etc.)
- [ ] **Medical records:** doctor visits, medications and allergies, vaccinations, uploaded documents/photos of reports
- [ ] **AI OCR of medical documents:** upload document, fill basic fields manually or let AI read it; AI returns data in an exact JSON structure via a prepared prompt, which is stored in the DB; bulk re-generation of past documents; handles agent usage limits (quota exhaustion, retry/fallback)
- [ ] **Multi-agent AI backend:** supports multiple AI agents and model selection; v1 agents are Codex CLI (primary) and GitHub Copilot CLI (fallback); CLI subscription login is done once on the server, the settings screen picks agent order/model and shows login health; runs headless on the server using subscriptions, NOT per-token paid APIs (research outcome: Claude Code and Antigravity excluded from v1 on terms grounds, app does not store subscription tokens)
- [ ] **Medical exports:** export by category (e.g. gastroenterology, internal medicine), or all at once, for doctor consultations and in an AI-friendly format

### Out of Scope

- Per-token paid AI APIs — user explicitly wants subscription-based machine use only
- AI providers with no machine-usable subscription path — excluded after research verifies each candidate (Antigravity and Copilot status unverified; Claude subscription terms to be checked)
- Native mobile apps — PWA covers phone/iPad/desktop

## Context

- Greenfield; repo contains only README. Project owner writes in Slovak.
- Hosting: owner's Hetzner VPS (Docker Compose under `/opt`, edge nginx SNI proxy, Cloudflare, staging/prod flow like the work-tracker project: `develop` to staging, `main` to production via PR). AI CLIs run on this server in the background (e.g. spawned from the backend, the owner mentioned PHP exec as an idea).
- Medical and financial data are sensitive; documents will be sent to external AI services, so AI processing should be explicit and results reviewable.
- Users: extended family, mixed ages and technical comfort; used on mobile, PC and iPad.

## Constraints

- **Deployment**: Docker Compose on the owner's VPS, behind existing edge proxy — matches existing infra
- **AI cost model**: subscription-based only, no per-token API billing — owner's explicit requirement
- **Privacy**: per-module permissions enforced server-side; health and finance default restricted
- **Languages**: SK, PL, EN from the start
- **Devices**: responsive for phone, desktop, iPad (PWA)
- **Tech stack**: TypeScript/Node 24, Next.js 16, PostgreSQL + Drizzle, pg-boss, Better Auth, next-intl (from research; PHP rejected because CLI jobs need a supervised long-lived worker)

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| To-do list is the first module | Owner's stated starting point and priority | — Pending |
| Per-module configurable permissions | Extended family, sensitive health/finance data | — Pending |
| Subscription-based AI agents instead of paid APIs | Owner's cost requirement | — Pending |
| Multiple AI agents with settings-based config | Quota limits and provider flexibility | — Pending |
| Hosted on owner's VPS with staging/prod flow | Existing infrastructure and workflow | — Pending |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `/gsd-transition`):
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone** (via `/gsd-complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-11-11 after initialization*
