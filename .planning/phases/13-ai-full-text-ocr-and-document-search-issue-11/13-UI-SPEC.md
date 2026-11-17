---
phase: 13
slug: ai-full-text-ocr-and-document-search-issue-11
status: draft
shadcn_initialized: false
preset: none
created: 2026-11-17
---

# Phase 13 — UI Design Contract

> Visual and interaction contract for Phase 13 (GitHub #11): document search on the Health pages, the read-only "Recognised text" view in the document panel, the document panel states and notice for the two-step AI reading, and the new bulk scope "Documents without recognised text". Everything reuses the locked design system (`resources/css/tokens.css`, sketch findings, `Components/ui/*`). No new tokens, colours, type sizes or weights. Sources: 13-CONTEXT.md, 13-RESEARCH.md (search design, privacy, UI notes), ISSUES-6-11-OWNER-DECISIONS.md ("Phase 13 defaults"), Phase 11 and 12 UI-SPECs (format), `resources/js/Health/*` (`DocumentPanel.vue`, `Ai/RunAiControl.vue`, `Ai/AiStatusTag.vue`, `Ai/AiNoticeDialog.vue`, `PersonSwitcher.vue`, `AiConsentControl.vue`), `resources/js/Settings/AiAgents/BulkRegenPanel.vue`, `resources/js/Pages/Recipes/Index.vue` (debounced search pattern). Deferred and not designed: "documents mentioning this drug" view, evidence/grounding tag on suggestions (owner default: not added), fuzzy matching, search of other modules.

Facts that shape the layout:
- `/health` has no overview page of its own: it redirects to the first visible person (`PersonController::index`) and renders `Pages/Health/Person.vue`; only a family with no visible person gets the empty `PersonPage`. So "health overview" and "person page" are the same page. The search box lives once, in `Person.vue`, with a scope control "All people" / "{person}". It is not rendered when no person is visible.
- Search runs only against `POST /health/search` (JSON). The query text never appears in a URL, history entry or `Referer`, so the search state is NOT kept in the query string (unlike Recipes filters).

---

## Design System

| Property | Value |
|----------|-------|
| Tool | none (in-house Vue set in `resources/js/Components/ui`; shadcn gate not applicable: Laravel + Inertia + Vue) |
| Preset | not applicable |
| Component library | none (in-house `Ui*` components) |
| Icon library | `@lucide/vue` |
| Font | `--font-sans`; `--font-mono` is NOT used for the recognised text (see section 2) |

Components reused (do not re-create): `UiAlert`, `UiButton`, `UiCard`, `UiConfirmDialog`, `UiDetailPanel`, `UiDialog`, `UiEmptyState`, `UiErrorState`, `UiField`, `UiInput`, `UiList`/`UiListRow`, `UiRadio`, `UiSelect`, `UiSkeleton`, `UiTag`, `useToast`, `HelpTip`, `securityFetch` (POST, `replay: true` as in `RunAiControl`), `handleHealthRefusal`, `AiStatusTag`, `AiNoticeDialog`, `RunAiControl`, `BulkRegenPanel`.

New components (compositions only, under `resources/js/Health/Search/` and `resources/js/Health/Text/`):

| Component | Built from | Purpose |
|-----------|-----------|---------|
| `Health/Search/DocumentSearch.vue` | UiInput, UiSelect, UiButton, UiAlert | Search box, scope select, state machine, live region; mounted once in `Pages/Health/Person.vue` above the view switch |
| `Health/Search/SearchResults.vue` | UiList, UiListRow, UiTag, UiSkeleton, UiEmptyState, UiErrorState, UiButton | Results list and all non-idle states |
| `Health/Search/SearchSnippet.vue` | `<span>`, `<mark>` | Renders `[{text, hit}]` segments with `v-for` (never `v-html`) |
| `Health/Text/RecognisedText.vue` | UiButton, UiTag, UiAlert, UiSkeleton, UiConfirmDialog | Disclosure in the document panel: expand, page blocks, copy, partial and "may contain errors" notes, remove |

Changed (copy and small additions only): `RunAiControl.vue` (detail line while step 1 is done, provider line wording, `text_saved` state), `AiStatusTag.vue` (one new status `text_saved`), `AiNoticeDialog.vue` and the three notice key families (new text), `AiConsentControl.vue` (turn-off confirmation wording, text is deleted), `BulkRegenPanel.vue` (new scope `no_text`), `DocumentPanel.vue` (mounts `RecognisedText` under `RunAiControl`).

Navigation: no new route in the sidebar. Search results link to the existing person and document panel (`/health/people/{person}?panel=document&id={documentId}`); the panel is opened by the existing panel logic of `Person.vue`.

---

## Spacing Scale

Existing tokens only: `--space-1` 4, `--space-2` 8, `--space-3` 12, `--space-4` 16, `--space-6` 24, `--space-8` 32, `--space-12` 48, `--space-16` 64.

| Token | Value | Usage in this phase |
|-------|-------|---------------------|
| xs | 4px | icon to label gap; title to meta line in a result row; snippet line gap |
| sm | 8px | gap between search box, scope select and button; gap between meta tags; gap between disclosure header buttons |
| md | 16px | result row padding; gap between result rows; padding of the recognised-text box; gap between notes in the panel |
| lg | 24px | gap between the search block and the view below it; gap between the results header and the first row |
| xl | 32px | top spacing of the results region on wide |
| 2xl | 48px | empty and error state vertical padding |
| 3xl | 64px | empty state vertical padding on wide only |

Exceptions (sizes, not spacing): search input and scope select height `--control-md` 40px on wide, `--control-touch` 44px on phone; every result row is one link, min height 64px; recognised-text box max height 360px (`overflow: auto`, scroll region), max width 72ch for the text lines; page separator row height 32px. Copy and Expand buttons 44px hit area on phone. No other exceptions.

---

## Typography

Existing scale only: 4 sizes, 2 weights.

| Role | Size | Weight | Line Height |
|------|------|--------|-------------|
| Body / result title / recognised text / input text | `--text-base` 16px | 400 (600 for result title and `<mark>` hits) | 1.5 |
| Label / meta / tag / hint / snippet / count line / page separator label / button label | `--text-sm` 14px | 600 for labels and separators, 400 for meta, snippets and hints | 1.5 |
| Heading (results heading "N documents found", "Recognised text" disclosure title) | `--text-xl` 20px | 600 | 1.2 |
| Display (page title) | `--text-2xl` 24px | 600 | 1.2 |

The recognised text itself is `--text-base` 16px 400 line-height 1.5 in `--font-sans` with `white-space: pre-wrap; overflow-wrap: anywhere` so tables from the OCR keep their line breaks without a new size or a monospace face. Snippets are `--text-sm` 400; the hit inside is 600. SK and PL run about 30 percent longer: titles wrap to 2 lines, never truncated in the list (snippet clamps to 3 lines with ellipsis; the full text is in the document panel).

---

## Color

Tokens from `tokens.css` (light and dark defined); nothing new.

| Role | Value | Usage |
|------|-------|-------|
| Dominant (60%) | `--color-bg` | page background |
| Secondary (30%) | `--color-surface`, `--color-surface-2` | result rows, recognised-text box, panel sections |
| Accent (10%) | `--color-primary` | see list below |
| Destructive | `--color-danger` | "Remove recognised text" confirm button, the purge wording in the turn-off dialog, form/transport error alerts; nothing else |
| Warn (existing semantic) | `--color-warn-bg` / `--color-warn-text` | "may contain errors" tag, partial-reading note tag, `<mark>` hit background |

Accent reserved for: primary button (Search submit when a visible submit button is shown, Run AI confirm, Start of the bulk run), focus ring, the selected scope option, the link colour of a result title on hover/focus (underline plus colour). Nothing else.

`<mark>` styling (never colour alone): background `--color-warn-bg`, text `--color-warn-text`, weight 600, radius `--radius-sm`, padding 0 2px. The weight change plus background keeps it readable in forced-colors and for colour-blind users; `forced-colors` falls back to the system `Mark` colours (do not override `forced-color-adjust`).

State styling (each has icon and text):

| State | Treatment |
|-------|-----------|
| Text may contain errors | `UiTag` warn with `TriangleAlert` icon, label "May contain errors"; shown in the recognised-text header and once above the search results when the evaluation gate flag `textReliability === 'uncertain'` is true; absent otherwise |
| Partial reading | `UiTag` neutral with `Layers` icon "First {n} of {total} pages" in the recognised-text header; per search result an `Info` icon line "Only the first {n} pages are searched" when `textComplete` is false |
| Text saved (run state) | `AiStatusTag` status `text_saved`: icon `FileText`, variant neutral, text "Text saved" |
| Document has no recognised text | nothing shown in the panel apart from the disclosure's empty note; never an error |

No red except the destructive confirm and request-failure alerts.

---

## Screens and Interaction Contract

### 1. Document search (`DocumentSearch.vue` in `Pages/Health/Person.vue`)

Placement: directly under the page title and `PersonSwitcher`/`PersonHeader`, above the `ViewSwitch` and the timeline, full width up to `--content-max`. A `role="search"` landmark `<form>` with `aria-label` "Search documents". Rendered when the viewer has Health view access and at least one visible person; hidden while the feature is off (`ai.ocr.enabled` false and no text rows: the box still searches titles, so it shows whenever the prop `search.enabled` is true).

Controls (stacked on phone, one row on wide from 640px container width, gaps `--space-2`):
- Search `UiInput` `type="search"`, icon `Search`, visible label "Search documents" (visually hidden label allowed on wide only; on phone the label is the placeholder plus an `aria-label`), placeholder "Name or words in the document", `autocomplete="off"`, `autocapitalize="off"`, `spellcheck="false"`, `enterkeyhint="search"`, `inputmode="search"`, `maxlength="100"`. A clear button `X` (44px, `aria-label` "Clear search") inside the field when it has text.
- Scope `UiSelect` (label "Search in"): options "All people" (default) and "{person name}" for the person currently open. Visible only when more than one person is visible to the viewer; with a single person the scope is fixed and not rendered. The selected person is sent as `person` (UUID) and a malformed or unknown value yields the same empty result as no match.
- `UiButton` primary "Search" with `Search` icon (44px phone, 40px wide). Not hidden on phone: Enter and the button do the same.

Behaviour:
- Minimum 2 characters after trim; tokens are `\p{L}\p{N}` only on the server, the client does not strip anything but trims. Under 2 characters nothing is sent; the idle hint is shown and, when the user has typed exactly 1 character, the hint line "Type at least 2 letters." appears (muted, `aria-live="polite"`).
- Debounce 300 ms after typing stops, immediate on Enter or button. A new request aborts the previous one (`AbortController`); only the newest answer is rendered (a stale answer is dropped by a request counter).
- Request: `securityFetch('/health/search', { method: 'POST', body: { q, person?, page }, replay: true })`. The query is held in component memory only: no URL, no `localStorage`/`sessionStorage`, no Inertia visit, no `router` state, no push into history, no service-worker cache. Closing or leaving the page forgets it. The `name` of the input has no `form` autofill hint (`autocomplete="off"`), so browsers do not save medical words as form history.
- Results appear in a region directly under the search block. While a query is active the timeline and folder view below it are visually replaced by the results region (the view switch and add buttons stay reachable above; the results region has a heading and one way back, the ghost button "Clear search", which restores the view and moves focus to the search input).
- Focus: after a search completes by Enter or button, focus moves to the results heading (`tabindex="-1"`); while typing (debounced) focus stays in the input. The polite live region announces "N documents found" / "No documents found" (not the content of results).
- Escape inside the input clears it when it has text, else blurs nothing.

Results region (`SearchResults.vue`):
- Heading `--text-xl` "N documents found" (ICU plural), computed only from the filtered answer of this page. When `hasMore` is true the heading is the plain "Documents found" (no number, so the count never implies a total) with a muted count line under it "Showing {n}" and the pagination button below the list. No total across pages, no "N hidden results", no hint about documents the viewer cannot see. Hidden and non-matching documents give an identical answer.
- When `textReliability === 'uncertain'`: one `UiAlert` info (not warn) under the heading: "The text was read by AI and may contain mistakes. A word can be missed if it was misread." with `TriangleAlert` icon. Hidden when reliable.
- Result row (`UiListRow`, the whole row is one link to the document panel, focus ring on the row, min 64px):
  1. Title (`--text-base` 600, wraps, `overflow-wrap: anywhere`) rendered as text only (interpolated, never HTML).
  2. Meta line (`--text-sm` 400 muted, wraps): person name as `UiTag` neutral with `UiAvatar` 24 omitted (name only, `User` icon), upload date (`Intl.DateTimeFormat` locale, medium date), visit name as `UiTag` neutral with `Stethoscope` icon when `visitId` is set and the visit is visible (omitted when archived, as in `PersonPage::documentProps`), "page {n}" when `page` is not null.
  3. Snippets (`--text-sm`, 400, up to 2 per document, each clamped to 3 lines, `…` between): `SearchSnippet` renders segments as `<span>` for `hit: false` and `<mark>` for `hit: true`; a visually hidden "match: " prefix is NOT added (screen readers announce `<mark>` as is); each snippet is `lang`-neutral plain text. A document found only by title has no snippet and shows the muted line "Matches the document name."
  4. When `textComplete` is false: muted `Info` line "Only the first {n} pages are searched." (pages from the result item `pagesRead`).
- Row link target `/health/people/{personId}?panel=document&id={documentId}`; Enter and Space on the row activate (a real `<a>`). If the viewer cannot open it any more (403/404 after loading) the existing panel error applies; no special copy.
- Order: server order (relevance, then newest). No sort control in v1.
- Pagination: 20 per page, "Load more" `UiButton` ghost (44px) under the list when `hasMore`; appends the next page (the same POST with `page + 1`), keeps focus on the first new row, live region "{n} more documents loaded". A page request that fails keeps the list and shows an inline `UiAlert` danger with "Try again".
- Loading: first search shows 3 `UiSkeleton` rows at real row height, region `aria-busy="true"`; subsequent searches keep the old list at reduced emphasis (opacity per existing disabled convention is not used: keep the list and show a small `UiProgress` indeterminate bar above it) so the list does not jump.
- Empty (no match, same copy whether the cause is "nothing matches" or "hidden"): `UiEmptyState` icon `SearchX`, title "No documents found", body "Documents are searched by name and by the text read from them. Only documents you may open are searched. Documents that were never read by AI are found by name only.", ghost button "Clear search".
- Idle (no query yet): muted hint under the box, `--text-sm`: "Search by document name or by words inside the documents." plus `HelpTip` `health.search`.
- Error (network or 5xx): `UiErrorState` "We could not search. Try again." with button "Try again" (re-sends the same query). Throttled (429): `UiAlert` warn "You searched too often. Wait a moment and try again." (no countdown; the box stays usable after 5 s). Session expired / 423 locked: `handleHealthRefusal` as everywhere else in Health. No stack, status code or server text is shown.
- Privacy of the screen: results are never written to the Inertia page props, never persisted, and the region is cleared when the person lock/re-lock of Health engages (existing `handleHealthRefusal` path clears state on 423).

Responsive:
- Phone (container under 640px): box, scope and button stacked full width; result rows full width without side gutters beyond `--space-4`; meta tags wrap; snippets 3 lines; Load more full width.
- Tablet (640 to 1024px): one row (input grows, scope 200px, button auto); list single column.
- Desktop: same single column list up to `--content-max`; no two-column results (snippets need width). Responds to container width (container queries), per sketch findings.

Accessibility:
- Landmark `role="search"`; label tied to the input; scope select labelled; `type="search"` clear button is the app's own (the browser's native cancel button is hidden with `appearance: none` so it is not duplicated).
- Results region `aria-live` is only the single polite status line; the list itself is not live. `aria-busy` on the region while loading.
- Heading levels: results heading is an `h2` under the page `h1`.
- Row is a single link whose accessible name is the title followed by person and date (meta text is inside the link, snippets also inside, in DOM order); `<mark>` is kept as a plain element (no `aria-label`).
- All controls at least 44px on phone; focus ring from tokens; `prefers-reduced-motion` respected (no scroll animation when focus moves to the heading).
- No `v-html` anywhere in this feature (checked by a Vitest source scan like other Health views).

### 2. Recognised text in the document panel (`RecognisedText.vue`)

Placement: inside `DocumentPanel.vue`, between `RunAiControl` and the edit form (read-only members see it between the links and the read-only list). Rendered for everyone who sees the document (view-only included); it is hidden entirely when the server prop `document.hasText` is false and no run is in progress. The text itself is never in the Inertia props: it is fetched on first expand from `GET /health/people/{person}/documents/{document}/text` (`Cache-Control: no-store`), kept in component memory, and dropped when the panel closes or the document changes.

Header row (a `<section aria-labelledby>`): a disclosure button (`UiButton` ghost, full-width row, 44px, `aria-expanded`, `aria-controls`, icon `ChevronDown` rotating to `ChevronUp` without animation under reduced motion) with the title "Recognised text" (`--text-xl` 600 as the section heading inside the button label) and, on the right of the same row, the tags: warn tag "May contain errors" (when `textReliability === 'uncertain'`) and neutral tag "First {n} of {total} pages" (when `complete` is false). `HelpTip` `health.document_text` next to the title. Collapsed by default every time.

Expanded content (`id` target of `aria-controls`):
1. Info line (muted `--text-sm`, `Info` icon) always: "Read by AI from the document. Words can be misread, so check the document itself when it matters." (shown once, not repeated per page).
2. If `complete` is false: `UiAlert` info "Only the first {n} of {total} pages were read, so only those pages are searchable." (reuses the same facts as the tag; the tag is the short form, this is the explanation).
3. The text box: `UiCard`-style block on `--color-surface-2`, radius `--radius-md`, padding `--space-4`, max height 360px, `overflow: auto`, `tabindex="0"`, `role="region"`, `aria-label` "Recognised text of {title}" (so keyboard users can scroll it), `lang` attribute = the language hint when it is `sk`, `pl`, `en`, otherwise omitted. Pages are separated by a page label row: `--text-sm` 600 muted "Page {n}" on a 1px divider (token border), `--space-4` above. A single-page document shows no page label. Text is rendered as plain strings with `white-space: pre-wrap` (never HTML; a hostile document that contains markup shows it as characters). Page 1 starts at the top; empty pages (`legible: false`, empty string) show the muted line "Nothing legible on this page." instead of empty space.
4. Actions row (`--space-2` gap, wraps): ghost `UiButton` "Copy text" (`Copy` icon) and, for editors only, ghost "Remove recognised text" (`Trash2` icon, `--color-danger` text like `.doc-delete`).
   - Copy: `navigator.clipboard.writeText(text)`; success toast "Text copied" (`useToast`) and the button label stays; failure (no clipboard permission or insecure context) toast "Could not copy. Select the text and copy it yourself." The copied text is the whole stored text with page labels written as a blank line plus "Page {n}" (so pages stay distinguishable); a Vitest test pins the exact format. No copy is logged.
   - Remove (edit permission only; read-only members never see it, it is not rendered): `UiConfirmDialog` danger. Title "Remove the recognised text?", body "The text read from this document will be deleted from this app. The document and any suggestions you already accepted stay. The document will no longer be found by the words inside it. You can run AI again to read it later. Copies in backups are removed when the backups expire.", confirm "Remove text", cancel "Keep text". Success: toast "Recognised text removed", the disclosure collapses and disappears (`hasText` false), focus goes to the Run AI button. Failure: inline `UiAlert` danger in the section "The text could not be removed. Try again."; a 423 uses `handleHealthRefusal`.
5. Loading on expand: `UiSkeleton` 5 text lines inside the box area, `aria-busy="true"`. Error: `UiAlert` danger "We could not load the text." with ghost "Try again". 404/hidden: the section silently disappears (same as no text) and the panel keeps working.
- While a run is in progress for this document the section shows the muted line "The text is being read. It will appear here when it is saved." in place of the disclosure body only when no text exists yet; when an older text exists it remains readable and a muted line says "A new reading is in progress. This is the earlier text." (so the user never sees half-saved text; the server replaces the row atomically).

Who sees it: exactly the people who may see the document (view-only included, read-only); no separate permission. Hidden for archived documents and hidden persons by the server; the client has nothing to decide. Text is not offered in exports, print or the emergency card, so no button for that exists.

### 3. Document panel states for the AI run (`RunAiControl.vue`, `AiStatusTag.vue`)

The control, its blocked reasons, Cancel run, quota waiting and "Review suggestions" stay as they are. Changes are limited to copy, one new status and one detail line:

| Moment | Tag | Detail line (`--text-sm` muted) | Notes |
|--------|-----|--------------------------------|-------|
| Queued / reading (step 1, images) | `queued` "Waiting in line" / `running` "AI is reading" (existing) | "AI is reading the document. This usually takes a few minutes. You can leave this page." | `wait_detail` reworded to cover reading and extracting; same spinner rule |
| Text stored, suggestions being prepared (step 2) | `running` "AI is reading" (unchanged tag) | "The text is saved. AI is now preparing suggestions from it." (`entry.textSaved === true` while `status === 'running'`) | tag does not change so the screen does not flicker; the recognised-text section appears (live region announces "The text of the document is saved.") |
| Waiting for quota between the steps | `waiting` (existing) | existing `waiting_detail` plus, when `entry.textSaved`, "The text is already saved." | resumes at step 2 |
| Finished full run | `ready` "Suggestions ready" (existing) | existing ready detail plus the existing "first {n} pages" line | `pagesRead < pagesTotal` marker also appears in the text section |
| Finished text-only run (bulk `no_text`, no proposal) | new `text_saved` "Text saved" | "The text is saved and searchable. No suggestions were made. Press Run AI to get suggestions." | tag icon `FileText`, neutral; the Run AI button shows its normal label "Run AI" (secondary); no "Review suggestions" button; `announce_text_saved` "The text of the document is saved." |
| Failed after text saved | `failed` (existing) | existing failed detail plus "The recognised text was saved." | the text stays readable; the tag stays danger |
| Failed before text saved | `failed` (existing) | existing | nothing in the text section |
| Partial text (page cap) | `ready` or `text_saved` | "Only the first {n} pages were read." (existing key) | additionally marked in the text section |

- `AiStatusTag` gets exactly one new case (`text_saved`) and `text_saved` is added to the tag's `data-status` tests; `detail` switch gains the cases above. The existing `readyExtractionId` and Review button behaviour is unchanged (a `text_saved` run never replaces a ready proposal; if one exists the control keeps showing its tag by the server's rule).
- Provider line under the button (replaces `provider_codex`/`provider_both`/`provider_copilot` copy): names the provider and says the recognised text is saved here (see Copywriting). Blocked reasons are unchanged.
- Notice dialog: `AiNoticeDialog` gets new body copy for the three variants (codex, multi, github) stating the two-step sending and the storage. Acknowledgment is required again once (server-side `text_notice_acked_at`); the dialog title and buttons are unchanged ("Send this document to OpenAI?", "Run AI", "Keep document private"). A 409 `notice_required` already reopens the dialog; no new client path. The dialog content is `max-width` 480 on wide, bottom sheet on phone (existing `UiDialog`), the body is the one place the user is told: "stored in the family's database", "can be searched by members who may open the document", "kept until the document is deleted or you remove it", "also in backups".
- Re-run confirm (`rerun_*`): unchanged. For a document that already has text, Run AI replaces the text; the existing re-run dialog is not shown for text alone.

AI consent turned off (`AiConsentControl.vue`): the existing turn-off confirmation body gets one added sentence because the owner default is to purge: "The text read from this person's documents will also be deleted, so documents can no longer be found by the words inside them. Names and titles can still be searched." The confirm button keeps its existing label; there is no checkbox (owner default: purge always). After purging, the search results for this person show titles only; nothing else changes in the UI.

### 4. Bulk re-generation panel (`BulkRegenPanel.vue`, Settings > AI agents)

- New scope `no_text` in `SCOPES`, order: `no_proposal`, `no_text`, `last_failed`, `all`. A `UiRadio` row with label "Documents without recognised text" and hint (always visible under that option, `--text-sm` muted): "Only the text is read and saved. Suggestions that exist or were accepted are not changed." (sk/pl in Copywriting).
- Same plan block as today: cap field, mandatory dry run, freshness rule, guarded Start. Changing scope invalidates the dry run as for the other scopes (existing `result.scope === scope` rule).
- Dry-run result rows for `no_text`: `row_decided` ("skipped, suggestions already accepted") is not meaningful, so the row is hidden for this scope (the server also returns 0); the other rows are unchanged. Result sentence: the existing "N documents ..." text, plus for `no_text` the line "These documents will be read by the AI provider and their text saved here. No suggestions will be made." (same `Info` line style as the existing dry-run notes).
- The confirm dialog for Start (existing) gets the same notice sentence in its body when the scope is `no_text`; the provider words and the "starts at {hour}" parts are unchanged. If the admin has not yet acknowledged the new text notice (`text_notice_acked_at` null) the existing notice-required path applies: the dialog shows the new notice and Start sends the acknowledgment; no new component.
- Progress and history: the active run block shows counts as today. History rows get an extra neutral `UiTag` "Text only" (`FileText`) for runs with scope `no_text` (scope is already in the row data; label added), so a counts-only history stays distinguishable. No person, document or text is shown anywhere in the panel (unchanged rule).
- Empty / zero eligible: existing `zero_eligible` copy; for `no_text` a dedicated line "Every document already has recognised text." (replaces the generic one only for this scope).
- Help: the existing `settings.ai_bulk` tip text gets a sentence about the new scope (reword, same key); no new tip.

### 5. Empty, loading and permission states summary

| Where | State | Result |
|-------|-------|--------|
| Search box | no Health access | box not rendered (page itself is 403) |
| Search | only hidden/archived matches | same "No documents found" as no match |
| Recognised text | no text yet | section not rendered; the Run AI control is the entry point |
| Recognised text | read-only member | sees text and Copy, no Remove |
| Bulk panel | AI agents not signed in | existing `noAgentSignedIn` note applies to the new scope too |

### 6. Accessibility (feature-wide)

- Every icon-only button has `aria-label` (clear search 44px, and so on); disclosures use `aria-expanded` and `aria-controls`.
- The scrollable recognised-text box is focusable (`tabindex="0"`) with a name; text has AA contrast from tokens.
- Search status via one polite live region; never announce document names or snippet text.
- Dialogs trap focus (`UiDialog`/`UiConfirmDialog`), Escape closes except while a request runs; focus returns to the trigger (Remove text: to Run AI because the trigger disappears).
- Contrast of `<mark>` uses the warn pair (designed AA) and weight 600.
- Motion: only existing transitions; `prefers-reduced-motion` respected.
- 320px: everything wraps; no horizontal scroll except inside the recognised-text box lines (which wrap anyway).

---

## Copywriting Contract

All strings via vue-i18n / Laravel language keys: new namespaces `health.search.*` and `health.document_text.*`; changed existing keys listed below (rewording only, meaning of each key kept; new behaviour uses new keys where meaning changes: `health.ai.run.notice_text_*`, `health.ai.notice.*_text_body`, `health.ai.status.text_saved*`, `settings.ai_bulk.scope_no_text*`). en is source; sk and pl are drafts for native review (plural forms follow the existing 4-form sk and pl patterns in `health.ai.status.*`).

### Primary elements

| Element | en | sk (draft) | pl (draft) |
|---------|----|------------|------------|
| Search label / landmark | Search documents | Hľadať v dokumentoch | Szukaj w dokumentach |
| Search placeholder | Name or words in the document | Názov alebo slová v dokumente | Nazwa lub słowa w dokumencie |
| Search submit / clear | Search / Clear search | Hľadať / Zrušiť hľadanie | Szukaj / Wyczyść wyszukiwanie |
| Scope label / options | Search in / All people / {name} | Hľadať v / Všetci ľudia / {name} | Szukaj w / Wszystkie osoby / {name} |
| Idle hint | Search by document name or by words inside the documents. | Hľadajte podľa názvu dokumentu alebo podľa slov v dokumentoch. | Szukaj według nazwy dokumentu lub słów w dokumentach. |
| Too short hint | Type at least 2 letters. | Napíšte aspoň 2 písmená. | Wpisz co najmniej 2 litery. |
| Results heading | {n} document found \| {n} documents found | Nájdený {n} dokument \| Nájdené {n} dokumenty \| Nájdených {n} dokumentu \| Nájdených {n} dokumentov | Znaleziono {n} dokument \| Znaleziono {n} dokumenty \| Znaleziono {n} dokumentów \| Znaleziono {n} dokumentu |
| Results heading with more pages | Documents found | Nájdené dokumenty | Znalezione dokumenty |
| Showing count | Showing {n} | Zobrazených: {n} | Wyświetlono: {n} |
| Load more / announce | Load more / {n} more documents loaded | Načítať viac / Načítaných ďalších dokumentov: {n} | Wczytaj więcej / Wczytano kolejne dokumenty: {n} |
| Title-only match line | Matches the document name. | Zhoduje sa s názvom dokumentu. | Pasuje do nazwy dokumentu. |
| Page meta | Page {n} | Strana {n} | Strona {n} |
| Partial note (per result) | Only the first {n} pages are searched. | Prehľadávajú sa len prvé {n} strany. | Przeszukiwane są tylko pierwsze {n} strony. |
| Recognised text title | Recognised text | Rozpoznaný text | Rozpoznany tekst |
| Disclosure aria / page label | Show or hide the recognised text / Page {n} | Zobraziť alebo skryť rozpoznaný text / Strana {n} | Pokaż lub ukryj rozpoznany tekst / Strona {n} |
| Text region aria-label | Recognised text of {title} | Rozpoznaný text dokumentu {title} | Rozpoznany tekst dokumentu {title} |
| Tag: may contain errors | May contain errors | Môže obsahovať chyby | Może zawierać błędy |
| Tag: partial | First {n} of {total} pages | Prvé {n} z {total} strán | Pierwsze {n} z {total} stron |
| Copy / Remove | Copy text / Remove recognised text | Kopírovať text / Odstrániť rozpoznaný text | Kopiuj tekst / Usuń rozpoznany tekst |
| Info line (text view) | Read by AI from the document. Words can be misread, so check the document itself when it matters. | Text prečítala AI z dokumentu. Slová sa môžu prečítať nesprávne, preto keď na tom záleží, skontrolujte samotný dokument. | Tekst odczytała AI z dokumentu. Słowa mogą zostać źle odczytane, dlatego gdy to ważne, sprawdź sam dokument. |
| Text saved tag | Text saved | Text uložený | Tekst zapisany |
| Scope: no text | Documents without recognised text | Dokumenty bez rozpoznaného textu | Dokumenty bez rozpoznanego tekstu |
| History tag | Text only | Iba text | Tylko tekst |

### Empty states

| Element | en | sk (draft) | pl (draft) |
|---------|----|------------|------------|
| No results (title / body / button) | No documents found / Documents are searched by name and by the text read from them. Only documents you may open are searched. Documents that were never read by AI are found by name only. / Clear search | Nenašli sa žiadne dokumenty / Dokumenty sa hľadajú podľa názvu a podľa textu, ktorý z nich AI prečítala. Hľadajú sa len dokumenty, ktoré môžete otvoriť. Dokumenty, ktoré AI nikdy neprečítala, sa nájdu len podľa názvu. / Zrušiť hľadanie | Nie znaleziono dokumentów / Dokumenty są przeszukiwane według nazwy i tekstu odczytanego z nich przez AI. Przeszukiwane są tylko dokumenty, które możesz otworzyć. Dokumenty, których AI nigdy nie czytała, są znajdowane tylko po nazwie. / Wyczyść wyszukiwanie |
| Page without legible text | Nothing legible on this page. | Na tejto strane nie je nič čitateľné. | Na tej stronie nic nie jest czytelne. |
| Text being read (no text yet) | The text is being read. It will appear here when it is saved. | Text sa číta. Zobrazí sa tu, keď sa uloží. | Tekst jest odczytywany. Pojawi się tu po zapisaniu. |
| Text being re-read | A new reading is in progress. This is the earlier text. | Prebieha nové čítanie. Toto je predchádzajúci text. | Trwa nowy odczyt. To jest wcześniejszy tekst. |
| Bulk: none left | Every document already has recognised text. | Každý dokument už má rozpoznaný text. | Każdy dokument ma już rozpoznany tekst. |

### Error states

| Element | en | sk (draft) | pl (draft) |
|---------|----|------------|------------|
| Search failed (title+button) | We could not search. / Try again | Hľadanie sa nepodarilo. / Skúsiť znova | Nie udało się wyszukać. / Spróbuj ponownie |
| Load more failed | The next documents could not be loaded. | Ďalšie dokumenty sa nepodarilo načítať. | Nie udało się wczytać kolejnych dokumentów. |
| Too many searches (429) | You searched too often. Wait a moment and try again. | Hľadali ste príliš často. Chvíľu počkajte a skúste to znova. | Szukano zbyt często. Poczekaj chwilę i spróbuj ponownie. |
| Results may be wrong (uncertain gate) | The text was read by AI and may contain mistakes. A word can be missed if it was misread. | Text prečítala AI a môže obsahovať chyby. Slovo sa nemusí nájsť, ak sa nesprávne prečítalo. | Tekst odczytała AI i może zawierać błędy. Słowo może nie zostać znalezione, jeśli zostało źle odczytane. |
| Text load failed | We could not load the text. (Try again) | Text sa nepodarilo načítať. (Skúsiť znova) | Nie udało się wczytać tekstu. (Spróbuj ponownie) |
| Partial alert | Only the first {n} of {total} pages were read, so only those pages are searchable. | Prečítaných bolo len prvých {n} z {total} strán, preto sa dajú prehľadávať len tie. | Odczytano tylko pierwsze {n} z {total} stron, więc tylko te strony można przeszukiwać. |
| Copy failed (toast) | Could not copy. Select the text and copy it yourself. | Kopírovanie sa nepodarilo. Text označte a skopírujte ručne. | Nie udało się skopiować. Zaznacz tekst i skopiuj go ręcznie. |
| Remove failed | The text could not be removed. Try again. | Text sa nepodarilo odstrániť. Skúste to znova. | Nie udało się usunąć tekstu. Spróbuj ponownie. |

### Dialogs, hints, notices and toasts

| Element | en | sk (draft) | pl (draft) |
|---------|----|------------|------------|
| Remove confirm (title / body / buttons) | Remove the recognised text? / The text read from this document will be deleted from this app. The document and any suggestions you already accepted stay. The document will no longer be found by the words inside it. You can run AI again to read it later. Copies in backups are removed when the backups expire. / Remove text / Keep text | Odstrániť rozpoznaný text? / Text prečítaný z tohto dokumentu sa z aplikácie odstráni. Dokument a návrhy, ktoré ste už prijali, ostanú. Dokument sa už nebude dať nájsť podľa slov v ňom. Neskôr môžete znova spustiť AI a text prečítať. Kópie v zálohách sa odstránia, keď zálohy vypršia. / Odstrániť text / Ponechať text | Usunąć rozpoznany tekst? / Tekst odczytany z tego dokumentu zostanie usunięty z aplikacji. Dokument i zaakceptowane już sugestie zostają. Dokument nie będzie już wyszukiwany według słów w nim zawartych. Później możesz ponownie uruchomić AI, aby go odczytać. Kopie w kopiach zapasowych znikną po wygaśnięciu kopii. / Usuń tekst / Zachowaj tekst |
| Toasts | Text copied / Recognised text removed | Text skopírovaný / Rozpoznaný text odstránený | Tekst skopiowany / Rozpoznany tekst usunięty |
| Run detail: reading | AI is reading the document. This usually takes a few minutes. You can leave this page. | AI číta dokument. Zvyčajne to trvá niekoľko minút. Túto stránku môžete opustiť. | AI czyta dokument. Zwykle trwa to kilka minut. Możesz opuścić tę stronę. |
| Run detail: text saved, preparing | The text is saved. AI is now preparing suggestions from it. | Text je uložený. AI z neho teraz pripravuje návrhy. | Tekst jest zapisany. AI przygotowuje teraz na jego podstawie sugestie. |
| Run detail: waiting with text | The text is already saved. | Text je už uložený. | Tekst jest już zapisany. |
| Run detail: failed with text | The recognised text was saved. | Rozpoznaný text sa uložil. | Rozpoznany tekst został zapisany. |
| Run detail: text only | The text is saved and searchable. No suggestions were made. Press Run AI to get suggestions. | Text je uložený a dá sa v ňom hľadať. Návrhy sa nevytvorili. Pre návrhy stlačte Spustiť AI. | Tekst jest zapisany i można w nim szukać. Nie utworzono sugestii. Aby je dostać, naciśnij Uruchom AI. |
| Announce text saved | The text of the document is saved. | Text dokumentu je uložený. | Tekst dokumentu został zapisany. |
| Provider line (codex) | Sends this document to OpenAI and saves the text it reads here. | Odošle tento dokument spoločnosti OpenAI a prečítaný text uloží sem. | Wysyła ten dokument do OpenAI i zapisuje odczytany tekst tutaj. |
| Provider line (both) | Sends this document to OpenAI, or to GitHub if OpenAI is not available, and saves the text it reads here. | Odošle tento dokument spoločnosti OpenAI, alebo spoločnosti GitHub, ak OpenAI nie je dostupná, a prečítaný text uloží sem. | Wysyła ten dokument do OpenAI lub do GitHub, jeśli OpenAI jest niedostępne, i zapisuje odczytany tekst tutaj. |
| Provider line (github) | Sends this document to GitHub and saves the text it reads here. | Odošle tento dokument spoločnosti GitHub a prečítaný text uloží sem. | Wysyła ten dokument do GitHub i zapisuje odczytany tekst tutaj. |
| Notice body (codex) | This document will leave this server and be sent to OpenAI to be read. It is sent in two steps: first the pictures of the pages, then the text that was read from them. OpenAI may keep both under its own terms. The text that was read is saved in this app's database, can be searched by members who may open the document, and stays until the document is deleted or you remove the text. It is also in backups until they expire. You will check every suggestion before anything is saved. You will only see this notice once. | Tento dokument opustí tento server a odošle sa spoločnosti OpenAI na prečítanie. Odošle sa v dvoch krokoch: najprv obrázky strán, potom text, ktorý z nich bol prečítaný. OpenAI môže uchovať oboje podľa vlastných podmienok. Prečítaný text sa uloží do databázy tejto aplikácie, môžu v ňom hľadať členovia, ktorí môžu dokument otvoriť, a ostane, kým sa dokument neodstráni alebo text neodstránite. Je aj v zálohách, kým nevypršia. Každý návrh skontrolujete vy skôr, než sa čokoľvek uloží. Toto upozornenie uvidíte len raz. | Ten dokument opuści ten serwer i zostanie wysłany do OpenAI w celu odczytania. Wysyłany jest w dwóch krokach: najpierw obrazy stron, potem tekst z nich odczytany. OpenAI może przechowywać oba zgodnie z własnymi warunkami. Odczytany tekst jest zapisywany w bazie danych tej aplikacji, mogą w nim szukać członkowie, którzy mogą otworzyć dokument, i pozostaje do usunięcia dokumentu lub tekstu przez ciebie. Jest też w kopiach zapasowych do ich wygaśnięcia. Każdą sugestię sprawdzisz, zanim cokolwiek zostanie zapisane. To powiadomienie zobaczysz tylko raz. |
| Notice body (github) | Same text with "GitHub" in place of "OpenAI" and the last sentence "You will see this notice again only if another service is added." | Rovnaký text so „GitHub“ namiesto „OpenAI“ a poslednou vetou „Toto upozornenie uvidíte znova, len ak sa pridá ďalšia služba.“ | Ten sam tekst z „GitHub” zamiast „OpenAI” i ostatnim zdaniem „To powiadomienie zobaczysz ponownie tylko wtedy, gdy zostanie dodana kolejna usługa.” |
| Notice body (multi) | Same text with "OpenAI, or GitHub if OpenAI is not available" in place of "OpenAI" and the last sentence of the github variant | Rovnaký text s „OpenAI, alebo GitHub, ak OpenAI nie je dostupná“ namiesto „OpenAI“ a poslednou vetou github verzie | Ten sam tekst z „OpenAI lub GitHub, jeśli OpenAI jest niedostępne” zamiast „OpenAI” i ostatnim zdaniem wersji github |
| Consent turn-off (added sentence) | The text read from this person's documents will also be deleted, so documents can no longer be found by the words inside them. Names and titles can still be searched. | Text prečítaný z dokumentov tejto osoby sa tiež odstráni, takže dokumenty sa už nedajú nájsť podľa slov v nich. Podľa názvov sa hľadať dá naďalej. | Tekst odczytany z dokumentów tej osoby również zostanie usunięty, więc dokumentów nie będzie już można znaleźć według słów w nich zawartych. Nazwy i tytuły nadal można przeszukiwać. |
| Bulk scope hint | Only the text is read and saved. Suggestions that exist or were accepted are not changed. | Číta sa a ukladá len text. Existujúce alebo prijaté návrhy sa nemenia. | Odczytywany i zapisywany jest tylko tekst. Istniejące lub zaakceptowane sugestie nie są zmieniane. |
| Bulk dry-run / confirm line | These documents will be read by the AI provider and their text saved here. No suggestions will be made. | Tieto dokumenty prečíta poskytovateľ AI a ich text sa uloží sem. Návrhy sa nevytvoria. | Te dokumenty odczyta dostawca AI, a ich tekst zostanie zapisany tutaj. Sugestie nie zostaną utworzone. |

### Destructive actions

| Action | Confirmation approach | Confirm / Cancel (en) |
|--------|----------------------|-----------------------|
| Remove recognised text (editors) | `UiConfirmDialog` danger, text above | Remove text / Keep text |
| Turn AI consent off (existing dialog, added sentence: text purged) | existing confirm, one sentence added | existing labels |
| Bulk run with scope no_text | existing Start confirm dialog, line added; not destructive (nothing deleted) | existing labels |

### Contextual help and manual (CLAUDE.md "User documentation")

HelpTip topics (registry `resources/help/topics.json`, texts in `resources/help/en.json`, `sk.json`, `pl.json`, each under 400 characters, never changing the meaning of an existing key): new `health.search` (what is searched: names and the text AI read from documents; only documents you may open; the words you type are not saved or put in the address), new `health.document_text` (what the recognised text is, who can see it, that it can contain errors, how to remove it). Reworded existing keys (same meaning, extended facts): `health.ai_run` (the text is saved here and is searchable), `settings.ai_bulk` (new scope text only). Placement: next to the search label (idle hint), next to the "Recognised text" title.

User manual: update `resources/manual/<en|sk|pl>/health.json`: new section id `search` (searching documents, results, what "may contain errors" means, limits such as two letters minimum and name-only for unread documents), new section `recognised-text` (the view, copy, remove, partial reading, who sees it, backups) and rewording of the existing `ai` section (two-step reading, text stored, notice, consent off deletes the text), same section ids and counts in en, sk and pl; sk and pl are drafts for native review. Settings AI agents manual topic: add the "Documents without recognised text" scope to the bulk run section in all three languages. Run `tests/js/manual/ManualCoverage.test.js`, `tests/js/help/HelpCoverage.test.js` and `tests/Feature/Manual`; list the touched `resources/manual/` and `resources/help/` files in `files_modified`.

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
