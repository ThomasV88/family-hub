import { wish } from './fixtures.js';

// Reservation contract of plan 04-08 (giver lists only).
export const contributor = (id, name, extra = {}) => ({ id, name, amount: null, is_organizer: false, is_me: false, ...extra });

export const reservation = (extra = {}) => ({
    organizer: { id: 5, name: 'Petra' },
    is_mine: false,
    cobuy_state: 'none',
    contributors: [contributor(5, 'Petra', { is_organizer: true })],
    pledged: null,
    people: 1,
    i_joined: false,
    my_amount: null,
    ...extra,
});

export const reserved = (id, resExtra = {}, wishExtra = {}) => wish(id, { title: `Gift ${id}`, price: '100.00', reservation: reservation(resExtra), ...wishExtra });

export const OPEN_BY_OTHER = {
    cobuy_state: 'open',
    contributors: [contributor(5, 'Petra', { is_organizer: true, amount: '40.00' }), contributor(6, 'Jano')],
    pledged: '40.00',
    people: 2,
};

export const giverListWithReservations = (extra = {}) => ({
    owner: { id: 1, name: 'Adam' },
    viewer_is_recipient: false,
    viewer_is_manager: false,
    can_edit: false,
    viewer_can_reserve: true,
    wishes: [
        wish(31, { title: 'Free gift' }),
        reserved(32, { is_mine: true, organizer: { id: 2, name: 'Mira' } }),
        reserved(33, OPEN_BY_OTHER),
        reserved(34),
    ],
    ...extra,
});
