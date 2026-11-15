// Fixture props for the recurring UI; shapes follow the plan 05-10 props contract (Occ, items) and 05-13 (candidates).
import { finance } from './fixtures.js';

export const occ = (recurringId, dueDate, extra = {}) => ({
    key: `${recurringId}.${dueDate}`,
    recurringId,
    name: 'Rent',
    dueDate,
    expectedMinor: '65000',
    currency: 'EUR',
    accountId: 'acc-main',
    accountName: 'Household',
    categoryId: 'cat-rent',
    status: 'upcoming',
    match: null,
    ...extra,
});

export const MATCH = { transactionId: 't9', description: 'Landlord transfer', bookedOn: '2026-03-02', amountMinor: '-65000', accountName: 'Household', differenceMinor: '0', matchedBy: 'auto' };

export const OVERDUE = occ('r1', '2026-03-01', { status: 'overdue' });
export const PAID = occ('r2', '2026-03-05', { name: 'Internet', expectedMinor: '2500', status: 'paid', match: { ...MATCH, transactionId: 't2', description: 'Telekom', bookedOn: '2026-03-05', amountMinor: '-2500' } });
export const DIFFERENT = occ('r3', '2026-03-08', { name: 'Energy', expectedMinor: '8000', status: 'paid_differently', match: { ...MATCH, transactionId: 't3', description: 'Energy bill', bookedOn: '2026-03-09', amountMinor: '-8750', differenceMinor: '750' } });
export const UPCOMING = occ('r4', '2026-03-20', { name: 'Insurance', expectedMinor: '4200' });
export const UPCOMING_2 = occ('r5', '2026-03-20', { name: 'Gym', expectedMinor: '1900' });
export const NEXT_MONTH = occ('r1', '2026-04-01');

const ALL = [OVERDUE, PAID, DIFFERENT, UPCOMING, UPCOMING_2];

export const calendar = (month = '2026-03', occurrences = ALL) => {
    const [y, m] = month.split('-').map(Number);
    const count = new Date(Date.UTC(y, m, 0)).getUTCDate();
    return Array.from({ length: count }, (_, i) => {
        const date = `${month}-${String(i + 1).padStart(2, '0')}`;
        return { date, occurrences: occurrences.filter((o) => o.dueDate === date) };
    });
};

export const ITEM = {
    id: 'r1',
    name: 'Rent',
    expectedMinor: '65000',
    currency: 'EUR',
    accountId: 'acc-main',
    categoryId: 'cat-rent',
    rule: 'FREQ=MONTHLY;BYMONTHDAY=1',
    pattern: { kind: 'monthly_day', interval: 1, monthDay: 1 },
    startsOn: '2026-01-01',
    endsOn: null,
    textHint: 'landlord',
    toleranceMinor: '500',
    windowDays: 7,
};

export const recurring = (extra = {}) => ({
    items: [ITEM],
    calendar: calendar(),
    overdue: [OVERDUE],
    next30: [UPCOMING, UPCOMING_2, NEXT_MONTH],
    stillToPay: [{ currency: 'EUR', sumMinor: '75600', count: 4, overdueCount: 1, next: [OVERDUE, UPCOMING, UPCOMING_2] }],
    ...extra,
});

export const recurringPage = (extra = {}) => ({
    finance: finance(),
    recurring: recurring(),
    panelBill: null,
    panelRecurring: null,
    dayPanel: null,
    candidates: null,
    ...extra,
});

export const CANDIDATES = [
    { id: 'c1', description: 'Landlord Mar', bookedOn: '2026-03-02', accountName: 'Household', amountMinor: '-65000' },
    { id: 'c2', description: 'Rent part', bookedOn: '2026-03-04', accountName: 'Household', amountMinor: '-30000' },
];
