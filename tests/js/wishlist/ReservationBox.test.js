import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { pseudoLong } from '../helpers/pseudoLong.js';
import { wish } from './fixtures.js';
import { contributor, reservation, reserved, OPEN_BY_OTHER } from './givingFixtures.js';

const page = reactive({ props: { flash: { data: null } } });
vi.mock('@inertiajs/vue3', () => ({
    router: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), visit: vi.fn(), on: vi.fn() },
    usePage: () => page,
}));
import { router } from '@inertiajs/vue3';
import ReservationBox from '../../../resources/js/Wishlist/ReservationBox.vue';
import GiftWishCard from '../../../resources/js/Wishlist/GiftWishCard.vue';

let current;
function mountBox(w, props = {}, { locale = 'en', messages } = {}) {
    current = mount(ReservationBox, { props: { wish: w, ownerName: 'Adam', canReserve: true, ...props }, attachTo: document.body, global: { plugins: [createAppI18n(locale, messages ?? loadMessages(locale))] } });
    return current;
}
const buttons = () => [...document.body.querySelectorAll('button')].map((b) => b.textContent.trim());
const click = async (label) => {
    [...document.body.querySelectorAll('button')].find((b) => b.textContent.trim() === label).click();
    await flushPromises();
};
beforeEach(() => { page.props.flash = { data: null }; vi.clearAllMocks(); });
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; });

const FREE = wish(1, { title: 'Free', price: '100.00' });
const MINE = reserved(2, { is_mine: true, organizer: { id: 2, name: 'Mira' } });
const MINE_OPEN = reserved(3, { ...OPEN_BY_OTHER, is_mine: true, organizer: { id: 2, name: 'Mira' }, people: 2 });
const OTHER = reserved(4);
const OTHER_OPEN = reserved(5, OPEN_BY_OTHER);
const OTHER_JOINED = reserved(6, { ...OPEN_BY_OTHER, i_joined: true, my_amount: '20.00', contributors: [...OPEN_BY_OTHER.contributors, contributor(2, 'Mira', { amount: '20.00', is_me: true })], pledged: '60.00', people: 3 });
const CLOSED = reserved(7, { ...OPEN_BY_OTHER, cobuy_state: 'closed' });
const CLOSED_MINE = reserved(8, { ...OPEN_BY_OTHER, cobuy_state: 'closed', is_mine: true, organizer: { id: 2, name: 'Mira' } });

describe('ReservationBox states', () => {
    it.each([
        ['free', FREE, 'Nobody is buying this yet.', ['Reserve gift']],
        ['mine', MINE, 'You are buying this.', ['Open for co-buying', 'Cancel reservation']],
        ['mine open', MINE_OPEN, 'You are buying this. Co-buying is open.', ['Close co-buying', 'Cancel reservation']],
        ['other', OTHER, 'Petra is buying this.', []],
        ['other open', OTHER_OPEN, 'Petra is buying this. Co-buying is open.', ['Join co-buy']],
        ['other joined', OTHER_JOINED, 'Petra is buying this. Co-buying is open.', ['Change my share', 'Leave co-buy']],
        ['closed', CLOSED, 'Petra is buying this. Co-buying is closed.', []],
        ['closed mine', CLOSED_MINE, 'You are buying this.', ['Open for co-buying', 'Cancel reservation']],
    ])('renders %s', (_n, w, text, actions) => {
        mountBox(w);
        const box = document.body.querySelector('[data-testid="reservation-box"]');
        expect(box.getAttribute('aria-live')).toBe('polite');
        expect(document.body.textContent).toContain('Reservation');
        expect(box.textContent).toContain(text);
        expect(buttons()).toEqual(actions);
    });

    it('free state names the owner in the hint', () => {
        mountBox(FREE);
        expect(document.body.textContent).toContain('not to Adam');
    });

    it('renders no action buttons when the viewer cannot reserve', () => {
        for (const w of [FREE, MINE, MINE_OPEN, OTHER_OPEN, OTHER_JOINED, CLOSED_MINE]) {
            mountBox(w, { canReserve: false });
            expect(buttons()).toEqual([]);
            current.unmount();
        }
    });

    it('lists contributors with the organizer first and pledge progress from server values', () => {
        mountBox(OTHER_OPEN);
        const rows = [...document.body.querySelectorAll('[data-testid="contributors"] li')].map((li) => li.textContent.replace(/\s+/g, ' ').trim());
        expect(rows[0]).toMatch(/Petra\s*\(organizer\)/);
        expect(rows[0]).toContain(new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'EUR' }).format(40));
        expect(rows[1]).toContain('no amount');
        const bar = document.body.querySelector('[role="progressbar"]');
        expect(bar.getAttribute('aria-valuenow')).toBe('40');
        expect(bar.getAttribute('aria-valuemax')).toBe('100');
        expect(document.body.querySelector('[data-testid="pledge"]').textContent).toContain('Pledged');
    });

    it('shows "n in" without a price and no progress bar', () => {
        mountBox(reserved(9, OPEN_BY_OTHER, { price: null }));
        expect(document.body.querySelector('[role="progressbar"]')).toBeNull();
        expect(document.body.textContent).toContain('2 people in');
    });

    it('over-pledge shows no danger styling', () => {
        mountBox(reserved(10, { ...OPEN_BY_OTHER, pledged: '150.00' }));
        expect(document.body.querySelector('[data-testid="pledge"]').textContent).toContain('Pledged');
        expect(document.body.querySelector('.ui-alert--danger')).toBeNull();
    });
});

describe('ReservationBox actions', () => {
    it('reserve posts with preserveScroll', async () => {
        mountBox(FREE);
        await click('Reserve gift');
        expect(router.post).toHaveBeenCalledWith('/wishlist/wishes/1/reservation', {}, expect.objectContaining({ preserveScroll: true }));
    });

    it('cancel deletes without confirm when alone', async () => {
        mountBox(MINE);
        await click('Cancel reservation');
        expect(router.delete).toHaveBeenCalledWith('/wishlist/wishes/2/reservation', expect.objectContaining({ preserveScroll: true }));
    });

    it('cancel with co-buyers asks first, default Keep reservation', async () => {
        mountBox(MINE_OPEN);
        await click('Cancel reservation');
        expect(router.delete).not.toHaveBeenCalled();
        const dialog = document.body.querySelector('[role="dialog"], dialog');
        expect(dialog.textContent).toContain('Keep reservation');
        const confirm = [...dialog.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Cancel reservation');
        expect(confirm.className).toContain('ui-button--danger');
        confirm.click();
        await flushPromises();
        expect(router.delete).toHaveBeenCalledWith('/wishlist/wishes/3/reservation', expect.anything());
    });

    it('open and close co-buy', async () => {
        mountBox(MINE);
        await click('Open for co-buying');
        expect(router.post).toHaveBeenCalledWith('/wishlist/wishes/2/reservation/cobuy', {}, expect.anything());
        current.unmount();
        mountBox(MINE_OPEN);
        await click('Close co-buying');
        expect(router.delete).toHaveBeenCalledWith('/wishlist/wishes/3/reservation/cobuy', expect.anything());
    });

    it('joins with an optional share through the inline form', async () => {
        mountBox(OTHER_OPEN);
        await click('Join co-buy');
        const input = document.body.querySelector('input');
        expect(document.body.textContent).toContain('Your share (optional)');
        expect(document.body.querySelector('[role="dialog"]')).toBeNull();
        input.value = '12,50';
        input.dispatchEvent(new Event('input'));
        document.body.querySelector('form').dispatchEvent(new Event('submit'));
        await flushPromises();
        expect(router.put).toHaveBeenCalledWith('/wishlist/wishes/5/reservation/contribution', { amount: '12.50' }, expect.objectContaining({ preserveScroll: true }));
    });

    it('joins without an amount and rejects an invalid one without a request', async () => {
        mountBox(OTHER_OPEN);
        await click('Join co-buy');
        const input = document.body.querySelector('input');
        input.value = 'abc';
        input.dispatchEvent(new Event('input'));
        document.body.querySelector('form').dispatchEvent(new Event('submit'));
        await flushPromises();
        expect(router.put).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain('Enter an amount above 0');
        input.value = '';
        input.dispatchEvent(new Event('input'));
        document.body.querySelector('form').dispatchEvent(new Event('submit'));
        await flushPromises();
        expect(router.put).toHaveBeenCalledWith('/wishlist/wishes/5/reservation/contribution', { amount: null }, expect.anything());
    });

    it('change share prefills, cancel closes the form, leave deletes', async () => {
        mountBox(OTHER_JOINED);
        await click('Change my share');
        expect(document.body.querySelector('input').value).toBe('20.00');
        expect(buttons()).toContain('Save my share');
        await click('Cancel');
        expect(document.body.querySelector('form')).toBeNull();
        await click('Leave co-buy');
        expect(router.delete).toHaveBeenCalledWith('/wishlist/wishes/6/reservation/contribution', expect.anything());
    });

    it('sets aria-busy during the request', async () => {
        router.post.mockImplementation((_u, _d, o) => o.onStart());
        mountBox(FREE);
        await click('Reserve gift');
        expect(document.body.querySelector('button').getAttribute('aria-busy')).toBe('true');
    });
});

describe('ReservationBox alerts', () => {
    it('shows the warning conflict alert for this wish only', () => {
        page.props.flash = { data: { reservation_conflict: { wish_id: 1, name: 'Petra' } } };
        mountBox(FREE);
        const alert = document.body.querySelector('[data-testid="res-conflict"]');
        expect(alert.getAttribute('role')).toBe('status');
        expect(alert.textContent).toContain('Petra just reserved this gift. Nothing was changed.');
        current.unmount();
        page.props.flash = { data: { reservation_conflict: { wish_id: 99, name: 'Petra' } } };
        mountBox(FREE);
        expect(document.body.querySelector('[data-testid="res-conflict"]')).toBeNull();
    });

    it('shows the danger alert on a server error', async () => {
        router.post.mockImplementation((_u, _d, o) => o.onError({}));
        mountBox(FREE);
        await click('Reserve gift');
        const alert = document.body.querySelector('[data-testid="res-error"]');
        expect(alert.getAttribute('role')).toBe('alert');
        expect(alert.textContent).toContain('We could not update the reservation');
    });
});

describe('GiftWishCard status tag', () => {
    const card = (w, locale = 'en') => mount(GiftWishCard, { props: { wish: w }, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });
    const text = (w) => card(w).find('[data-testid="card-status"]').text();
    it('renders exactly one tag per state', () => {
        expect(text(FREE)).toBe('Available');
        expect(text(MINE)).toBe('Reserved by you');
        expect(text(OTHER)).toBe('Reserved by Petra');
        expect(text(OTHER_OPEN)).toBe('Co-buy, 2 people');
        expect(card(FREE).findAll('[data-testid="card-status"] .ui-tag')).toHaveLength(1);
    });
});

describe('ReservationBox a11y', () => {
    const states = { FREE, MINE_OPEN, OTHER, OTHER_JOINED, CLOSED };
    for (const locale of ['en', 'sk', 'pl']) {
        for (const theme of ['light', 'dark']) {
            it(`is axe clean in ${locale} ${theme}`, async () => {
                document.documentElement.dataset.theme = theme;
                for (const w of Object.values(states)) {
                    mountBox(w, {}, { locale });
                    await expectNoAxeViolations(document.body);
                    current.unmount();
                }
                delete document.documentElement.dataset.theme;
            });
        }
    }

    it('survives pseudo-long strings', async () => {
        mountBox(OTHER_JOINED, {}, { messages: pseudoLong(loadMessages('en')) });
        await expectNoAxeViolations(document.body);
    });
});
