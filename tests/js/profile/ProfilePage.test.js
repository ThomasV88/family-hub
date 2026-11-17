import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { loadMessages } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { pseudoLong } from '../helpers/pseudoLong.js';
import { createAppI18n } from '../../../resources/js/i18n.js';

const forms = [];
let putOptions;
vi.mock('@inertiajs/vue3', async () => {
    const { reactive, h: vh } = await import('vue');
    return {
        usePage: () => ({
            props: {
                locale: 'en', app: { name: 'Family Hub', env: 'local' }, avatars: { 1: 2 },
                auth: { user: { id: 1, name: 'Mira', email: 'mira@example.test' }, access: { todo: 'edit' } },
            },
        }),
        router: { post: vi.fn(), visit: vi.fn(), on: vi.fn(), delete: vi.fn() },
        Head: { render: () => null },
        Link: { props: ['href'], render() { return vh('a', { href: this.href }, this.$slots.default?.()); } },
        useForm: (data) => {
            const form = reactive({
                ...data,
                processing: false,
                errors: {},
                clearErrors: vi.fn(),
                post: vi.fn(),
                put: vi.fn((url, options) => { putOptions = { url, ...options }; }),
            });

            forms.push(form);

            return form;
        },
    };
});

import Edit from '../../../resources/js/Pages/Profile/Edit.vue';

const SECTIONS = [{ key: 'appearance', href: '/settings/appearance', labelKey: 'settings.section.appearance', icon: 'palette' }];
const SUBJECT = { id: 1, name: 'Mira Nováková', email: 'mira@example.test', photoVersion: null, isSelf: true, isChild: false };
let wrapper;
beforeEach(() => {
    forms.length = 0;
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
});
afterEach(() => { wrapper?.unmount(); wrapper = null; document.body.innerHTML = ''; });

function mountPage({ locale = 'en', messages, subject = SUBJECT, managedPeople = [], wishlistManagers = [] } = {}) {
    wrapper = mount(Edit, {
        props: { sections: SECTIONS, subject, nameMax: 60, managedPeople, wishlistManagers },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, messages ?? loadMessages(locale))] },
    });

    return wrapper;
}
const save = () => wrapper.findAll('button').find((b) => b.text() === 'Save changes');

describe('Profile settings page', () => {
    it('has the title, a Photo card and a Details card', () => {
        mountPage();
        expect(wrapper.find('h1').text()).toBe('Profile settings');
        expect(wrapper.findAll('h2').map((h2) => h2.text())).toEqual(['Photo', 'Details']);
    });

    it('has a required name field with autocomplete name and the current name', () => {
        mountPage();
        const input = wrapper.find('input[autocomplete="name"]');
        expect(input.element.value).toBe('Mira Nováková');
        expect(wrapper.find('label[for]').text()).toContain('Display name');
        expect(wrapper.text()).toContain('*');
    });

    it('shows the e-mail read-only with its hint and a way to tell it is fixed', () => {
        mountPage();
        const email = wrapper.find('input[type="email"]');
        expect(email.element.value).toBe('mira@example.test');
        expect(email.attributes('readonly')).toBeDefined();
        expect(email.attributes('aria-readonly')).toBe('true');
        expect(wrapper.text()).toContain('You cannot change your e-mail yet.');
    });

    it('disables Save until the name changes, then sends a PUT to the person name route', async () => {
        mountPage();
        expect(save().attributes('disabled')).toBeDefined();
        await wrapper.find('input[autocomplete="name"]').setValue('Mira N.');
        expect(save().attributes('disabled')).toBeUndefined();
        await wrapper.find('form').trigger('submit');
        expect(putOptions.url).toBe('/people/1/name');
    });

    it('keeps Save disabled while processing', async () => {
        mountPage();
        await wrapper.find('input[autocomplete="name"]').setValue('Other');
        forms[0].processing = true;
        await flushPromises();
        expect(save().attributes('disabled')).toBeDefined();
    });

    it('shows a translated inline error, the save alert in a polite live region, and focuses the name field', async () => {
        mountPage();
        await wrapper.find('input[autocomplete="name"]').setValue('x');
        forms[0].errors = { name: 'profile.err_name_chars' };
        await flushPromises();
        expect(wrapper.text()).toContain('The name contains characters that are not allowed.');
        expect(wrapper.find('[data-testid=save-failed]').text()).toContain('Your changes were not saved.');
        expect(wrapper.find('[aria-live="polite"]').find('[data-testid=save-failed]').exists()).toBe(true);
        expect(wrapper.find('input[autocomplete="name"]').attributes('aria-invalid')).toBe('true');
    });

    it('moves focus to the name field after a failed save', async () => {
        mountPage();
        await wrapper.find('input[autocomplete="name"]').setValue('x');
        await wrapper.find('form').trigger('submit');
        await putOptions.onError();
        expect(document.activeElement).toBe(wrapper.find('input[autocomplete="name"]').element);
    });

    it('shows the photo editor with Upload photo for a member without a photo', () => {
        mountPage();
        expect(wrapper.findAll('button').map((b) => b.text())).toContain('Upload photo');
    });

    it('does not show the e-mail row when there is no e-mail', () => {
        mountPage({ subject: { ...SUBJECT, email: null } });
        expect(wrapper.find('input[type="email"]').exists()).toBe(false);
    });

    it.each(['en', 'sk', 'pl'])('has no axe violations in %s', async (locale) => {
        mountPage({ locale });
        await expectNoAxeViolations(wrapper.element);
    });

    it('has no axe violations with pseudo-long text, and the buttons keep their full labels', async () => {
        mountPage({ messages: pseudoLong(loadMessages('en')) });
        await expectNoAxeViolations(wrapper.element);
        const button = wrapper.find('button[type="submit"]');
        expect(button.text().length).toBeGreaterThan('Save changes'.length);
    });
});

describe('Who manages my wish list', () => {
    it('names the members who manage the own wish list, in each language', () => {
        for (const locale of ['en', 'sk', 'pl']) {
            mountPage({ locale, wishlistManagers: ['Jana', 'Peter'] });
            const note = wrapper.find('[data-testid=managers-notice]');
            expect(note.exists()).toBe(true);
            expect(note.text()).toContain('Jana, Peter');
            wrapper.unmount();
        }
    });

    it('shows nothing when nobody manages the list or when the page shows another person', () => {
        mountPage();
        expect(wrapper.find('[data-testid=managers-notice]').exists()).toBe(false);
        wrapper.unmount();
        mountPage({ subject: { id: 7, name: 'Ema', email: null, photoVersion: null, isSelf: false, isChild: true }, wishlistManagers: ['Jana'] });
        expect(wrapper.find('[data-testid=managers-notice]').exists()).toBe(false);
    });

    it('has no axe violations with the notice', async () => {
        mountPage({ wishlistManagers: ['Jana', 'Peter'] });
        await expectNoAxeViolations(wrapper.element);
    });
});

describe('People you manage and a child subject', () => {
    const PEOPLE = [{ id: 7, name: 'Ema', photoVersion: null }, { id: 8, name: 'Adam', photoVersion: 2 }];
    const CHILD = { id: 7, name: 'Ema', email: null, photoVersion: null, isSelf: false, isChild: true };

    it('lists managed children with an Edit button to their page', () => {
        mountPage({ managedPeople: PEOPLE });
        const card = wrapper.find('[data-testid=people-card]');
        expect(card.text()).toContain('People you manage');
        expect(card.text()).toContain('Ema');
        const edit = card.find('[aria-label="Edit Ema"]');
        expect(edit.attributes('href')).toBe('/profile/people/7');
        expect(edit.text()).toBe('Edit');
    });

    it('hides the card when nobody is managed or when the page shows another person', () => {
        mountPage();
        expect(wrapper.find('[data-testid=people-card]').exists()).toBe(false);
        wrapper.unmount();
        mountPage({ subject: CHILD, managedPeople: PEOPLE });
        expect(wrapper.find('[data-testid=people-card]').exists()).toBe(false);
    });

    it('shows the info alert instead of the e-mail field for a child', () => {
        mountPage({ subject: CHILD });
        expect(wrapper.find('h1').text()).toBe('Child profile');
        expect(wrapper.find('[data-testid=child-info]').text()).toContain('No e-mail, cannot sign in.');
        expect(wrapper.find('input[type="email"]').exists()).toBe(false);
        expect(wrapper.find('input[autocomplete="name"]').element.value).toBe('Ema');
    });

    it.each(['en', 'sk', 'pl'])('has no axe violations in %s, light and dark', async (locale) => {
        for (const theme of ['light', 'dark']) {
            document.documentElement.dataset.theme = theme;
            mountPage({ locale, managedPeople: PEOPLE, wishlistManagers: ['Jana', 'Peter'] });
            await expectNoAxeViolations(wrapper.element);
            wrapper.unmount();
            mountPage({ locale, subject: CHILD });
            await expectNoAxeViolations(wrapper.element);
            wrapper.unmount();
            wrapper = null;
            document.body.innerHTML = '';
        }
        delete document.documentElement.dataset.theme;
    });

    it('holds with pseudo-long strings', async () => {
        mountPage({ messages: pseudoLong(loadMessages('en')), managedPeople: PEOPLE });
        await expectNoAxeViolations(wrapper.element);
    });
});
