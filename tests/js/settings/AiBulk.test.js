import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import { readFileSync } from 'node:fs';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { pseudoLong } from '../helpers/pseudoLong.js';
import { createAppI18n } from '../../../resources/js/i18n.js';

const fetchMock = vi.fn();
vi.mock('../../../resources/js/Security/securityFetch.js', () => ({ securityFetch: (...a) => fetchMock(...a) }));

import BulkRegenPanel from '../../../resources/js/Settings/AiAgents/BulkRegenPanel.vue';
import BulkHistoryTable from '../../../resources/js/Settings/AiAgents/BulkHistoryTable.vue';
import { useToast } from '../../../resources/js/Components/ui/useToast.js';

const load = (l) => JSON.parse(readFileSync(`${process.cwd()}/lang/${l}.json`, 'utf8'));
const src = (p) => readFileSync(`${process.cwd()}/${p}`, 'utf8');

const CONFIG = { defaultCap: 20, maxCap: 30, startHour: 2, timezone: 'Europe/Bratislava', validMinutes: 15 };
const RUN = (extra = {}) => ({
    id: 'run-1', status: 'active', state: 'running', scope: 'no_proposal', dailyCap: 20, total: 40, done: 12, read: 9, failed: 2, skipped: 1,
    waiting: 0, left: 25, todayUsed: 3, nextBatchAfter: null, availableAt: null, ...extra,
});
const ROW = (extra = {}) => ({ startedAt: '2026-11-10T09:30:00+00:00', scope: 'all', dailyCap: 20, wouldRead: 18, read: 15, failed: 2, skipped: 1, status: 'completed', ...extra });
const PANEL = (active = null, history = [ROW(), ROW({ scope: 'last_failed', status: 'cancelled', startedAt: '2026-11-01T09:30:00+00:00' })]) => ({ active, history });
const COUNTS = (extra = {}) => ({ eligible: 18, skipped_consent: 2, skipped_unsupported: 3, skipped_decided: 4, skipped_active: 1, ...extra });
const DRY = (counts = COUNTS()) => ({ ok: true, status: 200, data: { token: '11111111-1111-4111-8111-111111111111', checkedAt: '2026-11-14T10:00:00+00:00', validMinutes: 15, counts } });
const AGENTS = [{ key: 'codex', enabled: true, position: 1, health: { state: 'logged_in' } }];

let current;
function mountPanel({ locale = 'en', messages, ...props } = {}) {
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    current = mount(BulkRegenPanel, {
        props: { bulk: PANEL(), bulkConfig: CONFIG, providers: ['codex'], agents: AGENTS, ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, messages ?? load(locale))] },
    });
    return current;
}
const startBtn = () => current.find('[data-control="start"]');
const checkBtn = () => current.find('[data-control="check"]');
const status = () => current.find('p.visually-hidden[role="status"]').text();
const radios = () => current.findAll('[role="radio"]');
const capInput = () => current.find('input[type="number"]');
const dialog = () => document.body.querySelector('[role="dialog"]');
const dialogButton = (text) => [...(dialog()?.querySelectorAll('button') ?? [])].find((b) => b.textContent.trim() === text);
const toggle = () => current.find('[data-control="toggle"]');
const urlOf = (call) => call[0];

async function doCheck(counts) {
    fetchMock.mockResolvedValueOnce(DRY(counts));
    await checkBtn().trigger('click');
    await flushPromises();
}

beforeEach(() => {
    useToast().toasts.splice(0);
    fetchMock.mockReset();
});
afterEach(() => {
    current?.unmount(); current = null; document.body.innerHTML = '';
    vi.useRealTimers(); vi.restoreAllMocks();
    Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true });
});

describe('Bulk panel: plan block', () => {
    it('shows the panel heading, intro, legend and the three scopes with the default selected', () => {
        mountPanel();
        expect(current.find('h2').text()).toBe('Bulk re-generation');
        expect(current.text()).toContain('you see only counts, never names or content');
        expect(current.find('[role="radiogroup"]').attributes('aria-labelledby')).toBeTruthy();
        expect(radios().map((r) => r.text().split('\n')[0])).toHaveLength(3);
        expect(radios()[0].attributes('aria-checked')).toBe('true');
        expect(radios()[2].text()).toContain('Documents with accepted suggestions are still skipped.');
        expect(capInput().element.value).toBe('20');
    });

    it('Start is aria-disabled with a visible reason until a check exists; pressing it sends nothing and announces the reason', async () => {
        mountPanel();
        expect(startBtn().attributes('aria-disabled')).toBe('true');
        const reasonId = startBtn().attributes('aria-describedby');
        expect(current.find(`#${reasonId}`).text()).toBe('Check what would run first.');
        await startBtn().trigger('click');
        await nextTick();
        expect(fetchMock).not.toHaveBeenCalled();
        expect(status()).toBe('Check what would run first.');
        expect(dialog()).toBeNull();
    });

    it('arrow keys move and select the scope, with one Tab stop', async () => {
        mountPanel();
        expect(radios().filter((r) => r.attributes('tabindex') === '0')).toHaveLength(1);
        await radios()[0].trigger('keydown', { key: 'ArrowDown' });
        expect(radios()[1].attributes('aria-checked')).toBe('true');
        expect(radios()[1].attributes('tabindex')).toBe('0');
        expect(radios()[0].attributes('tabindex')).toBe('-1');
        await radios()[1].trigger('keydown', { key: 'ArrowUp' });
        await radios()[0].trigger('keydown', { key: 'ArrowUp' });
        expect(radios()[2].attributes('aria-checked')).toBe('true');
        expect(document.activeElement).toBe(radios()[2].element);
        expect(radios().filter((r) => r.attributes('tabindex') === '0')).toHaveLength(1);
    });

    it.each(['0', '31', 'abc', ''])('cap "%s" shows the error on check and sends nothing', async (value) => {
        mountPanel();
        await capInput().setValue(value);
        await checkBtn().trigger('click');
        await flushPromises();
        expect(fetchMock).not.toHaveBeenCalled();
        expect(current.text()).toContain('Choose a whole number from 1 to 30.');
        expect(capInput().attributes('aria-invalid')).toBe('true');
    });

    it('a dry run posts scope and cap, focuses the result heading, announces once and shows counts only', async () => {
        mountPanel();
        await capInput().setValue('5');
        await radios()[1].trigger('click');
        await doCheck();
        expect(fetchMock).toHaveBeenCalledWith('/settings/ai-agents/bulk/dry-run', { method: 'POST', body: { scope: 'last_failed', cap: 5 } });
        const heading = current.find('h3[tabindex="-1"]');
        expect(heading.text()).toBe('Result of the check');
        expect(document.activeElement).toBe(heading.element);
        expect(status()).toBe('Check finished. 18 documents would be read.');
        const dl = current.find('[data-testid="bulk-result"] dl');
        expect(dl.text()).toContain('Documents that would be read');
        expect(dl.findAll('dd').map((d) => d.text())).toEqual(['18', '2', '3', '4', '1']);
        expect(current.find('[data-testid="bulk-result"]').text()).toContain('About 4 days at 5 per day');
        expect(current.find('[data-testid="bulk-result"] time').attributes('datetime')).toBe('2026-11-14T10:00:00.000Z');
        expect(current.text()).toContain('This check is valid for 15 minutes.');
        expect(startBtn().attributes('aria-disabled')).toBeUndefined();
        expect(current.find(`#${startBtn().attributes('aria-describedby') ?? 'none'}`).exists()).toBe(false);
    });

    it('zero eligible shows the zero line and keeps Start disabled', async () => {
        mountPanel();
        await doCheck(COUNTS({ eligible: 0 }));
        expect(current.find('[data-testid="bulk-result"]').text()).toContain('Nothing would be read with these settings.');
        expect(current.find('[data-testid="bulk-result"]').text()).not.toContain('About');
        expect(startBtn().attributes('aria-disabled')).toBe('true');
        expect(current.find(`#${startBtn().attributes('aria-describedby')}`).text()).toBe('Nothing would be read with these settings.');
        await startBtn().trigger('click');
        expect(dialog()).toBeNull();
    });

    it('changing the scope or the cap after a check makes it stale', async () => {
        mountPanel();
        await doCheck();
        expect(current.text()).not.toContain('This check is out of date');
        await radios()[2].trigger('click');
        expect(current.text()).toContain('This check is out of date. Check again before starting.');
        expect(current.find('[data-testid="bulk-result"]').classes()).toContain('is-stale');
        expect(startBtn().attributes('aria-disabled')).toBe('true');
        expect(current.find(`#${startBtn().attributes('aria-describedby')}`).text()).toBe('The last check is out of date. Check again.');
        await radios()[0].trigger('click');
        expect(current.text()).not.toContain('This check is out of date');
        await capInput().setValue('7');
        expect(current.text()).toContain('This check is out of date');
    });

    it('a check older than its validity window becomes stale', async () => {
        vi.useFakeTimers();
        mountPanel();
        await doCheck();
        expect(startBtn().attributes('aria-disabled')).toBeUndefined();
        vi.advanceTimersByTime(14 * 60000);
        await nextTick();
        expect(startBtn().attributes('aria-disabled')).toBeUndefined();
        vi.advanceTimersByTime(60001);
        await nextTick();
        expect(current.text()).toContain('This check is out of date. Check again before starting.');
        expect(startBtn().attributes('aria-disabled')).toBe('true');
    });

    it('a dry-run error shows one danger alert and starts nothing', async () => {
        mountPanel();
        fetchMock.mockResolvedValueOnce({ ok: false, status: 500, error: 'failed', data: {} });
        await checkBtn().trigger('click');
        await flushPromises();
        const alerts = current.findAll('[role="alert"]');
        expect(alerts).toHaveLength(1);
        expect(alerts[0].text()).toBe('We could not check what would run. Nothing was started. Try again.');
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(startBtn().attributes('aria-disabled')).toBe('true');
    });

    it('marks the check button busy and the other controls inert while checking', async () => {
        mountPanel();
        let resolve;
        fetchMock.mockReturnValueOnce(new Promise((r) => { resolve = r; }));
        await checkBtn().trigger('click');
        expect(checkBtn().attributes('aria-busy')).toBe('true');
        expect(checkBtn().attributes('aria-disabled')).toBe('true');
        expect(current.text()).toContain('Checking');
        await radios()[2].trigger('click');
        expect(radios()[0].attributes('aria-checked')).toBe('true');
        resolve(DRY());
        await flushPromises();
        expect(checkBtn().attributes('aria-busy')).toBeUndefined();
    });

    it('writes nothing to any browser storage', async () => {
        const local = vi.spyOn(Storage.prototype, 'setItem');
        mountPanel();
        await doCheck();
        await startBtn().trigger('click');
        await flushPromises();
        expect(local).not.toHaveBeenCalled();
        const source = ['BulkRegenPanel.vue', 'BulkProgress.vue', 'BulkHistoryTable.vue', 'useBulkStatus.js']
            .map((f) => src(`resources/js/Settings/AiAgents/${f}`)).join('\n');
        expect(source).not.toMatch(/localStorage|sessionStorage|indexedDB|remember\(/);
    });
});

describe('Bulk panel: start confirmation', () => {
    it('opens a confirm that names the providers, count, cap and hour, with the safe button focused', async () => {
        mountPanel({ providers: ['codex', 'copilot'] });
        await capInput().setValue('5');
        await doCheck();
        await startBtn().trigger('click');
        await flushPromises();
        expect(dialog().textContent).toContain('Start bulk re-generation?');
        expect(dialog().textContent).toContain('18 documents will be sent to OpenAI or GitHub to be read, at most 5 per day, one at a time, after');
        expect(dialog().textContent).toMatch(/after 0?2:00|after 2:00/);
        expect(document.activeElement.textContent.trim()).toBe('Do not start');
        expect(dialog().textContent).not.toContain('No AI tool is signed in');
    });

    it.each([[['codex'], 'sent to OpenAI to'], [['copilot'], 'sent to GitHub to']])('provider wording for %j', async (providers, text) => {
        mountPanel({ providers });
        await doCheck(COUNTS({ eligible: 1 }));
        await startBtn().trigger('click');
        await flushPromises();
        expect(dialog().textContent).toContain(`1 document will be ${text}`);
    });

    it('shows the informative sign-in note when no enabled agent is logged in', async () => {
        mountPanel({ agents: [{ key: 'codex', enabled: true, position: 1, health: { state: 'expired' } }] });
        await doCheck();
        await startBtn().trigger('click');
        await flushPromises();
        expect(dialog().textContent).toContain('No AI tool is signed in right now. The run will wait until one is.');
    });

    it('Do not start and Esc close it without sending', async () => {
        mountPanel();
        await doCheck();
        await startBtn().trigger('click');
        await flushPromises();
        dialogButton('Do not start').click();
        await flushPromises();
        expect(dialog()).toBeNull();
        await startBtn().trigger('click');
        await flushPromises();
        dialog().parentElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    async function confirmStart(response) {
        mountPanel();
        await doCheck();
        await startBtn().trigger('click');
        await flushPromises();
        fetchMock.mockResolvedValueOnce(response);
        dialogButton('Start bulk run').click();
        await flushPromises();
    }

    it('sends the token and acknowledged true, then 201 shows block B and focuses the Progress heading', async () => {
        await confirmStart({ ok: true, status: 201, data: { panel: PANEL(RUN()) } });
        expect(fetchMock).toHaveBeenLastCalledWith('/settings/ai-agents/bulk', { method: 'POST', body: { token: '11111111-1111-4111-8111-111111111111', acknowledged: true } });
        expect(useToast().toasts.map((x) => x.message)).toContain('Bulk run started');
        expect(current.text()).toContain('A bulk run is active. Pause or cancel it before planning another.');
        expect(current.find('[role="radiogroup"]').exists()).toBe(false);
        const heading = current.findAll('h3').find((h) => h.text() === 'Progress');
        expect(document.activeElement).toBe(heading.element);
        expect(startBtn().exists()).toBe(false);
    });

    it('410 closes the dialog and shows the stale alert', async () => {
        await confirmStart({ ok: false, status: 410, error: 'failed', data: { reason: 'expired' } });
        expect(dialog()).toBeNull();
        expect(current.text()).toContain('This check is out of date. Check again before starting.');
        expect(startBtn().attributes('aria-disabled')).toBe('true');
    });

    it('409 stale closes the dialog and shows the stale alert', async () => {
        await confirmStart({ ok: false, status: 409, error: 'failed', data: { reason: 'stale' } });
        expect(dialog()).toBeNull();
        expect(current.text()).toContain('This check is out of date');
    });

    it('409 active switches to block B quietly and announces it', async () => {
        await confirmStart({ ok: false, status: 409, error: 'failed', data: { reason: 'active', panel: PANEL(RUN()) } });
        expect(current.find('[data-testid="bulk-state"]').exists()).toBe(true);
        expect(current.findAll('[role="alert"]')).toHaveLength(0);
        expect(status()).toBe('A bulk run is already active. Pause or cancel it first.');
    });

    it('a server error shows one start error alert and nothing starts', async () => {
        await confirmStart({ ok: false, status: 500, error: 'failed', data: {} });
        const alerts = current.findAll('[role="alert"]');
        expect(alerts).toHaveLength(1);
        expect(alerts[0].text()).toBe('We could not start the run. Nothing was sent. Try again.');
        expect(current.find('[data-testid="bulk-state"]').exists()).toBe(false);
    });
});

describe('Bulk panel: active run', () => {
    it('shows the progressbar, plural sentence, counts and today usage', () => {
        mountPanel({ bulk: PANEL(RUN()) });
        const bar = current.find('[role="progressbar"]');
        expect(bar.attributes('aria-valuenow')).toBe('12');
        expect(bar.attributes('aria-valuemax')).toBe('40');
        expect(bar.attributes('aria-valuetext')).toBe('12 of 40 documents handled');
        expect(current.text()).toContain('12 of 40 documents handled');
        const dl = current.find('dl');
        expect(dl.findAll('dt').map((d) => d.text())).toEqual(['Read', 'Failed', 'Skipped', 'Waiting for AI quota', 'Left']);
        expect(dl.findAll('dd').map((d) => d.text())).toEqual(['9', '2', '1', '0', '25']);
        expect(current.text()).toContain('Today: 3 of 20 runs');
        expect(current.find('[data-testid="bulk-state"]').text()).toBe('Running');
    });

    it('next batch, waiting and stopped states', () => {
        mountPanel({ bulk: PANEL(RUN({ state: 'next_batch', nextBatchAfter: '2026-11-15T01:00:00+00:00' })) });
        const tag = current.find('[data-testid="bulk-state"]');
        expect(tag.text()).toMatch(/^Next batch after /);
        expect(tag.find('time').attributes('datetime')).toBe('2026-11-15T01:00:00.000Z');
        current.unmount();
        mountPanel({ bulk: PANEL(RUN({ state: 'waiting_quota', waiting: 2, availableAt: '2026-11-14T16:00:00+00:00' })) });
        expect(current.find('[data-testid="bulk-state"]').text()).toContain('Waiting for AI quota');
        expect(current.find('[data-testid="bulk-state"] time').exists()).toBe(true);
        current.unmount();
        mountPanel({ bulk: PANEL(RUN({ state: 'stopped' })) });
        expect(current.find('[data-testid="bulk-state"]').text()).toBe('Stopped');
        expect(current.find('[data-testid="bulk-state"]').classes()).toContain('ui-tag--danger');
        // the pause control stays available when stopped
        expect(toggle().attributes('aria-label')).toBe('Pause bulk run');
    });

    it('Pause has the accessible name "Pause bulk run", keeps focus, toggles to Resume and back', async () => {
        mountPanel({ bulk: PANEL(RUN()) });
        expect(toggle().attributes('aria-label')).toBe('Pause bulk run');
        expect(toggle().text()).toBe('Pause');
        toggle().element.focus();
        fetchMock.mockResolvedValueOnce({ ok: true, status: 200, data: { panel: PANEL(RUN({ status: 'paused', state: 'paused' })) } });
        await toggle().trigger('click');
        await flushPromises();
        expect(fetchMock).toHaveBeenLastCalledWith('/settings/ai-agents/bulk/run-1/pause', { method: 'POST' });
        expect(useToast().toasts.map((x) => x.message)).toContain('Bulk run paused');
        expect(toggle().text()).toBe('Resume');
        expect(toggle().attributes('aria-label')).toBe('Resume bulk run');
        expect(document.activeElement).toBe(toggle().element);
        expect(current.find('[data-testid="bulk-state"]').text()).toBe('Paused');
        fetchMock.mockResolvedValueOnce({ ok: true, status: 200, data: { panel: PANEL(RUN()) } });
        await toggle().trigger('click');
        await flushPromises();
        expect(fetchMock).toHaveBeenLastCalledWith('/settings/ai-agents/bulk/run-1/resume', { method: 'POST' });
        expect(toggle().text()).toBe('Pause');
        expect(document.activeElement).toBe(toggle().element);
    });

    it('Cancel asks first with "Keep running" focused, then cancels, returns the plan block and focuses the legend', async () => {
        mountPanel({ bulk: PANEL(RUN()) });
        await current.find('[data-control="cancel"]').trigger('click');
        await flushPromises();
        expect(dialog().textContent).toContain('Cancel the bulk run?');
        expect(document.activeElement.textContent.trim()).toBe('Keep running');
        dialogButton('Keep running').click();
        await flushPromises();
        expect(fetchMock).not.toHaveBeenCalled();
        await current.find('[data-control="cancel"]').trigger('click');
        await flushPromises();
        fetchMock.mockResolvedValueOnce({ ok: true, status: 200, data: { panel: PANEL(null, [ROW({ status: 'cancelled' })]) } });
        dialogButton('Cancel bulk run').click();
        await flushPromises();
        expect(fetchMock).toHaveBeenLastCalledWith('/settings/ai-agents/bulk/run-1/cancel', { method: 'POST' });
        expect(useToast().toasts.map((x) => x.message)).toContain('Bulk run cancelled');
        expect(current.find('[role="radiogroup"]').exists()).toBe(true);
        const legend = current.find('[role="radiogroup"] > span');
        expect(document.activeElement).toBe(legend.element);
    });

    it('a control error shows one alert; a 409 adopts the returned panel', async () => {
        mountPanel({ bulk: PANEL(RUN()) });
        fetchMock.mockResolvedValueOnce({ ok: false, status: 500, error: 'failed', data: {} });
        await toggle().trigger('click');
        await flushPromises();
        expect(current.findAll('[role="alert"]')).toHaveLength(1);
        fetchMock.mockResolvedValueOnce({ ok: false, status: 409, error: 'failed', data: { reason: 'conflict', panel: PANEL(RUN({ status: 'paused', state: 'paused' })) } });
        await toggle().trigger('click');
        await flushPromises();
        expect(current.findAll('[role="alert"]')).toHaveLength(0);
        expect(toggle().text()).toBe('Resume');
    });
});

describe('Bulk panel: polling', () => {
    const visibility = (value) => {
        Object.defineProperty(document, 'visibilityState', { value, configurable: true });
        document.dispatchEvent(new Event('visibilitychange'));
    };
    const polls = () => fetchMock.mock.calls.filter((c) => urlOf(c) === '/settings/ai-agents/bulk' && c[1] === undefined);

    it('polls every 15 seconds while visible, never announces counts per tick', async () => {
        vi.useFakeTimers();
        mountPanel({ bulk: PANEL(RUN()) });
        fetchMock.mockResolvedValue({ ok: true, status: 200, data: PANEL(RUN({ done: 20 })) });
        await vi.advanceTimersByTimeAsync(14999);
        expect(polls()).toHaveLength(0);
        await vi.advanceTimersByTimeAsync(2);
        expect(polls()).toHaveLength(1);
        expect(current.find('[role="progressbar"]').attributes('aria-valuenow')).toBe('20');
        expect(status()).toBe('');
        await vi.advanceTimersByTimeAsync(15000);
        expect(polls()).toHaveLength(2);
    });

    it('pauses while hidden and resumes with an immediate poll when visible again', async () => {
        vi.useFakeTimers();
        mountPanel({ bulk: PANEL(RUN()) });
        fetchMock.mockResolvedValue({ ok: true, status: 200, data: PANEL(RUN()) });
        visibility('hidden');
        await vi.advanceTimersByTimeAsync(60000);
        expect(polls()).toHaveLength(0);
        visibility('visible');
        await vi.advanceTimersByTimeAsync(1);
        expect(polls()).toHaveLength(1);
    });

    it('does not poll when no run is open', async () => {
        vi.useFakeTimers();
        mountPanel();
        await vi.advanceTimersByTimeAsync(60000);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('announces only transitions, and stops polling when the run completes, then shows the finished alert and the plan block', async () => {
        vi.useFakeTimers();
        mountPanel({ bulk: PANEL(RUN()) });
        fetchMock.mockResolvedValueOnce({ ok: true, status: 200, data: PANEL(RUN({ status: 'paused', state: 'paused' })) });
        await vi.advanceTimersByTimeAsync(15001);
        expect(status()).toBe('Bulk run paused.');
        fetchMock.mockResolvedValueOnce({ ok: true, status: 200, data: PANEL(RUN()) });
        await vi.advanceTimersByTimeAsync(15000);
        expect(status()).toBe('Bulk run resumed.');
        fetchMock.mockResolvedValueOnce({ ok: true, status: 200, data: PANEL(null, [ROW({ read: 30, failed: 4, skipped: 6 })]) });
        await vi.advanceTimersByTimeAsync(15000);
        expect(status()).toBe('The bulk run finished.');
        const alert = current.find('[data-testid="bulk-finished"]');
        expect(alert.text()).toContain('The bulk run finished.');
        expect(alert.findAll('dd').map((d) => d.text())).toEqual(['30', '4', '6']);
        expect(current.find('[role="radiogroup"]').exists()).toBe(true);
        const calls = polls().length;
        await vi.advanceTimersByTimeAsync(60000);
        expect(polls()).toHaveLength(calls);
    });

    it('stops polling when another admin cancels the run', async () => {
        vi.useFakeTimers();
        mountPanel({ bulk: PANEL(RUN()) });
        fetchMock.mockResolvedValueOnce({ ok: true, status: 200, data: PANEL(null, [ROW({ status: 'cancelled' })]) });
        await vi.advanceTimersByTimeAsync(15001);
        const calls = polls().length;
        await vi.advanceTimersByTimeAsync(60000);
        expect(polls()).toHaveLength(calls);
        expect(current.find('[data-testid="bulk-finished"]').exists()).toBe(false);
    });

    it('announces entering the quota wait with the time', async () => {
        vi.useFakeTimers();
        mountPanel({ bulk: PANEL(RUN()) });
        fetchMock.mockResolvedValueOnce({ ok: true, status: 200, data: PANEL(RUN({ state: 'waiting_quota', waiting: 1, availableAt: '2026-11-14T16:00:00+00:00' })) });
        await vi.advanceTimersByTimeAsync(15001);
        expect(status()).toMatch(/^Waiting for AI quota\. Retrying after .+\.$/);
    });

    it('three consecutive errors show the inline error with Try again, which resumes polling', async () => {
        vi.useFakeTimers();
        mountPanel({ bulk: PANEL(RUN()) });
        fetchMock.mockResolvedValue({ ok: false, status: 0, error: 'network', data: {} });
        await vi.advanceTimersByTimeAsync(15001);
        await vi.advanceTimersByTimeAsync(15000);
        expect(current.text()).not.toContain('We could not check the progress');
        await vi.advanceTimersByTimeAsync(15000);
        expect(current.text()).toContain('We could not check the progress. Check your connection.');
        const calls = polls().length;
        await vi.advanceTimersByTimeAsync(60000);
        expect(polls()).toHaveLength(calls);
        fetchMock.mockResolvedValue({ ok: true, status: 200, data: PANEL(RUN({ done: 30 })) });
        const retry = current.findAll('button').find((b) => b.text() === 'Try loading again');
        await retry.trigger('click');
        await vi.advanceTimersByTimeAsync(1);
        expect(current.text()).not.toContain('We could not check the progress');
        expect(current.find('[role="progressbar"]').attributes('aria-valuenow')).toBe('30');
    });
});

describe('Bulk history', () => {
    it('lists the runs newest first with eight columns, a caption, a note and scope labels', () => {
        mountPanel();
        const table = current.find('table');
        expect(table.find('caption').text()).toBe('Earlier bulk runs');
        expect(table.findAll('th').map((h) => h.text())).toEqual(['Started', 'Which documents', 'Per day', 'Would read', 'Read', 'Failed', 'Skipped', 'Status']);
        const rows = table.findAll('tbody tr');
        expect(rows).toHaveLength(2);
        expect(rows[0].findAll('td')[1].text()).toBe('All documents');
        expect(rows[1].findAll('td')[1].text()).toBe('Documents whose latest AI run failed');
        expect(rows[0].findAll('td')[7].text()).toBe('Completed');
        expect(rows[1].findAll('td')[7].text()).toBe('Cancelled');
        expect(rows[0].find('time').attributes('datetime')).toBe('2026-11-10T09:30:00.000Z');
        expect(rows[0].findAll('td').every((td) => td.attributes('data-label'))).toBe(true);
        expect(current.text()).toContain('Showing the last 10 runs. Only counts are kept.');
        expect(rows[0].findAll('button, a, input')).toHaveLength(0);
        expect(current.findAll('h3').map((h) => h.text())).toContain('Earlier bulk runs');
    });

    it('empty state uses ListChecks, loading shows three skeleton rows, error offers a retry', async () => {
        mountPanel({ bulk: PANEL(null, []) });
        expect(current.text()).toContain('No bulk runs yet');
        expect(current.text()).toContain('When you start a bulk run, its counts are listed here.');
        expect(current.html()).toContain('lucide-list-checks');
        expect(current.html()).not.toContain('lucide-layers');
        current.unmount();
        mountPanel({ bulk: null });
        expect(current.find('table[aria-busy="true"]').exists()).toBe(true);
        expect(current.findAll('tbody tr')).toHaveLength(3);
        current.unmount();
        mountPanel({ loadFailed: true });
        expect(current.text()).toContain('Try loading again');
    });

    it('reads only the contract fields, so extra fields never render', () => {
        const messages = load('en');
        const w = mount(BulkHistoryTable, {
            props: { rows: [{ ...ROW(), startedBy: 'CANARY-ADMIN-NAME', documentId: 'CANARY-DOC-ID', title: 'CANARY-TITLE', person: 'CANARY-PERSON' }] },
            global: { plugins: [createAppI18n('en', messages)] },
        });
        expect(w.html()).not.toContain('CANARY');
        w.unmount();
        mountPanel({ bulk: PANEL(RUN({ startedBy: 'CANARY-ADMIN', documentTitle: 'CANARY-DOC' }), [{ ...ROW(), startedBy: 'CANARY-X', memberName: 'CANARY-Y' }]) });
        expect(current.html()).not.toContain('CANARY');
    });

    it('caps the list at ten rows', () => {
        mountPanel({ bulk: PANEL(null, Array.from({ length: 14 }, () => ROW())) });
        expect(current.findAll('tbody tr')).toHaveLength(10);
    });
});

describe('Bulk panel: language, layout and plurals', () => {
    it('has no truncation hooks and wraps at 320 px', () => {
        const css = ['BulkRegenPanel.vue', 'BulkProgress.vue', 'BulkHistoryTable.vue'].map((f) => src(`resources/js/Settings/AiAgents/${f}`)).join('\n');
        expect(css).not.toMatch(/text-overflow:\s*ellipsis/);
        expect(css).not.toMatch(/overflow-x:\s*(auto|scroll)/);
        expect(src('resources/js/Settings/AiAgents/BulkProgress.vue')).toMatch(/\.ui-tag\.bulk-tag[^}]*white-space:\s*normal/);
        expect(src('resources/js/Settings/AiAgents/BulkRegenPanel.vue')).toMatch(/min-height:\s*44px/);
    });

    it.each([['en'], ['sk'], ['pl']])('pseudo-long %s text renders in full on every state', async (locale) => {
        document.body.style.width = '320px';
        const long = pseudoLong(load(locale));
        const states = [PANEL(RUN({ state: 'next_batch', nextBatchAfter: '2026-11-15T01:00:00+00:00' })), PANEL(RUN({ state: 'waiting_quota', availableAt: '2026-11-14T16:00:00+00:00' })), PANEL(RUN({ state: 'stopped' }))];
        for (const bulk of states) {
            mountPanel({ locale, messages: long, bulk });
            expect(current.find('[data-testid="bulk-state"]').text().length).toBeGreaterThan(8);
            current.unmount();
        }
        mountPanel({ locale, messages: long });
        await doCheck();
        expect(current.find('[role="radio"]').text()).toContain('ŵ');
        expect(current.find('[data-testid="bulk-result"] dt').text()).toContain('ŵ');
        document.body.style.width = '';
    });

    const NS = [0, 1, 2, 4, 5, 12, 22, 25];
    it.each([['sk'], ['pl'], ['en']])('plural keys render for 0, 1, 2, 4, 5, 12, 22, 25 in %s', (locale) => {
        const i18n = createAppI18n(locale, load(locale));
        const t = i18n.global.t;
        for (const n of NS) {
            const lines = [
                t('settings.ai_bulk.progress', { done: 3, n }, n),
                t('settings.ai_bulk.days_estimate', { n, cap: 5 }, n),
                t('settings.ai_bulk.confirm_body', { n, providers: 'OpenAI', cap: 5, hour: '2:00' }, n),
                t('settings.ai_bulk.valid_minutes', { n }, n),
            ];
            for (const line of lines) {
                expect(line).toContain(String(n));
                expect(line).not.toMatch(/[{}]|\|/);
            }
        }
        if (locale !== 'en') {
            const one = t('settings.ai_bulk.progress', { done: 0, n: 1 }, 1);
            const few = t('settings.ai_bulk.progress', { done: 0, n: 2 }, 2);
            const many = t('settings.ai_bulk.progress', { done: 0, n: 5 }, 5);
            expect(new Set([one, few, many]).size).toBe(3);
        }
    });

    it('renders the panel in sk and pl with their own copy', async () => {
        for (const locale of ['sk', 'pl']) {
            mountPanel({ locale });
            expect(current.find('h2').text()).toBe(load(locale).settings.ai_bulk.heading);
            expect(startBtn().text()).toBe(load(locale).settings.ai_bulk.start);
            await doCheck();
            expect(current.text()).toContain(load(locale).settings.ai_bulk.zero_eligible === '' ? '' : load(locale).settings.ai_bulk.result_heading);
            current.unmount();
        }
    });
});

describe('Bulk panel: accessibility', () => {
    const scenarios = {
        'plan, no result': { bulk: PANEL() },
        'plan, loading history': { bulk: null },
        'plan, empty history': { bulk: PANEL(null, []) },
        'plan, history error': { bulk: PANEL(), loadFailed: true },
        'active running': { bulk: PANEL(RUN()) },
        'active paused': { bulk: PANEL(RUN({ status: 'paused', state: 'paused' })) },
        'active next batch': { bulk: PANEL(RUN({ state: 'next_batch', nextBatchAfter: '2026-11-15T01:00:00+00:00' })) },
        'active waiting': { bulk: PANEL(RUN({ state: 'waiting_quota', waiting: 2, availableAt: '2026-11-14T16:00:00+00:00' })) },
        'active stopped': { bulk: PANEL(RUN({ state: 'stopped' })) },
    };
    for (const locale of ['en', 'sk', 'pl']) {
        for (const theme of ['light', 'dark']) {
            it(`axe clean in ${locale} ${theme} for every static state`, async () => {
                document.documentElement.dataset.theme = theme;
                for (const props of Object.values(scenarios)) {
                    mountPanel({ locale, ...props });
                    await expectNoAxeViolations(current.element);
                    current.unmount();
                }
                delete document.documentElement.dataset.theme;
            });
        }
    }

    it('axe clean for fresh, zero, stale, error, finished and confirm states', async () => {
        mountPanel();
        await doCheck();
        await expectNoAxeViolations(current.element);
        await radios()[1].trigger('click');
        await expectNoAxeViolations(current.element);
        await radios()[0].trigger('click');
        await startBtn().trigger('click');
        await flushPromises();
        await expectNoAxeViolations(document.body);
        dialogButton('Do not start').click();
        await flushPromises();
        current.unmount();

        mountPanel();
        await doCheck(COUNTS({ eligible: 0 }));
        await expectNoAxeViolations(current.element);
        current.unmount();

        mountPanel();
        fetchMock.mockResolvedValueOnce({ ok: false, status: 500, error: 'failed', data: {} });
        await checkBtn().trigger('click');
        await flushPromises();
        await expectNoAxeViolations(current.element);
        current.unmount();

        vi.useFakeTimers();
        mountPanel({ bulk: PANEL(RUN()) });
        fetchMock.mockResolvedValueOnce({ ok: true, status: 200, data: PANEL(null, [ROW()]) });
        await vi.advanceTimersByTimeAsync(15001);
        expect(current.find('[data-testid="bulk-finished"]').exists()).toBe(true);
        vi.useRealTimers();
        await expectNoAxeViolations(current.element);
    });

    it('axe clean with the cancel dialog and the poll error', async () => {
        vi.useFakeTimers();
        mountPanel({ bulk: PANEL(RUN()) });
        fetchMock.mockResolvedValue({ ok: false, status: 0, error: 'network', data: {} });
        for (let i = 0; i < 3; i += 1) await vi.advanceTimersByTimeAsync(15001);
        expect(current.text()).toContain('We could not check the progress');
        vi.useRealTimers();
        await expectNoAxeViolations(current.element);
        await current.find('[data-control="cancel"]').trigger('click');
        await flushPromises();
        await expectNoAxeViolations(document.body);
    });
});

describe('Bulk panel: privacy and wiring', () => {
    it('contains only counts: no id, token or name leaks into the DOM', async () => {
        mountPanel({ bulk: PANEL(RUN()) });
        expect(current.html()).not.toContain('run-1');
        current.unmount();
        mountPanel();
        await doCheck();
        expect(current.html()).not.toContain('11111111-1111-4111-8111-111111111111');
    });

    it('the page includes the panel after the agent list and the page status region', () => {
        const page = src('resources/js/Pages/Settings/AiAgents.vue');
        expect(page.indexOf('<BulkRegenPanel')).toBeGreaterThan(page.indexOf('class="agents__list"'));
        expect(page.indexOf('<BulkRegenPanel')).toBeGreaterThan(page.indexOf('role="status"'));
    });
});
