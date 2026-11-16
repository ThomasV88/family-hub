import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest';
import { nextTick } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { mountUi, loadMessages } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { pseudoLong } from '../helpers/pseudoLong.js';
import { helpAreas, helpRows, installHelp, readTopics } from '../helpers/help.js';
import { createAppI18n } from '../../../resources/js/i18n.js';

let mockLocale = 'en';
const reload = vi.fn();
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({
            props: {
                locale: mockLocale,
                app: { name: 'Family Hub', env: 'local' },
                auth: { user: { id: 1, name: 'Mira', isAdmin: true }, access: { todo: 'edit', wishlist: 'edit', finance: 'edit', health: 'edit' } },
            },
        }),
        router: { post: vi.fn(), get: vi.fn(), visit: vi.fn(), on: vi.fn(), reload: (...a) => reload(...a) },
        Head: { render: () => null },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});

import HelpPage from '../../../resources/js/Pages/Settings/Help.vue';
import { help } from '../../../resources/js/Help/helpStore.js';
import { useToast } from '../../../resources/js/Components/ui/useToast.js';

const sections = [
    { key: 'ai_agents', href: '/settings/ai-agents', labelKey: 'settings.section.ai_agents', icon: 'bot' },
    { key: 'help', href: '/settings/help', labelKey: 'settings.section.help', icon: 'circle-help' },
    { key: 'appearance', href: '/settings/appearance', labelKey: 'settings.section.appearance', icon: 'palette' },
];
const baseProps = (over = {}, locale = 'en') => ({
    sections,
    topics: helpRows(locale),
    orphans: [],
    areas: helpAreas(locale),
    locales: ['sk', 'pl', 'en'],
    maxLength: 400,
    error: false,
    ...over,
});

let wrapper;
let calls;
const originalWidth = window.innerWidth;
const q = (sel) => document.body.querySelector(sel);
const qa = (sel) => [...document.body.querySelectorAll(sel)];
const mountPage = (over = {}, locale = 'en') => {
    mockLocale = locale;
    return (wrapper = mountUi(HelpPage, { props: baseProps(over, locale), locale }));
};
const setValue = async (el, value) => {
    el.value = value;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    await nextTick();
};

beforeEach(() => {
    installHelp('en', { admin: true });
    reload.mockClear();
    calls = [];
    vi.stubGlobal('fetch', vi.fn(async (url, init = {}) => {
        const body = init.body ? JSON.parse(init.body) : undefined;
        calls.push({ url, method: init.method, body });
        const row = helpRows('en').find((r) => url.endsWith(`/${r.key}`)) ?? helpRows('en')[0];
        const next = { ...row, texts: { ...row.texts, ...(body?.texts ?? {}) }, edited: { ...row.edited, ...Object.fromEntries(Object.keys(body?.texts ?? {}).map((l) => [l, true])) } };
        return { ok: true, status: 200, headers: new Headers(), json: async () => ({ topic: next, current: { title: next.titles.en, text: next.texts.en } }) };
    }));
});
afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    delete document.documentElement.dataset.theme;
    window.innerWidth = originalWidth;
    vi.unstubAllGlobals();
    useToast().toasts.splice(0);
});

describe('Settings > Contextual help', () => {
    it('lists every registered question mark with its placement, key and the texts in all languages', () => {
        mountPage();
        const rows = qa('.help-row');
        expect(rows).toHaveLength(readTopics().length);
        const first = helpRows('en')[0];
        const row = q(`[data-key="${first.key}"]`);
        expect(row.textContent).toContain(first.location.area);
        expect(row.textContent).toContain(first.location.screen);
        expect(row.textContent).toContain(first.key);
        for (const l of ['sk', 'pl', 'en']) expect(row.querySelector(`[data-locale="${l}"]`).textContent).toContain(first.texts[l]);
        expect(q('[data-testid="help-counts"]').textContent).toContain(`Question marks: ${rows.length}.`);
        expect(q('[data-testid="help-counts"]').textContent).toContain('With an edited text: 0.');
        expect(q('[aria-current="page"]').textContent).toContain('Contextual help');
    });

    it('marks edited texts with a badge and counts them', () => {
        const key = readTopics()[0].key;
        mountPage({ topics: helpRows('en', { edited: { [key]: { en: 'My own words.' } } }) });
        expect(q(`[data-key="${key}"] [data-testid="help-edited-badge"]`)).not.toBeNull();
        expect(q('[data-testid="help-counts"]').textContent).toContain('With an edited text: 1.');
        expect(qa('[data-testid="help-edited-badge"]')).toHaveLength(1);
    });

    it('filters by area and searches text, place and key, ignoring diacritics', async () => {
        mountPage();
        const all = qa('.help-row').length;
        const areas = [...new Set(readTopics().map((t) => t.area))];
        expect(areas.length).toBeGreaterThan(1);
        await setValue(q('[data-testid="help-area"]'), areas[0]);
        expect(qa('.help-row')).toHaveLength(readTopics().filter((t) => t.area === areas[0]).length);
        await setValue(q('[data-testid="help-area"]'), '');
        expect(qa('.help-row')).toHaveLength(all);

        await setValue(q('[data-testid="help-search"]'), 'tasks.recurrence');
        expect(qa('.help-row')).toHaveLength(1);
        await setValue(q('[data-testid="help-search"]'), 'OPAKOVANIE');
        expect(qa('.help-row').length).toBeGreaterThanOrEqual(1);
        expect(qa('.help-row').every((r) => r.textContent.toLowerCase().includes('opakovanie'))).toBe(true);

        await setValue(q('[data-testid="help-search"]'), 'zzzzqqq');
        expect(q('.help-row')).toBeNull();
        expect(document.body.textContent).toContain('No question mark matches your search.');
        [...document.body.querySelectorAll('button')].find((b) => b.textContent.includes('Clear search and filter')).click();
        await nextTick();
        expect(qa('.help-row')).toHaveLength(all);
    });

    it('edits a text inline with the shared editor and updates the row, the shared texts and shows a toast', async () => {
        mountPage();
        const first = helpRows('en')[0];
        const row = q(`[data-key="${first.key}"]`);
        const edit = row.querySelector('[data-testid="help-edit"]');
        expect(edit.getAttribute('aria-expanded')).toBe('false');
        edit.click();
        await nextTick();
        expect(edit.getAttribute('aria-expanded')).toBe('true');
        expect(qa('[data-testid="help-editor"]')).toHaveLength(1);
        await setValue(row.querySelector('[data-testid="help-text-en"]'), 'New English wording.');
        row.querySelector('[data-testid="help-save"]').click();
        await flushPromises();
        expect(calls[0]).toMatchObject({ url: `/settings/help/${first.key}`, method: 'PUT', body: { texts: { en: 'New English wording.' } } });
        expect(row.querySelector('[data-locale="en"]').textContent).toContain('New English wording.');
        expect(q('[data-testid="help-editor"]')).toBeNull();
        expect(help.topics[first.key].text).toBe('New English wording.');
        expect(document.body.textContent).not.toBe('');
        expect(useToast().toasts.map((x) => x.message)).toContain('Help text saved');
    });

    it('keeps one editor open at a time and closes it on Cancel', async () => {
        mountPage();
        const [a, b] = qa('[data-testid="help-edit"]');
        a.click();
        await nextTick();
        b.click();
        await nextTick();
        expect(qa('[data-testid="help-editor"]')).toHaveLength(1);
        expect(q('[data-testid="help-cancel"]')).not.toBeNull();
        q('[data-testid="help-cancel"]').click();
        await nextTick();
        expect(q('[data-testid="help-editor"]')).toBeNull();
        expect(calls).toHaveLength(0);
    });

    it('shows unused keys only when there are some', () => {
        mountPage();
        expect(q('[data-testid="help-orphans"]')).toBeNull();
        wrapper.unmount();
        mountPage({ orphans: [{ key: 'old.removed.key', texts: { sk: 'a', pl: 'b', en: 'c' }, edited: { sk: false, pl: false, en: false } }] });
        expect(q('[data-testid="help-orphans"]').textContent).toContain('old.removed.key');
        expect(q('[data-testid="help-orphans"]').textContent).toContain('Unused keys');
    });

    it('has an empty state and an error state with retry', async () => {
        mountPage({ topics: [] });
        expect(document.body.textContent).toContain('No question marks yet');
        wrapper.unmount();
        mountPage({ error: true, topics: [] });
        expect(document.body.textContent).toContain('The list of question marks could not be loaded.');
        [...document.body.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Try again').click();
        expect(reload).toHaveBeenCalled();
    });

    it('is translated into Slovak and Polish', () => {
        for (const locale of ['sk', 'pl']) {
            wrapper?.unmount();
            installHelp(locale, { admin: true });
            mountPage({}, locale);
            expect(document.body.textContent).toContain(loadMessages(locale).settings.help.intro);
        }
    });
});

describe('Settings > Contextual help layout and accessibility', () => {
    const cases = [];
    for (const locale of ['en', 'sk', 'pl']) for (const theme of ['light', 'dark']) cases.push([locale, theme]);

    it.each(cases)('has no axe violations, list and open editor (%s, %s)', async (locale, theme) => {
        document.documentElement.dataset.theme = theme;
        installHelp(locale, { admin: true });
        mountPage({}, locale);
        await expectNoAxeViolations(wrapper.element);
        q('[data-testid="help-edit"]').click();
        await nextTick();
        await expectNoAxeViolations(wrapper.element);
    });

    it('survives 320 px and 40% longer text without violations', async () => {
        window.innerWidth = 320;
        const messages = pseudoLong(loadMessages('en'));
        const rows = helpRows('en').map((r) => ({ ...r, texts: Object.fromEntries(Object.entries(r.texts).map(([l, tx]) => [l, tx + 'ŵ'.repeat(Math.ceil(tx.length * 0.4))])) }));
        mockLocale = 'en';
        wrapper = mount(HelpPage, { props: baseProps({ topics: rows }), attachTo: document.body, global: { plugins: [createAppI18n('en', messages)] } });
        await expectNoAxeViolations(wrapper.element);
    });

    it('stacks below 560 px and never forces a horizontal scroll (declared in the style rules)', async () => {
        const { readFileSync } = await import('node:fs');
        const page = readFileSync(`${process.cwd()}/resources/js/Pages/Settings/Help.vue`, 'utf8');
        const row = readFileSync(`${process.cwd()}/resources/js/Settings/Help/HelpRow.vue`, 'utf8');
        expect(page).toContain('max-width: 559px');
        expect(row).toContain('grid-template-columns: minmax(0, 1fr)');
        expect(row).toContain('overflow-wrap: anywhere');
        expect(page + row).not.toMatch(/#[0-9a-fA-F]{3,8}\b/);
    });
});
