import { describe, it, expect, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import GiftWishCard from '../../../resources/js/Wishlist/GiftWishCard.vue';
import ContributorList from '../../../resources/js/Wishlist/ContributorList.vue';
import ReservationBox from '../../../resources/js/Wishlist/ReservationBox.vue';

let w;
const plugins = () => [createAppI18n('en', loadMessages('en'))];
afterEach(() => { w?.unmount(); w = null; });

describe('group list gaps (16-10)', () => {
    it('shows only the Received tag on a received wish (no Available status)', () => {
        w = mount(GiftWishCard, { props: { wish: { id: 1, title: 'Book', received: true, reservation: null, urls: [] } }, global: { plugins: plugins() } });
        expect(w.text()).toContain('Received');
        expect(w.text()).not.toContain('Available');
    });

    it('puts a space before the organizer label', () => {
        w = mount(ContributorList, {
            props: { reservation: { contributors: [{ id: 1, name: 'Name', is_organizer: true, amount: null }], people: 1 }, price: null, currency: 'EUR' },
            global: { plugins: plugins() },
        });
        expect(w.find('.contributors__name').text()).toBe('Name (organizer)');
    });

    it('hides the personal-list name hint when showOwnerHint is false', () => {
        const props = { wish: { id: 1, title: 'x', reservation: null }, ownerName: 'Grandma', canReserve: true };
        w = mount(ReservationBox, { props: { ...props, showOwnerHint: false }, global: { plugins: plugins(), stubs: { UiCard: false } } });
        expect(w.text()).not.toContain('Your name will be visible');
        w.unmount();
        w = mount(ReservationBox, { props, global: { plugins: plugins() } });
        expect(w.text()).toContain('Your name will be visible');
    });
});
