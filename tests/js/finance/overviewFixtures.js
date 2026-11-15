// Fixture props for the Overview page; shapes follow the plan 05-15 props contract.
import { finance } from './fixtures.js';

export const budgetRow = (id, categoryId, extra = {}) => ({
    id, categoryId, limitMinor: '50000', spentMinor: '20000', percent: 40, state: 'ok', overMinor: null, effectiveFrom: '2026-01', ...extra,
});

export const block = (currency = 'EUR', extra = {}) => ({
    currency,
    summary: { incomeMinor: '250000', spendingMinor: '123456', balanceMinor: '1050050' },
    stillToPay: { currency, sumMinor: '75600', count: 4, overdueCount: 1, next: [] },
    budgets: [
        budgetRow('b1', 'cat-groc', { limitMinor: '30000', spentMinor: '28000', percent: 93, state: 'near' }),
        budgetRow('b2', 'cat-rent', { limitMinor: '65000', spentMinor: '70000', percent: 107, state: 'over', overMinor: '5000' }),
        budgetRow('b3', 'cat-custom'),
    ],
    categorySpending: {
        totalMinor: '100000',
        top: [{ categoryId: 'cat-rent', amountMinor: '70000', percent: 70 }, { categoryId: 'cat-groc', amountMinor: '30000', percent: 30 }],
        other: null,
        all: [{ categoryId: 'cat-rent', amountMinor: '70000', percent: 70 }, { categoryId: 'cat-groc', amountMinor: '30000', percent: 30 }],
    },
    balanceSeries: ['2025-04', '2025-05', '2025-06', '2025-07', '2025-08', '2025-09', '2025-10', '2025-11', '2025-12', '2026-01', '2026-02', '2026-03']
        .map((month, i) => ({ month, balanceMinor: String(100000 + i * 5000) })),
    ...extra,
});

export const overviewPage = (extra = {}) => ({
    finance: finance({ currencies: ['EUR'] }),
    overview: { hasRecurring: true, blocks: [block('EUR')] },
    panelBudget: null,
    ...extra,
});
