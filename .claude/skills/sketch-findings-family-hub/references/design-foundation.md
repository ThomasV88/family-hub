# Design Foundation

## Design Decisions
Winner A: neutral off-white `#fbfbfa`, white surfaces, borders `#e8e8e5`, accent blue `#3b6ea8`, radii 4/8/12, minimal shadows. Dark theme in the same file via `data-theme="dark"` (accent `#7aa6d6`). Tokens include `--color-on-primary`, `--color-primary-soft`, `--color-surface-2`. Danger/overdue are the only red; success shares the calm palette. `warm.css`/`mono.css` rejected (kept in `.planning/sketches/themes/`).

## CSS Patterns
Use tokens only: `var(--color-*)`, `--space-1..12`, `--text-xs..3xl`, `--radius-*`, `--shadow-*`. Buttons: primary/secondary/ghost/danger, min height 36 to 40 px. Tags (pill, 11 px) for status; avatar with initial; list rows with 1 px dividers; cards 12 px radius with 1 px border. Warn tag: `#f6ecd9`/`#9a6a1c` (light), `#3a3120`/accent (dark).

## What to Avoid
Heavy frames, saturated colors, more than one accent.

## Open
SK/PL/EN type scale review, WCAG AA check for muted text on surface-2, per-member avatar colors.

## Origin
Sketch 003. Sources: sources/themes/default.css, sources/003-design-foundation.
