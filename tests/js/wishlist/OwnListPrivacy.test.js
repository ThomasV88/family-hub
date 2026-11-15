import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { WISHES, wish, ownList, managedList } from './fixtures.js';

vi.mock('@inertiajs/vue3', () => ({ router: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn(), on: vi.fn() } }));

import OwnListView from '../../../resources/js/Wishlist/OwnListView.vue';
import OwnWishPanel from '../../../resources/js/Wishlist/OwnWishPanel.vue';

// UI half of the UI-SPEC privacy contract: the recipient side has no path to render giver data.
const CANARY = { organizer: { name: 'ZxCanaryGiver' }, contributors: [{ name: 'ZxCanaryGiver', amount: '7777.00' }] };
const inject = (list) => ({
    ...list,
    viewer_can_reserve: true,
    shopping: [{ owner: { id: 1, name: 'ZxCanaryGiver' }, wishes: [{ id: 11, title: 'ZxCanaryGiver', share: '7777.00' }] }],
    wishes: list.wishes.map((w) => ({ ...w, reservation: CANARY, status: 'reserved', reserved_by: 'ZxCanaryGiver' })),
    received: list.received?.map((w) => ({ ...w, reservation: CANARY })),
});

let current;
const mounted = [];
function render(component, props, locale = 'en') {
    const w = mount(component, { props, attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });
    mounted.push(w);
    return w;
}
afterEach(() => { while (mounted.length) mounted.pop().unmount(); current = null; document.body.innerHTML = ''; sessionStorage.clear(); });

// The panel teleports to the body, so the comparable DOM is the whole body.
async function snapshot(component, props, locale = 'en') {
    const w = render(component, props, locale);
    await flushPromises();
    const html = document.body.innerHTML;
    w.unmount();
    mounted.pop();
    return html;
}

// Every rendered string of wishlist.status.* and wishlist.res.* (placeholders filled, every plural form).
const bannedPhrases = (locale) => {
    const { status, res } = loadMessages(locale).wishlist;
    return [...Object.values(status), ...Object.values(res)].flatMap((text) => String(text).replace(/\{[a-z]+\}/g, 'Zx').split(' | ').map((v) => v.replace(/\{n\}/g, '3')));
};

const CASES = {
    own: ownList(),
    'managed child': managedList(),
    'with received group': ownList({ received: [wish(31, { title: 'Done thing', received: true }), wish(32, { title: 'Other', received: true })] }),
};

describe('OwnListView privacy', () => {
    for (const [name, list] of Object.entries(CASES)) {
        it(`renders identical DOM with and without injected reservation data (${name})`, async () => {
            const plain = await snapshot(OwnListView, { list, selectedId: list.wishes[0].id });
            const leaky = await snapshot(OwnListView, { list: inject(list), selectedId: list.wishes[0].id });
            expect(leaky).toBe(plain);
            expect(leaky).not.toContain('ZxCanaryGiver');
            expect(leaky).not.toContain('7777');
        });
    }

    it('expands the received group identically too', async () => {
        const list = CASES['with received group'];
        const open = async (props) => {
            const w = render(OwnListView, props);
            await w.find('.received__toggle').trigger('click');
            await flushPromises();
            const html = document.body.innerHTML;
            w.unmount();
            mounted.pop();
            return html;
        };
        expect(await open({ list: inject(list) })).toBe(await open({ list }));
    });

    it('contains none of the status or reservation copy in en, sk and pl', async () => {
        for (const locale of ['en', 'sk', 'pl']) {
            const banned = bannedPhrases(locale);
            expect(banned.length).toBeGreaterThan(20);
            for (const list of Object.values(CASES)) {
                const w = render(OwnListView, { list: inject(list), selectedId: null }, locale);
                if (w.find('.received__toggle').exists()) await w.find('.received__toggle').trigger('click');
                const text = w.text();
                w.unmount();
                mounted.pop();
                for (const phrase of banned) expect(text, `${locale}: ${phrase}`).not.toContain(phrase);
            }
        }
    });
});

describe('OwnWishPanel privacy', () => {
    it('renders identical DOM for a wish with and without reservation data, in edit and create mode', async () => {
        const base = WISHES[0];
        const reserved = { ...base, reservation: CANARY, status: 'reserved', reserved_by: 'ZxCanaryGiver' };
        const props = (w, mode = 'edit') => ({ open: true, mode, wish: w, ownerId: 2, ownerName: 'Mira', managing: false, viewer_can_reserve: true, shopping: [{ name: 'ZxCanaryGiver' }] });
        const plain = await snapshot(OwnWishPanel, props(base));
        const leaky = await snapshot(OwnWishPanel, props(reserved));
        expect(leaky).toBe(plain);
        expect(leaky).not.toContain('ZxCanaryGiver');
        expect(leaky).not.toContain('7777');
        const created = await snapshot(OwnWishPanel, props(null, 'create'));
        expect(created).not.toContain('ZxCanaryGiver');
    });

    it('contains none of the status or reservation copy in en, sk and pl', async () => {
        for (const locale of ['en', 'sk', 'pl']) {
            const banned = bannedPhrases(locale);
            const html = await snapshot(OwnWishPanel, { open: true, mode: 'edit', wish: { ...WISHES[0], reservation: CANARY }, ownerId: 2, ownerName: 'Mira', managing: false }, locale);
            const text = html.replace(/<[^>]+>/g, ' ');
            for (const phrase of banned) expect(text, `${locale}: ${phrase}`).not.toContain(phrase);
        }
    });
});
