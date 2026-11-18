# Phase 14: FamilyHUB brand notes

Source: `brand-kit/brand-guide/brand-guide.pdf`, 9 pages (A4 landscape, one embedded JPEG per page), read page by page.

## Pages read (9)

1. Page 1: cover.
2. Page 2: overview, master logo = F mark plus "FamilyHUB" wordmark on a peach brush stroke; clean, modern, scalable vector, high contrast.
3. Page 3: colour palette (see below).
4. Page 4: variations: original on cream, reversed on orange, monochrome white on black, black on cream.
5. Page 5: digital mock-ups (favicon is the coloured F mark).
6. Page 6: print mock-ups.
7. Page 7: typography (Coiny 400 plus a type scale).
8. Page 8: usage do's and don'ts.
9. Page 9: licensing (commercial use of delivered files, no trademark guarantee).

## Palette

| Role in guide | Hex as printed |
|---|---|
| Primary | #F2963E |
| Secondary 1 | #FFE1C8 |
| Accent | #FFF4EB |

Mark-only colours from the SVGs (inside the F icon, not in the guide palette): #e54738 (red), #1c4e9a (blue), #e8897a (coral).

## Variations and where each kit file is used

- Light theme: `primary.svg` (original lockup on cream/white).
- Dark theme: `mono-for-dark-bg.svg` (all fills #ffffff, verified in the SVG).
- Favicon and app icon: `icon.svg` / kit favicon PNGs (coloured F mark).
- `mono-for-light-bg.svg` (all #000000) for single-colour print.

## Clear space and minimum size

Clear space ("safety buffer") equal to the icon height around the logo. Minimum size: none stated.

## Typography

Coiny 400 for the wordmark and UI, with a scale (H1 48/56, H2 32/40, H3 24/32, body 16/24, small 14/20, button 14/16). Noted, not adopted: CONTEXT fixes typography as unchanged. The wordmark SVG paths are outlined, so no font is needed for the logo.

## Do and don't

Do: use SVG lockups; keep clear space equal to icon height; pick the mono variant that contrasts with the background.
Don't: stretch, skew or distort; add drop shadows or outer glows; place on busy textures or low-contrast photography; change the icon/wordmark relationship; assume global exclusivity (trademark search first).

## Reconciliation

The guide palette matches the SVG palette (orange #f2963e = #F2963E, peach #ffe1c8 = #FFE1C8); the guide additionally gives cream #FFF4EB, which the SVGs do not use. The guide's hex wins for guide colours.
One guide wording discrepancy: page 8 says to use "mono-for-dark" assets on backgrounds above 50% luminance, but the file `mono-for-dark-bg.svg` is white and page 4 shows white on black. The page 8 sentence is inverted; we follow the files and page 4: white mono on dark backgrounds, black mono on light.

## Token mapping (confirmed, no change from the plan's pre-computed values)

Light: bg #fffaf5, surface #ffffff, surface-2 #f8f1ea, border #ece3d9, primary #a64b0b (5.78 on white), primary-hover #8c3f09, primary-soft #fff4eb, on-primary #ffffff, accent #f2963e (decoration only), focus #a64b0b.
Dark (both blocks identical): primary #f2963e (7.0 on surface), primary-hover #f5a65c, primary-soft #3b2a1c, on-primary #1f1308, accent #f2963e, focus #f5a65c.
All pairs are verified by `tests/js/tokens.test.js` (TOKEN_PAIRS loop). Raw #f2963e is never a light-theme text or control colour.

## Product name

The guide and logo spell "FamilyHUB"; CONTEXT already decided the user-visible rename.
