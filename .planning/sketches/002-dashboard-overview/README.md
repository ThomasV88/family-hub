---
sketch: 002
name: dashboard-overview
question: "What does the home dashboard show and in what order: tasks, upcoming items, charts?"
winner: "B"
tags: [dashboard, layout, home]
---

# Sketch 002: Dashboard Overview

## Design Question
The owner wants an overview dashboard summarizing all modules, with charts allowed, and the to-do list always present. What is shown first and how is it arranged?

## How to View
open .planning/sketches/002-dashboard-overview/index.html (or via the local server on 127.0.0.1:8765)

Built on the winning shell from sketch 001. Toolbar: frame width (Mobile / iPad / Desktop), "Prázdny stav" (empty data), dark mode.

## Variants
- **A: "Today"** — one column in time order: today's tasks, then upcoming items from all modules mixed in one list, then a small spending chart
- **B: Module tiles** — one summary card per module (tasks, wishlist, finances with chart and budget, health), each with a headline number
- **C: Tasks first with side panel** — large task list on the left, side column with upcoming items, spending chart and budget

## Decision (winner B: module tiles)
- Dashboard is a grid of one summary card per module: Tasks, Wishlist, Finances (headline number, budget progress, monthly bar chart), Health (next vaccination, upcoming visit), plus a quick-add task field at the top
- Grid is 2 columns from about 700 px frame width, 1 column below
- To-do card is first (top-left) and has checkable tasks, which satisfies the owner's minimum requirement
- Each card has an "open" link into its module
- Wishlist card must only show reservations made by the viewer, never reservations on the viewer's own wishes (secrecy rule from WISH-04)
- Empty state per card was checked: each card shows a short empty message instead of numbers
- Rejected: "Today" single column (A) because it hides module summaries; tasks-first with side panel (C) because it gives the other modules too little room
- Open points for later: card order and visibility per user (permissions may hide Finance or Health cards), and what happens when a module is not granted

## What to Look For
- Which variant answers "what do I need to do or know today" fastest?
- Does the to-do list stay prominent (the owner's minimum requirement)?
- Empty state: does the screen still look fine with no data?
- Phone: how long is the scroll, and what falls below the fold?
- Calm, uncluttered feel versus information density
