# Quick 261118-pn5: Shorten AI consent text Summary

Visible AI consent paragraph is now one sentence; detail moved into the existing `health.ai_consent` help tip; the "?" sits on the toggle row.

Commit: 4ea69a8 (local only, not pushed)

## Changes
- `resources/js/Health/AiConsentControl.vue`: `.ai-consent__row` flex wrapper around switch and HelpTip.
- `lang/en.json`, `lang/sk.json`, `lang/pl.json`: `health.ai.explain` shortened.
- `resources/help/en.json`, `sk.json`, `pl.json`: `health.ai_consent` text rewritten (key and title unchanged; default off, review before saving, purge sentence).
- `tests/js/health/AiConsent.test.js`: two assertions updated to the new visible text.

## Manual
No manual change: `resources/manual/*` does not repeat the text (grep found nothing). Help files touched are listed above.

## Texts
- EN visible: "When on, this person's documents are sent to an outside AI company."
- SK/PL are drafts, need native review.

## Tests
Vitest help, manual, brand, health, settings/AiBulk green; Pest Manual and Help green (49 passed).

## Deviations
Updated two test assertions on the old "Off by default" wording (Rule 3).
