---
sketch: 005
name: wishlist-module
question: "How does the gift wishlist show wishes (multiple URLs, auto image, price) and handle reservations that the recipient must never see?"
winner: "D"
tags: [wishlist, gifts, reservation, module]
---

# Sketch 005: Wishlist Module

## Design Question
Each member keeps a wishlist. Wishes have several URLs, a price, notes and an image loaded from the link's meta tags. Other members reserve a gift or co-buy it. The owner must never see reservation state. Which layout makes this clear?

## How to View
open .planning/sketches/005-wishlist-module/index.html (or via the local server on 127.0.0.1:8765)

Toolbar: **Prihlásený: Jana / Peter** switches who is logged in (to see the owner view versus the giver view), frame width, dark mode. Click a wish for the detail panel.

## Variants
- **A: Card gallery** — grid of cards with product image, price, link count and reservation status
- **B: List with thumbnails** — compact rows with status and price
- **C: Shopping view** — what I reserved (with a running total), and free ideas from others, across all people

- **D: A + C summary (winner)** — the card gallery from A with the "My shopping list" summary from C on top (total in euros, number of gifts I reserved, expandable list of them)

## Decision (winner D: gallery with shopping summary)
- Layout from top: shopping summary card, person chips (own list marked "(moje)"), occasion strip for the chosen person, banner on the own list, then the card gallery (2 columns on phone, 3 on tablet, 4 on wide content)
- Summary card: total of what the viewer reserved for OTHER people (co-buy items count the viewer's share only), count, and a Show/Hide toggle that lists items; clicking an item jumps to that person's list with the wish open
- Secrecy rule verified in the mock: on the viewer's own list there are no status tags, and the detail panel says only that reservation state is not shown. The summary never mentions reservations on the viewer's own wishes. This must be enforced server-side (WISH-04), including notifications, counts, exports and search
- Cards show image, title, price, link count and a status tag for others' lists: free, reserved by name, reserved by you, co-buy with number of contributors
- Detail panel (docked right on wide, bottom sheet on phone): image, "load image from link" action (simulated shimmer), title, price, multiple URLs with add and remove, notes, and the reservation box (reserve, cancel, propose or join co-buy, share per person)
- Images in the sketch are placeholder gradients; real product images need the og:image fetch job with a manual upload fallback and SSRF protection
- Rejected as the main layout: thumbnail list (B), shopping view alone (C)
- Open points: occasions per person (name day and birthday) as a first-class object, reminders for givers, privacy of the viewer's own summary (only the viewer sees it), what a child sees on parents' lists (permissions), and a "bought" state after purchase

## What to Look For
- Open Jana's own list while logged in as Jana: no reservation info anywhere (including "Parfum Chloé", which Peter has reserved)
- Open Ema's list as Jana: status tags (free, reserved by someone, reserved by you, co-buy) and the reserve button
- The "Načítať obrázok z odkazu" action (simulated) with a loading shimmer
- Multiple URLs, price, notes, co-buying
- Phone bottom sheet and empty states
