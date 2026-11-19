import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

const calls = { get: vi.fn() };
vi.mock('@inertiajs/vue3', () => ({ router: { get: (...a) => calls.get(...a), visit: vi.fn(), on: vi.fn() } }));

import GroupChips from '../../../resources/js/Wishlist/GroupChips.vue';

const GROUPS = [
    { id: 5, name: 'Grandma 80', mode: 'gifts', is_member: true },
    { id: 6, name: 'Cottage', mode: 'shared', is_member: false },
];
let current;
function mountChips(props = {}, locale = 'en') {
    current = mount(GroupChips, { props: { groups: GROUPS, selectedId: 5, canCreate: true, ...props }, attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });
    return current;
}
beforeEach(() => calls.get.mockReset());
afterEach(() => { current?.unmount(); document.body.innerHTML = ''; });

describe('GroupChips', () => {
    it('is a labelled group with one chip per list and aria-pressed on the selection', () => {
        const w = mountChips();
        expect(w.attributes('role')).toBe('group');
        expect(w.attributes('aria-label')).toBe('Group lists');
        const chips = w.findAll('button');
        expect(chips.slice(0, 2).map((c) => c.attributes('aria-pressed'))).toEqual(['true', 'false']);
        expect(chips[0].text()).toContain('Grandma 80');
    });

    it('navigates to ?group= through the guard and ignores the selected chip', async () => {
        const guard = vi.fn((next) => next());
        const w = mountChips({ guard });
        await w.findAll('button')[0].trigger('click');
        expect(calls.get).not.toHaveBeenCalled();
        await w.findAll('button')[1].trigger('click');
        expect(guard).toHaveBeenCalled();
        expect(calls.get).toHaveBeenCalledWith('/wishlist', { group: 6 }, expect.objectContaining({ preserveState: false, replace: true }));
    });

    it('shows New group list only with canCreate and emits create', async () => {
        const w = mountChips();
        const add = w.findAll('button').find((b) => b.text() === 'New group list');
        expect(add).toBeTruthy();
        await add.trigger('click');
        expect(w.emitted('create')).toHaveLength(1);
        w.unmount();
        const w2 = mountChips({ canCreate: false });
        expect(w2.text()).not.toContain('New group list');
    });

    it('renders only the button without lists and nothing at all without both', () => {
        expect(mountChips({ groups: [] }).findAll('button').map((b) => b.text())).toEqual(['New group list']);
        current.unmount();
        const w = mountChips({ groups: [], canCreate: false });
        expect(w.html()).toBe('<!--v-if-->');
    });

    it('has no axe violations in every language', async () => {
        for (const locale of ['en', 'sk', 'pl']) {
            mountChips({}, locale);
            await expectNoAxeViolations(document.body);
            current.unmount();
            document.body.innerHTML = '';
        }
    });
});
