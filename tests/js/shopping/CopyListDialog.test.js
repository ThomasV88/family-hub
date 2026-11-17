import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

const { forms } = vi.hoisted(() => ({ forms: [] }));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    return {
        usePage: () => ({ props: {} }),
        useForm: (data) => {
            const form = reactive({
                ...data,
                processing: false,
                errors: {},
                post: vi.fn((url, opts) => opts?.onSuccess?.()),
                clearErrors() { form.errors = {}; },
            });
            forms.push(form);
            return form;
        },
    };
});

import CopyListDialog from '../../../resources/js/Shopping/CopyListDialog.vue';

let current;
function mountDialog({ locale = 'en', list = { id: 7, name: 'Weekly' }, open = true } = {}) {
    current = mount(CopyListDialog, { props: { open, list }, attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });
    return current;
}
const dialog = () => document.body.querySelector('[role="dialog"]');
const submitButton = (label) => [...dialog().querySelectorAll('button')].find((b) => b.textContent.trim() === label);

beforeEach(() => { forms.length = 0; });
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; });

describe('CopyListDialog', () => {
    it.each([['en', 'Weekly (copy)'], ['sk', 'Weekly (kópia)'], ['pl', 'Weekly (kopia)']])('defaults the name in %s and keeps the settings checked', async (locale, expected) => {
        mountDialog({ locale });
        await flushPromises();
        expect(forms[0].name).toBe(expected);
        expect(forms[0].keep).toBe(true);
        expect(dialog().querySelector('input[type="checkbox"]').checked).toBe(true);
    });

    it('keeps the default name within 80 characters for a long list name', async () => {
        mountDialog({ list: { id: 1, name: 'x'.repeat(80) } });
        await flushPromises();
        expect(forms[0].name.length).toBeLessThanOrEqual(80);
        expect(forms[0].name.endsWith(' (copy)')).toBe(true);
    });

    it('posts name and keep to the copy route and closes on success', async () => {
        mountDialog();
        await flushPromises();
        forms[0].name = 'Next week';
        forms[0].keep = false;
        dialog().querySelector('form#copy-list-form').dispatchEvent(new Event('submit'));
        await flushPromises();
        expect(forms[0].post).toHaveBeenCalledWith('/shopping/lists/7/copy', expect.any(Object));
        expect(current.emitted('update:open')?.at(-1)).toEqual([false]);
    });

    it('does not post with an empty name', async () => {
        mountDialog();
        await flushPromises();
        forms[0].name = '   ';
        await flushPromises();
        expect(submitButton('Copy list').disabled).toBe(true);
        dialog().querySelector('form#copy-list-form').dispatchEvent(new Event('submit'));
        expect(forms[0].post).not.toHaveBeenCalled();
    });

    it('shows a server error inline', async () => {
        mountDialog();
        await flushPromises();
        forms[0].errors = { name: 'shopping.error.name_invalid' };
        await flushPromises();
        expect(dialog().textContent).toContain('Enter a name that is not empty and not too long.');
    });
});
