import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { defineComponent, h } from 'vue';
import { mount, flushPromises } from '@vue/test-utils';
import { http } from '@inertiajs/core';
import { App, router } from '@inertiajs/vue3';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import AccountPanel from '../../../resources/js/Finance/AccountPanel.vue';
import StaleGateHost from '../../../resources/js/Components/Security/StaleGateHost.vue';
import { installStaleGate, resetStaleGate } from '../../../resources/js/Security/staleGate.js';

// Regression for GitHub issue 2: creating a bank account while the confirmation is stale used to bounce the member
// to /confirm-access, drop the half-filled form and, after confirming, never save the account. With the real Inertia
// router and the real form, the form must stay on screen, the dialog opens over it and the account is saved once.
const MEMBERS = [{ id: 1, name: 'Anna', financeAccess: true }, { id: 3, name: 'Cyril', financeAccess: true }];
const PAGE = { component: 'Finance/Accounts', props: { auth: { user: { id: 7, isAdmin: true } }, errors: {} }, url: '/finance/accounts', version: 'v1' };
const json = (status, body, headers = {}) => ({ status, headers: { 'content-type': 'application/json', ...headers }, data: JSON.stringify(body) });

let wrapper;
let client;
let closed;

const Root = defineComponent({
    render() {
        return [h(AccountPanel, { account: null, members: MEMBERS, onClose: () => { closed += 1; } }), h(StaleGateHost)];
    },
});

// The real Inertia App owns page state (usePage) and the router, exactly like resources/js/app.js.
function mountApp() {
    wrapper = mount(App, {
        attachTo: document.body,
        props: { initialPage: PAGE, initialComponent: Root, resolveComponent: async () => Root, serverRendered: false },
        global: { plugins: [createAppI18n('en', loadMessages('en'))] },
    });
    installStaleGate(router);
    return wrapper;
}

beforeEach(() => {
    closed = 0;
    window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    client = { request: vi.fn() };
    http.setClient(client);
});
afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    resetStaleGate();
    vi.unstubAllGlobals();
});

const q = (sel) => document.body.querySelector(sel);
async function typeInto(el, value) {
    el.value = value;
    el.dispatchEvent(new Event('input'));
    await flushPromises();
}

describe('creating a finance account while the confirmation is stale', () => {
    it('keeps the form, confirms in a dialog and saves the account exactly once', async () => {
        const stale = json(423, { error: 'confirm_required', methods: { password: true, totp: false, passkey: false } });
        client.request.mockResolvedValueOnce(stale).mockResolvedValueOnce(json(200, PAGE, { 'x-inertia': 'true' }));
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, status: 200, headers: { get: () => null }, json: async () => ({ confirmed: true }) }));

        mountApp();
        await flushPromises();
        await typeInto(q('input[maxlength="60"]'), 'Joint account');
        q('#finance-account-form').dispatchEvent(new Event('submit', { cancelable: true }));
        await flushPromises();

        // Refused once: no navigation, the form is still mounted and untouched, the dialog is open over it.
        expect(client.request).toHaveBeenCalledTimes(1);
        expect(q('input[maxlength="60"]').value).toBe('Joint account');
        expect(q('[data-testid="stale-gate"]')).not.toBeNull();
        expect(closed).toBe(0);
        expect(window.location.pathname).not.toBe('/confirm-access');

        await typeInto(q('[data-testid="stale-gate"] input[autocomplete="current-password"]'), 'secret');
        q('[data-testid="stale-gate"] form').dispatchEvent(new Event('submit', { cancelable: true }));
        await flushPromises();
        await flushPromises();

        // Replayed once with the very same payload; the normal success path closed the panel.
        expect(client.request).toHaveBeenCalledTimes(2);
        const [first, second] = client.request.mock.calls.map(([config]) => config);
        expect(second.url).toBe(first.url);
        expect(second.method).toBe(first.method);
        expect(second.data).toEqual(first.data);
        expect(closed).toBe(1);
        expect(q('[data-testid="stale-gate"]')).toBeNull();
    });

    it('sends nothing and keeps the form when the member cancels', async () => {
        client.request.mockResolvedValueOnce(json(423, { error: 'confirm_required', methods: { password: true, totp: false, passkey: false } }));
        mountApp();
        await flushPromises();
        await typeInto(q('input[maxlength="60"]'), 'Joint account');
        q('#finance-account-form').dispatchEvent(new Event('submit', { cancelable: true }));
        await flushPromises();

        q('[data-testid="stale-gate-cancel"]').click();
        await flushPromises();
        expect(client.request).toHaveBeenCalledTimes(1);
        expect(q('input[maxlength="60"]').value).toBe('Joint account');
        expect(closed).toBe(0);
        expect(q('[data-testid="stale-gate"]')).toBeNull();
    });
});
