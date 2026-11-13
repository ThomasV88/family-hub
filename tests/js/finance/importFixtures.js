// Fixtures for the import wizard (shapes follow the 05-11 and 05-18 contracts).
import { ACCOUNTS, CATEGORIES, finance } from './fixtures.js';

export const CONFIG = {
    limits: { maxBytes: 2097152, maxRows: 5000 },
    encodings: ['UTF-8', 'UTF-16LE', 'UTF-16BE', 'WINDOWS-1250', 'ISO-8859-2', 'CP852'],
    dateFormats: ['d.m.Y', 'j.n.Y', 'Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.y', 'm/d/Y'],
    delimiters: [';', ',', '\t', '|'],
};

export const PROFILE_SETTINGS = {
    delimiter: ';', encoding: 'WINDOWS-1250', decimalSeparator: ',', groupingSeparator: ' ', dateFormat: 'd.m.Y', headerRow: 1, amountMode: 'single',
    columns: { date: { name: 'Datum', index: 0 }, amount: { name: 'Suma', index: 1 }, description: { name: 'Popis', index: 2 } },
    indicatorExpense: [], indicatorIncome: [],
};

export const importPage = (extra = {}) => ({
    finance: finance({ selectedAccount: 'all' }),
    import: {
        accounts: ACCOUNTS.map((a) => ({ id: a.id, name: a.name, currency: a.currency })),
        profiles: [{ id: 'prof-1', name: 'Our bank', settings: PROFILE_SETTINGS }],
        history: [
            { id: 'b-1', createdAt: '2026-03-01T10:00:00Z', accountId: 'acc-main', accountName: 'Household', addedCount: 12, status: 'committed', canUndo: true },
            { id: 'b-2', createdAt: '2026-02-01T10:00:00Z', accountId: 'acc-main', accountName: 'Household', addedCount: 1, status: 'undone', canUndo: false },
        ],
        ...CONFIG,
        ...(extra.import ?? {}),
    },
    ...Object.fromEntries(Object.entries(extra).filter(([k]) => k !== 'import')),
});

export const PREVIEW = {
    suggested: { encoding: 'WINDOWS-1250', reason: 'bytes', delimiter: ';' },
    encoding: 'WINDOWS-1250',
    delimiter: ';',
    headerRow: 1,
    headers: ['Datum', 'Suma', 'Popis', 'Protistrana'],
    sample: [
        ['12.03.2026', '-45,90', 'Potraviny =SUM(A1)', 'Tesco'],
        ['11.03.2026', '2 500,00', 'Výplata', 'Firma'],
        ['10.03.2026', '-9,99', '<img src=x onerror=alert(1)>', ''],
    ],
    recordCount: 3,
};

export const csvFile = (name = 'statement.csv', size = 100) => {
    const f = new File(['x'], name, { type: 'text/csv' });
    Object.defineProperty(f, 'size', { value: size });
    return f;
};

// Review fixtures
export const rowOf = (id, n, extra = {}) => ({
    id, rowNo: n, bookedOn: '2026-03-12', description: `Line ${n}`, counterparty: null, amountMinor: '-1000', kind: 'expense',
    state: 'new', errorCode: null, include: true, probable: null, matchName: null, ...extra,
});

export const batchPage = (extra = {}) => ({
    finance: finance(),
    batch: {
        id: 'batch-1', status: 'draft', accountId: 'acc-main', accountName: 'Household', currency: 'EUR',
        counts: { total: 5, new: 2, duplicate: 1, probable: 1, invalid: 1 },
        rows: [
            rowOf('r1', 1, { description: 'Weekly shop', amountMinor: '-4590', matchName: null }),
            rowOf('r2', 2, { description: 'March salary', amountMinor: '250000', kind: 'income', bookedOn: '2026-03-10' }),
            rowOf('r3', 3, { description: 'Rent', amountMinor: '-65000', state: 'duplicate', include: false, matchName: 'Rent' }),
            rowOf('r4', 4, { description: 'Coffee', amountMinor: '-350', state: 'probable_duplicate', include: false, probable: { description: 'Coffee', bookedOn: '2026-03-12', amountMinor: '-350' } }),
            rowOf('r5', 5, { description: 'garbled', amountMinor: null, kind: null, state: 'invalid', include: false, errorCode: 'date', bookedOn: null }),
        ],
        result: null,
        ...(extra.batch ?? {}),
    },
    ...Object.fromEntries(Object.entries(extra).filter(([k]) => k !== 'batch')),
});

export const doneBatch = (result = { added: 4, matched: 1, kept: 0 }, status = 'committed') => batchPage({ batch: { status, rows: [], result, counts: { total: 0, new: 0, duplicate: 0, probable: 0, invalid: 0 } } });

export { CATEGORIES };
