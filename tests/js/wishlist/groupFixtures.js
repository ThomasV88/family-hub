import { wish } from './fixtures.js';
import { OPEN_BY_OTHER, reserved } from './givingFixtures.js';

// Group list props as ListPage::groupProps / GiverListPage::renderGroup send them (16-03, 16-04).
export const GROUP_MEMBERS = [{ id: 2, name: 'Mira' }, { id: 3, name: 'Zora' }];

const base = (mode, extra = {}, groupExtra = {}) => ({
    kind: 'group',
    owner: { id: null, name: 'Grandma 80' },
    group: { id: 7, name: 'Grandma 80', mode, is_member: true, can_manage: true, members: GROUP_MEMBERS, ...groupExtra },
    viewer_is_recipient: mode === 'gifts',
    viewer_is_manager: false,
    can_edit: true,
    wishes: [wish(41, { title: 'Garden chair' }), wish(42, { title: 'Tea set', priority: 'top' })],
    received: [wish(43, { title: 'Old lamp', received: true, received_by: { id: 3, name: 'Zora' } })],
    ...extra,
});

// Gifts mode, viewer is a member: reservation-blind, recipient side.
export const giftsMemberList = (extra = {}, groupExtra = {}) => base('gifts', extra, groupExtra);

// Shared mode, viewer is a member: reservations visible.
export const sharedMemberList = (extra = {}, groupExtra = {}) => base('shared', {
    viewer_can_reserve: true,
    wishes: [
        wish(41, { title: 'Garden chair' }),
        reserved(42, { is_mine: true, organizer: { id: 2, name: 'Mira' } }),
        reserved(44, OPEN_BY_OTHER),
    ],
    ...extra,
}, groupExtra);

// Gifts mode, viewer is NOT a member: giver view with reservations.
export const giftsNonMemberList = (extra = {}, groupExtra = {}) => base('gifts', {
    viewer_is_recipient: false,
    can_edit: false,
    viewer_can_reserve: true,
    wishes: [wish(41, { title: 'Garden chair' }), reserved(42, { is_mine: false })],
    received: undefined,
    ...extra,
}, { is_member: false, can_manage: false, ...groupExtra });

// A member who may not manage or edit (view only).
export const viewOnlyMemberList = (mode = 'gifts') => base(mode, { can_edit: false }, { can_manage: false });
