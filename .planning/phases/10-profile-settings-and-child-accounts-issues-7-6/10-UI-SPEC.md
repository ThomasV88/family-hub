---
phase: 10
slug: profile-settings-and-child-accounts-issues-7-6
status: draft
shadcn_initialized: false
preset: none
created: 2026-11-17
---

# Phase 10 — UI Design Contract

> Visual and interaction contract for Phase 10 (GitHub #7 profile settings, #6 child accounts). Everything here reuses the locked design system (`resources/css/tokens.css`, sketch findings). No new tokens, no new colours, no new type sizes. Sources: 10-CONTEXT.md (decisions), ISSUES-6-11-OWNER-DECISIONS.md, sketch-findings-family-hub, existing `ui/*` components.

---

## Design System

| Property | Value |
|----------|-------|
| Tool | none (custom Vue component set in `resources/js/Components/ui`, tokens in `resources/css/tokens.css`); shadcn gate not applicable (Laravel + Inertia + Vue) |
| Preset | not applicable |
| Component library | none (in-house `Ui*` components) |
| Icon library | `@lucide/vue` |
| Font | `--font-sans` (system / Inter stack) |

Components reused (do not re-create): `UiAvatar`, `UiField`, `UiInput`, `UiButton`, `UiCard`, `UiList`/`UiListRow`, `UiDetailPanel`, `UiDialog`, `UiConfirmDialog`, `UiMenu`, `UiTag`, `UiAlert`, `UiSwitch`, `useToast`, `HelpTip`, `SettingsLayout`, `MemberRow`, `MemberPanel`, `InviteDialog`.

New components allowed (compositions only, built from the above): `ProfilePhotoEditor.vue` (avatar preview + Upload/Replace/Remove buttons, reused on the profile page and in the child panel), `ChildCreateDialog.vue` (UiDialog + UiField).

---

## Spacing Scale

Existing tokens only: `--space-1` 4, `--space-2` 8, `--space-3` 12 (reserved for inner padding of 40px controls), `--space-4` 16, `--space-6` 24, `--space-8` 32, `--space-12` 48, `--space-16` 64.

| Token | Value | Usage in this phase |
|-------|-------|---------------------|
| xs | 4px | gap between photo buttons' icon and label |
| sm | 8px | gap between Upload / Remove buttons; gap avatar to tag in rows |
| md | 16px | field-to-field gap in profile form and dialog; card padding |
| lg | 24px | gap between profile cards; gap between photo block and form on wide |
| xl | 32px | page top spacing under SettingsLayout title |

Exceptions: profile photo preview is 96px (not on the scale, it is an image size not a spacing value, see Avatar sizes below). Touch targets: all buttons at least `--control-touch` 44px on phone, `--control-md` 40px otherwise.

Avatar sizes: `UiAvatar` currently allows 32 and 40. Extend the validator to `[32, 40, 96]` for the profile page only (96 renders initial at `--text-2xl`). Photo and initial occupy the identical circle (`--radius-full`), so layouts of every existing avatar user stay unchanged.

---

## Typography

Existing scale only (3 sizes, 2 weights used in this phase).

| Role | Size | Weight | Line Height |
|------|------|--------|-------------|
| Body / input text | `--text-base` 16px | 400 | 1.5 |
| Label / hint / tag / row meta | `--text-sm` 14px | 600 for labels, 400 for hints and meta | 1.5 |
| Heading (card title, dialog title) | `--text-xl` 20px | 600 | 1.2 |
| Display (page title via SettingsLayout, avatar initial at 96px) | `--text-2xl` 24px | 600 | 1.2 |

Weights: 400 and 600 only. Layouts must tolerate SK/PL labels about 30 percent longer than EN (wrap, never truncate buttons).

---

## Color

Tokens from `tokens.css` (light and dark already defined); nothing new.

| Role | Value | Usage |
|------|-------|-------|
| Dominant (60%) | `--color-bg` #fbfbfa / dark #191918 | page background |
| Secondary (30%) | `--color-surface` #fff, `--color-surface-2` #f4f4f2 | cards, dialogs, panels, avatar-less placeholder ring |
| Accent (10%) | `--color-primary` #3b6ea8 / dark #7aa6d6 | see list below |
| Destructive | `--color-danger` #b4432f / dark #e0735f | Remove photo confirm, nothing else |

Accent reserved for: the primary button (Save profile, Create child, Send invitation), focus ring, the selected row in Members, the "Profile settings" menu item hover/focus state only through the existing UiMenu styling. Avatar backgrounds keep `--avatar-1..6` (shown only for initial fallback).

Child badge: `UiTag` neutral (surface-2 background, muted text), label "Child". "No sign-in" status: `UiTag` warn variant (`--color-warn-bg`/`--color-warn-text`). No red for non-destructive states.

---

## Screens and Interaction Contract

### 1. Account menu (TopBar.vue)
- New `UiMenu` item "Profile settings" (icon `UserRound`) placed directly above "Settings". Navigates to `/profile` (own page, not under Settings sections list).
- The menu trigger shows the user's `UiAvatar` (photo if present, initial otherwise). The menu header shows avatar 32, name, and e-mail (muted, `--text-sm`).

### 2. Profile settings page (`Pages/Profile/Edit.vue`, uses SettingsLayout conventions: page title, stacked `UiCard`s, max width `--content-max`, form column max 560px)
- Card "Photo": `ProfilePhotoEditor` with avatar 96 left, buttons right (wide) or below centered (phone, under 480px container). Buttons: secondary "Upload photo" (becomes "Replace photo" when a photo exists), ghost "Remove photo" (only when a photo exists). Hint under buttons: "JPG, PNG or WebP, up to 5 MB. Square crops look best."
- Card "Details": `UiField` + `UiInput` "Display name" (required, max 60 chars, `autocomplete="name"`); `UiField` + disabled `UiInput` "E-mail" with hint "You cannot change your e-mail yet." (disabled via `readonly` + `aria-readonly`, normal contrast, not greyed out text; muted background `--color-surface-2`). Primary button "Save changes" aligned to start, disabled while form not dirty or processing.
- Photo upload flow: choosing a file posts immediately (multipart) via Inertia, shows `UiSkeleton` circle in the avatar while uploading, then toast "Photo updated". No client-side cropping in this phase; server re-encodes to a square 512px thumbnail without metadata. Preview uses an object URL until the server responds.
- Remove photo: `UiConfirmDialog` (not destructive-styled data loss beyond the photo, so confirm button is the danger variant per button standards): "Remove your photo? Your initial will be shown instead."
- States: loading (button spinner, field disabled), validation error (inline `UiField error`, `aria-live` polite, focus first invalid), photo error (`UiAlert` danger inside the Photo card), success (toast, 4s).
- Labelled with `HelpTip` topics `profile.photo` and `profile.name` (registry entries in `resources/help/topics.json`, texts in en/sk/pl).

### 3. Settings > Members: create child
- Header gets a second button next to "Invite member": secondary "Add child" (icon `Baby`). Opens `ChildCreateDialog` (`UiDialog`, bottom sheet on phone).
- Dialog fields: "Name" (required), "Photo" (optional, `ProfilePhotoEditor` compact at 40/96 preview), helper `UiAlert` info: "A child has no e-mail and cannot sign in. A parent or administrator manages their profile and wish list." Footer: ghost "Cancel", primary "Create child". The new child gets the Child permission preset; a "Parents" multi-select (`UiSelect`, members with Parent preset or admins, required at least one) sets `wishlist_managers`. Hint: "Parents can manage this child's wish list."
- After create: dialog closes, toast "Child added", the new row is selected and `MemberPanel` opens.

### 4. Members list rows (`MemberRow`)
- Avatar shows photo. A child row shows `UiTag` "Child" and, since `sign_in_disabled`, a warn `UiTag` "No sign-in". No e-mail line for a child (replaced by the muted text "Managed by {names}"). Row remains one tap target "Manage".

### 5. Member panel for a child (`MemberPanel`, docked/overlay/sheet via `UiDetailPanel`)
- Header: avatar 40, name, tags as above.
- Tab "Profile" (first tab for children, extra tab for others when viewer is admin): edit name and photo via the same `UiField` + `ProfilePhotoEditor`; e-mail row replaced by a `UiAlert` info "No e-mail, cannot sign in." Parent (non-admin, listed in `wishlist_managers`) sees only this tab plus nothing about permissions.
- Tab "Permissions": existing matrix; Child preset preselected.
- Section "Make a normal member" (admin only, `UiCard` inside panel): `UiField` "E-mail" + primary "Send invitation". On success the child becomes an invited member (tag "Invited"), toast "Invitation sent to {email}". Failure: e-mail already used shows field error "This e-mail already belongs to a member."
- Switch (`UiSwitch`) "Direct sign-in disabled" is shown read-only on for children until converted (disabled, with hint "Turns off when you invite them with an e-mail").
- Delete or archive child is out of scope for this phase.

### 6. Avatars everywhere
- `UiAvatar` gains optional prop `src` (URL of the authenticated photo route, e.g. `/people/{id}/photo?v={updatedAt}`), `loading="lazy"`, `alt` empty (name stays in `aria-label` through the wrapper). `<img>` fills the circle with `object-fit: cover`. On `error` the component falls back to the initial. Props `name`, `tint`, `size`, `decorative` stay backwards compatible. Every existing call site passes the person's photo URL when available (members list, task columns, wishlist owners, assignee pickers, account menu).
- Photo route is private, authenticated, `Cache-Control: private, max-age=0, must-revalidate` with ETag; versioned query param busts cache after change.

### Accessibility
- All buttons have text labels (icons are decorative). File input is visually hidden but reachable: the "Upload photo" button triggers it, with `aria-describedby` hint. Dialog focus trap and Escape via `UiDialog`. Errors announced via `aria-live="polite"`. Contrast follows tokens (AA).

---

## Copywriting Contract

All strings via vue-i18n keys (namespaces `profile.*`, `settings.members.child.*`) in `resources/js` locale files for en, sk, pl. en is source, sk and pl are drafts for native review.

| Element | en | sk (draft) | pl (draft) |
|---------|----|------------|------------|
| Menu item | Profile settings | Nastavenia profilu | Ustawienia profilu |
| Page title | Profile settings | Nastavenia profilu | Ustawienia profilu |
| Card title photo | Photo | Fotografia | Zdjęcie |
| Upload / replace | Upload photo / Replace photo | Nahrať fotografiu / Zmeniť fotografiu | Prześlij zdjęcie / Zmień zdjęcie |
| Remove photo | Remove photo | Odstrániť fotografiu | Usuń zdjęcie |
| Photo hint | JPG, PNG or WebP, up to 5 MB. | JPG, PNG alebo WebP, najviac 5 MB. | JPG, PNG lub WebP, do 5 MB. |
| Name label | Display name | Zobrazované meno | Wyświetlana nazwa |
| E-mail label / hint | E-mail / You cannot change your e-mail yet. | E-mail / E-mail zatiaľ nemožno zmeniť. | E-mail / Adresu e-mail na razie nie można zmienić. |
| Primary CTA (profile) | Save changes | Uložiť zmeny | Zapisz zmiany |
| Primary CTA (child) | Create child | Vytvoriť dieťa | Utwórz dziecko |
| Add child button | Add child | Pridať dieťa | Dodaj dziecko |
| Child dialog title | Add a child | Pridať dieťa | Dodaj dziecko |
| Child info alert | A child has no e-mail and cannot sign in. A parent or administrator manages their profile and wish list. | Dieťa nemá e-mail a nemôže sa prihlásiť. Jeho profil a zoznam želaní spravuje rodič alebo administrátor. | Dziecko nie ma adresu e-mail i nie może się zalogować. Jego profilem i listą życzeń zarządza rodzic lub administrator. |
| Parents field | Parents who manage the wish list | Rodičia, ktorí spravujú zoznam želaní | Rodzice zarządzający listą życzeń |
| Tags | Child / No sign-in | Dieťa / Bez prihlásenia | Dziecko / Bez logowania |
| Managed by line | Managed by {names} | Spravuje {names} | Zarządza {names} |
| Convert section / CTA | Make a normal member / Send invitation | Urobiť z neho bežného člena / Poslať pozvánku | Zrób zwykłym członkiem / Wyślij zaproszenie |
| Toasts | Photo updated / Profile saved / Child added / Invitation sent to {email} | Fotografia zmenená / Profil uložený / Dieťa pridané / Pozvánka odoslaná na {email} | Zdjęcie zmienione / Profil zapisany / Dziecko dodane / Zaproszenie wysłane na {email} |
| Empty state | not applicable (no new list; Members always has the current user) | n/a | n/a |
| Error state (photo) | We could not use that picture. Choose a JPG, PNG or WebP image up to 5 MB and try again. | Tento obrázok sa nepodarilo použiť. Vyberte JPG, PNG alebo WebP do 5 MB a skúste to znova. | Nie udało się użyć tego obrazu. Wybierz JPG, PNG lub WebP do 5 MB i spróbuj ponownie. |
| Error state (save) | Your changes were not saved. Check the highlighted fields and try again. | Zmeny sa neuložili. Skontrolujte zvýraznené polia a skúste to znova. | Zmiany nie zostały zapisane. Sprawdź zaznaczone pola i spróbuj ponownie. |
| E-mail in use | This e-mail already belongs to a member. | Tento e-mail už patrí členovi. | Ten adres e-mail należy już do członka. |
| Destructive confirmation | Remove photo: Remove your photo? Your initial will be shown instead. (Confirm "Remove photo", cancel "Keep photo") | Odstrániť fotografiu: Odstrániť fotografiu? Namiesto nej sa zobrazí iniciála. ("Odstrániť fotografiu" / "Ponechať") | Usuń zdjęcie: Usunąć zdjęcie? Zamiast niego pojawi się inicjał. ("Usuń zdjęcie" / "Zachowaj") |

Contextual help (HelpTip, 3 languages, under 400 chars): `profile.photo` (who sees the photo: all family members who see your name; stored privately, metadata removed), `profile.name` (shown on tasks, wishes and lists), `members.child` (what a child account is, cannot sign in, no mail, parent manages).

User manual: update topics for profile (new topic file, `covers` entry) and Members (child, convert to member) in en, sk, pl per CLAUDE.md; mark sk/pl for native review.

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
