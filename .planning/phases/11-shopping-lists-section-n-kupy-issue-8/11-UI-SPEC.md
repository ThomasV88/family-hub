---
phase: 11
slug: shopping-lists-section-n-kupy-issue-8
status: draft
shadcn_initialized: false
preset: none
created: 2026-11-17
---

# Phase 11 — UI Design Contract

> Visual and interaction contract for Phase 11 (GitHub #8, module "Nákupy" / Shopping). Everything reuses the locked design system (`resources/css/tokens.css`, sketch findings, `ui/*` components). No new tokens, colours, type sizes or weights. Sources: 11-CONTEXT.md, ISSUES-6-11-OWNER-DECISIONS.md, GitHub issue #8 and the owner comment, sketch-findings-family-hub, Phase 10 UI-SPEC (format), existing Wishlist and Todo pages (closest analogues), Settings sections pattern (`Pages/Settings/Index.vue` + `SettingsLayout`).

---

## Design System

| Property | Value |
|----------|-------|
| Tool | none (in-house Vue set in `resources/js/Components/ui`, tokens in `resources/css/tokens.css`); shadcn gate not applicable (Laravel + Inertia + Vue) |
| Preset | not applicable |
| Component library | none (in-house `Ui*` components) |
| Icon library | `@lucide/vue` |
| Font | `--font-sans` |

Components reused (do not re-create): `UiAvatar`, `UiButton`, `UiCard`, `UiCheckbox`, `UiChip`, `UiConfirmDialog`, `UiDetailPanel`, `UiDialog`, `UiEmptyState`, `UiErrorState`, `UiField`, `UiInput`, `UiList`/`UiListRow`, `UiMenu`, `UiProgress`, `UiSelect`, `UiSkeleton`, `UiSwitch`, `UiTabs`, `UiTag`, `UiTextarea`, `UiAlert`, `useToast`, `HelpTip`, `SettingsLayout`, `ProfilePhotoEditor` (pattern for image upload, generalised below), `AppShell`.

New components allowed (compositions only, built from the above):

| Component | Built from | Purpose |
|-----------|-----------|---------|
| `Pages/Shopping/Index.vue` | UiTabs, UiList, UiEmptyState | Overview (Open / Archive tabs) |
| `Pages/Shopping/Show.vue` | UiList, UiProgress, UiMenu, UiDetailPanel | List detail |
| `Components/Shopping/ShoppingItemRow.vue` | UiListRow, UiCheckbox, UiTag | One item in plan and shopping mode |
| `Components/Shopping/ItemPickerDialog.vue` | UiDialog, UiInput, UiChip | Catalog pop-up with images plus free-text fallback |
| `Components/Shopping/ListSettingsPanel.vue` | UiDetailPanel, UiSelect, UiField | Store, responsible person, contributors, viewers, reminder |
| `Components/Shopping/FinishShoppingDialog.vue` | UiDialog | Finish and carry-over question |
| `Components/Shopping/EmailListDialog.vue` | UiDialog, UiInput | Send by e-mail |
| `Components/Shopping/CatalogImage.vue` | `<img>` + placeholder | Square image tile with fallback icon |
| `Pages/Settings/Shopping.vue` | SettingsLayout, UiTabs, UiList | Settings section: Catalog, Stores, Units |
| `Components/Shopping/ImageUploadField.vue` | ProfilePhotoEditor pattern | Image or logo upload (item and store) |

Navigation: Shopping gets one entry in the bottom bar overflow / sidebar with icon `ShoppingCart` and label "Shopping" (SK "Nákupy", PL "Zakupy"). On phone the bottom bar already shows 5 modules; Shopping goes to the sidebar/"More" place used by modules beyond five. Dashboard gets no new tile in this phase.

---

## Spacing Scale

Existing tokens only: `--space-1` 4, `--space-2` 8, `--space-3` 12 (inner padding of 40px controls, card inner gaps), `--space-4` 16, `--space-6` 24, `--space-8` 32, `--space-12` 48, `--space-16` 64.

| Token | Value | Usage in this phase |
|-------|-------|---------------------|
| xs | 4px | icon to label gap; gap between name and meta line in a row |
| sm | 8px | gap between catalog tiles; gap between chips; gap between header buttons |
| md | 16px | row inner padding; field gap in dialogs and panels; card padding |
| lg | 24px | gap between header block and list; between settings cards |
| xl | 32px | page top spacing |
| 2xl | 48px | empty-state vertical padding |
| 3xl | 64px | empty-state vertical padding on wide only |

Exceptions (sizes, not spacing): item image tile 48px in rows, 72px in catalog picker tiles, 96px in the settings editor, store logo 40px (list header) / 96px (editor); all rendered `--radius-md`, `object-fit: cover` (items) or `contain` (logos, on `--color-surface-2`). Touch targets: row height in plan mode at least `--control-md` 40px (compact 48px with image); in shopping mode (locked) every row is at least 56px tall and the Bought and Unavailable buttons are at least `--control-touch` 44px wide and tall. Sticky bottom action bar on phone: height 64px plus safe-area inset.

---

## Typography

Existing scale only: 4 sizes, 2 weights.

| Role | Size | Weight | Line Height |
|------|------|--------|-------------|
| Body / item name / input text | `--text-base` 16px | 400 (600 for item name while in shopping mode and for the list title in a row) | 1.5 |
| Label / quantity / meta / tag / hint / button label | `--text-sm` 14px | 600 for labels and quantity, 400 for meta and hints | 1.5 |
| Heading (card, dialog, panel, section titles) | `--text-xl` 20px | 600 | 1.2 |
| Display (page title, list title on detail) | `--text-2xl` 24px | 600 | 1.2 |

Weights: 400 and 600 only. Layouts tolerate SK/PL labels about 30 percent longer than EN: item names wrap to two lines (never truncate the name; only truncate the meta line with ellipsis), buttons wrap or grow, never truncate.

---

## Color

Tokens from `tokens.css` (light and dark already defined); nothing new.

| Role | Value | Usage |
|------|-------|-------|
| Dominant (60%) | `--color-bg` | page background |
| Secondary (30%) | `--color-surface`, `--color-surface-2` | rows, cards, dialogs, panels, image placeholders, tiles |
| Accent (10%) | `--color-primary` | see list below |
| Destructive | `--color-danger` | delete catalog item / store / unit confirm, remove item confirm button, nothing else |

Accent reserved for: the primary button (Create list, Add items, Lock list, Finish shopping, Send, Save), focus ring, the selected tab underline, the checked Bought checkbox/check state, the selected tile in the picker, the progress bar fill. Nothing else.

State styling (status never relies on colour alone, each has a label or icon):

| State | Treatment |
|-------|-----------|
| Open list | `UiTag` neutral "Open" |
| Locked list | `UiTag` neutral with `Lock` icon "Locked" (shown in header and overview row) |
| Archived list | `UiTag` neutral "Finished" with `Archive` icon |
| Item Bought | accent check icon `Check` in filled circle, name keeps colour, quantity muted, row `--color-surface-2` background, name not struck through (readable at a glance) |
| Item Unavailable | `UiTag` warn variant (`--color-warn-bg`/`--color-warn-text`) with `Ban` icon and label "Unavailable", name in muted text colour, never struck through |
| Reminder set | muted text with `Bell` icon, no tag colour |
| Overdue reminder (reminder passed, list still open) | `UiTag` warn "Reminder passed" |

No red except destructive confirm buttons and form errors.

---

## Screens and Interaction Contract

### 1. Shopping overview (`Pages/Shopping/Index.vue`)

- Page title "Shopping" (`--text-2xl`), right-aligned primary `UiButton` "New list" (icon `Plus`; on phone the title row wraps and the button goes full width under the title, and a floating add button is not used because the bottom bar already holds one). 
- `UiTabs` (label "Shopping lists"): "Open" (default, includes locked lists) and "Archive". Tab counts shown in the label as plain numbers in parentheses only when over 0.
- Row (`UiListRow`, whole row is a link to the list; min height 64px): leading store logo 40px (or `Store` icon placeholder when no store), title (list name, 600, `--text-base`), meta line `--text-sm` muted: "{n} items · {bought} bought" for locked lists, store name, responsible person (`UiAvatar` 32 plus first name). Trailing: `UiTag` state (Locked) and `Bell` icon with date when a reminder is set. Archive rows show "Finished {date}" instead of progress.
- Locked lists show `UiProgress` (4px bar, `aria-label` "Bought {x} of {y}") under the title.
- Ordering: open tab by reminder date ascending (lists with no reminder after), then newest first; archive tab by finish date descending.
- Filters: none in this phase. A search field `UiInput` (icon `Search`, label "Search lists") appears only when more than 10 lists exist.
- Per-row overflow `UiMenu` (icon `Ellipsis`, 44px hit area): "Copy list" (all rows). Delete is not offered in the overview; delete lives in the detail menu.
- Empty state (Open tab, no lists): `UiEmptyState` icon `ShoppingCart`, title "No shopping lists yet", body "Create a list, add items from the catalog and share it with the family.", primary button "New list". Empty Archive: title "Nothing finished yet", body "Lists you finish shopping appear here. You can copy them into a new list." No button.
- Loading: 3 `UiSkeleton` rows of the real row height. Error: `UiErrorState` "We could not load the shopping lists." with retry button "Try again".
- No access: a member without module permission does not see the nav entry; direct URL gives the standard 403 page. A member without access to one list never sees it in the overview (server-filtered).

### 2. Create list (dialog from "New list")

`UiDialog` (bottom sheet on phone) title "New shopping list". Fields: "Name" (`UiInput`, required, max 80, placeholder "Weekly shopping"), "Store" (`UiSelect`, optional, options are store chains with logo thumbnail, first option "No store"), "Responsible person" (`UiSelect` of members, default is the current user). Footer: ghost "Cancel", primary "Create list". On success the dialog closes and the new list detail opens in plan mode with the picker pop-up opened automatically (list is empty). Contributors, viewers and reminder are set afterwards in the list settings panel (keeps the create dialog short).

### 3. List detail (`Pages/Shopping/Show.vue`)

Header (stacked on phone, one row on wide):
- Back link "Shopping" (icon `ChevronLeft`, 44px target), title (list name, `--text-2xl`, wraps), tags (state), meta line: store logo 40 plus store name, responsible person avatar and name ("Responsible: Anna"), reminder ("Reminder: 20 Nov, 18:00") with `Bell`.
- Action area (wide: right-aligned; phone: primary action in sticky bottom bar, the rest in overflow):
  - Plan mode (open): primary "Add items" (`Plus`), secondary "Lock list" (`Lock`, only for the responsible person), `UiMenu` overflow (`Ellipsis`): "List settings", "Send by e-mail", "Copy list", "Delete list" (danger, only the responsible person or the list creator).
  - Locked mode: primary only when applicable "Finish shopping" (see below), secondary "Unlock list" (`LockOpen`, responsible person only), overflow: "Send by e-mail", "Copy list", "List settings" (read-only except responsible person may change responsible person and reminder).
  - Archived: no primary; overflow "Copy list", "Send by e-mail"; everything read-only; banner `UiAlert` info "This shopping is finished. You can copy it into a new list."
- Permission matrix on the page (server-enforced, UI only mirrors it):

| Who | Plan mode | Locked mode |
|-----|-----------|-------------|
| Responsible person | add, edit, remove items, lock, settings, delete | mark Bought/Unavailable, unlock, finish, change responsible and reminder |
| Contributor | add, edit, remove items | read only, sees marks live on reload |
| Viewer | read only | read only |

  Controls a person may not use are not rendered (not just disabled), except Lock for contributors which is hidden; a short muted hint under the header tells them why: "Only {name} can lock this list." (shown to contributors and viewers in plan mode only, `--text-sm`).

Item list (`UiList`, rows `ShoppingItemRow`):
- Plan mode row: leading `CatalogImage` 48 (placeholder icon `Package` when free text or no image), name (`--text-base`), quantity chip text "2 kg" (`--text-sm` 600) on the right, optional note below name (`--text-sm` muted, one line). Tap on the row (contributors and the responsible person) opens the item edit dialog (name for free-text items, quantity, unit, note, "Remove item" danger ghost button with `UiConfirmDialog` "Remove {name} from this list?"). Quantity stepper inline is not used; edit is via the dialog (avoids mis-taps).
- Free-text items show a small muted `UiTag` "Not in catalog" only inside the edit dialog, not in the row.
- Duplicate guard: adding a catalog item already on the list increases its quantity instead of adding a second row and shows toast "Quantity of {name} updated".
- Locked mode row (shopping mode, rows min 56px, item name 600): the leading image stays; the right side holds two 44px buttons for the responsible person: `UiButton` icon-only plus text on wide, "Bought" (icon `Check`) and "Unavailable" (icon `Ban`) as a segmented pair with `aria-pressed`. Tapping the active one clears the mark (undo, no confirm). Others (contributors, viewers) see the state as a read-only tag. Rows never reorder after a mark, so they do not jump under the thumb; state is shown by the check, the tag and the muted background only. No "Mark all as bought" action is provided.
- Progress: `UiProgress` under header in locked mode "Bought {x} · Unavailable {y} · Left {z}".
- Optimistic update with rollback and error toast "Could not save. Try again." on failure.
- Empty list (plan mode): `UiEmptyState` icon `ListPlus`, title "This list is empty", body "Add items from the catalog or type your own.", button "Add items". Viewer variant: title "This list is empty", no body hint, no button.
- Locking guard: "Lock list" is disabled when the list has no items (hint "Add at least one item before you lock the list"). Lock opens `UiConfirmDialog` (non-danger): title "Lock this list?", body "Nobody can add, remove or edit items while it is locked. You can unlock it again.", confirm "Lock list", cancel "Cancel". Unlock confirm: title "Unlock this list?", body "Items can be changed again. Marks Bought and Unavailable are kept.", confirm "Unlock list".
- Finish shopping: the primary button "Finish shopping" appears (sticky bar on phone, header on wide) only when every item is Bought or Unavailable and viewer is the responsible person. Until then the sticky bar on phone shows the progress text "{n} items left" and no button.

### 4. Item picker pop-up (`ItemPickerDialog`)

- `UiDialog`: large sheet; on phone full height bottom sheet (safe-area aware), on tablet and desktop a centred dialog max width 720px, max height 80vh with the list scrolling inside. Title "Add items".
- Top: search `UiInput` (icon `Search`, label "Search items", autofocus on desktop only, not on phone to avoid the keyboard covering the grid), matching is accent- and case-insensitive (`unaccent`).
- Catalog grid: tiles `CatalogImage` 72 above the name (`--text-sm`, 2 lines max, wrap), tile min width 104px, `grid-template-columns: repeat(auto-fill, minmax(104px, 1fr))`, gap `--space-2`, tile min height 120px (so at least a 44px target in every direction). Tap a tile toggles selection: selected tile gets 2px `--color-primary` border and a `Check` badge top right with the number of units (default quantity 1 in the item's default unit). A selected tile shows an inline stepper row below (`-` `1` `+`, buttons 44px, unit `UiSelect` compact) so quantity and unit are edited in place; selecting several items in one visit is the norm.
- Items already on the list show a muted "On list" `UiTag` and tapping opens their quantity as already selected (increments).
- Free-text fallback: always visible at the top under the search as a row: "Add “{typed text}” as a new item" (icon `PencilLine`), shown whenever the search field has text, and a permanent secondary button "Type your own item" when the field is empty. It expands an inline form: name `UiInput` (required, max 80), quantity `UiInput` numeric (`inputmode="decimal"`, default 1), unit `UiSelect` (default "pcs"), button "Add to list". Free-text items are not added to the catalog. A `UiCheckbox` "Also save to catalog" appears only for users who may manage the catalog (settings permission) and is unchecked by default.
- Footer (sticky): ghost "Cancel", primary "Add {n} items" (disabled with 0 selected; label "Add 1 item" for one; SK/PL plural forms via ICU plural). After add: dialog closes, toast "{n} items added".
- Empty catalog: `UiEmptyState` (inside the dialog, size md) icon `Package`, title "The catalog is empty", body "Type your own item below, or add items in Shopping settings." (the second half is a link to Settings > Shopping only for catalog managers). The free-text form stays available.
- No search results: "No catalog item matches “{text}”." plus the free-text row.
- Loading: 8 `UiSkeleton` tiles. Error: inline `UiAlert` danger "We could not load the catalog. You can still type your own item." (free-text stays usable).
- Image loading: `loading="lazy"`, fixed-size tile avoids layout jump; on image error fall back to the `Package` icon placeholder.

### 5. List settings panel (`ListSettingsPanel`)

`UiDetailPanel` (docked right on wide, overlay on tablet, bottom sheet on phone). Title "List settings". Sections as stacked fields:
- "Name" `UiInput` and "Store" `UiSelect` (with logos). Editable by the responsible person and by the creator while open.
- "Responsible person" `UiSelect` of members (changeable any time, including when locked; confirm not needed). Helper: "Only this person can lock the list and mark items." Changing the responsible person updates the header immediately, toast "{name} is now responsible".
- "Can add and edit items" multi-select of members (`UiChip` list with avatar plus "Add person" `UiSelect`, remove by chip close button 44px target). "Can only view" the same. A member may be in only one of the three roles; choosing someone already in the other group moves them and shows inline hint "{name} moved to viewers." Children (Phase 10) appear in the pickers like any member and are labelled with the "Child" `UiTag`.
- "Reminder": `UiField` with native date and time inputs (`type="datetime-local"` styled as `UiInput`), helper "A push notification goes to the responsible person only." plus ghost button "Remove reminder". Past dates rejected with field error "Choose a time in the future." If the responsible person has push off a `UiAlert` info line appears: "{name} has no push notifications turned on, so the reminder may not reach them."
- Save: primary "Save settings" in a sticky footer, disabled until dirty. Locked lists: fields other than responsible person and reminder are shown read-only.
- Everyone with access sees the panel read-only (inputs `readonly`, no Save); viewers still see who is who.
- Deleted or removed member (see below): if the responsible person is removed from the family the creator becomes responsible and the list header shows a one-time `UiAlert` info for the creator "You are now responsible for this list because {name} was removed."

### 6. Finish shopping and carry-over (`FinishShoppingDialog`)

- Tap "Finish shopping" opens `UiDialog` (centred, bottom sheet on phone).
- Case A, no Unavailable items: `UiConfirmDialog` style, title "Finish shopping?", body "The list moves to the archive and becomes read-only.", confirm "Finish shopping", cancel "Not yet".
- Case B, Unavailable items exist: title "Some items could not be bought", body "Some items could not be bought. Create a new shopping list from them?" (owner wording), then a read-only compact list of the unavailable items with quantities (max 5 visible, "+{n} more"), then a note "The new list keeps the store, the responsible person and who can see it." Footer, stacked on phone (full width, 44px+): primary "Create new list", secondary "No, just finish", ghost "Cancel" (stay on the list). Both finishing choices archive the original; "Create new list" also creates an OPEN list named "{original name} (unbought)" with those items, store, responsible person, contributors and viewers, no reminder, then navigates to it with toast "Shopping finished. New list created." The other choice: toast "Shopping finished".
- Failure: inline `UiAlert` danger in the dialog "We could not finish the shopping. Nothing was changed. Try again."; dialog stays open (the operation is one transaction server-side).
- After finish the page shows the archived read-only state.

### 7. Copy list

From the overview row menu or the detail overflow: "Copy list". Opens `UiDialog` "Copy list" with a `UiInput` "Name of the new list" (default "{name} (copy)"), `UiCheckbox` "Keep store, responsible person and access" checked by default, and fields otherwise empty. Copied items start unmarked (not Bought, not Unavailable) with the same quantities and units; the new list is Open, unlocked, no reminder. Confirm primary "Copy list", ghost "Cancel". On success: navigate to the new list with toast "List copied". The copier becomes creator; if "keep" is unchecked they also become responsible and contributors and viewers are empty.

### 8. Send by e-mail (`EmailListDialog`)

`UiDialog` "Send by e-mail". Field "E-mail addresses" (`UiInput`, chip-style tokens: type or paste an address, Enter, comma, space or blur commits it into a `UiChip` with close button; max 5; the input hides at 5 and shows hint "You can send to at most 5 addresses at a time."). Optional field "Message" is not included in this phase. Preview line (muted): "The e-mail lists the items with quantities, the store and the responsible person." Footer: ghost "Cancel", primary "Send" (disabled with zero valid addresses, spinner while sending). Validation: invalid token shows inline field error "“{text}” is not a valid e-mail address." and stays editable. Server rate limit: field-level `UiAlert` warn "You have sent too many lists today. Try again later." Success: dialog closes, toast "List sent to {n} address(es)". The e-mail itself is plain text (no markup): subject "{list name} — shopping list", lines "- 2 kg Potatoes" per item, header lines "Store: …", "Responsible: …". Available in every state and for every role with access (viewers included).

### 9. Settings > Shopping (`Pages/Settings/Shopping.vue`)

New section in the Settings sections list: icon `ShoppingCart`, label "Shopping", `href /settings/shopping`, shown to users who may manage the catalog (a member with write access in the shopping module; default as To-Do). Uses `SettingsLayout` (page title "Shopping settings", `UiTabs` label "Shopping settings": "Item catalog", "Stores", "Units").

**Item catalog tab**
- Toolbar: search `UiInput`, primary "Add item" (`Plus`). List below (`UiList`, row 64px): `CatalogImage` 48, name, meta "Default unit: kg" and "Used on {n} lists" muted. Trailing `UiMenu` (44px): "Edit", "Delete" (danger).
- Add/Edit: `UiDialog` (bottom sheet on phone) "Add catalog item" / "Edit catalog item": `ImageUploadField` (preview 96, "Upload image" / "Replace image", ghost "Remove image", hint "JPG, PNG or WebP, up to 5 MB. A square picture works best." server re-encodes to square 512px thumbnail, metadata stripped, shown through authenticated route), `UiInput` "Name" (required, max 80, unique case- and accent-insensitive; error "An item with this name already exists."), `UiSelect` "Default unit". Footer: ghost "Cancel", primary "Save item".
- Delete while in use: the item is removed from the catalog only; list items already on lists keep their name and quantity as free text and lose the image (the server snapshots the name). `UiConfirmDialog` danger: title "Delete “{name}” from the catalog?", body when used "It is on {n} lists. Those lists keep the item as plain text without the image." body when unused "This cannot be undone.", confirm "Delete item", cancel "Keep item". After: toast "Item deleted".
- Empty: `UiEmptyState` "The catalog is empty", body "Add the things you buy often, with a picture, so the family can pick them quickly.", primary "Add item".

**Stores tab**
- Same pattern. Row: logo 40 (contain on surface-2), name, meta "Used on {n} lists". Add/Edit dialog: `ImageUploadField` "Logo" (PNG, JPG, WebP or SVG-free: raster only; hint "PNG, JPG or WebP, up to 2 MB. Transparent PNG works best."), `UiInput` "Store name" (required, max 60, unique). Delete confirm danger: title "Delete “{name}”?", body when used "It is assigned to {n} lists. Those lists will have no store." else "This cannot be undone.", confirm "Delete store", cancel "Keep store".
- Empty: "No stores yet", body "Add the chains you shop at, with a logo, to assign them to lists.", primary "Add store".

**Units tab**
- Built-in units (pcs, g, kg, ml, l, pack, bunch, tbsp, tsp) shown as a `UiList` with a muted "Built in" `UiTag`, no edit or delete. Administrator-added units appear below with "Edit" and "Delete". "Add unit" primary button visible to administrators only; others see the list read-only. Dialog "Add unit": `UiInput` "Unit name" (required, max 12, unique; hint "Short, for example “can” or “box”.") and the sk and pl labels are entered in the same dialog as three fields "Name (English)", "Name (Slovak)", "Name (Polish)" (each required). Delete a custom unit in use: `UiConfirmDialog` danger "Delete unit “{name}”?" body "It is used by {n} items. Those items will show the quantity without a unit." confirm "Delete unit", cancel "Keep unit".
- Built-in units are translated through vue-i18n keys; display "2 pcs" / "2 ks" / "2 szt." etc.

Errors in all dialogs: inline `UiField` errors with `aria-live="polite"`, focus moves to the first invalid field, request failure shows a `UiAlert` danger at the top of the dialog: "Your changes were not saved. Check the highlighted fields and try again."

### 10. Quantity and unit rules

- Quantity: positive decimal up to 3 digits before and 2 after the decimal point (0.25 to 999.99), step 1 (0.5 for g/kg/l). Locale-aware display (SK/PL use comma), `inputmode="decimal"`. Displayed as "2 kg", "1,5 l", "3 pcs"; unit "pcs" may be omitted in display when quantity is 1 and unit is pcs ("Milk" not "1 pcs Milk"). Unit plural forms follow ICU plural (SK and PL have three forms: "1 balenie / 2 balenia / 5 balení", "1 opakowanie / 2 opakowania / 5 opakowań").
- Invalid quantity error: "Enter a quantity greater than 0."

### 11. Responsive behaviour

- Phone (container under 640px): single column; header stacked; primary action in sticky bottom bar (above the tab bar, with safe-area padding); dialogs and the settings panel are bottom sheets; picker is full height. All interactive elements at least 44px. In locked (shopping) mode the Bought and Unavailable buttons sit at the row's right edge, each 44px with 8px gap, labels visible (icon plus text) down to 360px width; below that, icons only with `aria-label`.
- Tablet / iPad (640 to 1024px container): list max width 720px centred, settings panel overlays from the right, the picker is a centred 720px dialog, the sticky action bar is replaced by header buttons, item rows are 56px in both modes.
- Desktop: list max width 840px centred within `--content-max`; settings panel docked right (360px) beside the list; overview is a single list of rows (not a grid), max width 840px.
- Layout responds to container width (container queries), not only viewport, per the sketch findings.
- Screen lock: no special wake-lock in this phase.

### 12. Accessibility

- Every icon-only button has an `aria-label`; state is communicated by text/icon plus `aria-pressed` on Bought/Unavailable toggles (`role="group"` with label "Mark {name}").
- Tabs follow `UiTabs` roles. Dialogs trap focus (`UiDialog`), Escape closes except the finish dialog while saving.
- Picker tiles are `button`s with `aria-pressed`; the live count announced via `aria-live="polite"` ("{n} selected").
- Status changes (item marked, list locked) are announced through the toast region (`aria-live`).
- Contrast follows tokens (AA); image tiles always carry the item name as visible text, images are decorative (`alt=""`).
- Motion: only the existing transitions; respect `prefers-reduced-motion`.

---

## Copywriting Contract

All strings via vue-i18n keys (namespaces `shopping.*`, `settings.shopping.*`, nav `nav.shopping`) in the locale files for en, sk, pl. en is source, sk and pl are drafts for native review.

### Primary elements

| Element | en | sk (draft) | pl (draft) |
|---------|----|------------|------------|
| Module / nav / page title | Shopping | Nákupy | Zakupy |
| Primary CTA (overview) | New list | Nový zoznam | Nowa lista |
| Create dialog title / CTA | New shopping list / Create list | Nový nákupný zoznam / Vytvoriť zoznam | Nowa lista zakupów / Utwórz listę |
| Tabs | Open / Archive | Otvorené / Archív | Otwarte / Archiwum |
| Name / Store / Responsible | Name / Store / Responsible person | Názov / Obchod / Zodpovedná osoba | Nazwa / Sklep / Osoba odpowiedzialna |
| No store option | No store | Bez obchodu | Bez sklepu |
| Add items (CTA) | Add items | Pridať položky | Dodaj produkty |
| Picker title / search | Add items / Search items | Pridať položky / Hľadať položky | Dodaj produkty / Szukaj produktów |
| Picker confirm | Add {n} items | Pridať položky ({n}) | Dodaj produkty ({n}) |
| Type own item | Type your own item | Zadať vlastnú položku | Wpisz własny produkt |
| Free-text row | Add “{text}” as a new item | Pridať „{text}“ ako novú položku | Dodaj „{text}” jako nowy produkt |
| Add to list | Add to list | Pridať do zoznamu | Dodaj do listy |
| Save to catalog checkbox | Also save to catalog | Uložiť aj do katalógu | Zapisz też w katalogu |
| On list tag | On list | V zozname | Na liście |
| Quantity / Unit / Note | Quantity / Unit / Note | Množstvo / Jednotka / Poznámka | Ilość / Jednostka / Notatka |
| Remove item (button) | Remove item | Odstrániť položku | Usuń produkt |
| Lock / Unlock | Lock list / Unlock list | Uzamknúť zoznam / Odomknúť zoznam | Zablokuj listę / Odblokuj listę |
| Locked / Finished / Open tags | Locked / Finished / Open | Uzamknutý / Vybavený / Otvorený | Zablokowana / Zakończona / Otwarta |
| Bought / Unavailable | Bought / Unavailable | Zakúpené / Nedostupné | Kupione / Niedostępne |
| Finish shopping (primary CTA in locked state) | Finish shopping | Ukončiť nákup | Zakończ zakupy |
| Copy list | Copy list | Kopírovať zoznam | Kopiuj listę |
| Send by e-mail / Send | Send by e-mail / Send | Odoslať e-mailom / Odoslať | Wyślij e-mailem / Wyślij |
| List settings / Save | List settings / Save settings | Nastavenia zoznamu / Uložiť nastavenia | Ustawienia listy / Zapisz ustawienia |
| Roles | Can add and edit items / Can only view | Môže pridávať a upravovať položky / Môže iba zobraziť | Może dodawać i edytować produkty / Może tylko przeglądać |
| Reminder / Remove reminder | Reminder / Remove reminder | Pripomienka / Odstrániť pripomienku | Przypomnienie / Usuń przypomnienie |
| Settings: tabs | Item catalog / Stores / Units | Katalóg položiek / Obchody / Jednotky | Katalog produktów / Sklepy / Jednostki |
| Settings: add buttons | Add item / Add store / Add unit | Pridať položku / Pridať obchod / Pridať jednotku | Dodaj produkt / Dodaj sklep / Dodaj jednostkę |
| Save buttons (catalog, store, unit) | Save item / Save store / Save unit | Uložiť položku / Uložiť obchod / Uložiť jednotku | Zapisz produkt / Zapisz sklep / Zapisz jednostkę |
| Image buttons | Upload image / Replace image / Remove image | Nahrať obrázok / Zmeniť obrázok / Odstrániť obrázok | Prześlij obraz / Zmień obraz / Usuń obraz |
| Logo field | Logo | Logo | Logo |
| Image hint | JPG, PNG or WebP, up to 5 MB. A square picture works best. | JPG, PNG alebo WebP, najviac 5 MB. Najlepšie vyzerá štvorcový obrázok. | JPG, PNG lub WebP, do 5 MB. Najlepiej wygląda kwadratowy obraz. |
| Logo hint | PNG, JPG or WebP, up to 2 MB. Transparent PNG works best. | PNG, JPG alebo WebP, najviac 2 MB. Najlepšie je priehľadné PNG. | PNG, JPG lub WebP, do 2 MB. Najlepiej przezroczysty PNG. |

### Empty states

| Element | en | sk (draft) | pl (draft) |
|---------|----|------------|------------|
| Overview empty (title / body) | No shopping lists yet / Create a list, add items from the catalog and share it with the family. | Zatiaľ žiadne nákupné zoznamy / Vytvorte zoznam, pridajte položky z katalógu a zdieľajte ho s rodinou. | Brak list zakupów / Utwórz listę, dodaj produkty z katalogu i udostępnij ją rodzinie. |
| Archive empty (title / body) | Nothing finished yet / Lists you finish shopping appear here. You can copy them into a new list. | Zatiaľ nič vybavené / Tu sa zobrazia zoznamy, ktoré ste vybavili. Môžete ich kopírovať do nového zoznamu. | Nic jeszcze nie zakończono / Tutaj pojawią się listy, które zakończysz. Możesz je skopiować do nowej listy. |
| List empty (title / body) | This list is empty / Add items from the catalog or type your own. | Tento zoznam je prázdny / Pridajte položky z katalógu alebo zadajte vlastné. | Ta lista jest pusta / Dodaj produkty z katalogu lub wpisz własne. |
| Catalog empty (picker) | The catalog is empty / Type your own item below, or add items in Shopping settings. | Katalóg je prázdny / Zadajte vlastnú položku nižšie alebo pridajte položky v nastaveniach Nákupov. | Katalog jest pusty / Wpisz własny produkt poniżej lub dodaj produkty w ustawieniach Zakupów. |
| Catalog empty (settings) | The catalog is empty / Add the things you buy often, with a picture, so the family can pick them quickly. | Katalóg je prázdny / Pridajte veci, ktoré často kupujete, aj s obrázkom, aby ich rodina vedela rýchlo vybrať. | Katalog jest pusty / Dodaj rzeczy, które często kupujesz, z obrazkiem, aby rodzina mogła je szybko wybrać. |
| Stores empty | No stores yet / Add the chains you shop at, with a logo, to assign them to lists. | Zatiaľ žiadne obchody / Pridajte reťazce, v ktorých nakupujete, aj s logom, a priraďte ich k zoznamom. | Brak sklepów / Dodaj sieci, w których robisz zakupy, z logo, aby przypisać je do list. |

### Error states

| Element | en | sk (draft) | pl (draft) |
|---------|----|------------|------------|
| Load failure (lists) | We could not load the shopping lists. (button: Try again) | Nákupné zoznamy sa nepodarilo načítať. (Skúsiť znova) | Nie udało się wczytać list zakupów. (Spróbuj ponownie) |
| Load failure (catalog) | We could not load the catalog. You can still type your own item. | Katalóg sa nepodarilo načítať. Vlastnú položku môžete stále zadať. | Nie udało się wczytać katalogu. Nadal możesz wpisać własny produkt. |
| Save failure | Your changes were not saved. Check the highlighted fields and try again. | Zmeny sa neuložili. Skontrolujte zvýraznené polia a skúste to znova. | Zmiany nie zostały zapisane. Sprawdź zaznaczone pola i spróbuj ponownie. |
| Mark failure (toast) | Could not save. Try again. | Nepodarilo sa uložiť. Skúste to znova. | Nie udało się zapisać. Spróbuj ponownie. |
| Finish failure | We could not finish the shopping. Nothing was changed. Try again. | Nákup sa nepodarilo ukončiť. Nič sa nezmenilo. Skúste to znova. | Nie udało się zakończyć zakupów. Nic się nie zmieniło. Spróbuj ponownie. |
| Image failure | We could not use that picture. Choose a JPG, PNG or WebP image and try again. | Tento obrázok sa nepodarilo použiť. Vyberte JPG, PNG alebo WebP a skúste to znova. | Nie udało się użyć tego obrazu. Wybierz JPG, PNG lub WebP i spróbuj ponownie. |
| Lock without items | Add at least one item before you lock the list. | Pred uzamknutím pridajte aspoň jednu položku. | Przed zablokowaniem dodaj co najmniej jeden produkt. |
| Invalid quantity | Enter a quantity greater than 0. | Zadajte množstvo väčšie ako 0. | Podaj ilość większą niż 0. |
| Invalid e-mail | “{text}” is not a valid e-mail address. | „{text}“ nie je platná e-mailová adresa. | „{text}” nie jest prawidłowym adresem e-mail. |
| Too many addresses | You can send to at most 5 addresses at a time. | Naraz možno odoslať najviac na 5 adries. | Jednorazowo można wysłać najwyżej na 5 adresów. |
| Send limit | You have sent too many lists today. Try again later. | Dnes ste odoslali príliš veľa zoznamov. Skúste to neskôr. | Dziś wysłano zbyt wiele list. Spróbuj ponownie później. |
| Reminder in the past | Choose a time in the future. | Vyberte čas v budúcnosti. | Wybierz czas w przyszłości. |
| Duplicate catalog name | An item with this name already exists. | Položka s týmto názvom už existuje. | Produkt o tej nazwie już istnieje. |
| Forbidden action (403 toast) | You are not allowed to do that on this list. | Na tomto zozname to nemôžete urobiť. | Nie możesz tego zrobić na tej liście. |

### Dialogs, hints and toasts

| Element | en | sk (draft) | pl (draft) |
|---------|----|------------|------------|
| Only responsible hint | Only {name} can lock this list. | Zoznam môže uzamknúť iba {name}. | Listę może zablokować tylko {name}. |
| Lock confirm (title / body) | Lock this list? / Nobody can add, remove or edit items while it is locked. You can unlock it again. | Uzamknúť zoznam? / Po uzamknutí nikto nemôže pridávať, odstraňovať ani upravovať položky. Môžete ho znova odomknúť. | Zablokować listę? / Po zablokowaniu nikt nie może dodawać, usuwać ani edytować produktów. Możesz ją ponownie odblokować. |
| Unlock confirm (title / body) | Unlock this list? / Items can be changed again. Marks Bought and Unavailable are kept. | Odomknúť zoznam? / Položky sa budú dať opäť meniť. Označenia Zakúpené a Nedostupné ostanú zachované. | Odblokować listę? / Produkty będzie można znowu zmieniać. Oznaczenia Kupione i Niedostępne zostaną zachowane. |
| Finish (no unavailable) | Finish shopping? / The list moves to the archive and becomes read-only. (Finish shopping / Not yet) | Ukončiť nákup? / Zoznam sa presunie do archívu a bude iba na čítanie. (Ukončiť nákup / Ešte nie) | Zakończyć zakupy? / Lista trafi do archiwum i będzie tylko do odczytu. (Zakończ zakupy / Jeszcze nie) |
| Carry-over question (owner wording) | Some items could not be bought. Create a new shopping list from them? | Niektoré položky sa nepodarilo zakúpiť. Chcete z nich vytvoriť nový nákupný zoznam? | Nie udało się kupić niektórych produktów. Czy chcesz utworzyć z nich nową listę zakupów? |
| Carry-over buttons | Create new list / No, just finish / Cancel | Vytvoriť nový zoznam / Nie, iba ukončiť / Zrušiť | Utwórz nową listę / Nie, tylko zakończ / Anuluj |
| Carry-over note | The new list keeps the store, the responsible person and who can see it. | Nový zoznam zachová obchod, zodpovednú osobu a prístupové práva. | Nowa lista zachowa sklep, osobę odpowiedzialną i prawa dostępu. |
| New list name suffix | {name} (unbought) | {name} (nezakúpené) | {name} (niekupione) |
| Archived banner | This shopping is finished. You can copy it into a new list. | Tento nákup je vybavený. Môžete ho skopírovať do nového zoznamu. | Te zakupy są zakończone. Możesz skopiować je do nowej listy. |
| Copy dialog (title / name / keep / CTA) | Copy list / Name of the new list / Keep store, responsible person and access / Copy list | Kopírovať zoznam / Názov nového zoznamu / Zachovať obchod, zodpovednú osobu a prístup / Kopírovať zoznam | Kopiuj listę / Nazwa nowej listy / Zachowaj sklep, osobę odpowiedzialną i dostęp / Kopiuj listę |
| Copy name default | {name} (copy) | {name} (kópia) | {name} (kopia) |
| E-mail dialog (title / field / preview) | Send by e-mail / E-mail addresses / The e-mail lists the items with quantities, the store and the responsible person. | Odoslať e-mailom / E-mailové adresy / E-mail obsahuje položky s množstvami, obchod a zodpovednú osobu. | Wyślij e-mailem / Adresy e-mail / E-mail zawiera produkty z ilościami, sklep i osobę odpowiedzialną. |
| Reminder helper | A push notification goes to the responsible person only. | Push upozornenie dostane iba zodpovedná osoba. | Powiadomienie push otrzyma tylko osoba odpowiedzialna. |
| Reminder push (title / body) | Time to shop / {list} at {store}. | Čas na nákup / {list} v obchode {store}. | Czas na zakupy / {list} w sklepie {store}. |
| Responsible role hint | Only this person can lock the list and mark items. | Iba táto osoba môže zoznam uzamknúť a označovať položky. | Tylko ta osoba może zablokować listę i oznaczać produkty. |
| Creator takes over | You are now responsible for this list because {name} was removed. | Teraz zodpovedáte za tento zoznam, pretože {name} bol odstránený. | Jesteś teraz odpowiedzialny za tę listę, ponieważ {name} został usunięty. |
| Toasts | List created / Items added / Quantity of {name} updated / List locked / List unlocked / Shopping finished / Shopping finished. New list created. / List copied / List sent to {n} address(es) / Item deleted / Store deleted / Unit deleted / Settings saved | Zoznam vytvorený / Položky pridané / Množstvo položky {name} zmenené / Zoznam uzamknutý / Zoznam odomknutý / Nákup ukončený / Nákup ukončený. Nový zoznam vytvorený. / Zoznam skopírovaný / Zoznam odoslaný na {n} adries / Položka odstránená / Obchod odstránený / Jednotka odstránená / Nastavenia uložené | Lista utworzona / Produkty dodane / Zmieniono ilość produktu {name} / Lista zablokowana / Lista odblokowana / Zakupy zakończone / Zakupy zakończone. Utworzono nową listę. / Lista skopiowana / Lista wysłana na {n} adresów / Produkt usunięty / Sklep usunięty / Jednostka usunięta / Ustawienia zapisane |

### Destructive actions

| Action | Confirmation approach | Confirm / Cancel (en) |
|--------|----------------------|-----------------------|
| Delete list (responsible person or creator) | `UiConfirmDialog` danger. Title "Delete “{name}”?", body "The list and its items will be deleted for everyone. This cannot be undone." | Delete list / Keep list |
| Remove item from list | `UiConfirmDialog` danger from the edit dialog: "Remove {name} from this list?" | Remove item / Keep item |
| Delete catalog item | `UiConfirmDialog` danger with usage count (see Settings screen) | Delete item / Keep item |
| Delete store | `UiConfirmDialog` danger with usage count | Delete store / Keep store |
| Delete custom unit | `UiConfirmDialog` danger with usage count | Delete unit / Keep unit |
| Remove image or logo | `UiConfirmDialog` danger: "Remove this image? The placeholder icon will be shown instead." | Remove image / Keep image |
| Lock, unlock, finish | Non-destructive confirm (accent button), see dialogs above; marking Bought/Unavailable has no confirm and is undone by tapping again |  |

sk and pl equivalents of the destructive confirms (drafts): Delete list / Odstrániť zoznam / Usuń listę ("Odstrániť zoznam „{name}“? Zoznam a jeho položky sa odstránia pre všetkých. Nedá sa to vrátiť." / "Usunąć listę „{name}”? Lista i jej produkty zostaną usunięte dla wszystkich. Tego nie można cofnąć."); keep buttons Ponechať / Zachowaj; Delete item Odstrániť položku / Usuń produkt; Delete store Odstrániť obchod / Usuń sklep; Delete unit Odstrániť jednotku / Usuń jednostkę; Remove image Odstrániť obrázok / Usuń obraz.

### Contextual help and manual (CLAUDE.md "User documentation")

HelpTip topics (registry `resources/help/topics.json`, texts en/sk/pl in `resources/help/*.json`, each under 400 characters): `shopping.lock` (what locking does, who can lock and unlock, marking Bought/Unavailable), `shopping.roles` (responsible person versus contributors versus viewers), `shopping.carry_over` (what happens to unavailable items and the archive), `shopping.reminder` (reminder goes to the responsible person only), `shopping.email` (any address, max 5, plain text, rate limit), `shopping.catalog` (catalog versus free-text items, deleting keeps items as text), `shopping.units` (built-in units and adding more), `shopping.store` (stores and logos). Placement: next to the "Lock list" button label in the list header, the "Responsible person" and role fields, the carry-over dialog title, the Reminder field, the e-mail dialog title and the three settings tabs.

User manual: new topic file `resources/manual/<en|sk|pl>/shopping.json` (sections: Lists, Adding items, Roles and access, Locking and shopping, Finishing and archive, Copying, E-mail, Reminders, Settings) with a `covers` entry; the Settings topic gets a "Shopping" section. sk and pl are drafts for native review. Run `ManualCoverage.test.js`, `HelpCoverage.test.js` and `tests/Feature/Manual`.

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
