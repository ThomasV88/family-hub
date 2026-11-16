import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { loadMessages } from '../helpers/mount.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { fromDisplay, toDisplay } from '../../../resources/js/Settings/securityTimings.js';

const reload = vi.fn();
vi.mock('@inertiajs/vue3', () => ({ router: { reload: (...a) => reload(...a) } }));
const fetchMock = vi.fn();
vi.mock('../../../resources/js/Security/securityFetch.js', () => ({ securityFetch: (...a) => fetchMock(...a) }));

import SecurityTimingsCard from '../../../resources/js/Components/Settings/SecurityTimingsCard.vue';
import AuditTable from '../../../resources/js/Components/Settings/AuditTable.vue';
import { useToast } from '../../../resources/js/Components/ui/useToast.js';

const TIMINGS = { idleSeconds: 3600, confirmSeconds: 3600, idle: { min: 60, max: 86400 }, confirm: { min: 60, max: 3600 } };
let wrapper;
const q = (sel) => document.body.querySelector(sel);
const mountCard = (timings = TIMINGS, locale = 'en') => {
    wrapper = mount(SecurityTimingsCard, { props: { timings }, attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });
    return wrapper;
};
const set = async (sel, value) => { const el = q(sel); el.value = value; el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input')); await flushPromises(); };
const save = async () => { q('[data-testid="timings-form"]').dispatchEvent(new Event('submit', { cancelable: true })); await flushPromises(); };

beforeEach(() => { fetchMock.mockReset(); reload.mockReset(); useToast().toasts.splice(0); });
afterEach(() => { wrapper?.unmount(); wrapper = null; document.body.innerHTML = ''; document.documentElement.removeAttribute('data-theme'); });

describe('securityTimings helpers', () => {
    it('shows whole hours in hours and everything else in minutes', () => {
        expect(toDisplay(3600)).toEqual({ value: '1', unit: 'hours' });
        expect(toDisplay(7200)).toEqual({ value: '2', unit: 'hours' });
        expect(toDisplay(5400)).toEqual({ value: '90', unit: 'minutes' });
        expect(toDisplay(60)).toEqual({ value: '1', unit: 'minutes' });
        expect(toDisplay('x')).toEqual({ value: '', unit: 'minutes' });
    });
    it('turns a plain whole number and a unit into seconds, and refuses everything else', () => {
        expect(fromDisplay('90', 'minutes')).toBe(5400);
        expect(fromDisplay(' 2 ', 'hours')).toBe(7200);
        for (const bad of ['', '0', '-5', '1.5', '1e3', 'abc', '15m', '9999999']) expect(fromDisplay(bad, 'minutes')).toBeNull();
    });
});

describe('SecurityTimingsCard', () => {
    it('shows the stored values in friendly units with the two labels', () => {
        mountCard({ ...TIMINGS, idleSeconds: 5400, confirmSeconds: 1800 });
        expect(q('[data-testid="timing-idle"]').value).toBe('90');
        expect(q('[data-testid="timing-idle-unit"]').value).toBe('minutes');
        expect(q('[data-testid="timing-confirm"]').value).toBe('30');
        expect(document.body.textContent).toContain('Re-confirm after inactivity in Health and Finance');
        expect(document.body.textContent).toContain('Re-confirm sensitive actions after');
    });

    it('saves both windows in seconds through the replaying call, then toasts and reloads', async () => {
        fetchMock.mockResolvedValue({ ok: true, status: 200, data: {} });
        mountCard();
        await set('[data-testid="timing-idle"]', '2');
        await set('[data-testid="timing-idle-unit"]', 'hours');
        await set('[data-testid="timing-confirm"]', '45');
        await set('[data-testid="timing-confirm-unit"]', 'minutes');
        await save();
        expect(fetchMock).toHaveBeenCalledWith('/settings/security/timings', { method: 'PUT', body: { idle_seconds: 7200, confirm_window_seconds: 2700 }, replay: true });
        expect(useToast().toasts.map((x) => x.message)).toEqual(['Times saved']);
        expect(reload).toHaveBeenCalledWith({ only: ['securityTimings'] });
    });

    it('refuses an out of range value locally with an inline error and sends nothing', async () => {
        mountCard();
        await set('[data-testid="timing-confirm"]', '2');
        await set('[data-testid="timing-confirm-unit"]', 'hours');
        await save();
        expect(fetchMock).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain('Enter a time between 1 minute and 1 hour.');
        await set('[data-testid="timing-idle"]', '0');
        await save();
        expect(document.body.textContent).toContain('Enter a time between 1 minute and 24 hours.');
    });

    it('stays quiet when the member closed "Confirm it\'s you"', async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 423, error: 'cancelled' });
        mountCard();
        await save();
        expect(useToast().toasts).toHaveLength(0);
        expect(q('[data-testid="timings-failed"]')).toBeNull();
        expect(q('[data-testid="timing-idle"]').value).toBe('1');
    });

    it('shows a general failure and keeps the entered values', async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 500, error: 'failed' });
        mountCard();
        await set('[data-testid="timing-idle"]', '3');
        await save();
        expect(q('[data-testid="timings-failed"]')).not.toBeNull();
        expect(q('[data-testid="timing-idle"]').value).toBe('3');
    });

    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`passes axe (${locale}${dark ? ', dark' : ''}, with an error shown)`, async () => {
                document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
                mountCard(TIMINGS, locale);
                await expectNoAxeViolations(document.body);
                await set('[data-testid="timing-idle"]', '0');
                await save();
                await expectNoAxeViolations(document.body);
            });
        }
    }
});

describe('audit rows for the two windows', () => {
    const row = (setting, value) => ({
        id: 1, event: 'security_policy_changed', group: 'security', module: null, actorLabel: 'Anna', targetLabel: null, ip: '203.0.113.7',
        occurredAt: '2026-05-12T10:00:00+00:00', meta: { setting, value },
    });
    const render = (rows, locale = 'en') => mount(AuditTable, { props: { entries: rows }, attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))], stubs: { Link: true } } });

    it('names the setting and the new duration', () => {
        wrapper = render([row('security.idle_seconds', 7200), row('security.confirm_window_seconds', 900)]);
        expect(document.body.textContent).toContain('Re-confirmation after inactivity set to 2 hours');
        expect(document.body.textContent).toContain('Re-confirmation of sensitive actions set to 15 minutes');
    });

    it('uses the right plural in Slovak and Polish', () => {
        wrapper = render([row('security.confirm_window_seconds', 120)], 'sk');
        expect(document.body.textContent).toContain('nastavené na 2 minúty');
        wrapper.unmount();
        wrapper = render([row('security.confirm_window_seconds', 300)], 'pl');
        expect(document.body.textContent).toContain('ustawione na 5 minut');
    });

    it('keeps the requirement switch wording for the other setting', () => {
        wrapper = render([row('require_second_factor', true)]);
        expect(document.body.textContent).toContain('Requirement turned on');
    });
});
