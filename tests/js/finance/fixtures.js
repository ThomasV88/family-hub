// Fixture props for the finance components; the shapes follow the plan 05-08 props contract.
export const ACCOUNTS = [
    { id: 'acc-main', name: 'Household', type: 'bank', currency: 'EUR' },
    { id: 'acc-cash', name: 'Wallet', type: 'cash', currency: 'EUR' },
    { id: 'acc-zl', name: 'Polish card', type: 'card', currency: 'PLN' },
];

export const CATEGORIES = [
    { id: 'cat-groc', kind: 'expense', defaultKey: 'groceries', name: null },
    { id: 'cat-rent', kind: 'expense', defaultKey: 'housing', name: null },
    { id: 'cat-custom', kind: 'expense', defaultKey: null, name: 'Pets' },
    { id: 'cat-sal', kind: 'income', defaultKey: 'salary', name: null },
];

export const finance = (extra = {}) => ({
    accounts: ACCOUNTS,
    selectedAccount: 'all',
    month: '2026-03',
    today: '2026-03-14',
    timezone: 'Europe/Bratislava',
    canEdit: true,
    isAdmin: false,
    categories: CATEGORIES,
    currencies: ['EUR', 'PLN'],
    ...extra,
});

export const row = (id, extra = {}) => ({
    id,
    kind: 'expense',
    amountMinor: '-1234',
    currency: 'EUR',
    bookedOn: '2026-03-12',
    description: `Payment ${id}`,
    categoryId: 'cat-groc',
    accountId: 'acc-main',
    accountName: 'Household',
    transfer: null,
    source: 'manual',
    createdAt: '2026-03-12T10:00:00Z',
    ...extra,
});

export const ROWS = [
    row('t1', { description: 'Weekly shop', amountMinor: '-4590' }),
    row('t2', { kind: 'income', description: 'March salary', amountMinor: '250000', categoryId: 'cat-sal', bookedOn: '2026-03-12' }),
    row('t3', { kind: 'transfer', description: 'To wallet', amountMinor: '5000', categoryId: null, bookedOn: '2026-03-10', transfer: { fromId: 'acc-main', fromName: 'Household', toId: 'acc-cash', toName: 'Wallet' } }),
    row('t4', { description: 'Bank import line', source: 'import', categoryId: null, bookedOn: '2026-03-10', amountMinor: '-999' }),
];

export const panel = (extra = {}) => ({ ...row('t1', { description: 'Weekly shop', amountMinor: '-4590' }), note: 'Fruit', editable: true, match: null, ...extra });

export const transactionsPage = (extra = {}) => ({
    finance: finance(),
    filters: { q: '', type: 'all', category: 'all', source: 'all' },
    transactions: { total: ROWS.length, page: 1, hasMore: false },
    transactionItems: ROWS,
    panelTransaction: null,
    ...extra,
});

export const accountsPage = (extra = {}) => ({
    finance: finance(),
    accounts: [
        { ...ACCOUNTS[0], balanceMinor: '152340' },
        { ...ACCOUNTS[1], balanceMinor: '-2500' },
        { ...ACCOUNTS[2], balanceMinor: '10000' },
    ],
    ...extra,
});
