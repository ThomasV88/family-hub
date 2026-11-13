import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { createAppI18n } from '../../resources/js/i18n.js';

let mockProps;
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: mockProps }), router: { post: vi.fn(), visit: vi.fn() } }));
const AccountMenu = (await import('../../resources/js/Shell/AccountMenu.vue')).default;
const messages = JSON.parse(readFileSync(`${process.cwd()}/lang/en.json`, 'utf8'));

async function open(props) {
    mockProps = { locale: 'en', ...props };
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
