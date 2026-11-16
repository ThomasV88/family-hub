// Where to put a tooltip bubble: below the "?" by default, above when there is more room there, centred on the mark and
// shifted so the bubble stays inside the viewport with a margin. Pure, so it is unit tested without a layout engine.
//   anchor:   { top, left, width, height } of the button (viewport coordinates)
//   bubble:   { width, height }
//   viewport: { width, height }
export function positionTip({ anchor, bubble, viewport, gap = 8, margin = 8 }) {
    const maxWidth = Math.max(0, viewport.width - margin * 2);
    const width = Math.min(bubble.width, maxWidth);

    const spaceBelow = viewport.height - (anchor.top + anchor.height) - gap - margin;
    const spaceAbove = anchor.top - gap - margin;
    const placement = bubble.height > spaceBelow && spaceAbove > spaceBelow ? 'top' : 'bottom';

    let top = placement === 'bottom' ? anchor.top + anchor.height + gap : anchor.top - gap - bubble.height;
    // When neither side fits the whole bubble, keep it fully on screen at the better side.
    top = Math.min(Math.max(top, margin), Math.max(margin, viewport.height - margin - bubble.height));

    const centre = anchor.left + anchor.width / 2;
    let left = centre - width / 2;
    left = Math.min(Math.max(left, margin), Math.max(margin, viewport.width - margin - width));

    // Where the arrow-free bubble touches the mark, for tests and styling hooks.
    return { top: Math.round(top), left: Math.round(left), width: Math.round(width), placement };
}
