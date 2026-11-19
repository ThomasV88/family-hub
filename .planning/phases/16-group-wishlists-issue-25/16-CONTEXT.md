# Phase 16: Group wishlists (issue #25) - Context

**Gathered:** 2026-11-20
**Status:** Ready for planning
**Source:** GitHub #25 (owner-written) + owner decisions of 2026-11-20

<domain>
## Phase Boundary
Owner request: "vo wishliste chcem mať možnosť vytvárať skupinový wishlist, napríklad jeden pre mňa a manželku, kde si budeme dávať spoločne veci".
A group wishlist is one list belonging to several members. It must coexist with the existing personal lists, children lists (managers) and hidden reservations without changing their behaviour.
</domain>

<decisions>
## Implementation Decisions (locked by owner, 2026-11-20)
- **Purpose:** both modes, chosen per list with a switch: (a) "gifts from others": the group is the recipient, other family members reserve and buy for it and reservations are hidden from every group member; (b) "shared purchases": no surprise, every group member sees reservations and bought state with the name of who did it.
- **Who creates and manages:** any member with wishlist edit access can create a group list and invite members; every group member can add, edit and remove wishes and manage the group (members, name, mode).
- **Reservation privacy in hidden mode:** nobody in the group sees who reserved or bought what. A group member sees only what a recipient sees today for a personal list.
- Server-side enforcement through the existing access layer; a role-by-endpoint matrix and the wishlist privacy tests must cover the new routes and prove that personal lists, children lists and managers are unchanged.

### Claude's Discretion
- Data model (group table plus members pivot, how wishes attach to a group vs an owner, migration of existing queries), naming in UI, where the lists appear (Wishlist tabs/sections), how "recipient" logic in reservations, contributions (co-buying), occasions and reminders treats a group (for example giver-only reminders, birthdays of members), what happens when a member leaves or is removed, deleting a group and its wishes, limits (members per group, groups per user), and whether the mode can be switched after creation (if so, what happens to existing reservations: record the decision in the plan, prefer a safe default and an explicit confirmation).
- Reuse the existing wishlist services, policies, ViewerContext/WishAccess and the managers mechanism where it fits; read Phase 4 (wishlist) summaries and the Phase 10-12 module patterns first.
</decisions>

<canonical_refs>
## Canonical References
- `.planning/ROADMAP.md` Phase 16, `.planning/REQUIREMENTS.md` WLG-01..06
- `app/Wishlist/` (WishAccess.php, ViewerContext.php, WishlistMembers.php, WishlistManagerSync.php, Giving/, Occasions/), `app/Http/Controllers/Wishlist/`, `app/Models/Wish*.php`, `routes/web/wishlist*.php`
- `tests/Support/WishlistPrivacy.php`, `tests/Support/EndpointMatrix/wishlist*.php`, `tests/Feature/Wishlist/` (ReservationPrivacyTest, ChildListTest, WishlistManagersTest)
- `.planning/phases/04-*` (wishlist phase) summaries
- `CLAUDE.md`: user documentation rule (manual + help in en/sk/pl, coverage tests), issue tracking rule
- `.claude/skills/sketch-findings-family-hub/SKILL.md`
</canonical_refs>

<specifics>
- The final commit carries `Fixes #25`. Staging uses synthetic data only; add a synthetic group list to the staging seed if the seeders show wishlists.
</specifics>

<deferred>
## Deferred Ideas
- Group lists for other modules (shopping lists already have members), per-group notifications, recurring group occasions.
</deferred>

---
*Phase: 16-group-wishlists-issue-25*
