import axe from 'axe-core';

// Colour contrast is disabled because jsdom cannot compute it; tokens.test.js is authoritative for contrast.
// The page-level 'region' rule is disabled because component tests render fragments, not whole pages.
export async function expectNoAxeViolations(element, options = {}) {
    const results = await axe.run(element, { rules: { 'color-contrast': { enabled: false }, region: { enabled: false } }, ...options });
    if (results.violations.length) {
        const lines = results.violations.map((v) => `${v.id}: ${v.help} (${v.nodes[0]?.target?.join(' ')})`);
        throw new Error(`Accessibility violations:\n${lines.join('\n')}`);
    }
}
