# Finance

## Design Decisions (006, winner D = A + C)
- Account chips and month switcher on top, tabs Prehľad / Transakcie / Pravidelné, actions "Import CSV" and "+ Záznam".
- Overview: income/expense/balance cards, "Ešte treba zaplatiť" card (sum, count, overdue), budgets by category with over-budget in red, monthly spending bars. Could show next 2 to 3 upcoming payments.
- Recurring tab: same still-to-pay card, Kalendár (default) / Zoznam switch, month grid with payments per day (overdue red), day detail with "Zaplatené" that records the matching transaction.
- **State rule:** paid if a matching transaction exists this month, overdue if the due day passed without one, else waiting. Matching compares amount, date window and payee, and the same rule applies to bank import so manual payments are not duplicated.
- Restricted account has a lock; visibility enforced server-side including totals, recurring items and charts.
- Add panel (docked/sheet): expense/income, amount, description, category, account, "repeat every month". Import panel: file, recognized bank profile, preview with duplicates struck through, account, explicit confirm; nothing saved otherwise.
- Money: whole euros with thin minus and Slovak formatting in the UI; store minor units plus currency code.
- Avoid: one-page month (too dense on phone), calendar alone as module home. Deferred: multi-currency, savings goals, categories/rules.

## Origin
Sketch 006. Source: sources/006-finance-module.
