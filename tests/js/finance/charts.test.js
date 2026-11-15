import { describe, it, expect, afterEach } from 'vitest';
import { readFileSync, readdirSync } from 'node:fs';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { mountUi } from '../helpers/mount.js';
import { contrastRatio } from '../../../resources/js/design/contrast.js';
import SpendingByCategoryChart from '../../../resources/js/Finance/charts/SpendingByCategoryChart.vue';
import BalanceChart from '../../../resources/js/Finance/charts/BalanceChart.vue';
import { CATEGORIES } from './fixtures.js';

const DATA = {
    totalMinor: '100000',
    top: [
        { categoryId: 'cat-rent', amountMinor: '65000', percent: 65 },
        { categoryId: 'cat-groc', amountMinor: '20000', percent: 20 },
        { categoryId: 'cat-custom', amountMinor: '5', percent: 0 },
    ],
    other: { amountMinor: '14995', percent: 15 },
    all: [
        { categoryId: 'cat-rent', amountMinor: '65000', percent: 65 },
        { categoryId: 'cat-groc', amountMinor: '20000', percent: 20 },
        { categoryId: 'cat-custom', amountMinor: '5', percent: 0 },
        { categoryId: null, amountMinor: '14995', percent: 15 },
    ],
};
const SERIES = (values) => values.map((v, i) => ({ month: `2025-${String(i + 1).padStart(2, '0')}`, balanceMinor: String(v) }));
const POSITIVE = SERIES([100000, 120000, 90000, 150000, 160000, 155000, 170000, 180000, 175000, 190000, 200000, 210050]);
const CROSSING = SERIES([1000, 2000, 3000, 4000, 5000, -60000, -50000, -20000, 10000, 40000, 25000, 100000]);

let current;
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; document.documentElement.classList.remove('dark'); });
const text = () => document.body.textContent.replace(/\s+/g, ' ');

function category(props = {}, opts) {
    current = mountUi(SpendingByCategoryChart, { props: { data: DATA, currency: 'EUR', month: '2026-03', categories: CATEGORIES, ...props }, ...opts });
    return current;
}
function balance(props = {}, opts) {
    current = mountUi(BalanceChart, { props: { points: POSITIVE, currency: 'EUR', ...props }, ...opts });
    return current;
}

describe('SpendingByCategoryChart', () => {
    it('lists rows with server amounts and percents, an Other bar and the aria summary', () => {
        category();
        const rows = document.querySelectorAll('.cat-chart__row');
        expect(rows).toHaveLength(4);
        expect(rows[0].textContent).toContain('Housing');
        expect(rows[0].textContent).toContain('€650.00');
        expect(rows[0].textContent).toContain('65%');
        expect(rows[3].textContent).toContain('Other categories');
        expect(rows[3].querySelector('.is-other')).not.toBeNull();
        expect(document.querySelector('[role="img"]').getAttribute('aria-label')).toBe('Spending by category, March 2026. Largest: Housing, €650.00.');
        const widths = [...document.querySelectorAll('.cat-chart__fill')].map((r) => r.getAttribute('width'));
        expect(widths[0]).toBe('100%');
        expect(widths[2]).not.toBe('0%');
        expect(widths[2]).toBe('0.00769%');
        expect(document.querySelector('svg[role="presentation"]').getAttribute('height')).toBe('12');
    });

    it('shows the empty line when nothing was spent', () => {
        category({ data: { totalMinor: '0', top: [], other: null, all: [] } });
        expect(text()).toContain('No spending recorded this month.');
        expect(document.querySelector('[data-testid="category-chart"] button')).toBeNull();
    });

    it('toggles to a table of every category with a total row', async () => {
        category();
        const toggle = document.querySelector('[data-testid="category-chart"] button');
        expect(toggle.getAttribute('aria-pressed')).toBe('false');
        toggle.click();
        await current.vm.$nextTick();
        expect(document.querySelector('[data-testid="category-chart"] button').getAttribute('aria-pressed')).toBe('true');
        const rows = [...document.querySelectorAll('tbody tr')].map((r) => r.textContent.replace(/\s+/g, ' ').trim());
        expect(rows).toHaveLength(5);
        expect(rows[4]).toContain('Total');
        expect(rows[4]).toContain('€1,000.00');
        expect(text()).toContain('View as chart');
    });

    it.each(['en', 'sk', 'pl'])('passes axe in chart and table modes (%s, light and dark)', async (locale) => {
        for (const dark of [false, true]) {
            document.documentElement.classList.toggle('dark', dark);
            category({}, { locale });
            await expectNoAxeViolations(document.body);
            document.querySelector('[data-testid="category-chart"] button').click();
            await current.vm.$nextTick();
            await expectNoAxeViolations(document.body);
            current.unmount();
            document.body.innerHTML = '';
        }
    });
});

describe('BalanceChart', () => {
    it('draws one polyline with markers, exact labels and the aria summary', () => {
        balance({ height: 200 });
        expect(document.querySelectorAll('polyline')).toHaveLength(1);
        expect(document.querySelectorAll('circle')).toHaveLength(6);
        const svg = document.querySelector('svg[role="img"]');
        expect(svg.getAttribute('aria-label')).toBe('Balance over 6 months, ending December 2025 at €2,100.50.');
        expect(svg.textContent).toContain('€2,100.50');
        expect(svg.textContent).not.toMatch(/\d[kKmM]\b/);
        expect(document.querySelectorAll('.bal-chart__gridline').length).toBeGreaterThanOrEqual(2);
        expect(document.querySelector('.bal-chart__zero')).toBeNull();
    });

    it('switches to 12 months and back with chips', async () => {
        balance();
        const chip = (label) => [...document.querySelectorAll('[role="radiogroup"] button')].find((b) => b.textContent.trim() === label);
        expect(chip('6 months').getAttribute('aria-pressed')).toBe('true');
        chip('12 months').click();
        await current.vm.$nextTick();
        expect(document.querySelectorAll('circle')).toHaveLength(12);
        expect(document.querySelector('svg[role="img"]').getAttribute('aria-label')).toContain('Balance over 12 months');
        chip('6 months').click();
        await current.vm.$nextTick();
        expect(document.querySelectorAll('circle')).toHaveLength(6);
    });

    it('draws a dashed labelled zero line when the range crosses zero and uses U+2212', () => {
        balance({ points: CROSSING });
        const zero = document.querySelector('.bal-chart__zero');
        expect(zero.getAttribute('stroke-dasharray')).toBeTruthy();
        expect([...document.querySelectorAll('svg text')].some((n) => n.textContent === '0')).toBe(true);
        current.unmount();
        balance({ points: SERIES([-50000, -20000, -5000, 20000, 10000, -12345]) });
        expect(document.querySelector('svg[role="img"]').textContent).toContain('−€123.45');
    });

    it('drops the middle tick label when labels would collide', () => {
        balance({ height: 60 });
        const axis = [...document.querySelectorAll('.bal-chart__grid text')];
        expect(axis).toHaveLength(2);
    });

    it('shows the empty line with fewer than two points', () => {
        balance({ points: SERIES([100]) });
        expect(text()).toContain('Not enough data yet.');
        expect(document.querySelector('svg[role="img"]')).toBeNull();
    });

    it('toggles to a table with Month, Balance and Change including sr-only direction words', async () => {
        balance({ points: CROSSING });
        document.querySelector('[data-testid="balance-chart"] button[aria-pressed]').click();
        await current.vm.$nextTick();
        const headers = [...document.querySelectorAll('thead th')].map((h) => h.textContent);
        expect(headers).toEqual(['Month', 'Balance', 'Change']);
        const rows = [...document.querySelectorAll('tbody tr')];
        expect(rows).toHaveLength(6);
        const firstChange = rows[0].querySelectorAll('td')[2].textContent.replace(/\s+/g, ' ').trim();
        expect(firstChange).toContain('+€100.00');
        expect(firstChange).toContain('up');
        const last = rows[5].querySelectorAll('td')[2].textContent;
        expect(last).toContain('+€750.00');
        const down = rows.map((r) => r.querySelectorAll('td')[2].textContent).find((c) => c.includes('−'));
        expect(down).toContain('down');
    });

    it.each(['en', 'sk', 'pl'])('passes axe in chart and table modes (%s, light and dark)', async (locale) => {
        for (const dark of [false, true]) {
            document.documentElement.classList.toggle('dark', dark);
            balance({ points: CROSSING }, { locale });
            await expectNoAxeViolations(document.body);
            document.querySelector('[data-testid="balance-chart"] button[aria-pressed]').click();
            await current.vm.$nextTick();
            await expectNoAxeViolations(document.body);
            current.unmount();
            document.body.innerHTML = '';
        }
    });
});

describe('chart colours', () => {
    const css = readFileSync(`${process.cwd()}/resources/css/tokens.css`, 'utf8');
    const props = (body) => Object.fromEntries([...body.matchAll(/--([\w-]+)\s*:\s*([^;]+);/g)].map((m) => [m[1], m[2].trim()]));
    const block = (start) => {
        let depth = 0;
        for (let i = start; i < css.length; i++) {
            if (css[i] === '{') depth++;
            if (css[i] === '}' && --depth === 0) return css.slice(css.indexOf('{', start) + 1, i);
        }
        return '';
    };
    const light = props(block(css.indexOf(':root {')));
    const dark = { ...light, ...props(block(css.indexOf(':root[data-theme="dark"]'))) };

    it.each([['light', light], ['dark', dark]])('--color-primary is at least 3:1 on --color-surface (%s)', (_name, tokens) => {
        expect(contrastRatio(tokens['color-primary'], tokens['color-surface'])).toBeGreaterThanOrEqual(3);
    });

    it('chart components use no literal colours, v-html, parseFloat or toFixed', () => {
        const dir = `${process.cwd()}/resources/js/Finance/charts`;
        for (const file of readdirSync(dir)) {
            const src = readFileSync(`${dir}/${file}`, 'utf8');
            expect(src, file).not.toMatch(/#[0-9a-fA-F]{3,6}\b/);
            expect(src, file).not.toContain('v-html');
            expect(src, file).not.toContain('parseFloat(');
            expect(src, file).not.toContain('toFixed(');
        }
    });
});
