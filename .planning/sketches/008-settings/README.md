---
sketch: 008
name: settings
question: "How are per-module permissions (incl. per-person medical access), AI agents, language and theme set up on phone, iPad and desktop?"
winner: "A"
tags: [settings, permissions, ai-agents, i18n, theme]
---

# Sketch 008: Settings

## Design Question
Where and how do admins set per-module permissions for members (including which people's medical records each member can reach), manage AI agents (login health, order, model, bulk regeneration), and where do users set language and theme?

## How to View
open .planning/sketches/008-settings/index.html (or via the local server on 127.0.0.1:8765)

Change a member's Health level in the matrix (a per-person panel opens), reorder AI agents, use "Simulovať limit Codexu", open bulk regeneration.

## Variants
- **A: Sections on the left (winner)** — section list left, content right: Members and permissions, AI agents, Appearance and language, My account
- **B: One page** — all sections stacked
- **C: Tabs** — the same sections as top tabs

## Decision (winner A)
- Section list on the left (on phone the sections collapse into the single column), content on the right
- Members and permissions: matrix member × module (Tasks, Wishlist, Finance, Health) with levels None / Read / Edit / Manage; admin locked to everything; on phone the matrix becomes cards per member. Finance and Health default to None for new members
- Health: after granting a level, a per-person panel (Jana, Peter, Ema, Tomáš, Babka Mária) sets None / Read / Edit per person. People without an account belong to the family and are granted here. Export and running AI require Edit. AI consent stays on the person profile
- Invite: email, permission template (Adult, Child, Wider family) filling only Tasks and Wishlist; Finance and Health stay None; invite language; valid 7 days
- Finance account visibility is set in the Finance module, settings only point to it
- AI agents: ordered list (Codex primary, Copilot fallback), per-agent switch, login status, model choice, check-login button. When login expires, show the server command; the app never stores subscription tokens. Claude and Antigravity not offered in v1 (terms), stated in the UI. Never a per-token key
- Bulk regeneration: scope (failed / unverified / all with AI), pace (slow overnight / now), confirmation panel; skips people without AI consent; one document at a time, waits through quota limits
- Appearance and language: SK/PL/EN and light/dark/system, per user. Account: password, Google link, TOTP (required for Health and Finance)
- Open points: whether bulk regeneration is admin-only (assumed yes), notification preferences, per-member session and device management

## What to Look For
- How quickly can an admin answer who can see Health and for whom
- Does the AI section make clear which agent runs, why, and what to do when login expires
- Phone: matrix as cards
