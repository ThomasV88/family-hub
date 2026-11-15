---
sketch: 006
name: finance-module
question: "How does the finance module show money flow, with the focus on recurring expenses (expected versus actual), budgets, charts, restricted accounts and CSV import?"
winner: "D"
tags: [finance, recurring, budget, import, module]
---

# Sketch 006: Finance Module

## Design Question
The owner's emphasis is tracking recurring expenses (rent, savings, services, kindergarten, energy). The module also needs manual records, budgets, charts, account visibility and bank CSV import. Which structure makes the month readable at a glance?

## How to View
open .planning/sketches/006-finance-module/index.html (or via the local server on 127.0.0.1:8765)

Today in the sketch is 12 November 2026. Account chips filter by account (the personal account has a lock icon, meaning its visibility is restricted). "+ Záznam" opens the add panel, "Import CSV" opens the import panel (sample statement), and "Zaplatené" on a recurring payment records the matching transaction.

## Variants
- **A: Tabs** — Overview (stats, still-to-pay card, budgets, chart), Transactions, Recurring
- **B: One month page** — stats, still-to-pay card, recurring payments, budgets, chart and latest transactions in one scroll
- **C: Payment calendar** — month grid with recurring payments marked per day (overdue in red), day detail below

- **D: A + C synthesis (winner)** — the tabs from A (Overview, Transactions, Recurring) where the Recurring tab shows the payment calendar from C by default, with a Calendar/List switch

## Decision (winner D: tabs, calendar on the Recurring tab)
- Module structure: account chips and month switcher on top, then tabs Overview, Transactions, Recurring. Top-right actions: "Import CSV" and "+ Záznam"
- Overview tab: three stat cards (income, expenses, balance), the "Still to pay this month" card (sum, count, overdue count), budgets by category with an over-budget state in red, monthly spending bar chart
- Recurring tab: the same "Still to pay" card, a Calendar/List switch (calendar is the default), month grid with recurring payments per day (overdue in red), day detail below with a "Zaplatené" (paid) action that records the matching transaction. The list mode shows each recurring payment with status paid / waiting / overdue
- Recurring state rule: **paid** if a matching transaction exists this month, **overdue** if the due day has passed without one, otherwise **waiting**. Matching must compare amount, date window and payee, and the same rule must be applied to bank imports so a manually recorded payment is not duplicated by the import (found during testing)
- Accounts: chips filter by account; the personal account has a lock icon and its restricted visibility must be enforced server-side, including in totals, recurring items and charts
- Add panel (docked right on wide, bottom sheet on phone): expense/income, amount, description, category, account, "repeat every month" option that creates a recurring payment
- Import panel: file picker, recognized bank profile, preview with duplicates struck through, account choice, confirm button ("Importovať N záznamov"); nothing is saved without confirmation
- Money is shown as whole euros with a thin minus sign and Slovak formatting; real amounts are stored in minor units with a currency code
- Rejected: one-page month (B) as it hides nothing but is too dense for phone; pure calendar (C) without tabs as the module home
- Open points for later: the Overview could show the next two or three upcoming payments with a link to the Recurring tab, category management and rules, month-over-month comparison, savings goals, and multi-currency (deferred)

## What to Look For
- How fast can you answer "what still has to be paid this month and is anything overdue"?
- Expected versus actual: each recurring payment is paid, waiting or overdue, based on whether a matching transaction exists
- Restricted account: the personal account is marked with a lock
- Import: format profile recognized, duplicate skipped, confirmation before saving
- Phone: stat cards, calendar dots and bottom sheet panels
