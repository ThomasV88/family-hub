---
phase: quick-261118-rkf
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Wishlist/Fetch/MetaImageExtractor.php
  - app/Wishlist/Fetch/PagePreview.php
  - app/Wishlist/Catalog/FetchLinkPreview.php
  - app/Http/Controllers/Wishlist/Owner/LinkPreviewController.php
  - routes/web/wishlist-images.php
  - resources/js/Wishlist/OwnWishPanel.vue
  - resources/js/Wishlist/LinksEditor.vue
  - lang/en.json
  - lang/sk.json
  - lang/pl.json
  - resources/help/topics.json
  - resources/help/en.json
  - resources/help/sk.json
  - resources/help/pl.json
  - resources/manual/en/wishlist.json
  - resources/manual/sk/wishlist.json
  - resources/manual/pl/wishlist.json
  - tests/Unit/Wishlist/Fetch/MetaImageExtractorTest.php
  - tests/Feature/Wishlist/LinkPreviewTest.php
autonomous: true
requirements: [GH-16]
must_haves:
  truths:
    - "Pasting the first URL in a new or edited gift prefills empty title and notes (description) fields, editable"
    - "A field the user already typed is never overwritten"
    - "Adding a 2nd/3rd URL or editing later never triggers a fetch; only an explicit Reload button does"
    - "Missing image/title/description is listed inline with a reason when known"
    - "SSRF guard, byte and time limits, throttling and edit permission still apply; no page content in logs"
  artifacts:
    - path: "app/Wishlist/Fetch/MetaImageExtractor.php"
      provides: "title() and description() plus existing candidates()"
    - path: "app/Wishlist/Catalog/FetchLinkPreview.php"
      provides: "queued job, SafeFetcher only, result in cache"
    - path: "app/Http/Controllers/Wishlist/Owner/LinkPreviewController.php"
      provides: "start + poll endpoints"
  key_links:
    - from: "OwnWishPanel.vue"
      to: "/wishlist/link-preview"
      via: "POST on first valid URL, then GET poll"
      pattern: "link-preview"
---

<objective>
Implements GitHub issue #16 (commit messages carry `Fixes #16`). Autofill a gift's title, description and image from the FIRST link, reusing SafeFetcher (SSRF guard, caps) and the queue worker.

Design (decided, follow it):
- Extractor: add `MetaImageExtractor::title()` and `::description()` (pure parsing, same DOM setup; refactor the DOM loading into a private shared helper without changing `candidates()` behaviour). Title priority: og:title, twitter:title, JSON-LD Product name, then `<title>`. Description priority: meta name=description and og:description (equally primary: take whichever exists, name=description first), then twitter:description, JSON-LD Product description. Trim, collapse whitespace, strip tags, cap title at 120 chars (config wishlist.title_max) and description at 2000 (notes_max), no truncation mid-multibyte (mb_substr). Image: keep `candidates()` order, and append as a last fallback the first `<img>` with width or height attribute >= 200 (resolved via UrlResolver) when no other candidate exists.
- `PagePreview` value object: title, description, hasImage (bool), failures (list of field => reason code: timeout, blocked, no_tags, unreachable).
- `FetchLinkPreview` queued job (ShouldQueue, tries 1, timeout 60, like FetchWishImage): args (string $token, string $url). Fetches ONE page via SafeFetcher::get($url, config wishlist.fetch_html_max_bytes, 'html'), builds PagePreview from the extractor, maps FetchFailed reasons to timeout/blocked/unreachable (inspect SafeFetcher for the actual reason strings), stores a plain array in Cache for 5 minutes under key `wishlist.link_preview.{token}` together with the owning user id. Logs only token-free reason codes (like FetchWishImage::note), never URL or body.
- `LinkPreviewController`: `start` (POST /wishlist/link-preview, middleware access:module,wishlist,edit and throttle:15,1,wishlist.link_preview; validate `url` as required http(s) string max 2048; generate random token, put `{status: pending, user: id}` in cache, dispatch job, return JSON {token}) and `show` (GET /wishlist/link-preview/{token}, same access middleware, returns JSON {status: pending|done, title, description, image: bool, failures}; 404 if token unknown or belongs to another user). Add both routes to routes/web/wishlist-images.php.
- Frontend (OwnWishPanel.vue, LinksEditor.vue): LinksEditor emits a `first-url` event on blur/paste-change of row 0 when the row holds a valid http URL, the form had no URL before (previous non-blank link count was 0) and no preview has run yet for this panel open. OwnWishPanel handles it: POST, poll GET every 1.5 s up to 20 times, then fill `form.title` only if blank and `form.notes` only if blank (never overwrite typed text; fields stay editable). Show an inline status line under the links ("Loading title, description and image..."), and on completion an inline message listing exactly which of image / title / description could not be loaded and the reason when known. When the wish already has links on open (edit) no automatic fetch happens; show a small "Reload from first link" secondary button that triggers the same flow (also only filling blank fields). The image is not previewed in the form: when the preview reports an image was found the message says it will be attached when the wish is saved (existing FetchWishImage runs on save for wishes with links and no uploaded image); when the user is editing and presses Reload, also call the existing POST /wishlist/wishes/{id}/image/fetch route if no uploaded image exists. Use existing UI components and design tokens from the sketch-findings-family-hub skill; no emojis.
- Do NOT touch received/confirmation behaviour (issue #17 follows in the same files, keep edits to OwnWishPanel.vue minimal and localised to link preview).
</objective>

<execution_context>
@/home/voslar/projects/family-hub/.claude/get-shit-done/workflows/execute-plan.md
@/home/voslar/projects/family-hub/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@CLAUDE.md
@app/Wishlist/Fetch/MetaImageExtractor.php
@app/Wishlist/Fetch/SafeFetcher.php
@app/Wishlist/Catalog/FetchWishImage.php
@app/Http/Controllers/Wishlist/Owner/WishImageController.php
@routes/web/wishlist-images.php
@resources/js/Wishlist/OwnWishPanel.vue
@resources/js/Wishlist/LinksEditor.vue
@tests/Unit/Wishlist/Fetch/MetaImageExtractorTest.php
@tests/Feature/Wishlist/FetchWishImageTest.php
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Extractor title/description, PagePreview, job, controller, routes</name>
  <files>app/Wishlist/Fetch/MetaImageExtractor.php, app/Wishlist/Fetch/PagePreview.php, app/Wishlist/Catalog/FetchLinkPreview.php, app/Http/Controllers/Wishlist/Owner/LinkPreviewController.php, routes/web/wishlist-images.php, tests/Unit/Wishlist/Fetch/MetaImageExtractorTest.php, tests/Feature/Wishlist/LinkPreviewTest.php</files>
  <behavior>
    - title(): og:title beats twitter:title beats JSON-LD name beats `<title>`; falls to next only when empty; whitespace collapsed; length capped
    - description(): meta name=description / og:description first, then twitter:description, then JSON-LD Product description; empty string/null when none
    - candidates(): unchanged order; large-img fallback only when no other candidate; small imgs ignored
    - Job with Http fake/SafeFetcher handler: full page gives title+description+image true and empty failures; page without tags gives failures for all three with no_tags; FetchFailed timeout/blocked maps to the reason for all three
    - Private/loopback URL (e.g. http://127.0.0.1/ or 169.254.169.254) is refused by SafeFetcher and reported as blocked, no request sent
    - Feature: POST with a user lacking wishlist edit gets 403; throttle returns 429 after the limit; another user polling the token gets 404; non-http URL gets 422
    - First-URL-only rule at the API level is a frontend concern (Task 2); here assert the endpoint never writes to wishes or wish_urls
    - Logs (Log::spy or log file) contain no URL or body text
  </behavior>
  <action>Implement per the objective design (per issue #16: priorities, fallbacks, SSRF/size/time limits kept, no content in logs, permission checks, throttling). Write tests first following the existing MetaImageExtractorTest and FetchWishImageTest patterns (fixtures in tests/fixtures/fetch, bind a SafeFetcher with a fake handler as FetchWishImageTest does). Do not place raw HTML or URLs in log context or in exception messages. Use mb_* functions for text caps. Keep candidates() behaviour identical for existing tests.</action>
  <verify>
    <automated>php artisan test --filter="MetaImageExtractor|LinkPreview|FetchWishImage|SafeFetcher"</automated>
  </verify>
  <done>New and existing extractor/fetch tests pass; endpoints exist with throttle and permission middleware; SSRF refusal covered by a test.</done>
</task>

<task type="auto">
  <name>Task 2: Form wiring and inline messages</name>
  <files>resources/js/Wishlist/OwnWishPanel.vue, resources/js/Wishlist/LinksEditor.vue, lang/en.json, lang/sk.json, lang/pl.json</files>
  <action>Implement the frontend part of the objective. Add lang keys under wishlist.preview.* (loading, done_image_on_save, missing_intro, missing_image, missing_title, missing_description, reason_timeout, reason_blocked, reason_no_tags, reason_unreachable, reload) in en, sk, pl (SK/PL drafts for native review). First-URL-only: track `previewDone` per panel open (reset when the panel opens/closes); only the first link row's first valid URL triggers; adding rows, editing the first URL after the preview, or opening an existing wish never triggers automatically; only the Reload button does. Never overwrite non-blank title/notes; also do not apply a result if the user typed into that field while the request was in flight. Do not set `dirty` oddly: filled values must count as changes so Save works. Add a Vitest test next to existing wishlist JS tests (find them under tests/js) covering: first URL triggers one POST, second URL does not, typed title not overwritten, missing-data message lists exactly the missing fields.</action>
  <verify>
    <automated>npx vitest run tests/js/wishlist</automated>
  </verify>
  <done>Vitest passes; behaviour matches the truths; lang files have identical key sets in all three languages.</done>
</task>

<task type="auto">
  <name>Task 3: User documentation and help tips (CLAUDE.md rule)</name>
  <files>resources/manual/en/wishlist.json, resources/manual/sk/wishlist.json, resources/manual/pl/wishlist.json, resources/help/topics.json, resources/help/en.json, resources/help/sk.json, resources/help/pl.json</files>
  <action>Update the wishlist manual topic in en (source), sk, pl (drafts for native review): in the add-wish steps and Links bullet describe that pasting the first link fills in an empty Title and Notes automatically and loads the picture when the wish is saved, that typed text is never overwritten, that nothing is fetched for later links, that a message names anything that could not be loaded, and the **Reload from first link** button. Keep identical section ids, section count and links across languages. Reword help tip `wishlist.links` in all three help files to mention the autofill (do not change key meaning; text max 400 chars); add no new key unless the Reload button needs one, in which case add `wishlist.link_reload` to topics.json (placement wish_panel/links) and all three help files and use it via HelpTip. Run: npx vitest run tests/js/manual/ManualCoverage.test.js tests/js/help/HelpCoverage.test.js and php artisan test tests/Feature/Manual and the full php artisan test --filter=Wishlist. Commit all changes in one commit whose message ends with `Fixes #16`; no push, no deploy. In the summary list touched resources/manual files and flag SK/PL as drafts needing native review.</action>
  <verify>
    <automated>npx vitest run tests/js/manual tests/js/help &amp;&amp; php artisan test tests/Feature/Manual --filter=Manual</automated>
  </verify>
  <done>Coverage tests and Manual Pest tests pass; docs describe autofill in en/sk/pl; commit made with Fixes #16.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| browser to /wishlist/link-preview | user-supplied URL crosses to server-side fetch |
| worker to internet | arbitrary remote HTML parsed |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-rkf-01 | I/E | FetchLinkPreview | mitigate | all network via SafeFetcher (public IPv4 pin, redirect re-validation, byte cap); test refuses 127.0.0.1 and metadata IP |
| T-rkf-02 | D | start endpoint | mitigate | throttle:15,1, queued job timeout 60, html cap 512 KB |
| T-rkf-03 | I | poll endpoint | mitigate | token random, cache entry bound to user id, 404 otherwise |
| T-rkf-04 | I | logs | mitigate | reason codes only, no URL/body |
| T-rkf-05 | T | rendered title/description | mitigate | plain text only, tags stripped, rendered via Vue text interpolation / v-model |
</threat_model>

<verification>
php artisan test --filter=Wishlist; npx vitest run; coverage tests from Task 3.
</verification>

<success_criteria>
Issue #16 behaviour delivered, tests green, docs updated in en/sk/pl, single commit with `Fixes #16`, nothing pushed.
</success_criteria>

<output>
Create `.planning/quick/261118-rkf-wishlist-autofill-from-first-url-16/261118-rkf-SUMMARY.md` when done
</output>
