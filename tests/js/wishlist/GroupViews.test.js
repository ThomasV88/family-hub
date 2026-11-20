import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { pseudoLong } from '../helpers/pseudoLong.js';
import { MEMBERS } from './fixtures.js';
import { giftsMemberList, sharedMemberList, giftsNonMemberList, viewOnlyMemberList } from './groupFixtures.js';

let mockProps = {};
const calls = { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() };
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({ props: mockProps, get url() { return '/wishlist'; } }),
        router: { get: (...a) => calls.get(...a), post: (...a) => calls.post(...a), put: (...a) => calls.put(...a), patch: (...a) => calls.patch(...a), delete: (...a) => calls.delete(...a), visit: vi.fn(), on: vi.fn() },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});

import Index from '../../../resources/js/Pages/Wishlist/Index.vue';
import OwnWishPanel from '../../../resources/js/Wishlist/OwnWishPanel.vue';
import GiftWishPanel from '../../../resources/js/Wishlist/GiftWishPanel.vue';

let current;
const GROUPS = [{ id: 7, name: 'Grandma 80', mode: 'gifts', is_member: true }];
async function mountIndex(list, { selected = null, locale = 'en', messages } = {}) {
    globalThis.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ groups: [] }) }));
    window.innerWidth = 1280;
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    mockProps = reactive({
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { todo: 'none', wishlist: 'edit', finance: 'none', health: 'none' } },
        app: { env: 'local', styleGuide: false },
        list,
    });
    current = mount(Index, { props: { members: MEMBERS, selected, list, groups: GROUPS, group_candidates: [] }, attachTo: document.body, global: { plugins: [createAppI18n(locale, messages ?? loadMessages(locale))] } });
    await flushPromises();
    return current;
}
beforeEach(() => Object.values(calls).forEach((f) => f.mockReset()));
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; delete globalThis.fetch; });

const bodyBtn = (text) => [...document.body.querySelectorAll('button')].find((b) => b.textContent.trim() === text);

describe('gifts-mode member view (recipient side)', () => {
    it('renders the own list with the group header and no reservation data', async () => {
        const list = giftsMemberList();
        const injected = { ...list, wishes: list.wishes.map((w) => ({ ...w, reservation: { organizer: { name: 'ZxCanaryGiver' } }, reserved_by: 'ZxCanaryGiver' })) };
        const w = await mountIndex(injected);
        expect(w.find('[data-testid="own-list"]').exists()).toBe(true);
        expect(w.find('[data-testid="shared-list"]').exists()).toBe(false);
        const header = w.find('[data-testid="group-header"]');
        expect(header.text()).toContain('Grandma 80');
        expect(header.find('[data-testid="group-mode-tag"]').text()).toBe('Gifts from others');
        expect(header.text()).toContain('Members: Mira and Zora');
        expect(header.text()).toContain('You never see what others reserved');
        expect(bodyBtn('Manage list')).toBeTruthy();
        expect(w.find('[data-testid^="res-"]').exists()).toBe(false);
        expect(document.body.innerHTML).not.toContain('ZxCanaryGiver');
        expect(w.find('[data-testid="card-status"]').exists()).toBe(false);
        expect(w.find('[data-testid="add-tile"]').text()).toBe('Add wish');
        expect(w.find('[data-testid="shopping-summary"]').exists()).toBe(false);
        expect(globalThis.fetch).not.toHaveBeenCalled();
    });

    it('Manage list opens the group panel in edit mode', async () => {
        const w = await mountIndex(giftsMemberList());
        await w.find('[data-testid="group-manage"]').trigger('click');
        await flushPromises();
        expect(document.body.querySelector('[data-testid="group-panel"]') ?? document.body.textContent).toBeTruthy();
        expect(document.body.textContent).toContain('Save list');
    });
});

describe('shared-mode member view', () => {
    it('shows reservation tags, the add tile, banner and received names', async () => {
        const w = await mountIndex(sharedMemberList());
        expect(w.find('[data-testid="shared-list"]').exists()).toBe(true);
        expect(w.findAll('[data-testid="gallery"] [data-testid="card-status"]').map((n) => n.text())).toEqual(['Available', 'Reserved by you', 'Co-buy, 2 people']);
        expect(w.find('[data-testid="add-tile"]').exists()).toBe(true);
        expect(w.find('[data-testid="group-banner"]').text()).toContain('Everyone in this group sees who reserved or bought what');
        expect(w.find('[data-testid="group-mode-tag"]').text()).toBe('Shared purchases');
        await w.find('.received__toggle').trigger('click');
        expect(w.find('[data-testid="received-by"]').text()).toBe('Received by Zora');
    });

    it('opens the wish with a reservation box, Edit wish switches to the edit form and closing returns', async () => {
        const list = sharedMemberList();
        const w = await mountIndex(list, { selected: 44 });
        expect(document.body.querySelector('[data-testid="gift-wish-panel"]')).toBeTruthy();
        expect(document.body.textContent).toContain('Co-buy');
        expect(document.body.querySelector('[data-testid="own-wish-panel"]')).toBeFalsy();
        bodyBtn('Edit wish').click();
        await flushPromises();
        expect(document.body.querySelector('[data-testid="own-wish-panel"]')).toBeTruthy();
        expect(w.findComponent(OwnWishPanel).props('open')).toBe(true);
        expect(w.findComponent(GiftWishPanel).props('open')).toBe(false);
        expect(document.body.querySelector('[data-testid="own-wish-panel"] input').value).toBe('Gift 44');
    });

    it('add opens the create form for the group', async () => {
        const w = await mountIndex(sharedMemberList(), { selected: 'new' });
        const panel = w.findComponent(OwnWishPanel);
        expect(panel.props('open')).toBe(true);
        expect(panel.props('groupId')).toBe(7);
        expect(panel.props('ownerId')).toBe(null);
        expect(document.body.querySelector('h2.ui-panel__title').textContent).toBe('Add wish to Grandma 80');
    });
});

describe('gifts-mode non-member view', () => {
    it('renders the giver view titled Group list with member names and no add or manage', async () => {
        const w = await mountIndex(giftsNonMemberList());
        expect(w.find('[data-testid="gift-list"]').exists()).toBe(true);
        expect(w.find('h2').text()).toBe('Grandma 80, group list');
        expect(w.find('[data-testid="group-members"]').text()).toBe('Members: Mira and Zora');
        expect(w.find('[data-testid="group-header"]').exists()).toBe(false);
        expect(w.find('[data-testid="add-tile"]').exists()).toBe(false);
        expect(bodyBtn('Manage list')).toBeUndefined();
        expect(w.findAll('[data-testid="card-status"]').map((n) => n.text())).toEqual(['Available', 'Reserved by Petra']);
    });
});

describe('view-only member', () => {
    for (const mode of ['gifts', 'shared']) {
        it(`sees no add tile and no Manage list (${mode})`, async () => {
            const w = await mountIndex(viewOnlyMemberList(mode));
            expect(w.find('[data-testid="add-tile"]').exists()).toBe(false);
            expect(w.find('[data-testid="group-manage"]').exists()).toBe(false);
            expect(w.find('[data-testid="group-header"]').exists()).toBe(true);
        });
    }
});

describe('group views a11y and wrapping', () => {
    const CASES = { gifts: giftsMemberList, shared: sharedMemberList, giver: giftsNonMemberList };
    for (const [name, make] of Object.entries(CASES)) {
        for (const locale of ['en', 'sk', 'pl']) {
            it(`${name} view is axe clean in ${locale}`, async () => {
                await mountIndex(make(), { locale });
                await expectNoAxeViolations(document.body);
            });
        }
        it(`${name} view survives pseudo-long strings`, async () => {
            await mountIndex(make(), { messages: pseudoLong(loadMessages('en')) });
            await expectNoAxeViolations(document.body);
        });
    }
});

describe('personal lists are unchanged', () => {
    it('a personal list shows no group header', async () => {
        const w = await mountIndex({ owner: { id: 2, name: 'Mira' }, viewer_is_recipient: true, viewer_is_manager: false, can_edit: true, wishes: [], received: [] });
        expect(w.find('[data-testid="group-header"]').exists()).toBe(false);
    });
});

describe('panels for group wishes', () => {
    const type = async (el, value) => { el.value = value; el.dispatchEvent(new Event('input', { bubbles: true })); await flushPromises(); };
    const mountPanel = (props) => mount(OwnWishPanel, { props: { open: true, mode: 'create', ...props }, attachTo: document.body, global: { plugins: [createAppI18n('en', loadMessages('en'))] } });

    it('create with groupId posts group_id and no owner_id', async () => {
        const w = mountPanel({ groupId: 7, ownerName: 'Grandma 80' });
        await flushPromises();
        await type(document.body.querySelector('input'), 'Lamp');
        bodyBtn('Save wish').click();
        await flushPromises();
        const [url, data] = calls.post.mock.calls[0];
        expect(url).toBe('/wishlist/wishes');
        expect(data.group_id).toBe(7);
        expect('owner_id' in data).toBe(false);
        w.unmount();
    });

    it('create with ownerId only posts owner_id as before', async () => {
        const w = mountPanel({ ownerId: 3, ownerName: 'Zora' });
        await flushPromises();
        await type(document.body.querySelector('input'), 'Lamp');
        bodyBtn('Save wish').click();
        await flushPromises();
        const data = calls.post.mock.calls[0][1];
        expect(data.owner_id).toBe(3);
        expect('group_id' in data).toBe(false);
        w.unmount();
    });

    it('a received group wish shows who marked it, a personal one shows nothing new', async () => {
        const group = mountPanel({ mode: 'edit', groupId: 7, wish: { id: 43, title: 'Old lamp', urls: [], image: { status: 'none' }, received: true, received_by: { id: 3, name: 'Zora' } } });
        await flushPromises();
        expect(document.body.querySelector('[data-testid="received-by"]').textContent).toBe('Received by Zora');
        group.unmount();
        const personal = mountPanel({ mode: 'edit', ownerId: 2, wish: { id: 21, title: 'Old toy', urls: [], image: { status: 'none' }, received: true } });
        await flushPromises();
        expect(document.body.querySelector('[data-testid="received-by"]')).toBeNull();
        personal.unmount();
    });

    it('GiftWishPanel shows Edit wish only when editable and emits the id', async () => {
        const wishProp = { id: 44, title: 'Gift 44', urls: [], image: { status: 'none' }, priority: 'love' };
        const plain = mount(GiftWishPanel, { props: { open: true, wish: wishProp }, attachTo: document.body, global: { plugins: [createAppI18n('en', loadMessages('en'))] } });
        await flushPromises();
        expect(bodyBtn('Edit wish')).toBeUndefined();
        plain.unmount();
        const editable = mount(GiftWishPanel, { props: { open: true, wish: wishProp, editable: true }, attachTo: document.body, global: { plugins: [createAppI18n('en', loadMessages('en'))] } });
        await flushPromises();
        bodyBtn('Edit wish').click();
        expect(editable.emitted('edit')).toEqual([[44]]);
        editable.unmount();
    });
});
