---
name: sketch-findings-family-hub
description: Validated design decisions, CSS patterns, and visual direction from sketch experiments. Auto-loaded during UI implementation on family-hub.
---

<context>
## Project: family-hub

Calm and neutral, minimalist and clean. Grey and white surfaces, thin dividers instead of heavy frames, lots of whitespace, one restrained accent color. Readable for adults and older family members. Home is an overview dashboard with the to-do list always on it. Quick add always within reach. Light and dark theme, phone/iPad/desktop, Slovak/Polish/English (text length varies, so layouts must not depend on short labels).

Reference points: Notion (clean pages, subtle dividers), Apple Reminders (simple lists, quick add).

Sketch sessions wrapped: 2026-11-12. Target stack: Laravel + Inertia + Vue (sketches are plain HTML, port patterns to Vue components).
</context>

<design_direction>
## Overall Direction

Neutral off-white palette with one blue accent (`themes/default.css`, light and dark). 8 to 12 px radii, 1 px borders, minimal shadows, controls at least 36 to 40 px high. Phone: bottom bar with 5 modules and a floating add button, panels as bottom sheets. iPad/desktop: toggleable sidebar (64 ↔ 232 px), detail panels docked on the right (wide) or overlay. Layouts respond to container width (container queries on frame and content), not only viewport. Short nav labels (Wishlist, Financie, Zdravie). Server-side enforced privacy: reservations hidden from the wish owner, restricted finance accounts, per-person medical access, AI per-person consent default off.
</design_direction>

<findings_index>
## Design Areas

| Area | Reference | Key Decision |
|------|-----------|--------------|
| Shell and dashboard | references/shell-and-dashboard.md | Bottom bar on phone, toggleable sidebar on wide; dashboard of module tiles, tasks first |
| Design foundation | references/design-foundation.md | Neutral off-white, blue accent, tokens in default.css |
| Tasks and wishlist | references/tasks-and-wishlist.md | To-do columns per person with detail panel; wishlist card gallery, own list hides reservations |
| Finance | references/finance.md | Tabs Overview/Transactions/Recurring, payment calendar, expected vs actual, import with duplicate matching |
| Medical and settings | references/medical-and-settings.md | Specialty folders as timelines, per-person AI consent and review, permission matrix with per-person health access, ordered AI agents |

## Theme

The winning theme file is at `sources/themes/default.css`.

## Source Files

Original sketch HTML files (all variants, helpers, container-query CSS) are in `sources/NNN-name/index.html`.
</findings_index>

<metadata>
## Processed Sketches

- 001-app-shell-navigation
- 002-dashboard-overview
- 003-design-foundation
- 004-todo-module
- 005-wishlist-module
- 006-finance-module
- 007-medical-records
- 008-settings
</metadata>
