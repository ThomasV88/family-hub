---
sketch: 003
name: design-foundation
question: "How do colors, shapes and core components look in the calm, neutral direction, in light and dark?"
winner: "A"
tags: [theme, components, colors, typography]
---

# Sketch 003: Design Foundation

## Design Question
Within the agreed calm, neutral, minimalist direction (Notion / Apple Reminders), which visual treatment of color, radius and components fits the family app best, in light and dark theme?

## How to View
open .planning/sketches/003-design-foundation/index.html (or via the local server on 127.0.0.1:8765)

The sketch is a component gallery. The tabs swap the theme file (`themes/default.css`, `warm.css`, `mono.css`). The toolbar toggles dark mode.

## Variants
- **A: Neutral + blue accent** — crisp, 8 px radius, soft blue accent (current default theme)
- **B: Warm neutral + green accent** — cream surfaces, muted green accent, 12 to 18 px radius, softer shadows, more personal
- **C: Monochrome** — white and grey, near-black primary button, 3 to 8 px radius, almost no shadows (Notion-like)

## Decision (winner A: neutral + blue accent)
- The theme is `themes/default.css`: neutral off-white background (#fbfbfa), white surfaces, thin 1 px borders (#e8e8e5), blue accent (#3b6ea8), 4/8/12 px radii, minimal shadows
- Dark theme is defined in the same file with `data-theme="dark"` (blue accent #7aa6d6, on-primary text color tokens exist in both)
- Components: primary/secondary/ghost/danger buttons, 38 to 40 px minimum control height, tags for status (overdue, today, done), avatars with member initial, list rows with 1 px dividers, cards with 12 px radius, bar charts in primary and primary-soft
- Danger and overdue are the only red; success and primary share the calm palette
- `warm.css` and `mono.css` are kept for reference but are not used
- Open points for the design system phase: a Slovak/Polish/English type scale review, contrast check of muted text on surface-2 (WCAG AA), and per-member avatar colors

## What to Look For
- Light and dark mode for each variant (toggle at the bottom right)
- Is the primary button obvious without being loud?
- Overdue and danger states: clear enough, not alarming
- Does it feel calm and readable for older family members (contrast, size of touch targets, 38 to 40 px minimum height)?
- Which gives the family the right feeling, neutral tool, warm home app, or editorial monochrome?
