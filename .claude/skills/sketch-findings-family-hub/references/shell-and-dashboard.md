# Shell and Dashboard

## Design Decisions
- **Shell (001, winner D = A+B):** phone bottom bar with 5 modules (Overview, Tasks, Wishlist, Finances, Health) plus a floating add button (FAB); iPad/desktop toggleable sidebar 64 px rail ↔ 232 px with tooltips in rail mode; settings, language and logout in the avatar menu. Nav labels short so they fit the bottom bar in SK/PL/EN; full names in page titles. Sidebar opens by default at frame width 1024+.
- **Dashboard (002, winner B):** one summary card per module, to-do first and checkable, quick add on top, 2 columns from ~700 px else 1. Finances card: headline number, budget progress, monthly bar chart. Health card: next vaccination, upcoming visit. Each card has an "open" link and a short empty state.
- Wishlist card shows only the viewer's own reservations, never reservations on the viewer's own wishes (WISH-04).
- Open: card order and visibility per permissions; what shows when a module is not granted.

## CSS Patterns
```css
.frame { container-type: inline-size; container-name: frame; }
.side { display: none; width: 64px; transition: width .2s ease; }
.app.open .side { width: 232px; }
.bottom { position: absolute; bottom: 0; display: flex; padding: 6px 4px 14px; }
@container frame (min-width: 640px) { .side { display: flex; } .bottom, .fab { display: none; } }
```
Content area also a container (`container-name: content`) so inner grids react to the panel being docked.

## HTML Structures
`.app > aside.side + .main(.topbar + .body(.content + aside.detail)) + nav.bottom + button.fab`. See `sources/001-app-shell-navigation/index.html`.

## What to Avoid
Top navigation (tabs and add button do not fit on a phone); drawer-only (awkward one-handed); "Today" single-column dashboard (hides modules); tasks plus side panel (starves other modules).

## Origin
Sketches 001, 002. Sources: sources/001-app-shell-navigation, sources/002-dashboard-overview.
