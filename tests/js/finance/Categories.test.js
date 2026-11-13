import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { finance } from './fixtures.js';

let mockProps = {};
const calls = { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() };
vi.mock('@inertiajs/vue3', async () => {
    const { reactive: r } = await import('vue');
    return {
        usePage: () => ({ props: mockProps, get url() { return '/finance/categories'; } }),
        router: { get: (...a) => calls.get(...a), post: vi.fn(), patch: vi.fn(), delete: (...a) => calls.delete(...a), visit: vi.fn(), on: vi.fn() },
        useForm: (data) => {
            const form = r({
                ...data, errors: {}, processing: false,
                post: (...a) => calls.post(form.data(), ...a),
                patch: (...a) => calls.patch(form.data(), ...a),
                data: () => Object.fromEntries(Object.keys(data).map((k) => [k, form[k]])),
            });
            return form;
        },
        Link: { props: ['href'], render() { return null; } },
    };
});

import Categories from '../../../resources/js/Pages/Finance/Categories.vue';

const CATS = [
    { id: 'c-groc', kind: 'expense', defaultKey: 'groceries', name: null },
    { id: 'c-rent', kind: 'expense', defaultKey: 'housing', name: null },
    { id: 'c-zoo', kind: 'expense', defaultKey: null, name: 'Zoo' },
    { id: 'c-pets', kind: 'expense', defaultKey: null, name: 'Pets' },
    { id: 'c-sal', kind: 'income', defaultKey: 'salary', name: null },
    { id: 'c-gift', kind: 'income', defaultKey: null, name: 'Gifts' },
];

let current;
function mountPage(props = {}, { locale = 'en' } = {}) {
    window.innerWidth = 1280;
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    mockProps = reactive({
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { todo: 'none', wishlist: 'none', finance: 'edit', health: 'none' } },
        app: { env: 'local', styleGuide: false },
    });
    current = mount(Categories, {
        props: { finance: finance({ categories: CATS }), panelCategory: null, ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, loadMessages(locale))] },
    });
    return current;
}
beforeEach(() => Object.values(calls).forEach((f) => f.mockReset()));
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; document.documentElement.classList.remove('dark'); });

const btn = (text) => [...document.body.querySelectorAll('button')].find((b) => b.textContent.trim() === text);
const group = (kind) => [...document.querySelectorAll(`[data-group="${kind}"] li`)].map((li) => li.textContent.trim());

describe('Finance Categories page', () => {
    it('shows h1, breadcrumb and two groups sorted by the displayed label, with no counts', () => {
        mountPage();
        expect(document.querySelector('h1').textContent).toBe('Categories');
        expect(document.querySelector('a.cat-page__crumb').getAttribute('href')).toBe('/finance');
        expect(group('expense')).toEqual(['Groceries', 'Housing', 'Pets', 'Zoo']);
        expect(group('income')).toEqual(['Gifts', 'Salary']);
        expect(document.querySelector('[data-testid="finance-categories"]').textContent).not.toMatch(/\d/);
        expect([...document.querySelectorAll('h2')].map((h) => h.textContent)).toEqual(['Expense categories', 'Income categories']);
    });

    it('translates default categories per locale and sorts with that locale', () => {
        mountPage({}, { locale: 'sk' });
        const labels = group('expense');
        expect(labels).toHaveLength(4);
        expect(labels).toContain('Pets');
        expect([...labels].sort((a, b) => a.localeCompare(b, 'sk'))).toEqual(labels);
    });

    it('opens the panel from a row and from Add category (edit users)', () => {
        mountPage();
        btn('Pets').click();
        expect(calls.get.mock.calls[0][1]).toEqual({ cat: 'c-pets' });
        btn('Add category').click();
        expect(calls.get.mock.calls[1][1]).toEqual({ cat: 'new' });
    });

    it('makes rows static and hides Add category for view-only users', () => {
        mountPage({ finance: finance({ categories: CATS, canEdit: false }) });
        expect(btn('Add category')).toBeUndefined();
        expect(document.querySelectorAll('[data-group] li button')).toHaveLength(0);
        expect(group('expense')).toContain('Pets');
    });
});

describe('CategoryPanel', () => {
    it('creates: name, type chips, posts the trimmed payload', async () => {
        mountPage({ panelCategory: { mode: 'new' } });
        await flushPromises();
        expect(document.querySelector('h2.ui-panel__title').textContent).toBe('New category');
        expect(document.querySelectorAll('input[type="radio"]')).toHaveLength(2);
        expect([...document.querySelectorAll('input[type="radio"]')].every((r) => !r.disabled)).toBe(true);
        expect(btn('Delete category')).toBeUndefined();
        const name = document.querySelector('input[maxlength="40"]');
        name.value = 'Travel';
        name.dispatchEvent(new Event('input', { bubbles: true }));
        document.querySelectorAll('input[type="radio"]')[1].click();
        await flushPromises();
        btn('Save category').click();
        await flushPromises();
        expect(calls.post.mock.calls[0][0]).toEqual({ name: 'Travel', kind: 'income' });
        expect(calls.post.mock.calls[0][1]).toBe('/finance/categories');
    });

    it('shows an empty-name error and does not post', async () => {
        mountPage({ panelCategory: { mode: 'new' } });
        await flushPromises();
        btn('Save category').click();
        await flushPromises();
        expect(calls.post).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain('Enter a name for the category.');
    });

    it('edits: type is read-only, rename patches, a default category starts with its translated name', async () => {
        mountPage({ panelCategory: { id: 'c-groc', kind: 'expense', defaultKey: 'groceries', name: null } });
        await flushPromises();
        expect(document.querySelector('h2.ui-panel__title').textContent).toBe('Category details');
        expect([...document.querySelectorAll('input[type="radio"]')].every((r) => r.disabled)).toBe(true);
        expect(document.querySelector('input[maxlength="40"]').value).toBe('Groceries');
        btn('Save category').click();
        await flushPromises();
        expect(calls.patch.mock.calls[0][1]).toBe('/finance/categories/c-groc');
        expect(calls.patch.mock.calls[0][0].name).toBe('Groceries');
    });

    it('delete dialog: Move to has same-kind targets only, no default, confirm disabled until chosen, safe default focus', async () => {
        mountPage({ panelCategory: { id: 'c-pets', kind: 'expense', defaultKey: null, name: 'Pets' } });
        await flushPromises();
        btn('Delete category').click();
        await flushPromises();
        const dialog = document.body.querySelector('[role="dialog"]');
        expect(dialog.textContent).toContain('Delete "Pets"? Its transactions and budgets move to the category you choose below.');
        const select = dialog.querySelector('select');
        expect(select.value).toBe('');
        expect([...select.options].filter((o) => o.value).map((o) => o.textContent.trim())).toEqual(['Groceries', 'Housing', 'Zoo']);
        const confirm = [...dialog.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Delete category');
        expect(confirm.disabled).toBe(true);
        expect(document.activeElement.textContent.trim()).toBe('Keep category');
        expect(dialog.textContent).not.toMatch(/\d/);

        select.value = 'c-groc';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        await flushPromises();
        expect(confirm.disabled).toBe(false);
        confirm.click();
        expect(calls.delete.mock.calls[0][0]).toBe('/finance/categories/c-pets');
        expect(calls.delete.mock.calls[0][1].data).toEqual({ move_to: 'c-groc' });
    });

    it('shows the server move error inline', async () => {
        mountPage({ panelCategory: { id: 'c-pets', kind: 'expense', defaultKey: null, name: 'Pets' } });
        await flushPromises();
        btn('Delete category').click();
        await flushPromises();
        const dialog = document.body.querySelector('[role="dialog"]');
        const select = dialog.querySelector('select');
        select.value = 'c-groc';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        await flushPromises();
        [...dialog.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Delete category').click();
        calls.delete.mock.calls[0][1].onError({ move_to: 'finance.cat.err_move' });
        await flushPromises();
        expect(dialog.textContent).toContain('Choose a category to move to.');
    });

    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`passes axe: page, new, edit and delete dialog (${locale}${dark ? ', dark' : ''})`, async () => {
                document.documentElement.classList.toggle('dark', dark);
                mountPage({}, { locale });
                await expectNoAxeViolations(document.body);
                current.unmount();
                document.body.innerHTML = '';

                mountPage({ panelCategory: { mode: 'new' } }, { locale });
                await flushPromises();
                await expectNoAxeViolations(document.body);
                current.unmount();
                document.body.innerHTML = '';

                mountPage({ panelCategory: { id: 'c-pets', kind: 'expense', defaultKey: null, name: 'Pets' } }, { locale });
                await flushPromises();
                await expectNoAxeViolations(document.body);
                [...document.body.querySelectorAll('button')].find((b) => b.classList.contains('cat-panel__delete')).click();
                await flushPromises();
                await expectNoAxeViolations(document.body);
            });
        }
    }
});
