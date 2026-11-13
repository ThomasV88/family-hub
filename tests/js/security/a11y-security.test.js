import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { h, reactive } from 'vue';
import { mount, flushPromises } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { pseudoLong } from '../helpers/pseudoLong.js';
import { loadMessages } from '../helpers/mount.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { AuditEvents } from '../settings/auditSecurityFixtures.js';

/*
 * Phase 6 axe sweep: every new screen and dialog state, SK/PL/EN, light and dark, plus a pseudo-long (+40%) run.
 * Per-component suites keep their behaviour assertions; this file proves the whole set in one place.
 */
const page = vi.hoisted(() => ({ props: {} }));
const router = vi.hoisted(() => ({ post: vi.fn(), reload: vi.fn(), clearHistory: vi.fn(), get: vi.fn(), visit: vi.fn(), on: vi.fn(), delete: vi.fn() }));
vi.mock('@inertiajs/vue3', async () => {
    const { h: hh, reactive: r } = await import('vue');
    return {
        router,
        usePage: () => page,
        useForm: (data) => r({
            ...data, errors: {}, processing: false,
            transform() { return this; }, post: vi.fn(), reset() {}, clearErrors() { this.errors = {}; },
        }),
        Head: { render: () => null },
        Link: { props: ['href'], render() { return hh('a', { href: this.href }, this.$slots.default?.()); } },
    };
});
const fetchMock = vi.fn();
vi.mock('../../../resources/js/Security/securityFetch.js', () => ({ securityFetch: (...a) => fetchMock(...a) }));
const webauthn = vi.hoisted(() => ({ supported: true }));
vi.mock('../../../resources/js/Security/webauthn.js', () => ({
    passkeysSupported: () => webauthn.supported,
    createCredential: async () => ({ id: 'cred', type: 'public-key' }),
    getAssertion: async () => ({ id: 'cred', type: 'public-key' }),
}));
vi.mock('../../../resources/js/Layouts/SettingsLayout.vue', async () => {
    const { h: hh } = await import('vue');
    return { default: { props: ['title', 'current', 'sections'], render() { return hh('main', {}, [hh('h1', {}, this.title), this.$slots.default?.()]); } } };
});
vi.mock('../../../resources/js/Layouts/AppShell.vue', async () => {
    const { h: hh } = await import('vue');
    return { default: { render() { return hh('main', {}, this.$slots.default?.()); } } };
});

import Security from '../../../resources/js/Pages/Settings/Security.vue';
import ConfirmAccess from '../../../resources/js/Pages/Auth/ConfirmAccess.vue';
import TwoFactorChallenge from '../../../resources/js/Pages/Auth/TwoFactorChallenge.vue';
import TwoFactorSetupDialog from '../../../resources/js/Components/Security/TwoFactorSetupDialog.vue';
import TotpDisableDialog from '../../../resources/js/Components/Security/TotpDisableDialog.vue';
import RegenerateCodesDialog from '../../../resources/js/Components/Security/RegenerateCodesDialog.vue';
import PasskeysCard from '../../../resources/js/Components/Security/PasskeysCard.vue';
import PasskeyNameDialog from '../../../resources/js/Components/Security/PasskeyNameDialog.vue';
import PasskeyRemoveDialog from '../../../resources/js/Components/Security/PasskeyRemoveDialog.vue';
import SecurityRequirementCard from '../../../resources/js/Components/Settings/SecurityRequirementCard.vue';
import MemberSecurityTab from '../../../resources/js/Components/Settings/MemberSecurityTab.vue';
import MemberRow from '../../../resources/js/Components/Settings/MemberRow.vue';
import UiList from '../../../resources/js/Components/ui/UiList.vue';
import AuditTable from '../../../resources/js/Components/Settings/AuditTable.vue';
import { useToast } from '../../../resources/js/Components/ui/useToast.js';

const METHODS = { password: true, totp: true, passkey: true, google: false };
const PW = { password: true, totp: false, passkey: false, google: false };
const NONE = { password: false, totp: false, passkey: false, google: false };
const ok = (data = {}, status = 200) => ({ ok: true, status, data });
const stale = { ok: false, status: 423, error: 'confirm_required', data: {} };
const CODES = ['aaaa-1111', 'bbbb-2222', 'cccc-3333', 'dddd-4444', 'eeee-5555', 'ffff-6666', 'gggg-7777', 'hhhh-8888'];
const phone = { id: 'p1', name: 'iPhone', authenticator: null, createdAt: '2026-11-01T10:00:00Z', lastUsedAt: null };
const laptop = { id: 'p2', name: 'Work laptop', authenticator: null, createdAt: '2026-10-01T10:00:00Z', lastUsedAt: '2026-11-10T10:00:00Z' };
const sec = (over = {}) => ({ totp: false, passkeys: 0, required: false, needs: false, ...over });
const member = (over = {}, security = {}) => ({
    id: 7, name: 'Bob', email: 'bob@example.test', isAdmin: false, isSelf: false,
    access: { todo: 'edit', wishlist: 'edit', finance: 'edit', health: 'none' }, security: sec(security), ...over,
});

let current;
const q = (sel) => document.body.querySelector(sel);
const click = async (sel) => { q(sel).click(); await flushPromises(); };
const type = async (sel, value) => { const el = q(sel); el.value = value; el.dispatchEvent(new Event('input')); await flushPromises(); };
const fetchRoute = (map = {}) => fetchMock.mockImplementation(async (url, opts = {}) => {
    const entry = map[`${opts.method ?? 'GET'} ${url}`];
    if (entry) return typeof entry === 'function' ? entry() : entry;
    if (url === '/user/two-factor-authentication' && opts.method === 'POST') return ok({ enabled: true });
    if (url === '/user/two-factor-qr-code') return ok({ svg: '<svg><rect width="4" height="4"/></svg>' });
    if (url === '/user/two-factor-secret-key') return ok({ secretKey: 'JBSWY3DPEHPK3PXP' });
    if (url === '/user/confirmed-two-factor-authentication') return ok({ recoveryCodes: CODES });
    if (url === '/user/two-factor-recovery-codes') return ok({ recoveryCodes: CODES });
    if (url === '/settings/security/passkeys/options') return ok({ options: { challenge: 'abc' } });
    if (url === '/confirm-access') return ok({ confirmed: true });
    return ok({});
});
globalThis.fetch = vi.fn(async () => ({ ok: false, status: 423, headers: { get: () => null }, json: async () => ({ error: 'confirm_required' }) }));

let ctx;
function mountIt(component, props = {}, extra = {}) {
    current?.unmount();
    document.body.innerHTML = '';
    current = mount(component, {
        props, attachTo: document.body,
        global: { plugins: [createAppI18n(ctx.locale, ctx.messages)], ...extra },
    });
    return current;
}
const axe = () => expectNoAxeViolations(document.body);

const SECURITY_BASE = { sections: [], status: { totp: false, recoveryRemaining: null }, passkeys: [], required: false, guidance: false, continueTo: null, methods: PW, confirmFresh: true, passkeysEnabled: true };
const dialogProps = (over = {}) => ({ open: true, methods: METHODS, isAdmin: false, ...over });
const tab = (over = {}) => ({ member: member({}, { totp: true, passkeys: 1, required: true }), securityPolicy: { required: true, pending: 1 }, confirmFresh: true, methods: PW, ...over });

const scenarios = {
    // --- Settings > Security ---
    'Security off': async () => { mountIt(Security, SECURITY_BASE); await axe(); },
    'Security on': async () => { mountIt(Security, { ...SECURITY_BASE, status: { totp: true, recoveryRemaining: 8 } }); await axe(); },
    'Security on, codes running low': async () => { mountIt(Security, { ...SECURITY_BASE, status: { totp: true, recoveryRemaining: 1 } }); await axe(); },
    'Security guidance': async () => { mountIt(Security, { ...SECURITY_BASE, guidance: true, required: true, continueTo: '/finance' }); await axe(); },
    'Security with passkeys': async () => { mountIt(Security, { ...SECURITY_BASE, passkeys: [phone, laptop], status: { totp: true, recoveryRemaining: 5 } }); await axe(); },
    'Security with empty passkeys': async () => { mountIt(Security, { ...SECURITY_BASE, passkeys: [] }); await axe(); },

    // --- setup dialog ---
    'Setup steps 1 to 3': async () => {
        fetchRoute();
        mountIt(TwoFactorSetupDialog, dialogProps({ confirmFresh: true }));
        await flushPromises();
        await axe();
        await click('[data-testid="cant-scan"]');
        await axe();
        await click('[data-testid="next-step"]');
        await axe();
        await type('input[autocomplete="one-time-code"]', '123456');
        await click('[data-testid="confirm-code"]');
        await axe();
    },
    'Setup stale confirm step': async () => { fetchRoute(); mountIt(TwoFactorSetupDialog, dialogProps({ confirmFresh: false, methods: PW })); await flushPromises(); await axe(); },
    'Setup no-method': async () => { fetchRoute(); mountIt(TwoFactorSetupDialog, dialogProps({ confirmFresh: false, methods: NONE, isAdmin: true })); await flushPromises(); await axe(); },

    // --- turn-off and new-codes dialogs ---
    'Turn off fresh': async () => { fetchRoute(); mountIt(TotpDisableDialog, dialogProps()); await flushPromises(); await axe(); },
    'Turn off last factor': async () => { fetchRoute(); mountIt(TotpDisableDialog, dialogProps({ lastFactor: true })); await flushPromises(); await axe(); },
    'Turn off stale with form': async () => {
        fetchRoute({ 'DELETE /user/two-factor-authentication': stale });
        mountIt(TotpDisableDialog, dialogProps({ methods: PW })); await flushPromises();
        await click('[data-testid="turn-off"]'); await axe();
    },
    'Turn off no-method': async () => {
        fetchRoute({ 'DELETE /user/two-factor-authentication': stale });
        mountIt(TotpDisableDialog, dialogProps({ methods: NONE, isAdmin: true })); await flushPromises();
        await click('[data-testid="turn-off"]'); await axe();
    },
    'New codes fresh': async () => { fetchRoute(); mountIt(RegenerateCodesDialog, dialogProps()); await flushPromises(); await axe(); },
    'New codes shown': async () => {
        fetchRoute(); mountIt(RegenerateCodesDialog, dialogProps()); await flushPromises();
        await click('[data-testid="create"]'); await axe();
    },
    'New codes stale with form': async () => {
        fetchRoute({ 'POST /user/two-factor-recovery-codes': stale });
        mountIt(RegenerateCodesDialog, dialogProps({ methods: PW })); await flushPromises();
        await click('[data-testid="create"]'); await axe();
    },
    'New codes no-method': async () => {
        fetchRoute({ 'POST /user/two-factor-recovery-codes': stale });
        mountIt(RegenerateCodesDialog, dialogProps({ methods: NONE })); await flushPromises();
        await click('[data-testid="create"]'); await axe();
    },

    // --- passkeys ---
    'Passkeys empty': async () => { mountIt(PasskeysCard, { passkeys: [], methods: METHODS, confirmFresh: true }); await axe(); },
    'Passkeys list': async () => { mountIt(PasskeysCard, { passkeys: [phone, laptop], methods: METHODS, confirmFresh: true }); await axe(); },
    'Passkeys unsupported': async () => {
        webauthn.supported = false;
        mountIt(PasskeysCard, { passkeys: [], methods: METHODS, confirmFresh: true }); await axe();
    },
    'Passkey add confirm dialog (stale)': async () => {
        fetchRoute(); mountIt(PasskeysCard, { passkeys: [], methods: PW, confirmFresh: false }); await flushPromises();
        await click('[data-testid="add-passkey"]'); await axe();
    },
    'Passkey add confirm dialog (no method)': async () => {
        fetchRoute(); mountIt(PasskeysCard, { passkeys: [], methods: NONE, confirmFresh: false }); await flushPromises();
        await click('[data-testid="add-passkey"]'); await axe();
    },
    'Passkey name dialog': async () => {
        fetchRoute(); mountIt(PasskeysCard, { passkeys: [], methods: METHODS, confirmFresh: true }); await flushPromises();
        await click('[data-testid="add-passkey"]'); await axe();
    },
    'Passkey rename dialog': async () => { mountIt(PasskeyNameDialog, { open: true, mode: 'rename', name: 'Old', error: 'passkey.error.name' }); await flushPromises(); await axe(); },
    'Passkey remove dialog': async () => { mountIt(PasskeyRemoveDialog, { open: true, passkey: phone, methods: METHODS }); await flushPromises(); await axe(); },
    'Passkey remove last factor': async () => { mountIt(PasskeyRemoveDialog, { open: true, passkey: phone, methods: METHODS, lastFactor: true }); await flushPromises(); await axe(); },
    'Passkey remove stale with form': async () => {
        fetchRoute({ 'DELETE /settings/security/passkeys/p1': stale });
        mountIt(PasskeyRemoveDialog, { open: true, passkey: phone, methods: PW }); await flushPromises();
        await click('[data-testid="confirm-remove"]'); await axe();
    },
    'Passkey remove no-method': async () => {
        fetchRoute({ 'DELETE /settings/security/passkeys/p1': stale });
        mountIt(PasskeyRemoveDialog, { open: true, passkey: phone, methods: NONE }); await flushPromises();
        await click('[data-testid="confirm-remove"]'); await axe();
    },

    // --- two-step challenge ---
    'Challenge code': async () => { mountIt(TwoFactorChallenge, { totp: true, passkey: false }); await axe(); },
    'Challenge recovery': async () => { mountIt(TwoFactorChallenge, { totp: true, passkey: false }); await click('[data-testid="use-recovery"]'); await axe(); },
    'Challenge with passkey': async () => { mountIt(TwoFactorChallenge, { totp: true, passkey: true }); await axe(); },
    'Challenge passkey only': async () => { mountIt(TwoFactorChallenge, { totp: false, passkey: true }); await axe(); },
    'Challenge throttle': async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 429, error: 'throttle', minutes: 2, data: {} });
        mountIt(TwoFactorChallenge, { totp: true, passkey: true });
        await click('[data-testid="use-passkey"]'); await axe();
    },

    // --- confirm page ---
    'Confirm password': async () => { mountIt(ConfirmAccess, { methods: PW, minutes: 15, interrupted: false }); await axe(); },
    'Confirm code': async () => { mountIt(ConfirmAccess, { methods: { ...NONE, totp: true }, minutes: 15, interrupted: false }); await axe(); },
    'Confirm password, code and passkey': async () => { mountIt(ConfirmAccess, { methods: METHODS, minutes: 15, interrupted: false }); await axe(); },
    'Confirm no-method (Google flag off)': async () => { mountIt(ConfirmAccess, { methods: NONE, minutes: 15, interrupted: false }); await axe(); },
    'Confirm no-method with Google link (flag on)': async () => { mountIt(ConfirmAccess, { methods: { ...NONE, google: true }, minutes: 15, interrupted: false }); await axe(); },
    'Confirm interrupted': async () => { mountIt(ConfirmAccess, { methods: PW, minutes: 15, interrupted: true }); await axe(); },
    'Confirm Google failed': async () => {
        page.props.flash.status = 'google_reauth_failed';
        mountIt(ConfirmAccess, { methods: { ...NONE, google: true }, minutes: 15, interrupted: false }); await axe();
    },

    // --- members ---
    'Requirement card': async () => { mountIt(SecurityRequirementCard, { securityPolicy: { required: false, pending: 2 }, confirmFresh: true, methods: PW }); await axe(); },
    'Requirement confirm dialog (stale)': async () => {
        mountIt(SecurityRequirementCard, { securityPolicy: { required: false, pending: 2 }, confirmFresh: false, methods: PW });
        await current.find('input[role=switch]').setValue(true); await flushPromises(); await axe();
    },
    'Member row tags': async () => {
        mountIt({ render: () => h(UiList, null, () => [
            h(MemberRow, { member: member({}, { totp: true, required: true }) }),
            h(MemberRow, { member: member({ id: 8 }, { required: true, needs: true }) }),
            h(MemberRow, { member: member({ id: 9 }, {}) }),
        ]) });
        await axe();
    },
    'Member security tab': async () => { mountIt(MemberSecurityTab, tab()); await axe(); },
    'Member security tab without factor': async () => { mountIt(MemberSecurityTab, tab({ member: member() })); await axe(); },
    'Member reset dialog': async () => {
        mountIt(MemberSecurityTab, tab());
        await current.find('[data-testid=reset-button]').trigger('click'); await flushPromises(); await axe();
    },
    'Member reset dialog (stale)': async () => {
        mountIt(MemberSecurityTab, tab({ confirmFresh: false }));
        await current.find('[data-testid=reset-button]').trigger('click'); await flushPromises(); await axe();
    },
    'Member reset dialog (no method)': async () => {
        mountIt(MemberSecurityTab, tab({ confirmFresh: false, methods: NONE }));
        await current.find('[data-testid=reset-button]').trigger('click'); await flushPromises(); await axe();
    },

    // --- audit ---
    'Audit table with security rows': async () => { mountIt(AuditTable, { entries: AuditEvents }); await axe(); },
};

// A scenario that never reached its state would pass axe trivially: each named state must show its marker.
const MARKERS = [
    [/stale with form|\(stale\)|stale confirm step|Passkey add confirm dialog \(stale\)/, 'input[type="password"]'],
    [/no-method|no method/i, '[data-testid="reauth-no-method"], [data-testid="sign-in-again"]'],
    [/dialog|Setup|Turn off|New codes|Passkey (name|rename|remove)/, '[role="dialog"]'],
    [/Challenge(?! passkey only)/, 'form'],
    [/Challenge passkey only/, '[data-testid="passkey-only"]'],
    [/Security tab|Audit table/, 'table, [data-testid]'],
];
function expectReached(name) {
    expect(document.body.textContent.trim().length, `${name} rendered nothing`).toBeGreaterThan(20);
    for (const [pattern, selector] of MARKERS) {
        if (pattern.test(name)) expect(document.body.querySelector(selector), `${name} did not reach its state (${selector})`).not.toBeNull();
    }
}

function setup(locale, dark, pseudo = false) {
    ctx = { locale, messages: pseudo ? pseudoLong(loadMessages(locale)) : loadMessages(locale) };
    document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
    document.documentElement.classList.toggle('dark', dark);
    window.innerWidth = pseudo ? 360 : 1280;
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    page.props = reactive({
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 1, name: 'Anna', email: 'a@example.test', isAdmin: false }, access: { todo: 'edit', wishlist: 'edit', finance: 'edit', health: 'none' } },
        app: { env: 'local', styleGuide: false },
    });
    webauthn.supported = true;
}

beforeEach(() => {
    fetchMock.mockReset();
    Object.values(router).forEach((f) => f.mockReset());
    useToast().toasts.splice(0);
});
afterEach(() => {
    current?.unmount();
    current = null;
    document.body.innerHTML = '';
    document.documentElement.removeAttribute('data-theme');
    document.documentElement.classList.remove('dark');
});

describe('Phase 6 accessibility sweep', () => {
    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            for (const [name, run] of Object.entries(scenarios)) {
                it(`${name} passes axe (${locale}${dark ? ', dark' : ''})`, async () => {
                    setup(locale, dark);
                    await run();
                    expectReached(name);
                });
            }
        }
    }
});

describe('Phase 6 pseudo-long strings (+40%)', () => {
    for (const locale of ['sk', 'pl']) {
        for (const [name, run] of Object.entries(scenarios)) {
            it(`${name} keeps its full text and passes axe (${locale})`, async () => {
                setup(locale, false, true);
                await run();
                expectReached(name);
                // Every translated heading renders its whole padded text (nothing cut off by character count).
                const headings = [...document.body.querySelectorAll('h1, h2, h3')].map((el) => el.textContent.trim()).filter(Boolean);
                for (const heading of headings) expect(heading.length).toBeGreaterThan(3);
                const translated = headings.filter((text) => text.includes('ŵ'));
                for (const text of translated) expect(text.endsWith('ŵ')).toBe(true);
                // Buttons and tags never carry an inline truncation style.
                for (const el of document.body.querySelectorAll('button, .ui-tag, [role="dialog"] *')) {
                    expect(el.getAttribute('style') ?? '').not.toMatch(/text-overflow|max-width|overflow:\s*hidden/);
                }
            });
        }
    }

    it('wraps tag and button rows: no ellipsis, and the multi-action rows may wrap', () => {
        const read = (p) => readFileSync(`${process.cwd()}/resources/js/${p}`, 'utf8');
        const files = [
            'Components/Security/PasskeyRow.vue', 'Components/Security/PasskeysCard.vue', 'Components/Security/RecoveryCodesPanel.vue',
            'Components/Security/ReauthForm.vue', 'Components/Security/ReauthPrompt.vue', 'Components/Security/TwoFactorSetupDialog.vue',
            'Components/Security/TotpDisableDialog.vue', 'Components/Security/RegenerateCodesDialog.vue', 'Components/Security/PasskeyNameDialog.vue',
            'Components/Security/PasskeyRemoveDialog.vue', 'Pages/Settings/Security.vue', 'Pages/Auth/ConfirmAccess.vue', 'Pages/Auth/TwoFactorChallenge.vue',
            'Components/Settings/SecurityRequirementCard.vue', 'Components/Settings/MemberSecurityTab.vue',
        ];
        for (const file of files) expect(read(file), file).not.toMatch(/text-overflow:\s*ellipsis|white-space:\s*nowrap/);
        for (const file of ['Components/Security/PasskeyRow.vue', 'Components/Security/PasskeysCard.vue', 'Components/Security/RecoveryCodesPanel.vue', 'Pages/Settings/Security.vue']) {
            expect(read(file), file).toMatch(/flex-wrap:\s*wrap/);
        }
    });
});
