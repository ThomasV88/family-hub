import { describe, it, vi, afterEach } from 'vitest';
import { mountUi, loadMessages } from './helpers/mount.js';
import { expectNoAxeViolations } from './helpers/axe.js';
import { pseudoLong } from './helpers/pseudoLong.js';
import { mount } from '@vue/test-utils';
import { createAppI18n } from '../../resources/js/i18n.js';

let mockLocale = 'en';
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({
            props: {
                locale: mockLocale,
                app: { name: 'Family Hub', env: 'local' },
                heartbeat: { last_recorded_at: '2026-11-13T10:00:00+00:00', last_source: 'schedule', count_24h: 3, expected_24h: 1440 },
            },
        }),
        router: { post: vi.fn(), visit: vi.fn(), on: vi.fn() },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});

import StyleGuide from '../../resources/js/Pages/StyleGuide.vue';
import Home from '../../resources/js/Pages/Home.vue';
import AppShell from '../../resources/js/Layouts/AppShell.vue';

let wrapper;
const originalWidth = window.innerWidth;
afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    delete document.documentElement.dataset.theme;
    window.innerWidth = originalWidth;
});

const pages = { StyleGuide, Home };
const cases = [];
for (const locale of ['en', 'sk', 'pl'])
    for (const theme of ['light', 'dark'])
        for (const width of [390, 1280])
            for (const name of Object.keys(pages)) cases.push([name, locale, theme, width]);

describe('accessibility sweep', () => {
    it.each(cases)('%s in %s, %s theme, %ipx has no axe violations', async (name, locale, theme, width) => {
        mockLocale = locale;
        document.documentElement.dataset.theme = theme;
        window.innerWidth = width;
        wrapper = mountUi(pages[name], { locale });
        await expectNoAxeViolations(wrapper.element);
    });

    it.each([390, 1280])('AppShell with pseudo-long English text at %ipx has no axe violations', async (width) => {
        mockLocale = 'en';
        window.innerWidth = width;
        wrapper = mount(AppShell, {
            attachTo: document.body,
            slots: { default: '<h1>Title</h1>' },
            global: { plugins: [createAppI18n('en', pseudoLong(loadMessages('en')))] },
        });
        await expectNoAxeViolations(wrapper.element);
    });
});
