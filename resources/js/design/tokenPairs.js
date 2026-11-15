// Authoritative list of token pairs and minimum WCAG ratios. Names are custom properties without the leading "--".
const pair = (fg, bg, min, usage) => ({ fg, bg, min, usage });

const avatars = [1, 2, 3, 4, 5, 6].map((n) => pair('color-text', `avatar-${n}`, 4.5, `Initials on avatar ${n}`));

export const TOKEN_PAIRS = [
    pair('color-text', 'color-bg', 4.5, 'Body text on page'),
    pair('color-text', 'color-surface', 4.5, 'Body text on cards'),
    pair('color-text', 'color-surface-2', 4.5, 'Body text on subtle surface'),
    pair('color-text-muted', 'color-bg', 4.5, 'Secondary text on page'),
    pair('color-text-muted', 'color-surface', 4.5, 'Secondary text on cards'),
    pair('color-text-muted', 'color-surface-2', 4.5, 'Secondary text on subtle surface'),
    pair('color-primary', 'color-bg', 4.5, 'Links on page'),
    pair('color-primary', 'color-surface', 4.5, 'Links on cards'),
    pair('color-on-primary', 'color-primary', 4.5, 'Primary button label'),
    pair('color-on-primary', 'color-danger-fill', 4.5, 'Danger button label'),
    pair('color-text', 'color-primary-soft', 4.5, 'Text on selected background'),
    pair('color-primary', 'color-primary-soft', 4.5, 'Active nav item'),
    pair('color-danger', 'color-bg', 4.5, 'Error text on page'),
    pair('color-danger', 'color-surface', 4.5, 'Error text on cards'),
    pair('color-success', 'color-bg', 4.5, 'Success text on page'),
    pair('color-success', 'color-surface', 4.5, 'Success text on cards'),
    pair('color-warn-text', 'color-warn-bg', 4.5, 'Staging banner'),
    pair('color-border-strong', 'color-bg', 3, 'Control border on page'),
    pair('color-border-strong', 'color-surface', 3, 'Control border on cards'),
    pair('color-focus', 'color-bg', 3, 'Focus ring on page'),
    pair('color-focus', 'color-surface', 3, 'Focus ring on cards'),
    ...avatars,
];
