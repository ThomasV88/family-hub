import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { h, nextTick, ref } from 'vue';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

const { fetchMock, refusal } = vi.hoisted(() => ({ fetchMock: vi.fn(), refusal: vi.fn() }));
vi.mock('../../../resources/js/Security/securityFetch.js', () => ({ securityFetch: fetchMock }));
vi.mock('../../../resources/js/Health/healthRefusal.js', () => ({ handleHealthRefusal: refusal }));
vi.mock('@inertiajs/vue3', () => ({
    router: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn(), reload: vi.fn(), on: vi.fn() },
    usePage: () => ({ props: {}, url: '/health' }),
    Head: { props: ['title'], setup: () => () => null },
    Link: { props: ['href'], setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) },
}));

import RunAiControl from '../../../resources/js/Health/Ai/RunAiControl.vue';
import DocumentPanel from '../../../resources/js/Health/DocumentPanel.vue';
import { useToast } from '../../../resources/js/Components/ui/useToast.js';

const PID = '11111111-1111-4111-8111-111111111111';
const DOC = { id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1', kind: 'pdf', title: 'Lab result', visitId: null, uploadedOn: '2025-05-02', sizeBytes: 2048 };
const HEIC = { ...DOC, kind: 'heic' };
const AI = { canRun: true, available: true, noticeAcknowledged: false };
const entry = (over = {}) => ({ status: 'ready', extractionId: 'ex1', readyExtractionId: 'ex1', cancelReason: null, pagesRead: null, pagesTotal: null, entryCount: 3, pending: 0, ...over });

const $ = (sel) => document.querySelector(sel);
const $$ = (sel) => [...document.querySelectorAll(sel)];
const flush = async () => { for (let i = 0; i < 4; i += 1) { await Promise.resolve(); await nextTick(); } };

let wrapper;
let poller;
function makePoller(initial = {}) {
    const statuses = ref(initial);
    return {
        statuses,
        available: ref(null),
        failedPolls: ref(0),
        stoppedLongWait: ref(false),
        stalled: ref(false),
        refresh: vi.fn(async () => {}),
        markQueued: vi.fn((id, extractionId) => { statuses.value = { ...statuses.value, [id]: { status: 'queued', extractionId, readyExtractionId: null, cancelReason: null, pagesRead: null, pagesTotal: null } }; }),
    };
}
function mountControl({ document: doc = DOC, ai = AI, consentOn = true, statuses = {}, locale = 'en' } = {}) {
    poller = makePoller(statuses);
    wrapper = mount(RunAiControl, {
        props: { personId: PID, document: doc, ai, consentOn, poller },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, loadMessages(locale))] },
    });
    return wrapper;
}
beforeEach(() => { fetchMock.mockReset(); refusal.mockReset(); useToast().toasts.splice(0); });
afterEach(() => { wrapper?.unmount(); wrapper = null; document.body.innerHTML = ''; document.documentElement.classList.remove('dark'); });

const runUrl = `/health/people/${PID}/documents/${DOC.id}/ai`;

describe('Run AI start flow', () => {
    it('shows "Run AI" with the permanent sends line linked by aria-describedby', () => {
        mountControl();
        const button = $('[data-testid="ai-run"]');
        expect(button.textContent).toContain('Run AI');
        expect(button.getAttribute('aria-disabled')).toBeNull();
        const line = $('[data-testid="ai-sends"]');
        expect(line.textContent).toBe('Sends this document to OpenAI.');
        expect(button.getAttribute('aria-describedby')).toBe(line.id);
    });

    it('opens the notice on the first press; "Keep document private" sends nothing', async () => {
        mountControl();
        $('[data-testid="ai-run"]').click();
        await flush();
        expect($('[role="dialog"]').textContent).toContain('Send this document to OpenAI?');
        expect(document.activeElement).toBe($('[data-testid="ai-notice-cancel"]'));
        $('[data-testid="ai-notice-cancel"]').click();
        await flush();
        expect(fetchMock).not.toHaveBeenCalled();
        expect($('[role="dialog"]')).toBeNull();
    });

    it('POSTs {acknowledged: true} on confirm, then toasts, shows "Waiting for AI" and focuses "Cancel run"', async () => {
        fetchMock.mockResolvedValue({ ok: true, status: 202, data: { status: 'queued', extractionId: 'ex9' } });
        mountControl();
        $('[data-testid="ai-run"]').click();
        await flush();
        $('[data-testid="ai-notice-confirm"]').click();
        await flush();
        expect(fetchMock).toHaveBeenCalledWith(runUrl, { method: 'POST', body: { acknowledged: true, providers: ['codex'] } });
        expect(useToast().toasts.map((x) => x.message)).toContain('AI run started');
        expect(poller.refresh).toHaveBeenCalled();
        expect($('[data-testid="ai-status-tag"]').textContent).toBe('Waiting for AI');
        expect(document.activeElement).toBe($('[data-testid="ai-cancel"]'));
        expect($('[data-testid="run-ai"]').getAttribute('aria-busy')).toBe('true');
    });

    it('POSTs directly once the notice was acknowledged', async () => {
        fetchMock.mockResolvedValue({ ok: true, status: 202, data: { extractionId: 'ex9' } });
        mountControl({ ai: { ...AI, noticeAcknowledged: true } });
        $('[data-testid="ai-run"]').click();
        await flush();
        expect($('[role="dialog"]')).toBeNull();
        expect(fetchMock).toHaveBeenCalledWith(runUrl, { method: 'POST', body: {} });
    });

    it('switches to the consent-off line on 409 consent_off without an error alert', async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 409, error: 'failed', data: { reason: 'consent_off' } });
        mountControl({ ai: { ...AI, noticeAcknowledged: true } });
        $('[data-testid="ai-run"]').click();
        await flush();
        expect($('[data-testid="ai-blocked"]').textContent).toContain('AI is off for this person.');
        expect($('[role="alert"]')).toBeNull();
        expect($('[data-testid="ai-run"]').getAttribute('aria-disabled')).toBe('true');
    });

    it.each([
        ['unavailable', 'AI is not available right now.'],
        ['unsupported', 'AI cannot read this file type.'],
    ])('shows the %s line on 409', async (reason, text) => {
        fetchMock.mockResolvedValue({ ok: false, status: 409, error: 'failed', data: { reason } });
        mountControl({ ai: { ...AI, noticeAcknowledged: true } });
        $('[data-testid="ai-run"]').click();
        await flush();
        expect($('[data-testid="ai-blocked"]').textContent).toContain(text);
        expect($('[role="alert"]')).toBeNull();
    });

    it('refreshes on 409 active', async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 409, error: 'failed', data: { reason: 'active' } });
        mountControl({ ai: { ...AI, noticeAcknowledged: true } });
        $('[data-testid="ai-run"]').click();
        await flush();
        expect(poller.refresh).toHaveBeenCalled();
        expect($('[role="alert"]')).toBeNull();
    });

    it('shows one queue_failed alert on a network failure', async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 0, error: 'network', data: {} });
        mountControl({ ai: { ...AI, noticeAcknowledged: true } });
        $('[data-testid="ai-run"]').click();
        await flush();
        expect($$('[role="alert"]')).toHaveLength(1);
        expect($('[role="alert"]').textContent).toContain('We could not start the run. Nothing was sent. Try again.');
    });

    it('sends a 423 to the refusal handler and replays nothing', async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 423, error: 'reauth_required', data: {} });
        refusal.mockReturnValue(true);
        mountControl({ ai: { ...AI, noticeAcknowledged: true } });
        $('[data-testid="ai-run"]').click();
        await flush();
        expect(refusal).toHaveBeenCalledWith('reauth_required');
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect($('[role="alert"]')).toBeNull();
    });
});

describe('status states', () => {
    it('queued and running show the tag, the wait detail and Cancel run; cancel POSTs and toasts', async () => {
        fetchMock.mockResolvedValue({ ok: true, status: 200, data: {} });
        mountControl({ statuses: { [DOC.id]: entry({ status: 'running', entryCount: undefined }) } });
        expect($('[data-testid="ai-status-tag"]').textContent).toBe('AI is reading');
        expect($('[data-testid="ai-detail"]').textContent).toBe('This usually takes a few minutes. You can leave this page.');
        expect($('[data-testid="ai-run"]')).toBeNull();
        $('[data-testid="ai-cancel"]').click();
        await flush();
        expect(fetchMock).toHaveBeenCalledWith(`/health/people/${PID}/ai/ex1/cancel`, { method: 'POST', body: {} });
        expect(useToast().toasts.map((x) => x.message)).toContain('AI run cancelled');
        expect($('[data-testid="ai-status-tag"]').textContent).toBe('AI is reading');
    });

    it('ready shows the primary review link with the review URL and a ghost "Run AI again"', () => {
        mountControl({ statuses: { [DOC.id]: entry() } });
        const review = $('[data-testid="ai-review"]');
        expect(review.getAttribute('href')).toBe(`/health/people/${PID}/documents/${DOC.id}/review`);
        expect(review.textContent).toContain('Review suggestions');
        expect(review.className).toContain('ui-button--primary');
        expect($('[data-testid="ai-run"]').textContent).toContain('Run AI again');
        expect($('[data-testid="ai-detail"]').textContent).toBe('3 suggestions are ready to review.');
        expect($('[data-testid="ai-status-tag"]').textContent).toBe('Suggestions ready');
    });

    it('ready with zero entries shows the neutral "Nothing to suggest" tag', () => {
        mountControl({ statuses: { [DOC.id]: entry({ entryCount: 0 }) } });
        expect($('[data-testid="ai-status-tag"]').textContent).toBe('Nothing to suggest');
    });

    it('Run AI again with unreviewed suggestions opens the replace confirm with default focus on "Keep current suggestions"', async () => {
        fetchMock.mockResolvedValue({ ok: true, status: 202, data: { extractionId: 'ex2' } });
        mountControl({ ai: { ...AI, noticeAcknowledged: true }, statuses: { [DOC.id]: entry({ pending: 2 }) } });
        $('[data-testid="ai-run"]').click();
        await flush();
        expect($('[role="dialog"]').textContent).toContain('Replace these suggestions?');
        expect(document.activeElement).toBe($('[data-testid="ai-rerun-keep"]'));
        $('[data-testid="ai-rerun-keep"]').click();
        await flush();
        expect(fetchMock).not.toHaveBeenCalled();
        $('[data-testid="ai-run"]').click();
        await flush();
        $('[data-testid="ai-rerun-confirm"]').click();
        await flush();
        expect(fetchMock).toHaveBeenCalledWith(runUrl, { method: 'POST', body: {} });
    });

    it('Run AI again without pending suggestions starts straight away', async () => {
        fetchMock.mockResolvedValue({ ok: true, status: 202, data: {} });
        mountControl({ ai: { ...AI, noticeAcknowledged: true }, statuses: { [DOC.id]: entry({ pending: 0 }) } });
        $('[data-testid="ai-run"]').click();
        await flush();
        expect($('[role="dialog"]')).toBeNull();
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('failed shows the generic detail and "Run AI again"; the old proposal keeps its review link', () => {
        mountControl({ statuses: { [DOC.id]: entry({ status: 'failed', extractionId: 'ex2', readyExtractionId: 'ex1' }) } });
        expect($('[data-testid="ai-status-tag"]').textContent).toBe('AI could not read this');
        expect($('[data-testid="ai-detail"]').textContent).toContain('Nothing was saved.');
        expect($('[data-testid="ai-run"]').textContent).toContain('Run AI again');
        expect($('[data-testid="ai-review"]')).not.toBeNull();
    });

    it('failed without an earlier proposal has no review link', () => {
        mountControl({ statuses: { [DOC.id]: entry({ status: 'failed', readyExtractionId: null }) } });
        expect($('[data-testid="ai-review"]')).toBeNull();
    });

    it('cancelled shows its detail and "Run AI"; consent-cancelled shows the stopped tag', () => {
        mountControl({ statuses: { [DOC.id]: entry({ status: 'cancelled', cancelReason: 'user', readyExtractionId: null }) } });
        expect($('[data-testid="ai-status-tag"]').textContent).toBe('Cancelled');
        expect($('[data-testid="ai-detail"]').textContent).toBe('The run was cancelled. Nothing was saved.');
        expect($('[data-testid="ai-run"]').textContent.trim()).toBe('Run AI');
        wrapper.unmount();
        mountControl({ consentOn: false, statuses: { [DOC.id]: entry({ status: 'cancelled', cancelReason: 'consent', readyExtractionId: null }) } });
        expect($('[data-testid="ai-status-tag"]').textContent).toBe('Stopped: AI is off');
        expect($('[data-testid="ai-blocked"]').textContent).toContain('AI is off for this person.');
    });

    it('shows the page-cap line when fewer pages were read than the document has', () => {
        mountControl({ statuses: { [DOC.id]: entry({ pagesRead: 10, pagesTotal: 12 }) } });
        expect($('[data-testid="ai-pages"]').textContent).toBe('Only the first 10 pages were read.');
        wrapper.unmount();
        mountControl({ statuses: { [DOC.id]: entry({ pagesRead: 12, pagesTotal: 12 }) } });
        expect($('[data-testid="ai-pages"]')).toBeNull();
    });

    it('long waits and failed polls show their lines with a retry', async () => {
        mountControl({ statuses: { [DOC.id]: entry({ status: 'running' }) } });
        poller.stoppedLongWait.value = true;
        poller.failedPolls.value = 3;
        await flush();
        expect($('[data-testid="ai-long-wait"]').textContent).toContain('Still waiting.');
        expect($('[data-testid="ai-poll-failed"]').textContent).toContain('We could not check the run.');
        $('[data-testid="ai-poll-failed"] button').click();
        expect(poller.refresh).toHaveBeenCalled();
    });
});

describe('disabled explanations', () => {
    it.each([
        ['consent off', { consentOn: false }, DOC, 'AI is off for this person.'],
        ['unavailable', { ai: { ...AI, available: false } }, DOC, 'AI is not available right now.'],
        ['HEIC', {}, HEIC, 'AI cannot read this file type.'],
    ])('%s: aria-disabled Run AI with a visible line; pressing announces and sends nothing', async (_n, opts, doc, text) => {
        mountControl({ ...opts, document: doc });
        const button = $('[data-testid="ai-run"]');
        expect(button.getAttribute('aria-disabled')).toBe('true');
        expect(button.disabled).toBe(false);
        const line = $('[data-testid="ai-blocked"]');
        expect(line.textContent).toContain(text);
        expect(button.getAttribute('aria-describedby')).toBe(line.id);
        expect($('[data-testid="ai-sends"]')).toBeNull();
        button.click();
        await flush();
        expect(fetchMock).not.toHaveBeenCalled();
        expect($('[role="dialog"]')).toBeNull();
        expect($('[data-testid="ai-announce"]').textContent).toContain(text);
    });

    it('the consent-off link asks the panel to go to the person header', async () => {
        mountControl({ consentOn: false });
        $('[data-testid="ai-go-header"]').click();
        expect(wrapper.emitted('go-header')).toHaveLength(1);
    });
});

describe('view-only members', () => {
    it('see only the tag: no Run AI, no Cancel, no lines', () => {
        mountControl({ ai: { ...AI, canRun: false }, statuses: { [DOC.id]: entry({ entryCount: undefined }) } });
        expect($('[data-testid="ai-status-tag"]').textContent).toBe('Suggestions ready');
        expect($('[data-testid="ai-run"]')).toBeNull();
        expect($('[data-testid="ai-cancel"]')).toBeNull();
        expect($('[data-testid="ai-review"]')).toBeNull();
        expect($('[data-testid="ai-sends"]')).toBeNull();
        wrapper.unmount();
        mountControl({ ai: { ...AI, canRun: false }, statuses: { [DOC.id]: entry({ status: 'running' }) } });
        expect($('[data-testid="ai-cancel"]')).toBeNull();
    });
});

describe('announcements', () => {
    it('announces a transition once and nothing for unchanged polls', async () => {
        mountControl({ statuses: { [DOC.id]: entry({ status: 'queued' }) } });
        const region = $('[data-testid="ai-announce"]');
        expect(region.getAttribute('role')).toBe('status');
        expect(region.textContent).toBe('');
        poller.statuses.value = { [DOC.id]: entry({ status: 'queued' }) };
        await flush();
        expect(region.textContent).toBe('');
        poller.statuses.value = { [DOC.id]: entry({ status: 'running' }) };
        await flush();
        expect(region.textContent).toBe('AI is reading the document');
        poller.statuses.value = { [DOC.id]: entry({ status: 'running', pagesRead: null }) };
        await flush();
        expect(region.textContent).toBe('AI is reading the document');
        poller.statuses.value = { [DOC.id]: entry({ status: 'ready' }) };
        await flush();
        expect(region.textContent).toBe('Suggestions are ready');
        expect($$('[role="status"]').filter((n) => n.dataset.testid === 'ai-announce')).toHaveLength(1);
    });
});

describe('accessibility', () => {
    const states = {
        none: {},
        queued: { statuses: { [DOC.id]: entry({ status: 'queued' }) } },
        running: { statuses: { [DOC.id]: entry({ status: 'running' }) } },
        ready: { statuses: { [DOC.id]: entry({ pagesRead: 10, pagesTotal: 12 }) } },
        empty: { statuses: { [DOC.id]: entry({ entryCount: 0 }) } },
        failed: { statuses: { [DOC.id]: entry({ status: 'failed' }) } },
        cancelled: { statuses: { [DOC.id]: entry({ status: 'cancelled', cancelReason: 'consent' }) }, consentOn: false },
        consentOff: { consentOn: false },
        unavailable: { ai: { ...AI, available: false } },
        heic: { document: HEIC },
        viewOnly: { ai: { ...AI, canRun: false }, statuses: { [DOC.id]: entry() } },
    };
    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`has no axe violations in any state in ${locale} ${dark ? 'dark' : 'light'}`, async () => {
                document.documentElement.classList.toggle('dark', dark);
                for (const opts of Object.values(states)) {
                    mountControl({ ...opts, locale });
                    await expectNoAxeViolations(wrapper.element);
                    wrapper.unmount();
                }
            });
        }
    }

    it('is clean at 320 px with the dialogs open', async () => {
        window.innerWidth = 320;
        mountControl();
        $('[data-testid="ai-run"]').click();
        await flush();
        await expectNoAxeViolations(document.body);
    });
});

describe('DocumentPanel integration', () => {
    const mountPanel = (props = {}) => {
        poller = makePoller({});
        wrapper = mount(DocumentPanel, {
            props: { open: true, personId: PID, document: DOC, visits: [], canEdit: true, ai: AI, aiStatus: poller, consentOn: true, ...props },
            attachTo: document.body,
            global: { plugins: [createAppI18n('en', loadMessages('en'))] },
        });
    };

    it('places Run AI after "Download document" and before "Delete document"', () => {
        mountPanel();
        const order = [...document.querySelectorAll('[data-testid="doc-download"], [data-testid="ai-run"], [data-testid="doc-delete"]')].map((n) => n.dataset.testid);
        expect(order).toEqual(['doc-download', 'ai-run', 'doc-delete']);
    });

    it('shows no Run AI controls without the ai props', () => {
        mountPanel({ ai: null, aiStatus: null });
        expect($('[data-testid="run-ai"]')).toBeNull();
    });
});

describe('waiting for AI quota, provenance and providers', () => {
    const soon = () => new Date(Date.now() + 2 * 3600 * 1000).toISOString();
    const waitingEntry = (over = {}) => entry({ status: 'waiting', readyExtractionId: null, availableAt: soon(), extractionId: 'ex7', entryCount: undefined, ...over });
    const ACK = { ...AI, noticeAcknowledged: true };

    it('shows the waiting tag with a <time>, the detail and Cancel run in place of Run AI, aria-busy on the block', () => {
        mountControl({ ai: ACK, statuses: { [DOC.id]: waitingEntry() } });
        expect($('[data-testid="ai-status-tag"]').textContent).toMatch(/^Waiting for AI quota, retrying after (\w{3},? )?\d{2}:\d{2}$/);
        expect($('[data-testid="ai-status-tag"] time').getAttribute('datetime')).toBeTruthy();
        expect($('[data-testid="ai-detail"]').textContent).toBe('The AI services have reached their usage limit. We will try again automatically. You can leave this page.');
        expect($('[data-testid="ai-cancel"]').textContent).toContain('Cancel run');
        expect($('[data-testid="ai-run"]')).toBeNull();
        expect($('[data-testid="run-ai"]').getAttribute('aria-busy')).toBe('true');
    });

    it('cancels immediately, toasts and refreshes the status', async () => {
        fetchMock.mockResolvedValue({ ok: true, status: 200, data: {} });
        mountControl({ ai: ACK, statuses: { [DOC.id]: waitingEntry() } });
        $('[data-testid="ai-cancel"]').click();
        await flush();
        expect(fetchMock).toHaveBeenCalledWith(`/health/people/${PID}/ai/ex7/cancel`, { method: 'POST', body: {} });
        expect(useToast().toasts.map((x) => x.message)).toContain('AI run cancelled');
        expect(poller.refresh).toHaveBeenCalled();
    });

    it('moves focus back to Run AI after cancelling a waiting run', async () => {
        fetchMock.mockResolvedValue({ ok: true, status: 200, data: {} });
        mountControl({ ai: ACK, statuses: { [DOC.id]: waitingEntry() } });
        poller.refresh.mockImplementation(async () => { poller.statuses.value = { [DOC.id]: entry({ status: 'cancelled', readyExtractionId: null, cancelReason: null }) }; });
        $('[data-testid="ai-cancel"]').click();
        await flush();
        expect(document.activeElement).toBe($('[data-testid="ai-run"]'));
    });

    it('announces entering waiting once, with the time', async () => {
        mountControl({ ai: ACK, statuses: { [DOC.id]: entry({ status: 'running', readyExtractionId: null }) } });
        poller.statuses.value = { [DOC.id]: waitingEntry() };
        await flush();
        expect($('[data-testid="ai-announce"]').textContent).toMatch(/^Waiting for AI quota\. Retrying after /);
        poller.statuses.value = { [DOC.id]: waitingEntry({ cancelReason: null }) };
        await flush();
        expect($('[data-testid="ai-announce"]').textContent).toMatch(/^Waiting for AI quota/);
    });

    it('shows "retrying soon" once the time passed', () => {
        mountControl({ ai: ACK, statuses: { [DOC.id]: waitingEntry({ availableAt: new Date(Date.now() - 1000).toISOString() }) } });
        expect($('[data-testid="ai-status-tag"]').textContent).toBe('Waiting for AI quota, retrying soon');
    });

    it('renders the stalled hint when the poller gave up on waiting', () => {
        mountControl({ ai: ACK, statuses: { [DOC.id]: waitingEntry() } });
        poller.stalled.value = true;
        return nextTick().then(() => {
            expect($('[data-testid="ai-stalled"]').textContent).toBe('Still waiting. Reload the page to check again.');
        });
    });

    it.each([
        ['quota_timeout', 'The AI usage limit did not come back in time. Nothing was saved. Try again later, or add the details by hand.'],
        ['quota_exhausted', 'The AI services have reached their usage limit and will not be back soon enough. Nothing was saved. Try again later, or add the details by hand.'],
        ['no_agent', 'No AI tool is signed in right now. Nothing was sent. Try again later, or add the details by hand.'],
        ['notice_required', 'The AI tools changed since you agreed to send documents. Nothing was sent. Run AI again to see the updated notice.'],
    ])('shows the %s failure detail with Run AI again', (failureCode, text) => {
        mountControl({ ai: ACK, statuses: { [DOC.id]: entry({ status: 'failed', readyExtractionId: null, failureCode }) } });
        expect($('[data-testid="ai-status-tag"]').textContent).toBe('AI could not read this');
        expect($('[data-testid="ai-detail"]').textContent).toBe(text);
        expect($('[data-testid="ai-run"]').textContent).toContain('Run AI again');
    });

    it('keeps the Phase 8 detail for other failures', () => {
        mountControl({ ai: ACK, statuses: { [DOC.id]: entry({ status: 'failed', readyExtractionId: null, failureCode: 'timeout' }) } });
        expect($('[data-testid="ai-detail"]').textContent).toContain('AI could not read this document.');
    });

    it('opens the provider-aware notice when Run AI again follows notice_required', async () => {
        mountControl({ ai: { ...AI, providers: ['codex', 'copilot'] }, statuses: { [DOC.id]: entry({ status: 'failed', readyExtractionId: null, failureCode: 'notice_required' }) } });
        $('[data-testid="ai-run"]').click();
        await flush();
        expect($('[role="dialog"]').textContent).toContain('Send this document to OpenAI or GitHub?');
    });

    it('shows the provenance line under the ready detail, never in the live region or toasts', async () => {
        mountControl({ ai: ACK, statuses: { [DOC.id]: entry({ agent: 'copilot', model: 'gpt-5' }) } });
        expect($('[data-testid="ai-provenance"]').textContent).toBe('Read by GitHub Copilot gpt-5');
        expect($('[data-testid="ai-detail"]').nextElementSibling.getAttribute('data-testid')).toBe('ai-provenance');
        expect($('[data-testid="ai-announce"]').textContent).toBe('');
    });

    it('renders a model with markup literally', () => {
        mountControl({ ai: ACK, statuses: { [DOC.id]: entry({ agent: 'codex', model: '<b>x</b>' }) } });
        expect($('[data-testid="ai-provenance"] b')).toBeNull();
        expect($('[data-testid="ai-provenance"]').textContent).toBe('Read by OpenAI Codex <b>x</b>');
    });

    it.each([
        [['codex'], 'Sends this document to OpenAI.'],
        [['codex', 'copilot'], 'Sends this document to OpenAI, or to GitHub if OpenAI is not available.'],
        [['copilot', 'codex'], 'Sends this document to OpenAI, or to GitHub if OpenAI is not available.'],
        [['copilot'], 'Sends this document to GitHub.'],
    ])('names the providers %j in the permanent line', (providers, text) => {
        mountControl({ ai: { ...ACK, providers } });
        const line = $('[data-testid="ai-sends"]');
        expect(line.textContent).toBe(text);
        expect($('[data-testid="ai-run"]').getAttribute('aria-describedby')).toBe(line.id);
    });

    it.each([
        [['codex', 'copilot'], 'Send this document to OpenAI or GitHub?'],
        [['copilot'], 'Send this document to GitHub?'],
        [['codex'], 'Send this document to OpenAI?'],
    ])('uses the right notice for %j with the safe default focus', async (providers, title) => {
        fetchMock.mockResolvedValue({ ok: true, status: 202, data: { extractionId: 'ex9' } });
        mountControl({ ai: { ...AI, providers } });
        $('[data-testid="ai-run"]').click();
        await flush();
        expect($('[role="dialog"]').textContent).toContain(title);
        expect(document.activeElement).toBe($('[data-testid="ai-notice-cancel"]'));
        $('[data-testid="ai-notice-confirm"]').click();
        await flush();
        expect(fetchMock).toHaveBeenCalledWith(runUrl, { method: 'POST', body: { acknowledged: true, providers } });
    });

    it('re-opens the notice with the current provider set when the server says another provider was enabled meanwhile', async () => {
        // The dialog named OpenAI only; the server answers that GitHub was enabled in the meantime.
        fetchMock.mockResolvedValueOnce({ ok: false, status: 409, error: 'failed', data: { reason: 'notice_required', providers: ['codex', 'copilot'] } });
        fetchMock.mockResolvedValueOnce({ ok: true, status: 202, data: { extractionId: 'ex9' } });
        mountControl({ ai: { ...AI, providers: ['codex'] } });
        $('[data-testid="ai-run"]').click();
        await flush();
        expect($('[role="dialog"]').textContent).toContain('Send this document to OpenAI?');
        $('[data-testid="ai-notice-confirm"]').click();
        await flush();
        expect(fetchMock).toHaveBeenNthCalledWith(1, runUrl, { method: 'POST', body: { acknowledged: true, providers: ['codex'] } });
        // Nothing started; the dialog is open again and now names both providers.
        expect(poller.markQueued).not.toHaveBeenCalled();
        expect($('[role="dialog"]').textContent).toContain('or to GitHub if OpenAI is not available');
        $('[data-testid="ai-notice-confirm"]').click();
        await flush();
        expect(fetchMock).toHaveBeenNthCalledWith(2, runUrl, { method: 'POST', body: { acknowledged: true, providers: ['codex', 'copilot'] } });
        expect(poller.markQueued).toHaveBeenCalled();
    });

    it('treats Esc in the notice like "Keep document private"', async () => {
        mountControl({ ai: { ...AI, providers: ['codex', 'copilot'] } });
        $('[data-testid="ai-run"]').click();
        await flush();
        $('[role="dialog"]').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await flush();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('keeps Run AI enabled while the server says available (agents cooling down) and shows the unavailable line when it is not', () => {
        mountControl({ ai: ACK });
        expect($('[data-testid="ai-run"]').getAttribute('aria-disabled')).toBeNull();
        wrapper.unmount();
        mountControl({ ai: { ...ACK, available: false } });
        expect($('[data-testid="ai-blocked"]').textContent).toContain('AI is not available right now');
    });

    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`has no axe violations for waiting, timeout, provenance and provider states in ${locale} ${dark ? 'dark' : 'light'}`, async () => {
                document.documentElement.classList.toggle('dark', dark);
                const states = [
                    { ai: { ...ACK, providers: ['codex', 'copilot'] }, statuses: { [DOC.id]: waitingEntry() } },
                    { ai: { ...ACK, providers: ['copilot'] }, statuses: { [DOC.id]: entry({ status: 'failed', readyExtractionId: null, failureCode: 'quota_timeout' }) } },
                    { ai: ACK, statuses: { [DOC.id]: entry({ agent: 'copilot', model: 'gpt-5' }) } },
                ];
                for (const state of states) {
                    mountControl({ ...state, locale });
                    await expectNoAxeViolations(document.body);
                    wrapper.unmount();
                    document.body.innerHTML = '';
                }
                mountControl({ ai: { ...AI, providers: ['codex', 'copilot'] }, locale });
                $('[data-testid="ai-run"]').click();
                await flush();
                await expectNoAxeViolations(document.body);
            });
        }
    }
});
