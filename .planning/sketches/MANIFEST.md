# Sketch Manifest

## Design Direction
Calm and neutral, minimalist and clean. Grey and white surfaces, thin dividers instead of heavy frames, lots of whitespace, one restrained accent color. Readable for adults and older family members. The home screen is an overview dashboard summarizing all modules, with the to-do list always on it and optional small charts. Quick add is always within reach. Light and dark theme, phone/iPad/desktop, Slovak/Polish/English (text length varies, so layouts must not depend on short labels).

## Reference Points
- Notion — clean pages, lots of blank space, subtle dividers
- Apple Reminders — simple lists, quick add, calm

## Sketches

| # | Name | Design Question | Winner | Tags |
|---|------|----------------|--------|------|
| 001 | app-shell-navigation | How does navigation across 5 modules work on phone, iPad and desktop? | D (A+B): bottom bar on phone, toggleable sidebar on iPad/desktop, short nav labels | layout, navigation, responsive |
| 002 | dashboard-overview | What does the home dashboard show and in what order? | B: one summary card per module (tasks first), quick add on top | dashboard, layout, home |
| 003 | design-foundation | How do colors, shapes and core components look, in light and dark? | A: neutral off-white, blue accent, 8 to 12 px radii (themes/default.css) | theme, components, colors, typography |
| 004 | todo-module | How does the shared to-do list show assignment, due dates, recurrence and personal vs shared? | B: one column per member, quick add, filters, detail panel (docked / sheet) | todo, list, module, detail |
| 005 | wishlist-module | How does the wishlist show wishes and keep reservations secret from the recipient? | D (A+C): card gallery with a shopping summary on top; own list shows no reservation state | wishlist, gifts, reservation, module |
| 006 | finance-module | How does finance show money flow with focus on recurring payments (expected vs actual), budgets, restricted accounts and CSV import? | D (A+C): tabs Overview / Transactions / Recurring, calendar on the Recurring tab, add and import panels | finance, recurring, budget, import, module |
