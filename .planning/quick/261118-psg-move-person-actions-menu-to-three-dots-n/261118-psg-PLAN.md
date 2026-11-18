---
phase: quick-261118-psg
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - resources/js/Health/PersonHeader.vue
  - tests/js/health/PersonManage.test.js
  - resources/manual/en/health.json
  - resources/manual/sk/health.json
  - resources/manual/pl/health.json
autonomous: true
requirements: []
must_haves:
  truths:
    - "The person menu is a three-dots icon button next to the person's name, not a separate secondary button below the action row"
    - "The menu has the same items (Edit person, Archived items, Delete person), shown only when person.canEdit"
    - "The icon button has an accessible name and title from health.person.more_actions (sk/pl/en) and a 40px touch target"
    - "A long name wraps inside its own column and the dots stay beside it at 360, 820 and 1280 px"
  artifacts:
    - path: "resources/js/Health/PersonHeader.vue"
      provides: "kebab UiMenu inside the name row"
  key_links:
    - from: "resources/js/Health/PersonHeader.vue"
      to: "UiMenu icon-only"
      via: ":icon=Ellipsis icon-only (same pattern as resources/js/Pages/Health/UploadBatch.vue)"
      pattern: "icon-only"
---

<objective>
Move the person actions menu in the Health person header to a three-dots icon button next to the name; remove the separate "Ďalšie akcie pre túto osobu" secondary button. Same items, same permissions, same UiMenu behaviour.
Output: updated PersonHeader.vue, updated tests, manual note in en/sk/pl.
</objective>

<execution_context>
@/home/voslar/projects/family-hub/.claude/get-shit-done/workflows/execute-plan.md
</execution_context>

<context>
@resources/js/Health/PersonHeader.vue
@resources/js/Pages/Health/UploadBatch.vue
@resources/js/Components/ui/UiMenu.vue
@tests/js/health/PersonManage.test.js
</context>

<tasks>

<task type="auto">
  <name>Task 1: Put the kebab menu next to the name</name>
  <files>resources/js/Health/PersonHeader.vue</files>
  <action>
In PersonHeader.vue: import Ellipsis from '@lucide/vue'. Remove the standalone `<div v-if="person.canEdit" class="person-header__menu" data-testid="header-menu">` that sits after the actions row, and the CSS rules `.person-header__menu` and `.person-header__actions + .person-header__menu`. Inside `.person-header__main`, wrap the h2 in a new flex row `<div class="person-header__title-row">` containing the h2 and, `v-if="person.canEdit"`, `<div class="person-header__menu" data-testid="header-menu"><UiMenu :label="t('health.person.more_actions')" :icon="Ellipsis" :items="menuItems" icon-only @select="onMenu" /></div>` (keep data-testid="header-menu" so existing tests still find the trigger button). Do not change menuItems, onMenu, the delete dialog or the lang key. CSS: `.person-header__title-row { display: flex; align-items: center; gap: var(--space-2); min-width: 0; }`, h2 keeps overflow-wrap:anywhere and gets `min-width: 0`; `.person-header__menu { flex: none; }` so the dots never wrap onto their own line. Confirm the UiMenu icon-only trigger renders at least 40px (check UiButton icon-only size / --control-touch; if smaller, add a scoped `:deep` min-width/min-height of 40px on the trigger inside .person-header__menu). Label must surface as aria-label and title (UiMenu passes label to the icon-only UiButton; verify it sets both, otherwise add title). The container-query rule `@container (max-width: 640px) { .person-header__edit-action { display: none } }` is untouched and must keep working (it targets only edit actions, so the menu stays visible when they are hidden, which is how those actions remain reachable). Follow sketch-findings-family-hub skill for spacing tokens.
  </action>
  <verify>
    <automated>npx vitest run tests/js/health tests/js/brand/BrandName.test.js</automated>
  </verify>
  <done>Header renders kebab beside the name for editors only; no secondary "more actions" button below the action row.</done>
</task>

<task type="auto">
  <name>Task 2: Update tests for the icon-only trigger and layout</name>
  <files>tests/js/health/PersonManage.test.js</files>
  <action>
In PersonManage.test.js "offers editors..." test: the trigger no longer has visible text, so assert `trigger.getAttribute('aria-label')` is 'More actions for this person' (and title if present) instead of textContent, assert the trigger is inside `.person-header__title-row` next to the h2 (same parent) and that no other button in `[data-testid="person-header"]` carries that label. Keep the menu item assertions and view-only null assertion. Check other tests (Archive.test.js, a11y-health.test.js, pseudo-long and a11y sweeps) via grep for text-based lookups of 'More actions for this person'; they use `[data-testid="header-menu"] button` and should pass unchanged, fix any that do not. Then do a visual check of layout at 360, 820 and 1280 px with a long person name (use the repo's existing browser/screenshot tooling if available, else reason from the flex CSS and state so in the summary).
  </action>
  <verify>
    <automated>npx vitest run tests/js/health tests/js/a11y-sweep.test.js tests/js/brand/BrandName.test.js</automated>
  </verify>
  <done>All health, a11y sweep and brand tests green.</done>
</task>

<task type="auto">
  <name>Task 3: Manual and help text, then commit</name>
  <files>resources/manual/en/health.json, resources/manual/sk/health.json, resources/manual/pl/health.json</files>
  <action>
Grep manual health.json (en/sk/pl) and resources/help/*.json for mentions of the person menu or "More actions"; none were found in en health.json at planning time (the archive-delete section mentions "Archived items" at line ~179). In the en `archive-delete` section (or the people section near line 13) add one sentence: the person's menu (the three-dots button next to the person's name) holds **Edit person**, **Archived items** and **Delete person**; shown to members who can change the person. Add equivalent sk and pl drafts with identical section ids, counts and links (mark SK/PL for native review in the summary). If a help tip does mention the old button, reword it in en/sk/pl (do not change key meaning). Then run: `npx vitest run tests/js/manual/ManualCoverage.test.js tests/js/help/HelpCoverage.test.js` and `php artisan test tests/Feature/Manual` (or ./vendor/bin/pest tests/Feature/Manual). Commit with `git add` of the touched files only, message "feat(health): move person actions menu to three-dots next to name". No push, no deploy. The summary lists touched resources/manual files and notes SK/PL drafts.
  </action>
  <verify>
    <automated>npx vitest run tests/js/manual/ManualCoverage.test.js tests/js/help/HelpCoverage.test.js tests/js/brand/BrandName.test.js</automated>
  </verify>
  <done>Coverage tests and Feature/Manual pass; one commit made; working tree clean.</done>
</task>

</tasks>

<verification>
Vitest health, a11y-sweep, manual, help and brand suites plus Pest tests/Feature/Manual all pass.
</verification>

<success_criteria>
Kebab button beside name with accessible name and 40px target, same items and permissions, no old secondary button, docs updated in en/sk/pl, committed.
</success_criteria>

<output>
Create .planning/quick/261118-psg-move-person-actions-menu-to-three-dots-n/261118-psg-SUMMARY.md when done
</output>
