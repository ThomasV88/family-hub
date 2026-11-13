import axe from 'axe-core';

// Colour contrast is disabled because jsdom cannot compute it; tokens.test.js is authoritative for contrast.
export async function expectNoAxeViolations(element, options = {}) {
    const results = await axe.run(element, { rules: { 'color-contrast': { enabled: false } }, ...options });
    if (results.violations.length) {
        const lines = results.violations.map((v) => `${v.id}: ${v.help} (${v.nodes[0]?.target?.join(' ')})`);
        throw new Error(`Accessibility violations:\n${lines.join('\n')}`);
    }
}
