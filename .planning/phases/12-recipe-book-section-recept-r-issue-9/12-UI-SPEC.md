---
phase: 12
slug: recipe-book-section-recept-r-issue-9
status: draft
shadcn_initialized: false
preset: none
created: 2026-11-17
---

# Phase 12 — UI Design Contract

> Visual and interaction contract for Phase 12 (GitHub #9, module "Receptár" / Recipes). Everything reuses the locked design system (`resources/css/tokens.css`, sketch findings, `Components/ui/*`). No new tokens, colours, type sizes or weights. Sources: 12-CONTEXT.md, ISSUES-6-11-OWNER-DECISIONS.md (Phase 12 block), ROADMAP Phase 12, Phase 11 UI-SPEC (format, quantity and unit rules, picker/dialog/sticky-bar patterns), `resources/js/Shopping/*` (reuse `units.js`, `CatalogImage`, `ImageUploadField`, `StickyActionBar`, `PeopleField` patterns), Wishlist (image-card gallery). Deferred and not designed: cooking history, meal planning, tags, photo-of-cookbook import.

---

## Design System

| Property | Value |
|----------|-------|
| Tool | none (in-house Vue set in `resources/js/Components/ui`; shadcn gate not applicable: Laravel + Inertia + Vue) |
| Preset | not applicable |
| Component library | none (in-house `Ui*` components) |
| Icon library | `@lucide/vue` |
| Font | `--font-sans` |

Components reused (do not re-create): `UiAvatar`, `UiButton`, `UiCard`, `UiCheckbox`, `UiChip`, `UiConfirmDialog`, `UiDetailPanel`, `UiDialog`, `UiEmptyState`, `UiErrorState`, `UiField`, `UiInput`, `UiList`/`UiListRow`, `UiMenu`, `UiProgress`, `UiRadio`, `UiSelect`, `UiSkeleton`, `UiSwitch`, `UiTabs`, `UiTag`, `UiTextarea`, `UiAlert`, `useToast`, `HelpTip`, `AppShell`, plus from Shopping `StickyActionBar`, `ImageUploadField` (generalised to multiple photos), `units.js` (`unitLabel`, `formatQuantity`, `localeCode`; the unit list is the same Shopping unit list, built-in plus administrator units, no second unit system).

New components (compositions only):

| Component | Built from | Purpose |
|-----------|-----------|---------|
| `Pages/Recipes/Index.vue` | UiInput, UiSelect, UiChip, UiSwitch, UiEmptyState | Overview grid with search and filters |
| `Pages/Recipes/Show.vue` | UiTabs, UiList, UiMenu, UiProgress | Detail and cooking mode |
| `Pages/Recipes/Form.vue` (Create and Edit) | UiField, UiInput, UiTextarea, UiSelect | Manual form, shared by create, edit and the review step of URL import |
| `Components/Recipes/RecipeCard.vue` | UiCard, UiTag | Grid card: photo 4:3, title, meta |
| `Components/Recipes/RecipePhotoGallery.vue` | `<img>`, UiButton | Main photo plus thumbnails |
| `Components/Recipes/RecipePhotosField.vue` | ImageUploadField pattern | Multiple uploads, main marker, remove, order |
| `Components/Recipes/ImportFromUrlDialog.vue` | UiDialog, UiInput, UiAlert | URL step, notice, loading, failure |
| `Components/Recipes/IngredientRows.vue` | UiInput, UiSelect, UiButton | Editable ingredient lines (name, quantity, unit, note) |
| `Components/Recipes/StepRows.vue` | UiTextarea, UiButton | Editable step list with move up/down |
| `Components/Recipes/ServingsControl.vue` | UiButton, UiInput | Stepper with recalculation |
| `Components/Recipes/IngredientRow.vue` | UiListRow, UiCheckbox | Detail ingredient with "prepared" tick |
| `Components/Recipes/StepRow.vue` | UiListRow, UiCheckbox | Detail step with "done" tick |
| `Components/Recipes/AddToShoppingDialog.vue` | UiDialog, UiRadio, UiSelect, UiInput, UiTag | Review before adding to Shopping |
| `Components/Recipes/CategoryField.vue` | UiChip, UiInput | Pick or type categories |
| `Components/Recipes/recipeScale.js` | pure helper | Scaling and fraction display |

Navigation: one entry in sidebar / "More" (beyond five bottom-bar modules, as Shopping) with icon `ChefHat`, label "Recipes" (SK "Receptár", PL "Receptarz"). No dashboard tile in this phase.

---

## Spacing Scale

Existing tokens only: `--space-1` 4, `--space-2` 8, `--space-3` 12, `--space-4` 16, `--space-6` 24, `--space-8` 32, `--space-12` 48, `--space-16` 64.

| Token | Value | Usage in this phase |
|-------|-------|---------------------|
| xs | 4px | icon to label gap; title to meta line inside a card |
| sm | 8px | gap between chips, between thumbnails, between header buttons |
| md | 16px | card inner padding; field gap in forms and dialogs; row padding; grid gap on phone |
| lg | 24px | grid gap on tablet and desktop; gap between detail sections |
| xl | 32px | page top spacing; gap between ingredients and steps blocks on desktop |
| 2xl | 48px | empty-state vertical padding |
| 3xl | 64px | empty-state vertical padding on wide only |

Exceptions (sizes, not spacing): card photo aspect ratio 4:3, `object-fit: cover`, top corners `--radius-md`, placeholder icon `ChefHat` 32px on `--color-surface-2`. Detail hero photo aspect 16:10 (max height 360px wide, 240px phone), thumbnails 64px square. Touch targets: all controls at least `--control-touch` 44px in cooking mode; elsewhere `--control-md` 40px minimum (44px on phone). Cooking-mode ingredient and step rows at least 56px tall, tick control hit area 44px by 44px (visual box 24px). Sticky bottom action bar on phone 64px plus safe-area inset. Servings stepper buttons 44px.

---

## Typography

Existing scale only: 4 sizes, 2 weights.

| Role | Size | Weight | Line Height |
|------|------|--------|-------------|
| Body / ingredient name / step text / input text / card title | `--text-base` 16px | 400 (600 for card title and ingredient quantity) | 1.5 |
| Label / meta / tag / hint / button label / quantity | `--text-sm` 14px | 600 for labels and quantity, 400 for meta and hints | 1.5 |
| Heading (section titles "Ingredients", "Steps", "Notes", dialog and panel titles) | `--text-xl` 20px | 600 | 1.2 |
| Display (page title, recipe title on detail) | `--text-2xl` 24px | 600 | 1.2 |

Cooking mode adds no new size: step text stays `--text-base` 16px with line-height 1.5 and larger spacing (`--space-4` padding, step number badge in `--text-sm` 600). Weights 400 and 600 only. SK/PL strings run about 30 percent longer: titles wrap to 2 lines in cards (clamp to 2 lines with ellipsis in the grid only; full title always on detail), buttons wrap or grow, never truncate ingredient names or step text.

---

## Color

Tokens from `tokens.css` (light and dark defined); nothing new.

| Role | Value | Usage |
|------|-------|-------|
| Dominant (60%) | `--color-bg` | page background |
| Secondary (30%) | `--color-surface`, `--color-surface-2` | cards, rows, dialogs, panels, photo placeholders, ticked rows |
| Accent (10%) | `--color-primary` | see list below |
| Destructive | `--color-danger` | delete recipe confirm, remove photo / ingredient / step confirm, form errors, nothing else |

Accent reserved for: primary button (Create recipe, Save recipe, Read recipe, Add to shopping list confirm, Add to list), focus ring, selected tab underline, checked tick state (prepared / done check), the favourite `Heart` when active (filled), selected filter chip, the "main photo" marker border on the selected thumbnail. Nothing else.

State styling (never colour alone; each has label or icon):

| State | Treatment |
|-------|-----------|
| Private recipe | `UiTag` neutral with `Lock` icon "Private" (card, detail header, form) |
| Shared recipe | `UiTag` neutral with `Users` icon "Shared" |
| Favourite | `Heart` icon button, outline when off, filled accent when on, `aria-pressed`; also on the card (top right of photo, 44px target on a `--color-surface` round backing) |
| Ingredient prepared | `UiCheckbox` checked (accent), row `--color-surface-2`, text keeps full colour, never struck through |
| Step done | `UiCheckbox` checked, step number badge replaced by `Check`, row `--color-surface-2`, text colour muted slightly, never struck through |
| Scaled quantity (servings differ from the recipe) | quantity text unchanged style, plus a `UiTag` neutral "Scaled" next to the servings control; reset button restores |
| AI-filled field awaiting review | `UiTag` neutral "From the page" with `Sparkles` icon next to the field label, removed once the user edits the field or the form is saved; a `UiAlert` info summarises |
| Not matched to catalog (shopping review) | `UiTag` warn variant "Not in catalog" with `Ban`-free icon `PencilLine`; matched = `UiTag` neutral "Matched" with `Link` icon |

No red except destructive confirms and form errors.

---

## Screens and Interaction Contract

### 1. Recipe overview (`Pages/Recipes/Index.vue`)

- Title "Recipes" (`--text-2xl`); right-aligned primary `UiButton` "New recipe" (`Plus`); on phone the button goes full width under the title (no floating button; the bottom bar holds one).
- Toolbar under the title (stacked on phone, one row on wide, `--space-2` gaps):
  - Search `UiInput` (icon `Search`, label "Search recipes", placeholder "Title or ingredient"). One field matches title and ingredient names, accent and case insensitive, debounced 300 ms, server-side. The ingredient match is also exposed separately in the filter panel (below) so users can search one without the other.
  - `UiButton` ghost "Filters" (`SlidersHorizontal`) with a count badge when active; opens a `UiDialog` (bottom sheet on phone) on phone and an inline row of controls on wide (container at least 640px): "Category" `UiSelect` (multi, shows `UiChip`s), "Author" `UiSelect` (members, first option "Everyone"), "Ingredient" `UiInput`, "Show" segmented `UiTabs`-style radio: "All / Mine / Shared with me", and `UiSwitch` "Favourites only".
  - Favourites quick toggle: `UiButton` toggle with `Heart`, label "Favourites", `aria-pressed`, always visible next to the search (so filtering favourites is one tap). It mirrors the switch in the filter panel (single state).
  - Active filters are shown as removable `UiChip`s under the toolbar plus ghost "Clear filters". Filters live in the query string (back button works, link shareable within the app).
- Grid: CSS grid inside a container query: 1 column under 480px container width, 2 columns from 480px, 3 columns from 840px; never more than 3 per row (hard cap). Gap `--space-4` phone, `--space-6` wide. Content max width `--content-max`.
- Card (`RecipeCard`, `UiCard`, the whole card is one link to the detail, focus ring on the card): photo 4:3 (main photo, `loading="lazy"`, placeholder `ChefHat` when none, `alt=""` because the title follows), favourite `Heart` button overlay top right, title (`--text-base` 600, 2-line clamp), meta line `--text-sm` muted: "{n} min · {servings} servings" (omit unset parts) and below it `UiTag` visibility ("Private" / "Shared") plus the first category as `UiTag` (+ "+{n}" if more). For recipes by other members the author first name with `UiAvatar` 24 is shown on the meta line ("by Anna"); on own recipes it is omitted.
- Ordering: newest first; no sort control in v1. With search active, title matches first, then ingredient matches.
- Pagination: 24 per page with a "Load more" ghost button (`aria-live` announces "{n} more recipes loaded").
- Empty (no recipes at all): `UiEmptyState` icon `ChefHat`, title "No recipes yet", body "Save your family recipes here, type them in or paste a link and let the app read it.", primary "New recipe".
- Empty (filters/search gave nothing): icon `SearchX`, title "No recipe matches", body "Try a different word or clear the filters.", ghost "Clear filters".
- Empty favourites: title "No favourites yet", body "Tap the heart on a recipe to find it here quickly."
- Loading: 6 `UiSkeleton` cards at real card height. Error: `UiErrorState` "We could not load the recipes." with button "Try again".
- No access: nav hidden without the module permission; direct URL gives the standard 403.

### 2. New recipe: choice and "from URL" flow

"New recipe" opens `UiDialog` "New recipe" (bottom sheet on phone) with two large option rows (`UiListRow`, 64px, whole row is a button):
- "Type it in" (icon `PencilLine`), subtitle "Fill in a blank form." goes straight to the manual form page (`/recipes/create`).
- "Read from a web page" (icon `Link`), subtitle "Paste a link and the app fills in the form for you to check." continues in the same dialog to the URL step. Row is hidden (not disabled) when no AI agent is enabled; then the dialog is skipped and "New recipe" goes straight to the form. Instead a muted hint is not shown to avoid noise; the AI-unavailable state below covers a failed attempt.

URL step (`ImportFromUrlDialog`):
- Field "Web page address" (`UiInput`, `type="url"`, `inputmode="url"`, placeholder "https://", autofocus on desktop only, required, max 2000, validated http/https). Helper: "Works with most recipe pages."
- One-time notice `UiAlert` info shown the first time the user uses the import (stored per user once they confirm): title "The page text is sent to the AI provider", body "To read the recipe, the text of the page is sent to the AI provider your administrator has turned on. Nothing else from your account is sent." with checkbox-free button "Got it" which reveals the field and is remembered; afterwards the notice collapses to a muted one-line hint with `Info` icon under the field: "The text of the page is sent to the AI provider." and a `HelpTip` `recipes.import`.
- Footer: ghost "Cancel", primary "Read recipe" (disabled when the URL is empty or invalid).
- Loading (up to about 60 seconds): the dialog keeps the field read-only and shows `UiProgress` indeterminate with text "Reading the page. This can take a minute." and ghost "Cancel" (stops waiting; the job result is discarded). The user may close the dialog; the import continues in the worker and the draft is offered on the overview as a `UiAlert` info row "Your recipe from {site} is ready to review." with button "Review". Polling every 2 s, `aria-live="polite"` announces completion.
- Success: the dialog closes and navigates to the form page (`Form.vue`) in review mode, see section 3.
- Failure states (inline `UiAlert` danger in the dialog, field stays editable, each has a clear next step):
  - Page could not be fetched (blocked address, timeout, non-HTML, 4xx/5xx): "We could not open that page. Check the address, or type the recipe in yourself."
  - Page has no recognisable recipe: "We could not find a recipe on that page. Try the page with the full recipe, or type it in yourself."
  - AI unavailable (agents off, quota or login problem): `UiAlert` warn "Reading pages is not available right now. You can type the recipe in yourself." (no technical detail shown to non-admins).
  - Each failure shows two buttons: primary "Try again" (or "Change address") and secondary "Type it in" which opens the manual form with the source URL pre-filled in the "Source" field so nothing the user pasted is lost.
- Rate limit (server): warn "You have read too many pages today. Try again later." with "Type it in".
- Untrusted content: the page text is never rendered as HTML anywhere; all auto-filled values are plain text in inputs.

### 3. Recipe form: create, edit and review (`Pages/Recipes/Form.vue`)

One form for manual create, edit and the review step after URL import. Page title "New recipe" / "Edit recipe" / "Check the recipe". Layout: single column phone; two columns on wide from 840px container (left: photos, title, basics, categories, visibility; right: ingredients and steps). Sticky footer (phone: `StickyActionBar`; wide: bottom of the page, right-aligned): ghost "Cancel" and primary "Save recipe" (disabled until valid; spinner while saving).

Review mode (after import): a `UiAlert` info at the top: "Check everything the app filled in from the page. Nothing is saved until you press Save recipe." Fields that came from the AI carry the "From the page" tag (see Color). Fields the AI could not find stay empty and show hint "Not found on the page." (muted). The source URL is shown in the "Source" field (read-only text link with `ExternalLink` icon plus ghost "Edit"), kept on the recipe. Leaving the page with unsaved changes asks `UiConfirmDialog`: title "Leave without saving?", body "The recipe has not been saved yet.", confirm "Leave", cancel "Keep editing". Page photos are not fetched in v1 unless the fetcher returns one image URL: if it does, a muted line "A picture from the page is available." with ghost "Use it as the photo" (the server downloads it through the safe fetcher; failure toast "Could not use that picture.").

Fields (in order):
1. Photos (`RecipePhotosField`): grid of 96px thumbnails (`--radius-md`, cover), ghost-bordered "Add photos" tile (`ImagePlus`, 44px+). Multiple upload (up to 10, JPG, PNG or WebP, up to 5 MB each, hint under the tile). First uploaded becomes main; the main photo has an accent 2px border plus `Star` badge and `UiTag` "Main". Each thumbnail has an overflow `UiMenu` (44px): "Make main", "Move earlier", "Move later", "Remove" (danger confirm "Remove this photo?" / confirm "Remove photo" / cancel "Keep photo"). The server re-encodes and strips metadata (as Shopping/profile images). Uploading shows a `UiSkeleton` tile; failure inline error "We could not use that picture. Choose a JPG, PNG or WebP image and try again."
2. "Title" `UiInput` (required, max 120).
3. "Description" `UiTextarea` (optional, max 1000, 3 rows, hint "A short line about the dish.").
4. Basics row (grid, 2 columns phone, 4 wide): "Servings" `UiInput` numeric (`inputmode="numeric"`, integer 1 to 99, default 4, helper "How many portions the quantities below make."), "Preparation time (min)" and "Cooking time (min)" `UiInput` numeric (0 to 1440, optional, whole minutes). Total time is shown as muted text "Total: {n} min" computed, not stored separately.
5. "Categories": `CategoryField`: chips of selected categories (`UiChip` with close, 44px target), `UiInput` "Add category" with suggestions from categories already used in the family plus a starter set (Breakfast, Lunch, Dinner, Soup, Salad, Dessert, Baking, Snack, Drink, Vegetarian), Enter or comma commits; max 5, each max 30; case-insensitive duplicate guard. AI-filled categories map to the same list; unknown ones become typed categories the user can remove.
6. "Ingredients" (`IngredientRows`, heading `--text-xl`): each row has "Quantity" (`UiInput` decimal, 0.01 to 9999, optional for "to taste"), "Unit" (`UiSelect` from the Shopping unit list, first option "No unit"), "Name" (`UiInput`, required, max 120) and optional "Note" (`UiInput`, max 80, placeholder "chopped, to taste"), then a 44px icon button `Trash2` (aria-label "Remove ingredient {n}"; no confirm for an empty row, confirm-free removal with undo toast "Ingredient removed" and action "Undo"). On phone each ingredient is a stacked card (quantity and unit side by side on one line, name full width, note below); on wide a single line. Buttons: "Add ingredient" (`Plus`, secondary) below the last row, Enter in the Name field adds a new row and focuses it. Rows reorder with "Move up/down" buttons in a row overflow menu (no drag-only interaction). Optional group heading line: not in v1.
   - Pasting multiple lines into the Name field of a row offers "Split into {n} ingredients" via `UiAlert` info with button "Split" (best-effort parse "200 g flour": quantity, unit, name; unknown parts stay in the name). 
   - Quantity rules as Shopping section 10 (positive decimal, locale-aware comma, `inputmode="decimal"`); here fractions up to 2 decimals and the maximum is 9999 (recipes use grams).
7. "Steps" (`StepRows`): numbered `UiTextarea` per step (required text, max 1000, auto-grow, 2 rows min), icon buttons "Move up", "Move down", "Remove step {n}" (44px), "Add step" (`Plus`). Numbers are display-only and renumber automatically.
8. "Source": `UiInput` `type="url"` (optional, max 2000). When set it is shown on the detail as a link.
9. "Who can see this recipe": two `UiRadio`s, "Only me" (`Lock`, subtitle "Only you can see this recipe.") and "Everyone in the family who uses Recipes" (`Users`, subtitle "Others can read it, cook from it and add it to shopping. Only you can edit it."), default Only me. `HelpTip` `recipes.sharing`. Changing a shared recipe to private later shows `UiConfirmDialog` "Make this recipe private?" body "Other members will no longer see it. Their notes, ticks and favourites on it are kept but hidden." confirm "Make private" cancel "Keep shared" (non-danger accent).
- Validation: inline `UiField` errors with `aria-live="polite"`, focus moves to the first invalid field; at least one ingredient and one step are required to save ("Add at least one ingredient." / "Add at least one step."). Request failure: `UiAlert` danger at the top "Your changes were not saved. Check the highlighted fields and try again."
- Edit: only the author sees "Edit recipe"; others get 403 on the URL. When an author edits a shared recipe while someone else is cooking, ticks of ingredients and steps are kept by position and id: ingredients and steps keep their ids when edited and lose only the ticks of removed ones. A muted hint at the top of the edit form for shared recipes: "Other members may be cooking from this recipe. Their ticks are kept for lines you do not remove."
- Save success: toast "Recipe saved" and navigation to the detail.

### 4. Recipe detail (`Pages/Recipes/Show.vue`)

Header (stacked on phone, one row on wide):
- Back link "Recipes" (`ChevronLeft`, 44px), title `--text-2xl` (wraps), tags (visibility; author name with `UiAvatar` 24 "by Anna" for other members' recipes), `Heart` favourite toggle (44px, "Add to favourites" / "Remove from favourites" aria-label, per member, toast-free, optimistic with rollback).
- Meta line `--text-sm` muted with icons: `Clock` "Prep {n} min", `Flame` "Cook {n} min", `Users` servings (via the control), categories as `UiTag`s, source link `ExternalLink` "Source: {host}" (opens in a new tab with `rel="noopener noreferrer"`; shows hostname only, never injects page content).
- Actions: wide, right-aligned: primary "Add to shopping list" (`ShoppingCart`; only when the member also has write access to Shopping, otherwise hidden), author-only secondary "Edit" (`Pencil`), overflow `UiMenu` `Ellipsis`: "Change visibility" (author), "Reset my progress" (everyone), "Delete recipe" (author, danger). Phone: primary "Add to shopping list" in `StickyActionBar`; the rest in overflow; the bar is replaced by the cooking bar in cooking mode (below).
- Photos: `RecipePhotoGallery`: hero (main photo first) with thumbnail strip beneath (64px, tap switches hero; the active thumbnail has a 2px accent border); on phone the hero supports swipe plus "Previous photo"/"Next photo" icon buttons (44px, `aria-label`), position text "{i} of {n}". Tap on hero opens a `UiDialog` full-screen viewer (pinch zoom native, close button 44px). No photos: placeholder `ChefHat` block, no gallery controls. Images served via authenticated route, `alt` is the recipe title for the hero, thumbnails `alt=""`.
- Description (if any) as body text under the header.
- Layout: phone single column in the order header, photos, servings, tabs "Ingredients / Steps / Notes" (`UiTabs`, label "Recipe sections", default "Ingredients", tab state kept in the address `?tab=`); on wide (container at least 840px) two columns: left column photos and ingredients (sticky servings control), right column steps and notes (no tabs). 

Servings control (`ServingsControl`, in the ingredients section header):
- Label "Servings" with stepper: `-` (44px, `Minus`, aria-label "Fewer servings"), numeric `UiInput` (width 64px, integer 1 to 99, editable by typing), `+` (44px, "More servings"). The default is the recipe's servings. A change rescales all ingredient quantities immediately (client-side, `quantity x chosen / original`), shows `UiTag` "Scaled" and ghost "Reset to {n}" (`RotateCcw`). The chosen servings is remembered per member per recipe (so cooking resumes with the same amount) and is also the value sent to the shopping list.
- Display rules (`recipeScale.js`): up to 2 decimals via `formatQuantity` (locale comma); when the unit is `tsp` or `tbsp` or the unit is none/pcs and the value is within 0.01 of a common fraction (1/4, 1/3, 1/2, 2/3, 3/4) show the fraction as "1/2" with the unit ("1/2 tsp"); `g` over 1000 is shown as is, not converted (no automatic unit conversion in the recipe view to keep what the author wrote; conversion exists only in the Shopping review through the shared `units.js` rules where compatible). Ingredients with no quantity ("to taste") are never scaled and show their note.
- Whole pieces: a scaled quantity for "pcs" with a fraction is shown with its decimals ("1,5 pcs" / "1,5 ks"), never rounded silently.
- Accessibility: stepper in a `role="group"` labelled "Servings"; the result announced via `aria-live="polite"` "Quantities updated for {n} servings".

Ingredients (`IngredientRow`, `UiList`): row min 56px (cooking mode and phone) / 48px wide; leading `UiCheckbox` 44px hit area labelled "Prepared: {name}", then quantity and unit (`--text-sm` 600, fixed column about 96px, right aligned on wide, stacked above the name when the container is under 360px), name (`--text-base`), note muted below. Tick persists per member immediately (optimistic, rollback, toast "Could not save. Try again."); others' ticks are never shown. Section header shows progress text "{x} of {y} prepared" (`UiProgress` 4px bar, `aria-label`). Ticked rows get `--color-surface-2` and keep position (rows never reorder).

Steps (`StepRow`): numbered list; row min 56px; leading `UiCheckbox` 44px "Done: step {n}" and the number badge (`--text-sm` 600, 28px circle on `--color-surface-2`), text `--text-base` 1.5 line height, `--space-4` padding. Progress "{x} of {y} steps done". When the last step is ticked, a small muted `UiAlert` info "All steps done. Enjoy your meal." with ghost "Reset my progress". Ticking is never required for the next step (no forced order).

Notes: "My notes" `UiTextarea` (max 2000, 4 rows, auto-grow, label "My notes (only you can see them)", placeholder "For example: use less salt next time."), autosaves 800 ms after typing stops with a quiet status text "Saved" / "Saving..." (`aria-live="polite"`), failure text "Could not save your notes" plus button "Try again". Personal to the member, never visible to the author or others; on the author's own recipe too. `HelpTip` `recipes.notes`.

Cooking mode (phone first, also usable on iPad):
- Toggle button "Cooking mode" (`UtensilsCrossed`, switch-style `UiButton` `aria-pressed`) in the header; on phone it is the second button in the sticky bar. Entering it hides the gallery strip and description, keeps the title, servings control, ingredients and steps in a single scroll with the tabs replaced by two sticky anchors ("Ingredients" / "Steps") as a `UiTabs` bar under the header, and sets larger rows: min 64px, tick hit area 48px, `--space-4` row padding, step text stays `--text-base` (no new font size) with a `--space-6` gap between steps.
- The screen is kept awake while cooking mode is on, using the Screen Wake Lock API where supported (released when the mode is turned off, the tab hides or the page unloads; re-acquired on visibility change). Where unsupported nothing is shown and nothing breaks; no permission prompt exists for it. A muted hint under the toggle when active: "The screen stays on while you cook." (only shown when supported).
- The cooking bar (`StickyActionBar`, phone): "Ingredients {x}/{y}" and "Steps {x}/{y}" progress text left, ghost "Leave cooking mode" right (44px). Contrast: same tokens, no brightness trick; dark theme works as is.
- Cooking mode state is local to the device (not stored), default off.

Delete (author): overflow "Delete recipe" opens `UiConfirmDialog` danger: title "Delete “{title}”?", body "The recipe, its photos and everyone's notes and ticks will be deleted. Shopping lists you already made from it are not changed. This cannot be undone.", confirm "Delete recipe", cancel "Keep recipe". Success: navigate to the overview with toast "Recipe deleted".

Detail states:
- Loading: `UiSkeleton` hero, title bar and 6 rows. Error: `UiErrorState` "We could not load this recipe." with "Try again".
- Not found or no longer shared (404): `UiEmptyState` icon `ChefHat`, title "This recipe is not available", body "It may have been deleted or made private.", button "Back to recipes".
- Forbidden action (403 toast): "You are not allowed to do that with this recipe."
- Read only for non-authors: Edit, Change visibility and Delete are not rendered (not just disabled); a muted line under the title "Only {name} can edit this recipe." shown only to non-authors.
- Ingredients empty (should not occur): `UiEmptyState` size sm "No ingredients".

### 5. Add to shopping list (`AddToShoppingDialog`)

Trigger: "Add to shopping list". `UiDialog` (full-height bottom sheet on phone, centred max width 720px on wide, max height 80vh, list scrolls inside). Title "Add to shopping list". Step 1 and step 2 are on one scrolling surface (no wizard) to keep it short:

A. Target (top): two `UiRadio`s:
 - "New list" (default when the member has no open list), with `UiInput` "Name of the new list" (default "{recipe title}", max 80) and, optional, `UiSelect` "Store" (same as Shopping create, first option "No store"). The responsible person is the current user.
 - "Open list" with `UiSelect` "Choose a list" of open, unlocked lists where the member may add items (contributors and responsible person; locked or archived lists are not offered; if there are none the radio is disabled with hint "You have no open list you can add to."). Default when one or more exist: the most recently changed list.
B. Servings: "Quantities for" read-only text "{n} servings" with a small `ServingsControl` copy so the user can adjust here too (same scale rule, default is the servings chosen on the detail).
C. Review lines (`UiList`, one row per ingredient, all selected by default): `UiCheckbox` (include, 44px, label "Include {name}"), editable "Name" `UiInput`, "Quantity" `UiInput` decimal and "Unit" `UiSelect` (compact, stacked on phone), and a match tag:
   - "Matched" (`UiTag` neutral, `Link`) with the catalog thumbnail 32 and the catalog name when the matching found one (case and accent insensitive on name, with simple plural and unit-aware rules); an underlined ghost "Change" opens a small `UiSelect` of catalog items plus "Add as typed item".
   - "Not in catalog" (`UiTag` warn) means it is added as a typed (manual) item with the name as edited. Hint at the section top: "Items found in the catalog get their picture. The others are added as typed items." (`HelpTip` `recipes.shopping`).
   - Ingredients without a quantity are included with quantity 1 and unit "pcs" shown as editable fields, with a muted hint "No amount in the recipe." and the quantity field highlighted only by the label, not by colour.
   - Unit conversion: when the target list already holds the same catalog item and the unit is compatible (g to kg, ml to l, tbsp/tsp not converted), the quantities are merged by the Shopping duplicate rule using `units.js`; the row shows a muted line "Already on the list: {qty}. It will be added up." Incompatible units add a second typed line and show "Different unit: kept as a separate line."
D. Footer (sticky): ghost "Cancel", primary "Add {n} items" (disabled when 0 selected or any included row invalid; label "Add 1 item" for one; ICU plural SK/PL) or "Create list and add {n} items" for the new list case. Success: dialog closes, toast "{n} items added to {list}" with action "Open list" (navigates to the Shopping list; on phone the toast action is 44px). Partial server failure never half-adds: one transaction; failure shows `UiAlert` danger in the dialog "We could not add the items. Nothing was added. Try again." and the dialog stays open with entries intact.
- Validation: name required (max 80), quantity "Enter a quantity greater than 0.", the same rules as Shopping.
- Permission: a member without the Shopping module does not see the button; a list the member cannot add to is not listed; a stale list (locked meanwhile) returns a 409 shown as "This list was locked in the meantime. Choose another list." selecting the radio and focusing the select.
- Loading of lists and catalog: `UiSkeleton` rows; if the catalog fails to load: `UiAlert` warn "We could not check the catalog. The items will be added as typed items." and the flow continues.

### 6. Overview item "from URL draft" and other states

- Pending import row on the overview as described in section 2 (info `UiAlert`, button "Review", ghost "Discard" with `UiConfirmDialog` "Discard this draft?" confirm "Discard draft" cancel "Keep draft").
- Failed import row: warn `UiAlert` "Reading {site} did not work." with buttons "Try again" and "Type it in".

### 7. Responsive behaviour

- Phone (container under 640px): single-column everything, grid 1 column (2 columns from 480px container), dialogs and filters as bottom sheets, primary actions in `StickyActionBar` above the tab bar with safe-area padding, all interactive elements at least 44px, ingredient and step rows stacked as specified, form ingredient lines as cards.
- Tablet / iPad (640 to 1024px container): grid 2 columns under 840px and 3 from 840px, detail is single column with tabs below 840px and two columns from 840px, dialogs centred max width 720px, header buttons replace the sticky bar, cooking mode keeps the larger rows (good for a kitchen stand).
- Desktop: grid 3 columns max, detail two columns (ingredients left, steps right, servings control sticky at the top of the left column), form two columns, dialogs centred, no docked panel needed in this phase.
- All layout responds to container width (container queries), not only viewport, per the sketch findings.

### 8. Accessibility

- Every icon-only button has `aria-label`; toggles use `aria-pressed` (favourite, cooking mode, favourites filter).
- Ticks are real checkboxes with visible labels for screen readers; progress counts in text, not colour; progress announced politely when changed by the user.
- Dialogs trap focus (`UiDialog`), Escape closes except while a save or an import request is running; focus returns to the trigger.
- Card is a single link (name = title); the favourite button is a separate focusable control placed after the link in DOM order but visually over the photo.
- Contrast follows tokens (AA); photos decorative in cards (`alt=""`), meaningful hero `alt` equals the title.
- Motion: only existing transitions; respect `prefers-reduced-motion` (gallery switch without animation).
- Status changes (saved, ticked all, added to list) via the toast region (`aria-live`).

---

## Copywriting Contract

All strings via vue-i18n keys (namespaces `recipes.*`, nav `nav.recipes`) in en, sk, pl. en is source; sk and pl are drafts for native review.

### Primary elements

| Element | en | sk (draft) | pl (draft) |
|---------|----|------------|------------|
| Module / nav / page title | Recipes | Receptár | Receptarz |
| Primary CTA (overview) | New recipe | Nový recept | Nowy przepis |
| New recipe dialog options | Type it in / Read from a web page | Zadať ručne / Načítať z webovej stránky | Wpisz ręcznie / Wczytaj ze strony internetowej |
| URL field / CTA | Web page address / Read recipe | Adresa webovej stránky / Načítať recept | Adres strony internetowej / Wczytaj przepis |
| Search / placeholder | Search recipes / Title or ingredient | Hľadať recepty / Názov alebo surovina | Szukaj przepisów / Nazwa lub składnik |
| Filters / clear | Filters / Clear filters | Filtre / Zrušiť filtre | Filtry / Wyczyść filtry |
| Filter labels | Category / Author / Ingredient / Favourites | Kategória / Autor / Surovina / Obľúbené | Kategoria / Autor / Składnik / Ulubione |
| Show segments | All / Mine / Shared with me | Všetky / Moje / Zdieľané so mnou | Wszystkie / Moje / Udostępnione mi |
| Visibility tags | Private / Shared | Súkromný / Zdieľaný | Prywatny / Udostępniony |
| Visibility options | Only me / Everyone in the family who uses Recipes | Iba ja / Všetci v rodine, ktorí používajú Receptár | Tylko ja / Wszyscy w rodzinie korzystający z Receptarza |
| Form title (new / edit / review) | New recipe / Edit recipe / Check the recipe | Nový recept / Upraviť recept / Skontrolujte recept | Nowy przepis / Edytuj przepis / Sprawdź przepis |
| Form labels | Title / Description / Servings / Preparation time (min) / Cooking time (min) / Categories / Ingredients / Steps / Source / Photos | Názov / Popis / Porcie / Čas prípravy (min) / Čas varenia (min) / Kategórie / Suroviny / Postup / Zdroj / Fotky | Tytuł / Opis / Porcje / Czas przygotowania (min) / Czas gotowania (min) / Kategorie / Składniki / Kroki / Źródło / Zdjęcia |
| Ingredient fields | Quantity / Unit / Name / Note / No unit | Množstvo / Jednotka / Názov / Poznámka / Bez jednotky | Ilość / Jednostka / Nazwa / Notatka / Bez jednostki |
| Add buttons | Add ingredient / Add step / Add photos / Add category | Pridať surovinu / Pridať krok / Pridať fotky / Pridať kategóriu | Dodaj składnik / Dodaj krok / Dodaj zdjęcia / Dodaj kategorię |
| Photo menu | Make main / Move earlier / Move later / Remove / Main | Nastaviť ako hlavnú / Posunúť dopredu / Posunúť dozadu / Odstrániť / Hlavná | Ustaw jako główne / Przesuń wcześniej / Przesuń później / Usuń / Główne |
| Save / Cancel | Save recipe / Cancel | Uložiť recept / Zrušiť | Zapisz przepis / Anuluj |
| Detail actions | Edit / Add to shopping list / Cooking mode / Reset my progress | Upraviť / Pridať do nákupného zoznamu / Režim varenia / Obnoviť môj postup | Edytuj / Dodaj do listy zakupów / Tryb gotowania / Zresetuj mój postęp |
| Servings | Servings / Fewer servings / More servings / Scaled / Reset to {n} | Porcie / Menej porcií / Viac porcií / Prepočítané / Vrátiť na {n} | Porcje / Mniej porcji / Więcej porcji / Przeliczone / Przywróć {n} |
| Time meta | Prep {n} min / Cook {n} min / Total: {n} min | Príprava {n} min / Varenie {n} min / Spolu: {n} min | Przygotowanie {n} min / Gotowanie {n} min / Razem: {n} min |
| Progress | {x} of {y} prepared / {x} of {y} steps done | {x} z {y} pripravených / {x} z {y} krokov hotových | {x} z {y} przygotowanych / {x} z {y} kroków gotowych |
| Tabs | Ingredients / Steps / Notes | Suroviny / Postup / Poznámky | Składniki / Kroki / Notatki |
| Tick labels | Prepared: {name} / Done: step {n} | Pripravené: {name} / Hotové: krok {n} | Przygotowane: {name} / Gotowe: krok {n} |
| Favourites | Add to favourites / Remove from favourites | Pridať medzi obľúbené / Odstrániť z obľúbených | Dodaj do ulubionych / Usuń z ulubionych |
| Notes | My notes (only you can see them) / For example: use less salt next time. | Moje poznámky (vidíte ich iba vy) / Napríklad: nabudúce menej soli. | Moje notatki (widzisz je tylko ty) / Na przykład: następnym razem mniej soli. |
| Source | Source: {host} / Edit | Zdroj: {host} / Upraviť | Źródło: {host} / Edytuj |
| Shopping dialog | Add to shopping list / New list / Open list / Name of the new list / Choose a list / Quantities for | Pridať do nákupného zoznamu / Nový zoznam / Otvorený zoznam / Názov nového zoznamu / Vyberte zoznam / Množstvá pre | Dodaj do listy zakupów / Nowa lista / Otwarta lista / Nazwa nowej listy / Wybierz listę / Ilości dla |
| Shopping CTA | Add {n} items / Create list and add {n} items | Pridať položky ({n}) / Vytvoriť zoznam a pridať položky ({n}) | Dodaj produkty ({n}) / Utwórz listę i dodaj produkty ({n}) |
| Match tags | Matched / Not in catalog / Change / Include {name} | Nájdené / Nie je v katalógu / Zmeniť / Zahrnúť {name} | Znaleziono / Brak w katalogu / Zmień / Uwzględnij {name} |
| AI tag | From the page | Zo stránky | Ze strony |

### Empty states

| Element | en | sk (draft) | pl (draft) |
|---------|----|------------|------------|
| No recipes (title / body) | No recipes yet / Save your family recipes here, type them in or paste a link and let the app read it. | Zatiaľ žiadne recepty / Ukladajte tu rodinné recepty, zadajte ich ručne alebo vložte odkaz a nechajte aplikáciu, aby ho prečítala. | Brak przepisów / Zapisuj tu rodzinne przepisy, wpisz je ręcznie lub wklej link, a aplikacja go odczyta. |
| No match (title / body) | No recipe matches / Try a different word or clear the filters. | Žiadny recept nevyhovuje / Skúste iné slovo alebo zrušte filtre. | Żaden przepis nie pasuje / Spróbuj innego słowa lub wyczyść filtry. |
| No favourites (title / body) | No favourites yet / Tap the heart on a recipe to find it here quickly. | Zatiaľ žiadne obľúbené / Klepnite na srdce pri recepte a nájdete ho tu rýchlo. | Brak ulubionych / Stuknij serce przy przepisie, aby szybko go tu znaleźć. |
| Recipe unavailable (title / body / button) | This recipe is not available / It may have been deleted or made private. / Back to recipes | Tento recept nie je dostupný / Možno bol odstránený alebo nastavený ako súkromný. / Späť na recepty | Ten przepis jest niedostępny / Mógł zostać usunięty lub ustawiony jako prywatny. / Wróć do przepisów |
| No open list | You have no open list you can add to. | Nemáte otvorený zoznam, do ktorého môžete pridávať. | Nie masz otwartej listy, do której możesz dodawać. |

### Error states

| Element | en | sk (draft) | pl (draft) |
|---------|----|------------|------------|
| Load failure (overview / detail) | We could not load the recipes. / We could not load this recipe. (button: Try again) | Recepty sa nepodarilo načítať. / Tento recept sa nepodarilo načítať. (Skúsiť znova) | Nie udało się wczytać przepisów. / Nie udało się wczytać tego przepisu. (Spróbuj ponownie) |
| Save failure | Your changes were not saved. Check the highlighted fields and try again. | Zmeny sa neuložili. Skontrolujte zvýraznené polia a skúste to znova. | Zmiany nie zostały zapisane. Sprawdź zaznaczone pola i spróbuj ponownie. |
| Tick / favourite failure (toast) | Could not save. Try again. | Nepodarilo sa uložiť. Skúste to znova. | Nie udało się zapisać. Spróbuj ponownie. |
| Notes failure | Could not save your notes. | Poznámky sa nepodarilo uložiť. | Nie udało się zapisać notatek. |
| Import: page not opened | We could not open that page. Check the address, or type the recipe in yourself. | Túto stránku sa nepodarilo otvoriť. Skontrolujte adresu alebo recept zadajte ručne. | Nie udało się otworzyć tej strony. Sprawdź adres lub wpisz przepis ręcznie. |
| Import: no recipe found | We could not find a recipe on that page. Try the page with the full recipe, or type it in yourself. | Na tej stránke sa recept nenašiel. Skúste stránku s celým receptom alebo ho zadajte ručne. | Na tej stronie nie znaleziono przepisu. Spróbuj strony z pełnym przepisem lub wpisz go ręcznie. |
| Import: AI unavailable | Reading pages is not available right now. You can type the recipe in yourself. | Čítanie stránok momentálne nie je dostupné. Recept môžete zadať ručne. | Wczytywanie stron jest teraz niedostępne. Możesz wpisać przepis ręcznie. |
| Import: limit | You have read too many pages today. Try again later. | Dnes ste načítali príliš veľa stránok. Skúste to neskôr. | Dziś wczytano zbyt wiele stron. Spróbuj ponownie później. |
| Invalid URL | Enter a web address that starts with http:// or https://. | Zadajte webovú adresu, ktorá začína http:// alebo https://. | Podaj adres strony zaczynający się od http:// lub https://. |
| Image failure | We could not use that picture. Choose a JPG, PNG or WebP image and try again. | Tento obrázok sa nepodarilo použiť. Vyberte JPG, PNG alebo WebP a skúste to znova. | Nie udało się użyć tego obrazu. Wybierz JPG, PNG lub WebP i spróbuj ponownie. |
| Too many photos | You can add up to 10 photos. | Môžete pridať najviac 10 fotiek. | Możesz dodać najwyżej 10 zdjęć. |
| Missing ingredient / step | Add at least one ingredient. / Add at least one step. | Pridajte aspoň jednu surovinu. / Pridajte aspoň jeden krok. | Dodaj co najmniej jeden składnik. / Dodaj co najmniej jeden krok. |
| Invalid quantity | Enter a quantity greater than 0. | Zadajte množstvo väčšie ako 0. | Podaj ilość większą niż 0. |
| Forbidden (toast) | You are not allowed to do that with this recipe. | S týmto receptom to nemôžete urobiť. | Nie możesz tego zrobić z tym przepisem. |
| Shopping add failure | We could not add the items. Nothing was added. Try again. | Položky sa nepodarilo pridať. Nič sa nepridalo. Skúste to znova. | Nie udało się dodać produktów. Nic nie zostało dodane. Spróbuj ponownie. |
| Shopping list locked (409) | This list was locked in the meantime. Choose another list. | Tento zoznam sa medzitým uzamkol. Vyberte iný zoznam. | Ta lista została w międzyczasie zablokowana. Wybierz inną listę. |
| Catalog check failed | We could not check the catalog. The items will be added as typed items. | Katalóg sa nepodarilo skontrolovať. Položky sa pridajú ako vlastné. | Nie udało się sprawdzić katalogu. Produkty zostaną dodane jako wpisane. |

### Dialogs, hints and toasts

| Element | en | sk (draft) | pl (draft) |
|---------|----|------------|------------|
| One-time notice (title / body / button) | The page text is sent to the AI provider / To read the recipe, the text of the page is sent to the AI provider your administrator has turned on. Nothing else from your account is sent. / Got it | Text stránky sa odošle poskytovateľovi AI / Na prečítanie receptu sa text stránky odošle poskytovateľovi AI, ktorého zapol váš administrátor. Z vášho účtu sa neodosiela nič iné. / Rozumiem | Tekst strony jest wysyłany do dostawcy AI / Aby odczytać przepis, tekst strony jest wysyłany do dostawcy AI włączonego przez administratora. Nic więcej z twojego konta nie jest wysyłane. / Rozumiem |
| Notice hint (after first use) | The text of the page is sent to the AI provider. | Text stránky sa odošle poskytovateľovi AI. | Tekst strony jest wysyłany do dostawcy AI. |
| Loading | Reading the page. This can take a minute. | Čítam stránku. Môže to trvať minútu. | Czytam stronę. Może to potrwać minutę. |
| Draft ready | Your recipe from {site} is ready to review. (Review / Discard) | Váš recept zo stránky {site} je pripravený na kontrolu. (Skontrolovať / Zahodiť) | Twój przepis ze strony {site} jest gotowy do sprawdzenia. (Sprawdź / Odrzuć) |
| Failed draft | Reading {site} did not work. (Try again / Type it in) | Načítanie stránky {site} sa nepodarilo. (Skúsiť znova / Zadať ručne) | Wczytanie strony {site} nie powiodło się. (Spróbuj ponownie / Wpisz ręcznie) |
| Review banner | Check everything the app filled in from the page. Nothing is saved until you press Save recipe. | Skontrolujte všetko, čo aplikácia vyplnila zo stránky. Nič sa neuloží, kým nestlačíte Uložiť recept. | Sprawdź wszystko, co aplikacja wypełniła ze strony. Nic nie zostanie zapisane, dopóki nie klikniesz Zapisz przepis. |
| Not found on page | Not found on the page. | Na stránke sa nenašlo. | Nie znaleziono na stronie. |
| Leave unsaved (title / body / buttons) | Leave without saving? / The recipe has not been saved yet. / Leave / Keep editing | Odísť bez uloženia? / Recept ešte nie je uložený. / Odísť / Pokračovať v úpravách | Wyjść bez zapisywania? / Przepis nie został jeszcze zapisany. / Wyjdź / Kontynuuj edycję |
| Sharing hint (shared option) | Others can read it, cook from it and add it to shopping. Only you can edit it. | Ostatní si ho môžu prečítať, variť podľa neho a pridať ho do nákupu. Upraviť ho môžete iba vy. | Inni mogą go czytać, gotować według niego i dodawać do zakupów. Tylko ty możesz go edytować. |
| Make private (title / body / buttons) | Make this recipe private? / Other members will no longer see it. Their notes, ticks and favourites on it are kept but hidden. / Make private / Keep shared | Nastaviť recept ako súkromný? / Ostatní členovia ho už neuvidia. Ich poznámky, odškrtnutia a obľúbené ostanú zachované, ale skryté. / Nastaviť ako súkromný / Ponechať zdieľaný | Ustawić przepis jako prywatny? / Pozostali członkowie go nie zobaczą. Ich notatki, odhaczenia i ulubione zostaną zachowane, ale ukryte. / Ustaw jako prywatny / Zostaw udostępniony |
| Edit hint (shared) | Other members may be cooking from this recipe. Their ticks are kept for lines you do not remove. | Iní členovia môžu podľa tohto receptu práve variť. Ich odškrtnutia ostanú pri riadkoch, ktoré neodstránite. | Inni członkowie mogą właśnie gotować według tego przepisu. Ich odhaczenia zostaną przy wierszach, których nie usuniesz. |
| Non-author hint | Only {name} can edit this recipe. | Tento recept môže upraviť iba {name}. | Ten przepis może edytować tylko {name}. |
| Scaled announcement | Quantities updated for {n} servings. | Množstvá upravené pre {n} porcií. | Ilości zmienione dla {n} porcji. |
| Cooking hint | The screen stays on while you cook. | Obrazovka ostane počas varenia zapnutá. | Ekran pozostanie włączony podczas gotowania. |
| All steps done | All steps done. Enjoy your meal. | Všetky kroky hotové. Dobrú chuť. | Wszystkie kroki gotowe. Smacznego. |
| Shopping hint | Items found in the catalog get their picture. The others are added as typed items. | Položky nájdené v katalógu dostanú obrázok. Ostatné sa pridajú ako vlastné. | Produkty znalezione w katalogu dostaną obraz. Pozostałe zostaną dodane jako wpisane. |
| No amount hint | No amount in the recipe. | V recepte nie je množstvo. | W przepisie brak ilości. |
| Merge hint | Already on the list: {qty}. It will be added up. | Už v zozname: {qty}. Množstvá sa sčítajú. | Już na liście: {qty}. Ilości zostaną zsumowane. |
| Different unit hint | Different unit: kept as a separate line. | Iná jednotka: ostane ako samostatný riadok. | Inna jednostka: zostanie jako osobny wiersz. |
| Toasts | Recipe saved / Recipe deleted / {n} items added to {list} (action: Open list) / Ingredient removed (action: Undo) / Progress reset / Visibility changed | Recept uložený / Recept odstránený / {n} položiek pridaných do zoznamu {list} (Otvoriť zoznam) / Surovina odstránená (Späť) / Postup obnovený / Viditeľnosť zmenená | Przepis zapisany / Przepis usunięty / Dodano {n} produktów do listy {list} (Otwórz listę) / Składnik usunięty (Cofnij) / Postęp zresetowany / Widoczność zmieniona |
| Reset progress confirm | Reset your progress? / Your ticks on this recipe will be cleared. Other members are not affected. / Reset progress / Keep progress | Obnoviť váš postup? / Vaše odškrtnutia pri tomto recepte sa zrušia. Ostatných členov sa to netýka. / Obnoviť postup / Ponechať postup | Zresetować twój postęp? / Twoje odhaczenia przy tym przepisie zostaną wyczyszczone. Nie dotyczy to innych członków. / Zresetuj postęp / Zachowaj postęp |

### Destructive actions

| Action | Confirmation approach | Confirm / Cancel (en) |
|--------|----------------------|-----------------------|
| Delete recipe (author) | `UiConfirmDialog` danger; title "Delete “{title}”?", body "The recipe, its photos and everyone's notes and ticks will be deleted. Shopping lists you already made from it are not changed. This cannot be undone." | Delete recipe / Keep recipe |
| Remove photo | `UiConfirmDialog` danger "Remove this photo?" | Remove photo / Keep photo |
| Remove ingredient / step | no confirm, undo toast (non-empty rows only) | Undo |
| Discard import draft | `UiConfirmDialog` danger "Discard this draft?" | Discard draft / Keep draft |
| Reset my progress | non-danger confirm (see above) | Reset progress / Keep progress |
| Make private | non-danger confirm (see above) | Make private / Keep shared |

sk and pl (drafts): Delete recipe / Odstrániť recept / Usuń przepis; Keep recipe / Ponechať recept / Zachowaj przepis ("Odstrániť recept „{title}“? Recept, jeho fotky a poznámky i odškrtnutia všetkých sa odstránia. Nákupné zoznamy, ktoré ste z neho už vytvorili, sa nezmenia. Nedá sa to vrátiť." / "Usunąć przepis „{title}”? Przepis, jego zdjęcia oraz notatki i odhaczenia wszystkich zostaną usunięte. Listy zakupów już z niego utworzone nie zostaną zmienione. Tego nie można cofnąć."); Remove photo / Odstrániť fotku / Usuń zdjęcie; Keep photo / Ponechať fotku / Zachowaj zdjęcie; Discard draft / Zahodiť návrh / Odrzuć szkic; Keep draft / Ponechať návrh / Zachowaj szkic.

### Contextual help and manual (CLAUDE.md "User documentation")

HelpTip topics (registry `resources/help/topics.json`, texts en, sk, pl in `resources/help/*.json`, each under 400 characters): `recipes.import` (page text goes to the AI provider, check before saving, works with most recipe pages), `recipes.sharing` (private versus shared, only the author edits), `recipes.servings` (quantities scale; the setting is yours and is remembered), `recipes.progress` (ticks and steps are only yours and are stored per person), `recipes.notes` (notes are personal), `recipes.shopping` (new or open list, catalog matching, review before adding), `recipes.cooking` (cooking mode and screen stays on). Placement: URL step title, the visibility field, the servings label, the Ingredients heading on detail, the Notes label, the shopping dialog title, the cooking mode toggle.

User manual: new topic file `resources/manual/<en|sk|pl>/recipes.json` (sections: Recipes overview, Adding a recipe, Reading a recipe from a web page, Photos, Ingredients and servings, Cooking mode and progress, Notes and favourites, Sharing and editing, Adding to shopping, Deleting), plus the `covers` entry; sk and pl are drafts for native review. Run `ManualCoverage.test.js`, `HelpCoverage.test.js` and `tests/Feature/Manual`; list touched `resources/manual/` and `resources/help/` files in `files_modified`.

---

## Registry Safety

| Registry | Blocks Used | Safety Gate |
|----------|-------------|-------------|
| shadcn official | none (not initialized) | not applicable |
| third-party | none | not applicable |

---

## Checker Sign-Off

- [ ] Dimension 1 Copywriting: PASS
- [ ] Dimension 2 Visuals: PASS
- [ ] Dimension 3 Color: PASS
- [ ] Dimension 4 Typography: PASS
- [ ] Dimension 5 Spacing: PASS
- [ ] Dimension 6 Registry Safety: PASS

**Approval:** pending
