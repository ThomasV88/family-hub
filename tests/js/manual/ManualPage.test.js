import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { nextTick } from 'vue';
import { mountUi, loadMessages } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { loadManual, padManual } from '../helpers/manual.js';
import { pseudoLong } from '../helpers/pseudoLong.js';

let mockLocale = 'en';
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({
            props: {
                locale: mockLocale,
                app: { name: 'Family Hub', env: 'local' },
                auth: { user: { id: 1, name: 'Mira', email: 'mira@example.test', isAdmin: true }, access: { todo: 'edit', wishlist: 'edit', finance: 'edit', health: 'edit' } },
            },
        }),
        router: { post: vi.fn(), get: vi.fn(), visit: vi.fn(), on: vi.fn(), reload: vi.fn() },
        Head: { render: () => null },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});

import Manual from '../../../resources/js/Pages/Manual/Index.vue';

let wrapper;
const originalWidth = window.innerWidth;
beforeEach(() => {
    Element.prototype.scrollIntoView = vi.fn();
    history.replaceState(null, '', '/manual');
});
afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    delete document.documentElement.dataset.theme;
    window.innerWidth = originalWidth;
    document.documentElement.style.fontSize = '';
});

const show = (locale = 'en', { admin = true, manual } = {}) => {
    mockLocale = locale;
    wrapper = mountUi(Manual, { locale, props: manual ?? loadManual(locale, { admin }), global: {} });
    return wrapper;
};
const en = loadMessages('en');
const sectionButton = (id) => wrapper.find(`#${id} button`);

describe('Manual page', () => {
    it('renders the title, categories, topics and a table of contents', () => {
        show();
        expect(wrapper.find('h1').text()).toBe(en.manual.title);
        const manual = loadManual('en');
        const cats = wrapper.findAll('h2').map((h) => h.text());
        for (const c of manual.categories) expect(cats).toContain(c.title);
        const toc = wrapper.find(`nav[aria-label="${en.manual.on_this_page}"]`);
        expect(toc.findAll('a')).toHaveLength(manual.topics.length);
        expect(wrapper.findAll('h3').map((h) => h.text())).toEqual(expect.arrayContaining(manual.topics.map((tp) => tp.title)));
    });

    it('marks administrator-only parts for an administrator and omits them for a member', () => {
        show('en', { admin: true });
        expect(wrapper.text()).toContain(en.manual.admin_only);
        expect(wrapper.find('#m-admin').exists()).toBe(true);
        wrapper.unmount();
        show('en', { admin: false });
        expect(wrapper.text()).not.toContain(en.manual.admin_only);
        expect(wrapper.find('#m-admin').exists()).toBe(false);
        expect(wrapper.find('a[href="#m-admin"]').exists()).toBe(false);
    });

    it('collapses long topics, opens short ones, and toggles a section with the keyboard-reachable button', async () => {
        show();
        const btn = sectionButton('m-tasks--create');
        expect(btn.element.tagName).toBe('BUTTON');
        expect(btn.attributes('aria-expanded')).toBe('false');
        const panel = wrapper.find('#m-tasks--create-panel');
        expect(btn.attributes('aria-controls')).toBe('m-tasks--create-panel');
        expect(panel.attributes('hidden')).toBeDefined();
        await btn.trigger('click');
        expect(btn.attributes('aria-expanded')).toBe('true');
        expect(panel.attributes('hidden')).toBeUndefined();
        // Glossary has one section, so it starts open.
        expect(sectionButton('m-glossary--words').attributes('aria-expanded')).toBe('true');
    });

    it('expands and collapses everything', async () => {
        show();
        const buttons = () => wrapper.findAll('.msec__btn');
        await wrapper.findAll('button').find((b) => b.text() === en.manual.expand_all).trigger('click');
        expect(buttons().every((b) => b.attributes('aria-expanded') === 'true')).toBe(true);
        await wrapper.findAll('button').find((b) => b.text() === en.manual.collapse_all).trigger('click');
        expect(buttons().every((b) => b.attributes('aria-expanded') === 'false')).toBe(true);
    });

    it('a table of contents link opens the topic, scrolls to it and moves focus there', async () => {
        show();
        const link = wrapper.find('nav a[href="#m-health"]');
        await link.trigger('click');
        await nextTick();
        expect(Element.prototype.scrollIntoView).toHaveBeenCalled();
        expect(document.activeElement.id).toBe('m-health');
        expect(location.hash).toBe('#m-health');
    });

    it('a topic section chip opens that section and focuses its heading button', async () => {
        show();
        await wrapper.find('a[href="#m-tasks--reminders"]').trigger('click');
        await nextTick();
        expect(sectionButton('m-tasks--reminders').attributes('aria-expanded')).toBe('true');
        expect(document.activeElement).toBe(sectionButton('m-tasks--reminders').element);
    });

    it('an in-text link to another topic works', async () => {
        show();
        await sectionButton('m-tasks--reminders').trigger('click');
        const link = wrapper.find('#m-tasks--reminders-panel a[href="#m-getting-started--notifications"]');
        expect(link.exists()).toBe(true);
        await link.trigger('click');
        await nextTick();
        expect(sectionButton('m-getting-started--notifications').attributes('aria-expanded')).toBe('true');
    });

    it('opens the section named in the address on load', async () => {
        history.replaceState(null, '', '/manual#m-finance--import');
        show();
        await nextTick();
        await nextTick();
        expect(sectionButton('m-finance--import').attributes('aria-expanded')).toBe('true');
    });

    it('filters by search, announces the count, and offers a way back', async () => {
        show();
        const input = wrapper.find('input[type=search]');
        expect(input.exists()).toBe(true);
        await input.setValue('reminder');
        const status = wrapper.find('[data-testid=manual-status]');
        expect(status.text()).toMatch(/section/);
        expect(wrapper.find('#m-tasks--reminders').exists()).toBe(true);
        expect(wrapper.find('#m-glossary').exists()).toBe(false);
        // Matching sections are shown open while searching.
        expect(sectionButton('m-tasks--reminders').attributes('aria-expanded')).toBe('true');

        await input.setValue('zzzzqqq');
        expect(wrapper.find('[data-testid=manual-empty]').exists()).toBe(true);
        expect(status.text()).toBe(en.manual.no_results_title);
        await wrapper.find('[data-testid=manual-empty] button').trigger('click');
        expect(input.element.value).toBe('');
        expect(wrapper.find('#m-glossary').exists()).toBe(true);
    });

    it('finds Slovak words typed without diacritics', async () => {
        show('sk');
        await wrapper.find('input[type=search]').setValue('pripomienk');
        expect(wrapper.find('#m-tasks--reminders').exists()).toBe(true);
        await wrapper.find('input[type=search]').setValue('zaciname');
        expect(wrapper.find('#m-getting-started').exists()).toBe(true);
    });

    it('prints with the browser print dialog', async () => {
        const print = vi.fn();
        window.print = print;
        show();
        await wrapper.findAll('button').find((b) => b.text() === en.manual.print).trigger('click');
        expect(print).toHaveBeenCalledOnce();
    });

    it('renders a link to an app page as a normal link and escapes angle brackets', () => {
        const manual = {
            categories: [{ id: 'c', title: 'C' }],
            topics: [{ id: 't', category: 'c', order: 1, title: 'T', summary: 'S', adminOnly: false, sections: [{ id: 's', title: 'Sec', adminOnly: false, blocks: [{ type: 'p', text: 'Go to [Security](/settings/security). <b>not bold</b> and [bad](javascript:x)' }] }] }],
        };
        show('en', { manual });
        const p = wrapper.find('#m-t--s-panel p');
        expect(p.find('a[href="/settings/security"]').exists()).toBe(true);
        expect(p.find('b').exists()).toBe(false);
        expect(p.text()).toContain('<b>not bold</b>');
        expect(p.findAll('a')).toHaveLength(1);
    });
});

describe('Manual page accessibility', () => {
    const expandAll = async () => {
        await wrapper.findAll('button').find((b) => b.text() === loadMessages(mockLocale).manual.expand_all).trigger('click');
    };

    it.each(['en', 'sk', 'pl'].flatMap((l) => ['light', 'dark'].map((th) => [l, th])))(
        'has no axe violations with everything expanded in %s, %s theme',
        async (locale, theme) => {
            document.documentElement.dataset.theme = theme;
            show(locale);
            await expandAll();
            await expectNoAxeViolations(wrapper.element);
        },
        60000,
    );

    it('has no axe violations as a member (no admin text) collapsed, at 320 px', async () => {
        window.innerWidth = 320;
        show('en', { admin: false });
        await expectNoAxeViolations(wrapper.element);
    }, 60000);

    it.each([320, 1280])('has no axe violations with 40% longer text at %i px, with the contents open', async (width) => {
        window.innerWidth = width;
        const manual = padManual(loadManual('en'));
        mockLocale = 'en';
        // Padded manual text and padded interface text together.
        const { mount } = await import('@vue/test-utils');
        const { createAppI18n } = await import('../../../resources/js/i18n.js');
        wrapper = mount(Manual, { props: manual, attachTo: document.body, global: { plugins: [createAppI18n('en', pseudoLong(loadMessages('en')))] } });
        await wrapper.findAll('button').find((b) => b.classes().includes('manual__toc-toggle')).trigger('click');
        await wrapper.findAll('.manual__buttons button')[0].trigger('click');
        await expectNoAxeViolations(wrapper.element);
    }, 60000);

    it('keeps a labelled search field, a labelled contents navigation and a single h1', () => {
        show();
        const input = wrapper.find('input[type=search]');
        expect(wrapper.find(`label[for="${input.attributes('id')}"]`).text()).toBe(en.manual.search_label);
        expect(wrapper.findAll('nav[aria-label]').some((n) => n.attributes('aria-label') === en.manual.on_this_page)).toBe(true);
        expect(wrapper.findAll('h1')).toHaveLength(1);
    });
});
