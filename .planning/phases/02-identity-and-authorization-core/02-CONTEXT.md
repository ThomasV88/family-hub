# Phase 2: Identity and Authorization Core - Context

**Gathered:** 2026-11-13
**Status:** Ready for planning

<domain>
## Phase Boundary

Family members join by invitation and sign in (email+password or Google); every piece of data is guarded server-side by per-member, per-module permissions controlled by the admin. Includes invites, sessions with admin revoke, permission presets, the deny-by-default policy layer with a role-by-endpoint test matrix, and an admin-viewable audit log. TOTP/passkey and re-auth for sensitive data are Phase 6.
</domain>

<decisions>
## Implementation Decisions

### Invites and first admin
- First admin is created by an artisan command `family:create-admin` run once on the server; it is the only way to create a user without an invite.
- Invite is single-use, expires after 7 days, admin can revoke or resend.
- Invite is sent by email via the owner's Stalwart SMTP; admin can also copy the link to share manually.
- At accept the invitee sets name and password, or signs up with Google; the Google email must match the invited address.

### Sign-in and sessions
- Laravel Fortify (email/password) and Socialite (Google) per CLAUDE.md; passwords hashed with Laravel's default hasher.
- Google links only by verified Google email and Google ID to an already invited or existing account; no self-signup via Google.
- "Stay signed in" 30 days, otherwise browser session; sessions stored in the database so admin can list and revoke per member.
- Login rate limiting and lockout, generic error messages, password reset by email.

### Permissions
- Per member per module: none, view, edit. Modules: todo, wishlist, finance, health; settings is admin-only.
- Presets (adjustable after applying): Parent: todo/wishlist/finance edit, health none. Child: todo/wishlist edit, finance and health none. Grandparent: todo/wishlist edit, finance and health none. Guest: todo view only.
- One deny-by-default `requireAccess(user, module, level)` policy layer called by every controller/route; role-by-endpoint Pest test matrix that fails if an endpoint is not registered in it.
- Admin always has settings access, but health and finance default to none even for admins until explicitly granted.

### Audit log
- Logs reads and writes of health and finance data, permission changes, invites and session revokes: who, what, when, IP; never content.
- Admin view is a filterable read-only page; no edit/delete. Append-only, retained 2 years.

### Claude's Discretion
Table and class names, middleware structure, email templates, admin UI layout (use the Phase 01.1 design system components and AppShell), test organisation.
</decisions>

<code_context>
## Existing Code Insights

### Reusable Assets
- Phase 01.1 `Ui*` components, `AppShell`, `ThemeSwitch`, i18n SK/PL/EN (lang/*.json), `/locale` endpoint.
- Phase 1 heartbeat slice (public), `app/Models/User.php`, users/sessions/jobs migrations, database queue + worker (use for mail), staging seeder (`@example.test` users).

### Established Patterns
- Inertia pages in `resources/js/Pages`; Pest feature tests; Vitest; no-store headers on dynamic responses; precache-only service worker (never cache authenticated data); AccountMenu has Settings/Log out placeholders waiting for this phase.

### Integration Points
- AccountMenu (add Settings, Log out), AppShell nav (module access should hide modules without a grant), `routes/web.php` (auth middleware groups), nginx locations for new routes.
</code_context>

<specifics>
## Specific Ideas

Reservations/finance/medical privacy rules build on this layer in later phases; health and finance are restricted by default.
</specifics>

<deferred>
## Deferred Ideas

TOTP/passkey and re-auth timeout (Phase 6); record-level rules such as hidden wishlist reservations (Phase 4).
</deferred>
