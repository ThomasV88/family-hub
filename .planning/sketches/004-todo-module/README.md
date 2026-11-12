---
sketch: 004
name: todo-module
question: "How does the shared family to-do list show tasks, assignment, due dates, recurrence and personal versus shared?"
winner: "B"
tags: [todo, list, module, detail]
---

# Sketch 004: To-Do Module

## Design Question
Tasks need assignment to a member, due dates, recurrence, and a personal or shared visibility. Which list layout makes that quick to scan and edit on phone, iPad and desktop?

## How to View
open .planning/sketches/004-todo-module/index.html (or via the local server on 127.0.0.1:8765)

Built on the winning shell (001), dashboard direction (002) and theme (003). Click a task to open the detail panel (side panel on iPad/desktop, bottom sheet on phone). Toolbar: frame width, empty state, dark mode.

## Variants
- **A: Groups by due date** — Today / Tomorrow / This week / Later / No date / Done, like Apple Reminders
- **B: Columns by person** — one column per family member, tasks as cards (stacks on phone)
- **C: Table** — Notion-like rows with columns Task, Who, Due, Repeat (Due and Repeat hide on phone)

## Decision (winner B: columns by person)
- The to-do list is one column per family member (Jana, Peter, Ema), tasks as cards with checkbox, title, recurrence icon, lock icon for personal tasks, due tag and overdue tag; the assignee is the column, so no avatar on the card
- Quick add field (Enter to add) and filter chips (All, Mine, Shared, Personal) sit above the columns
- Detail panel: title, assignee, due, repeat (none, daily, weekly, monthly, after completion), visibility (shared/personal) with an explanation line, notes, delete. Docked right on frames of 900 px and wider, overlay on iPad width, bottom sheet on phone
- Columns adapt to the content width, not the frame width: 3 columns from 700 px of content, 2 from 440 px, otherwise 1. With the detail panel open on a desktop only 2 columns fit and the third wraps below. Use `minmax(0, 1fr)` columns and container queries on the content area
- Rejected: due-date groups (A) as the main layout, but it remains a candidate for the phone and for the dashboard card; table (C) as too dense
- Open points for later: columns scale with the number of members (a family of 6 or more needs horizontal scroll or a "my column first" order), a "no assignee" column, overdue sorting, multiple assignees, and custom recurrence rules
- On phone, columns stack in one list, so the phone view should show the current user's column first

## What to Look For
- How fast can you add a task (Enter in the field) and find "what is mine today"?
- Filters: All, Mine, Shared, Personal
- Recurring tasks and personal tasks visible at a glance (repeat icon, lock icon)?
- The detail panel: title, assignee, due, repeat, shared/personal, notes
- Empty state and phone bottom sheet
