// Fixture props for the wishlist components; the shapes follow the plan 04-05 contract.
export const image = (extra = {}) => ({ status: 'none', thumb_url: null, full_url: null, source: null, host: null, ...extra });

export const wish = (id, extra = {}) => ({
    id,
    title: `Wish ${id}`,
    notes: null,
    price: null,
    currency: 'EUR',
    size_notes: null,
    priority: 'love',
    urls: [],
    image: image(),
    received: false,
    ...extra,
});

export const link = (id, extra = {}) => ({ id, url: `https://shop${id}.example/item`, label: null, host: `shop${id}.example`, ...extra });

export const MEMBERS = [
    { id: 2, name: 'Mira', is_viewer: true },
    { id: 1, name: 'Adam', is_viewer: false },
    { id: 3, name: 'Zora', is_viewer: false },
];

export const WISHES = [
    wish(11, { title: 'Blue backpack', price: '49.90', priority: 'top', urls: [link(1), link(2, { label: 'Outlet' })], notes: 'Line one\nLine two', size_notes: 'M' }),
    wish(12, { title: 'Book', price: '12.50' }),
    wish(13, { title: 'A very long wish title that keeps going on and on to prove that the clamp class is present' }),
];

export const ownList = (extra = {}) => ({
    owner: { id: 2, name: 'Mira' },
    viewer_is_recipient: true,
    viewer_is_manager: false,
    can_edit: true,
    wishes: WISHES,
    received: [],
    ...extra,
});

export const managedList = (extra = {}) => ({
    owner: { id: 3, name: 'Zora' },
    viewer_is_recipient: true,
    viewer_is_manager: true,
    can_edit: true,
    wishes: WISHES,
    received: [wish(21, { title: 'Old toy', received: true })],
    ...extra,
});

export const giverList = (extra = {}) => ({
    owner: { id: 1, name: 'Adam' },
    viewer_is_recipient: false,
    viewer_is_manager: false,
    can_edit: false,
    wishes: WISHES,
    ...extra,
});
