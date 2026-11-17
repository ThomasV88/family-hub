import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { loadMessages } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';

const del = vi.fn();
let lastForm;
let postOptions;
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    return {
        usePage: () => ({ props: {} }),
        router: { delete: (...a) => del(...a) },
        useForm: (data) => {
            lastForm = reactive({
                ...data,
                processing: false,
                errors: {},
                clearErrors: vi.fn(),
                post: vi.fn((url, options) => { postOptions = { url, ...options }; }),
            });

            return lastForm;
        },
    };
});

import ProfilePhotoEditor from '../../../resources/js/Components/Profile/ProfilePhotoEditor.vue';

let wrapper;
const create = vi.fn(() => 'blob:preview-1');
const revoke = vi.fn();
beforeEach(() => {
    URL.createObjectURL = create;
    URL.revokeObjectURL = revoke;
    create.mockClear();
    revoke.mockClear();
    del.mockClear();
});
afterEach(() => { wrapper?.unmount(); wrapper = null; document.body.innerHTML = ''; });

function mountEditor(props = {}, locale = 'en') {
    wrapper = mount(ProfilePhotoEditor, {
        props: { userId: 4, name: 'Mira', photoVersion: null, ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, loadMessages(locale))] },
    });

    return wrapper;
}
const buttons = () => wrapper.findAll('button').map((b) => b.text());
const pick = async () => {
    const input = wrapper.find('[data-testid=photo-input]');
    Object.defineProperty(input.element, 'files', { value: [new File(['x'], 'me.jpg', { type: 'image/jpeg' })], configurable: true });
    await input.trigger('change');
};

describe('ProfilePhotoEditor', () => {
    it('offers Upload photo and no Remove without a photo, and shows the initial', () => {
        mountEditor();
        expect(buttons()).toEqual(['Upload photo']);
        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.text()).toContain('M');
        expect(wrapper.text()).toContain('JPG, PNG or WebP, up to 5 MB');
    });

    it('offers Replace photo and Remove photo with a photo and shows the full variant', () => {
        mountEditor({ photoVersion: 3 });
        expect(buttons()).toEqual(['Replace photo', 'Remove photo']);
        expect(wrapper.find('img').attributes('src')).toBe('/people/4/photo/full?v=3');
    });

    it('describes the file input by the hint and triggers it from the button', async () => {
        mountEditor();
        const input = wrapper.find('[data-testid=photo-input]');
        const hint = wrapper.find(`#${input.attributes('aria-describedby')}`);
        expect(hint.exists()).toBe(true);
        const click = vi.spyOn(input.element, 'click');
        await wrapper.find('button').trigger('click');
        expect(click).toHaveBeenCalled();
    });

    it('posts the chosen file at once as multipart, previews it and revokes the object URL when done', async () => {
        mountEditor();
        await pick();
        expect(postOptions.url).toBe('/people/4/photo');
        expect(postOptions.forceFormData).toBe(true);
        expect(lastForm.photo).toBeInstanceOf(File);
        expect(wrapper.find('img').attributes('src')).toBe('blob:preview-1');
        lastForm.processing = true;
        await flushPromises();
        expect(wrapper.find('.ui-skeleton').exists()).toBe(true);
        expect(wrapper.find('[role=status]').text()).toBe('Uploading photo');
        lastForm.processing = false;
        postOptions.onFinish();
        await flushPromises();
        expect(revoke).toHaveBeenCalledWith('blob:preview-1');
        expect(wrapper.find('img').exists()).toBe(false);
    });

    it('shows a translated danger alert for a rejected photo', async () => {
        mountEditor();
        lastForm.errors = { photo: 'profile.photo.err_size' };
        await flushPromises();
        const alert = wrapper.find('[data-testid=photo-error]');
        expect(alert.text()).toBe('The picture is larger than 5 MB. Choose a smaller one.');
        expect(alert.attributes('role')).toBe('alert');
    });

    it('asks before removing, with action-named buttons, then deletes', async () => {
        mountEditor({ photoVersion: 1 });
        await wrapper.findAll('button')[1].trigger('click');
        const dialog = document.body.querySelector('[role=dialog]');
        expect(dialog.textContent).toContain('Remove your photo? Your initial will be shown instead.');
        const labels = [...dialog.querySelectorAll('button')].map((b) => b.textContent.trim());
        expect(labels).toContain('Keep photo');
        [...dialog.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Remove photo').click();
        await flushPromises();
        expect(del).toHaveBeenCalledWith('/people/4/photo', expect.objectContaining({ preserveScroll: true }));
    });

    it('keeps the photo when removal is declined', async () => {
        mountEditor({ photoVersion: 1 });
        await wrapper.findAll('button')[1].trigger('click');
        [...document.body.querySelectorAll('[role=dialog] button')].find((b) => b.textContent.trim() === 'Keep photo').click();
        await flushPromises();
        expect(del).not.toHaveBeenCalled();
    });

    it('has no axe violations in each language, with and without a photo', async () => {
        for (const locale of ['en', 'sk', 'pl']) {
            mountEditor({ photoVersion: 2 }, locale);
            await expectNoAxeViolations(wrapper.element);
            wrapper.unmount();
            mountEditor({}, locale);
            await expectNoAxeViolations(wrapper.element);
            wrapper.unmount();
        }
        wrapper = null;
    });
});
