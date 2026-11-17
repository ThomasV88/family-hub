import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { pseudoLong } from '../helpers/pseudoLong.js';

const { forms } = vi.hoisted(() => ({ forms: [] }));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    return {
        useForm: (data) => {
            const form = reactive({ ...data, processing: false, errors: {}, post: vi.fn(), clearErrors() {} });
            forms.push(form);
            return form;
        },
    };
});

import FinishShoppingDialog from '../../../resources/js/Shopping/FinishShoppingDialog.vue';

const UNITS = [{ id: 1, key: 'pcs', names: null }, { id: 2, key: 'kg', names: null }];
const unavailable = (n) => Array.from({ length: n }, (_, i) => ({ id: i + 1, name: `Item ${i + 1}`, quantity: i === 0 ? '2.00' : '1.00', unitId: i === 0 ? 2 : 1 }));

let current;
function mountDialog({ locale = 'en', messages = null, items = [], name = 'Týždenný nákup', open = true } = {}) {
    current = mount(FinishShoppingDialog, {
        props: { open, list: { id: 5, name }, unavailable: items, units: UNITS, 'onUpdate:open': (v) => current.setProps({ open: v }) },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, messages ?? loadMessages(locale))] },
    });
    return current;
}
const dialog = () => document.body.querySelector('[role="dialog"]');
const button = (label) => [...dialog().querySelectorAll('button')].find((b) => b.textContent.trim() === label);
const form = () => forms.at(-1);

beforeEach(() => { forms.length = 0; });
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; delete document.documentElement.dataset.theme; });

describe('Finish shopping dialog, nothing unavailable', () => {
    it('asks for a plain confirmation and posts carry_name null', async () => {
        mountDialog();
        await flushPromises();
        expect(dialog().textContent).toContain('Finish shopping?');
        expect(dialog().textContent).toContain('The list moves to the archive and becomes read-only.');
        expect(dialog().textContent).not.toContain('Create a new shopping list');
        button('Finish shopping').click();
        expect(form().post).toHaveBeenCalledTimes(1);
        expect(form().post.mock.calls[0][0]).toBe('/shopping/lists/5/finish');
        expect(form().carry_name).toBeNull();
    });

    it('Not yet closes without a request', async () => {
        mountDialog();
        await flushPromises();
        button('Not yet').click();
        await flushPromises();
        expect(form().post).not.toHaveBeenCalled();
        expect(document.body.querySelector('[role="dialog"]')).toBeNull();
    });
});

describe('Finish shopping dialog, with unavailable items', () => {
    it('asks the owner question and lists the unavailable items with quantities', async () => {
        mountDialog({ items: unavailable(2) });
        await flushPromises();
        expect(dialog().textContent).toContain('Some items could not be bought. Create a new shopping list from them?');
        const lines = [...dialog().querySelectorAll('[data-testid="finish-items"] li')].map((li) => [...li.querySelectorAll('span')].map((e) => e.textContent).join(' ') || li.textContent);
        expect(lines).toEqual(['Item 1 2 kg', 'Item 2']);
        expect(dialog().textContent).toContain('The new list keeps the store, the responsible person and who can see it.');
    });

    it('Create new list posts the localised name and closes on success', async () => {
        mountDialog({ locale: 'sk', items: unavailable(2) });
        await flushPromises();
        button('Vytvoriť nový zoznam').click();
        expect(form().carry_name).toBe('Týždenný nákup (nezakúpené)');
        form().post.mock.calls[0][1].onSuccess();
        await flushPromises();
        expect(document.body.querySelector('[role="dialog"]')).toBeNull();
    });

    it('No, just finish posts null', async () => {
        mountDialog({ items: unavailable(2) });
        await flushPromises();
        button('No, just finish').click();
        expect(form().carry_name).toBeNull();
        expect(form().post).toHaveBeenCalledTimes(1);
    });

    it('Cancel closes without a request', async () => {
        mountDialog({ items: unavailable(2) });
        await flushPromises();
        button('Cancel').click();
        await flushPromises();
        expect(form().post).not.toHaveBeenCalled();
        expect(document.body.querySelector('[role="dialog"]')).toBeNull();
    });

    it('shows five items and "+2 more" for seven', async () => {
        mountDialog({ items: unavailable(7) });
        await flushPromises();
        expect(dialog().querySelectorAll('[data-testid="finish-items"] li')).toHaveLength(6);
        expect(dialog().querySelector('[data-testid="finish-more"]').textContent).toBe('+2 more');
    });

    it('cuts a long list name so the new name stays within 80 characters', async () => {
        mountDialog({ items: unavailable(1), name: 'A'.repeat(80) });
        await flushPromises();
        button('Create new list').click();
        expect(form().carry_name.length).toBeLessThanOrEqual(80);
        expect(form().carry_name.endsWith(' (unbought)')).toBe(true);
    });

    it('keeps the dialog open with the danger alert when the server fails', async () => {
        mountDialog({ items: unavailable(2) });
        await flushPromises();
        button('Create new list').click();
        const opts = form().post.mock.calls[0][1];
        opts.onError({ carry_name: 'shopping.error.name_invalid' });
        await flushPromises();
        expect(dialog().querySelector('[data-testid="finish-failed"]').textContent).toContain('We could not finish the shopping. Nothing was changed. Try again.');
        expect(opts.onHttpException()).toBe(false);
    });

    it('does not close on Escape while saving', async () => {
        mountDialog({ items: unavailable(2) });
        await flushPromises();
        form().processing = true;
        await flushPromises();
        dialog().parentElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await flushPromises();
        expect(document.body.querySelector('[role="dialog"]')).not.toBeNull();
        expect(button('Create new list').disabled).toBe(true);
    });
});

describe('Finish shopping dialog accessibility', () => {
    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`has no axe violations in both cases (${locale}, ${dark ? 'dark' : 'light'})`, async () => {
                document.documentElement.dataset.theme = dark ? 'dark' : 'light';
                const base = loadMessages(locale);
                mountDialog({ locale, messages: base });
                await flushPromises();
                await expectNoAxeViolations(document.body);
                current.unmount();
                mountDialog({ locale, messages: base, items: unavailable(7).map((i) => ({ ...i, name: pseudoLong('Položka') })), name: pseudoLong('Zoznam') });
                await flushPromises();
                await expectNoAxeViolations(document.body);
            });
        }
    }
});
