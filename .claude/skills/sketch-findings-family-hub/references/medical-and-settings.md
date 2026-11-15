# Medical Records and Settings

## Medical (007, winner D = C + timeline from B)
- Person chips (people need no account), person header: age, "bez konta" marker, per-person AI consent flag (default off), access list, Export.
- Default view specialty folders (accordion); a folder body is a chronological timeline (dots, dates; visits and documents). Switch "Podľa špecializácií / Časová os" shows the full feed with specialty and vaccination filter chips. Emergency card plus medications and allergies in a side column.
- **AI flow:** blocked without per-person consent; consent notice; running; quota wait with fallback agent and time; proposal with sure/check field markers; accept / reject / re-run; only accepted data stored as verified. Manual entry is primary.
- **Export:** scope specialty or all; format for doctor (PDF) or for AI (Markdown, person name omitted); unverified AI data excluded unless chosen, then labeled.
- Review screen proposal (not sketched): wide screen on desktop/iPad with document left and fields right, document above fields on phone. A 440 px docked panel is too small.
- i18n: plural and name forms through ICU plurals, never string concatenation (found in testing: "1 návštev", name declension).

## Settings (008, winner A)
- Section list left (Členovia a práva, AI agenti, Vzhľad a jazyk, Môj účet), content right.
- Permission matrix member × module (Úlohy, Wishlist, Financie, Zdravie) with Žiadny/Čítanie/Úpravy/Správa; admin locked; phone shows cards. Finance and Health default None. Health grants open a per-person panel (None/Read/Edit per person, including people without accounts); export and running AI need Edit. Finance account visibility stays in the module.
- Invite: email, template (Dospelý, Dieťa, Širšia rodina) fills only Tasks and Wishlist; language; 7 days validity.
- AI agents: ordered list (Codex primary, Copilot fallback), switch, login status, model, check-login button; on expired login show the server command, app never stores tokens; Claude and Antigravity not offered in v1; never per-token keys. Bulk regeneration: scope (failed/unverified/all), pace (overnight/now), confirmation, skips people without AI consent, one document at a time.
- Language SK/PL/EN and light/dark/system per user; account: password, Google, TOTP (required for Health and Finance).
- Bug to avoid in sketches and the app: do not use `[data-theme]` as a click-delegation selector, `<html>` carries it.
- Open: bulk regeneration admin-only (assumed), notification preferences, session/device management.

## Origin
Sketches 007, 008. Sources: sources/007-medical-records, sources/008-settings.
