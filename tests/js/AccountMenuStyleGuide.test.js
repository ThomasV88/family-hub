import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { createAppI18n } from '../../resources/js/i18n.js';

let mockProps;
const post = vi.fn();
const visit = vi.fn();
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: mockProps }), router: { post: (...a) => post(...a), visit: (...a) => visit(...a) } }));
const AccountMenu = (await import('../../resources/js/Shell/AccountMenu.vue')).default;
const messages = JSON.parse(readFileSync(`${process.cwd()}/lang/en.json`, 'utf8'));

async function open(props) {
    mockProps = { locale: 'en', auth: { user: { id: 1, name: 'Anna Nováková', email: 'anna@example.test', locale: 'sk', isAdmin: true } }, ...props };
    const w = mount(AccountMenu, { attachTo: document.body, global: { plugins: [createAppI18n('en', messages)] } });
    await w.find('button').trigger('click');
    return w;
}

describe('IN-01 style guide link', () => {
    it('is shown only when the server flag says so, not by env name', async () => {
        const on = await open({ app: { name: 'FH', env: 'local', styleGuide: true } });
        expect(document.body.textContent).toContain(messages.nav.style_guide);
        on.unmount();
        const off = await open({ app: { name: 'FH', env: 'qa', styleGuide: false } });
        expect(document.body.textContent).not.toContain(messages.nav.style_guide);
        off.unmount();
    });
});

describe('account header and log out', () => {
    it('shows name and email in a non-interactive header, then Log out last', async () => {
        const w = await open({ app: { name: 'FH', env: 'local', styleGuide: false } });
        const header = document.querySelector('[data-testid=menu-header]');
        expect(header.textContent).toContain('Anna Nováková');
        expect(header.textContent).toContain('anna@example.test');
        expect(header.closest('[role=menuitem]')).toBeNull();
        const entries = [...document.querySelectorAll('[role=menuitem],[role=menuitemradio]')];
        expect(entries.at(-1).textContent).toContain('Log out');
        w.unmount();
    });

    it('lists Settings first, before Language and Theme, and navigates to /settings', async () => {
        const w = await open({ app: { name: 'FH', env: 'local', styleGuide: false } });
        const entries = [...document.querySelectorAll('[role=menuitem],[role=menuitemradio]')];
        expect(entries[0].textContent).toContain('Settings');
        const settings = [...document.querySelectorAll('[role=menuitem]')].find((e) => e.textContent.includes('Settings'));
        settings.click();
        expect(visit).toHaveBeenCalledWith('/settings');
        w.unmount();
    });

    it('posts /logout when Log out is chosen', async () => {
        const w = await open({ app: { name: 'FH', env: 'local', styleGuide: false } });
        const logout = [...document.querySelectorAll('[role=menuitem]')].find((e) => e.textContent.includes('Log out'));
        logout.click();
        expect(post).toHaveBeenCalledWith('/logout');
        w.unmount();
    });

    it('has no header or Log out for a guest', async () => {
        const w = await open({ auth: { user: null }, app: { name: 'FH', env: 'local', styleGuide: false } });
        expect(document.querySelector('[data-testid=menu-header]')).toBeNull();
        expect(document.body.textContent).not.toContain('Log out');
        w.unmount();
    });
});
