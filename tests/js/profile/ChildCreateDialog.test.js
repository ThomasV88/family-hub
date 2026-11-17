import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { loadMessages } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { pseudoLong } from '../helpers/pseudoLong.js';
import { createAppI18n } from '../../../resources/js/i18n.js';

let mockProps = {};
const forms = [];
let postOptions;
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    return {
        usePage: () => ({ props: mockProps }),
        router: { post: vi.fn(), delete: vi.fn(), visit: vi.fn(), on: vi.fn() },
        useForm: (data) => {
            const initial = JSON.parse(JSON.stringify(data));
            const form = reactive({
                ...data,
                processing: false,
                errors: {},
                clearErrors() { this.errors = {}; },
                reset() { Object.assign(this, JSON.parse(JSON.stringify(initial))); },
                post: vi.fn((url, options) => { postOptions = { url, ...options }; }),
            });

            forms.push(form);

            return form;
        },
    };
});

import ChildCreateDialog from '../../../resources/js/Components/Profile/ChildCreateDialog.vue';

const PARENTS = [{ id: 2, name: 'Petra' }, { id: 3, name: 'Tomáš' }];
let wrapper;
// The dialog's own form is the first one created (the photo editor makes another).
const dialogForm = () => forms[0];
beforeEach(() => {
    forms.length = 0;
    mockProps = { locale: 'en', avatars: {}, flash: { data: { created_child_id: 9 } } };
    URL.createObjectURL = vi.fn(() => 'blob:preview');
    URL.revokeObjectURL = vi.fn();
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
});
afterEach(() => { wrapper?.unmount(); wrapper = null; document.body.innerHTML = ''; });

function mountDialog({ locale = 'en', messages, ...props } = {}) {
    wrapper = mount(ChildCreateDialog, {
        props: { open: true, parents: PARENTS, ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, messages ?? loadMessages(locale))] },
    });

    return wrapper;
}
const dialog = () => document.body.querySelector('[role=dialog]');
const submit = () => [...dialog().querySelectorAll('button')].find((b) => b.textContent.trim() === 'Create child');
const nameInput = () => dialog().querySelector('input[type=text], input:not([type])');

describe('ChildCreateDialog', () => {
    it('shows the title, the info alert, name, photo, parents and the footer buttons', () => {
        mountDialog();
        const d = dialog();
        expect(d.textContent).toContain('Add a child');
        expect(d.textContent).toContain('A child has no e-mail and cannot sign in.');
        expect(d.textContent).toContain('Name');
        expect(d.textContent).toContain('Photo (optional)');
        expect(d.textContent).toContain('Parents who manage the wish list');
        expect(d.textContent).toContain("Parents can manage this child's wish list.");
        expect(d.querySelectorAll('input[type=checkbox]')).toHaveLength(2);
        expect(d.textContent).toContain('Petra');
        expect([...d.querySelectorAll('button')].map((b) => b.textContent.trim())).toEqual(expect.arrayContaining(['Cancel', 'Create child']));
    });

    it('keeps Create child disabled until a name and one parent are given', async () => {
        mountDialog();
        expect(submit().disabled).toBe(true);
        const input = nameInput();
        input.value = 'Ema';
        input.dispatchEvent(new Event('input'));
        await flushPromises();
        expect(submit().disabled).toBe(true);
        const box = dialog().querySelector('input[type=checkbox]');
        box.checked = true;
        box.dispatchEvent(new Event('change'));
        await flushPromises();
        expect(submit().disabled).toBe(false);
    });

    it('posts only JSON (name and parent ids) and hands the created id and the chosen photo to the parent', async () => {
        mountDialog();
        const input = nameInput();
        input.value = 'Ema';
        input.dispatchEvent(new Event('input'));
        const boxes = dialog().querySelectorAll('input[type=checkbox]');
        boxes[1].checked = true;
        boxes[1].dispatchEvent(new Event('change'));
        const file = new File(['x'], 'ema.jpg', { type: 'image/jpeg' });
        const picker = dialog().querySelector('[data-testid=photo-input]');
        Object.defineProperty(picker, 'files', { value: [file], configurable: true });
        picker.dispatchEvent(new Event('change'));
        await flushPromises();

        dialog().querySelector('form').dispatchEvent(new Event('submit', { cancelable: true }));
        await flushPromises();
        expect(postOptions.url).toBe('/settings/members/children');
        expect(dialogForm().name).toBe('Ema');
        expect(dialogForm().parent_ids).toEqual([3]);
        expect(dialogForm().photo).toBeUndefined();

        postOptions.onSuccess();
        expect(wrapper.emitted('created')[0][0]).toEqual({ id: 9, photo: file });
        expect(wrapper.emitted('update:open')[0]).toEqual([false]);
    });

    it('shows translated server errors and a failure alert in a polite live region', async () => {
        mountDialog();
        dialogForm().errors = { name: 'profile.err_name_chars', 'parent_ids.0': 'settings.members.child.err_parent' };
        await flushPromises();
        const d = dialog();
        expect(d.textContent).toContain('The name contains characters that are not allowed.');
        expect(d.textContent).toContain('Choose a member who can sign in and has Wishlist edit access.');
        expect(d.querySelector('[aria-live="polite"] [data-testid=create-failed]')).not.toBeNull();
    });

    it('says when the acting admin cannot be chosen and when nobody can', () => {
        mountDialog({ selfEligible: false, parents: [] });
        const d = dialog();
        expect(d.querySelector('[data-testid=self-hint]').textContent).toContain('You cannot be chosen');
        expect(d.querySelector('[data-testid=no-parents]')).not.toBeNull();
    });

    it.each(['en', 'sk', 'pl'])('has no axe violations in %s, light and dark, with errors and pseudo-long names', async (locale) => {
        for (const theme of ['light', 'dark']) {
            document.documentElement.dataset.theme = theme;
            mountDialog({ locale, parents: PARENTS.map((p) => ({ ...p, name: `${p.name} ${'Wŵ'.repeat(20)}` })) });
            await expectNoAxeViolations(document.body);
            dialogForm().errors = { name: 'profile.err_name_chars', parent_ids: 'settings.members.child.err_parent_required' };
            await flushPromises();
            await expectNoAxeViolations(document.body);
            wrapper.unmount();
            wrapper = null;
            document.body.innerHTML = '';
        }
        delete document.documentElement.dataset.theme;
    });

    it('holds with pseudo-long strings and keeps the full button labels', async () => {
        mountDialog({ messages: pseudoLong(loadMessages('en')) });
        await expectNoAxeViolations(document.body);
        expect([...dialog().querySelectorAll('button')].some((b) => b.textContent.trim().length > 'Create child'.length)).toBe(true);
    });
});
