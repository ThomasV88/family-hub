import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

const post = vi.fn();
const patch = vi.fn();
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    return {
        useForm: (data) => {
            const form = reactive({
                ...data, processing: false, errors: {},
                post: (...a) => post(JSON.parse(JSON.stringify(form)), ...a),
                patch: (...a) => patch(JSON.parse(JSON.stringify(form)), ...a),
            });
            return form;
        },
    };
});

import AccountPanel from '../../../resources/js/Finance/AccountPanel.vue';

const MEMBERS = [
    { id: 1, name: 'Anna', financeAccess: true },
    { id: 2, name: 'Bob', financeAccess: false },
    { id: 3, name: 'Cyril', financeAccess: true },
];
const UNTICKED = { id: 'a-1', name: 'Joint', type: 'bank', currency: 'EUR', memberIds: [3], memberCount: 1, sharedWithMe: false, opening: null };
const TICKED = { ...UNTICKED, sharedWithMe: true, opening: { minor: '-123450', asOf: '2026-01-01', currencyLocked: false } };
const LOCKED = { ...TICKED, opening: { ...TICKED.opening, currencyLocked: true } };

let current;
function mountPanel({ account = null, locale = 'en' } = {}) {
    window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    current = mount(AccountPanel, { props: { account, members: MEMBERS }, attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });
    return current;
}
const body = () => document.body;
const labels = () => [...body().querySelectorAll('label')].map((l) => l.textContent.trim());
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; post.mockReset(); patch.mockReset(); });

describe('AccountPanel', () => {
    it('opens a new-account panel with all fields, nobody ticked and members without access disabled', () => {
        mountPanel();
        expect(body().querySelector('h2').textContent).toBe('New account');
        const text = labels().join('|');
        for (const l of ['Name', 'Type', 'Currency', 'Opening balance', 'Balance as of']) expect(text).toContain(l);
        const boxes = [...body().querySelectorAll('input[type="checkbox"]')];
        expect(boxes.map((b) => b.checked)).toEqual([false, false, false]);
        expect(boxes.map((b) => b.disabled)).toEqual([false, true, false]);
        expect(body().textContent).toContain('No finance access');
        expect(body().textContent).toContain('Grant finance access in Members first.');
        expect([...body().querySelectorAll('select')][1].value).toBe('EUR');
        expect(body().querySelector('input[type="date"]').value).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    });

    it('shows only name, type, disabled currency without hint and visibility for an account not shared with the admin', () => {
        mountPanel({ account: UNTICKED });
        expect(body().textContent).not.toContain('Opening balance');
        expect(body().textContent).not.toContain('Balance as of');
        expect(body().textContent).not.toContain('cannot be changed after the first transaction');
        const selects = [...body().querySelectorAll('select')];
        expect(selects).toHaveLength(2);
        expect(selects[1].disabled).toBe(true);
    });

    it('shows the opening fields and an enabled currency for a ticked admin without transactions', () => {
        mountPanel({ account: TICKED });
        expect(body().textContent).toContain('Opening balance');
        const selects = [...body().querySelectorAll('select')];
        expect(selects[1].disabled).toBe(false);
        expect(body().textContent).not.toContain('cannot be changed after the first transaction');
        const inputs = [...body().querySelectorAll('input')].map((i) => i.value);
        expect(inputs).toContain('-1234.50');
    });

    it('disables the currency with the hint when it is locked', () => {
        mountPanel({ account: LOCKED });
        expect([...body().querySelectorAll('select')][1].disabled).toBe(true);
        expect(body().textContent).toContain('The currency cannot be changed after the first transaction.');
    });

    it('posts a new account with the ticked members', async () => {
        mountPanel();
        const boxes = [...body().querySelectorAll('input[type="checkbox"]')];
        boxes[0].click();
        await flushPromises();
        const name = body().querySelector('input[maxlength]');
        name.value = 'Family';
        name.dispatchEvent(new Event('input'));
        await flushPromises();
        body().querySelector('form').dispatchEvent(new Event('submit', { cancelable: true }));
        await flushPromises();
        expect(post).toHaveBeenCalledTimes(1);
        const [form, url] = post.mock.calls[0];
        expect(url).toBe('/settings/finance-accounts');
        expect(form.name).toBe('Family');
        expect(form.member_ids).toEqual([1]);
        expect(form.currency).toBe('EUR');
    });

    it('patches without money fields for an unticked admin', async () => {
        mountPanel({ account: UNTICKED });
        body().querySelector('form').dispatchEvent(new Event('submit', { cancelable: true }));
        await flushPromises();
        const [form, url] = patch.mock.calls[0];
        expect(url).toBe('/settings/finance-accounts/a-1');
        expect('currency' in form).toBe(false);
        expect('opening_balance' in form).toBe(false);
        expect(form.member_ids).toEqual([3]);
    });

    it('blocks an invalid opening balance client-side', async () => {
        mountPanel({ account: TICKED });
        const opening = [...body().querySelectorAll('input')].find((i) => i.value === '-1234.50');
        opening.value = 'abc';
        opening.dispatchEvent(new Event('input'));
        await flushPromises();
        body().querySelector('form').dispatchEvent(new Event('submit', { cancelable: true }));
        await flushPromises();
        expect(patch).not.toHaveBeenCalled();
        expect(body().textContent).toContain('Enter an amount above 0');
    });

    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`has no axe violations (${locale}, ${dark ? 'dark' : 'light'})`, async () => {
                document.documentElement.dataset.theme = dark ? 'dark' : 'light';
                mountPanel({ account: TICKED, locale });
                await expectNoAxeViolations(document.body);
                delete document.documentElement.dataset.theme;
            });
        }
    }
});
