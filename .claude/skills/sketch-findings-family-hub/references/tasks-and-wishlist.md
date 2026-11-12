# Tasks and Wishlist

## Tasks (004, winner B)
- One column per family member; cards with checkbox, title, recurrence icon, lock icon for personal tasks, due and overdue tags; no avatar (column is the assignee).
- Quick add (Enter) and filter chips (Všetky/Moje/Spoločné/Osobné) above the columns.
- Detail panel: title, assignee, due, repeat (none, daily, weekly, monthly, after completion), visibility shared/personal with explanation, notes, delete. Docked ≥900 px, overlay on iPad, bottom sheet on phone.
- Columns adapt to the **content** width: 3 from 700 px, 2 from 440 px, else 1; use `grid-template-columns: repeat(n, minmax(0, 1fr))` with container queries. Phone: current user's column first.
- Open: many members (scroll or "my column first"), unassigned column, multiple assignees, custom recurrence.
- Avoid: due-date groups as main layout (candidate for phone/dashboard card), dense table.

## Wishlist (005, winner D = A + C)
- From top: shopping summary card (viewer's reservations for OTHER people; co-buy counts own share; Show/Hide; click jumps to that person's wish), person chips (own list marked "(moje)"), occasion strip, banner on own list, card gallery (2 cols phone, 3 tablet, 4 wide).
- Cards: image, title, price, link count, status tag on others' lists (free, reserved by name, reserved by you, co-buy with contributors).
- **Secrecy rule (WISH-04):** own list shows no status tags, no reservation counts, no notifications about reservations, nothing in exports or search. Enforce server-side.
- Detail panel: image, "load image from link", title, price, multiple URLs, notes, reservation box (reserve, cancel, propose/join co-buy, share per person). Images need an og:image job with manual upload fallback and SSRF protection.
- Open: occasions as first-class objects, giver reminders, "bought" state, what a child sees on parents' lists.

## Origin
Sketches 004, 005. Sources: sources/004-todo-module, sources/005-wishlist-module.
