# Phase 3: To-Do - Context

**Gathered:** 2026-11-13
**Status:** Ready for planning

<domain>
## Phase Boundary

The family tracks shared and personal tasks together, including recurring ones, and gets reminded when tasks are due (email and web push). Delivers the to-do module UI (sketch 004: columns per person with a detail panel), the recurrence engine (reused by Finance in Phase 5) and the notification outbox (reused by Phase 4).
</domain>

<decisions>
## Implementation Decisions

### Tasks, assignment, visibility
- Fields: title, notes, due date (optional date or date+time), status To do / In progress / Done, one optional assignee (defaults to creator), creator.
- Visibility per task: Personal (creator only, hidden even from admins), Family (everyone with todo access), or Selected members (chosen list).
- Edit rights: needs todo `edit` grant; creator and assignee can edit; view-only members can read but not change. All checks go through the Phase 2 `requireAccess` policy layer plus record-level query scopes, with tests in the endpoint matrix.

### Recurrence
- Patterns: daily, weekly (chosen weekdays), monthly (day of month or last day), custom interval (every N days/weeks/months); stored as RRULE strings.
- Per task: recur from schedule (fixed by rule) or from completion (next date counted from completion).
- Month-end clamps to the last day of shorter months. Due times are stored with the family time zone (default Europe/Bratislava) so local times survive DST changes.
- Completing a recurring task creates the next occurrence and keeps completion history; a single occurrence can be skipped.
- The recurrence engine is a reusable service (Finance recurring expenses in Phase 5 use it).

### Reminders
- Default reminder at due time (08:00 for date-only tasks); optional per-task "remind me before" (15 min, 1 hour, 1 day).
- Channels: email (Stalwart SMTP via database queue) and Web Push (VAPID); each member chooses channels in settings. iOS push works only for the installed PWA, with an explanatory hint in the UI.
- A scheduler command finds due reminders and writes them to a notification outbox table; the worker sends with retry and dedupe. The outbox is reusable (Phase 4 wishlist occasions).
- Push text is a short title only ("Task due: ..."), never notes, to avoid lock-screen leaks.

### Claude's Discretion
Table and class names, push library choice (verify packagist: e.g. a maintained Web Push PHP package, package legitimacy checkpoint required), UI details following the Phase 01.1 design system and sketch 004, test organisation.
</decisions>

<code_context>
## Existing Code Insights

### Reusable Assets
- Phase 2 policy layer (`AccessGate`, `access:<rule>` middleware, endpoint matrix harness), audit logger, `User` locale preference, localized notifications pattern (InviteNotification), database queue + worker + scheduler containers.
- Phase 01.1 `Ui*` components, AppShell (module nav already lists To-do), dashboard To-do card, `QuickAdd` (currently discards input, intended to create tasks in this phase).
- Service worker is precache-only; push handling must add a push event handler without runtime caching of private data.

### Established Patterns
- Inertia pages, Pest feature tests per route with matrix rows, Vitest + axe sweeps in SK/PL/EN and light/dark, lang keys in lang/{en,sk,pl}.json, no-store dynamic responses.

### Integration Points
- routes/web/*.php (per-feature files) + tests/Support/EndpointMatrix/*.php; Home dashboard To-do card; QuickAdd; settings notification preferences section; scheduler (routes/console.php).
</code_context>

<specifics>
## Specific Ideas

Layout per sketch 004: to-do columns per person with a detail panel; Apple Reminders-like quick add.
</specifics>

<deferred>
## Deferred Ideas

Task priority/labels, multiple assignees, wishlist occasion reminders (Phase 4 reuses the outbox).
</deferred>
