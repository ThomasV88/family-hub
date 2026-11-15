---
sketch: 001
name: app-shell-navigation
question: "How does navigation across the 5 modules work on phone, iPad and desktop?"
winner: "D"
tags: [layout, navigation, responsive, shell]
---

# Sketch 001: App Shell and Navigation

## Design Question
How does the user move between Overview, Tasks, Wishlist, Finances and Medical records on phone, iPad and desktop, and where do settings, language and quick add live?

## How to View
open .planning/sketches/001-app-shell-navigation/index.html

Use the toolbar (bottom right) to switch frame width (Mobile 390, iPad 820, Desktop), language (SK/PL/EN, to test long labels) and dark mode. Click the avatar for settings and language.

## Variants
- **A: Adaptive** — bottom tab bar with floating add button on phone, icon rail on iPad, full sidebar on desktop
- **B: Always sidebar** — one sidebar everywhere; collapsible on iPad and desktop, slide-in drawer with hamburger on phone
- **C: Top navigation** — brand and tabs in a top bar on all sizes; scrollable tabs on phone, centered content column
- **D: A + B synthesis (winner)** — bottom tab bar with floating add button on phone; on iPad and desktop a sidebar that can be toggled between a 64 px icon rail and a 232 px full panel with a button in the top bar. Default is rail below 1024 px and open from 1024 px; a manual choice overrides until the frame width is changed

## Decision (winner D)
- Phone: bottom bar with 5 modules (Overview, Tasks, Wishlist, Finances, Health) plus a floating add button; settings, language and logout live in the avatar menu
- iPad and desktop: toggleable sidebar; icons carry the full name as tooltip in rail mode
- Nav labels are **short** (Wishlist, Finances, Health) so they fit the bottom bar in Slovak, Polish and English; full names are used in page titles. Tested in Polish at 390 px: no truncation
- Rejected: top navigation (C) because tabs and the add button do not fit on a phone; drawer-only (B) because it is awkward on one hand
- Tested in a real browser (Playwright): panel toggles 232 ↔ 64 px on desktop and iPad, bottom bar replaces the panel at 390 px

## What to Look For
- Phone: is the bottom bar easy to reach with a thumb, and does the Polish "Dokumentacja medyczna" and "Lista życzeń prezentów" still fit?
- iPad: does the layout feel like an app or a stretched phone?
- Is quick add (the + button) always in reach?
- Where settings and language sit (avatar menu in all variants)
- Which variant feels closest to the calm Notion / Apple Reminders reference
