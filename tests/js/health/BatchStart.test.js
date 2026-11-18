import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { h } from 'vue';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';

const { fetchMock, router, refusal } = vi.hoisted(() => ({
    fetchMock: vi.fn(),
    router: { visit: vi.fn(), get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), reload: vi.fn(), on: vi.fn(() => vi.fn()) },
    refusal: vi.fn(),
}));
vi.mock('../../../resources/js/Security/securityFetch.js', () => ({ securityFetch: fetchMock }));
vi.mock('../../../resources/js/Health/healthRefusal.js', () => ({ handleHealthRefusal: refusal }));
vi.mock('@inertiajs/vue3', () => ({
    router,
    usePage: () => ({ props: {}, url: '/health' }),
    Head: { props: ['title'], setup: () => () => null },
    Link: { props: ['href'], setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()) },
}));

import StartBatchControl from '../../../resources/js/Health/Batch/StartBatchControl.vue';
import OpenBatchBanner from '../../../resources/js/Health/Batch/OpenBatchBanner.vue';
import PersonHeader from '../../../resources/js/Health/PersonHeader.vue';

const PID = '11111111-1111-4111-8111-111111111111';
const BID = '22222222-2222-4222-8222-222222222222';
const URL = `/health/people/${PID}/upload-batches`;
const $ = (sel) => document.querySelector(sel);
let wrapper;
const mountStart = (ai = { noticeAcknowledged: true, providers: ['codex'] }, locale = 'en') => {
    wrapper = mount(StartBatchControl, { props: { personId: PID, ai }, attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });
    return wrapper;
};
beforeEach(() => { fetchMock.mockReset(); router.visit.mockReset(); refusal.mockReset(); });
afterEach(() => { wrapper?.unmount(); wrapper = null; document.body.innerHTML = ''; });
const press = async () => { await wrapper.find('[data-testid="header-add-batch"]').trigger('click'); await flushPromises(); };

describe('StartBatchControl', () => {
    it('creates the batch and opens its page', async () => {
        fetchMock.mockResolvedValue({ ok: true, status: 201, data: { batchId: BID } });
        mountStart();
        expect(wrapper.text()).toContain('Upload many documents');
        await press();
        expect(fetchMock).toHaveBeenCalledWith(URL, expect.objectContaining({ method: 'POST', body: {}, replay: true }));
        expect(router.visit).toHaveBeenCalledWith(`${URL}/${BID}`);
    });

    it('opens the existing batch the same way', async () => {
        fetchMock.mockResolvedValue({ ok: true, status: 200, data: { batchId: BID, existing: true } });
        mountStart();
        await press();
        expect(router.visit).toHaveBeenCalledWith(`${URL}/${BID}`);
    });

    it('asks for the AI notice first when it was not acknowledged and sends the acknowledgment with the shown providers', async () => {
        fetchMock.mockResolvedValue({ ok: true, status: 201, data: { batchId: BID } });
        mountStart({ noticeAcknowledged: false, providers: ['codex'], textNotice: true });
        await press();
        expect(fetchMock).not.toHaveBeenCalled();
        expect($('[data-testid="ai-notice-confirm"]')).not.toBeNull();
        $('[data-testid="ai-notice-confirm"]').click();
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledWith(URL, expect.objectContaining({ body: { acknowledged: true, providers: ['codex'], textNotice: true } }));
        expect(router.visit).toHaveBeenCalled();
    });

    it('reopens the notice with the current provider set on a 409 notice_required and then resends', async () => {
        fetchMock.mockResolvedValueOnce({ ok: false, status: 409, data: { reason: 'notice_required', providers: ['codex', 'copilot'] } });
        fetchMock.mockResolvedValueOnce({ ok: true, status: 201, data: { batchId: BID } });
        mountStart();
        await press();
        expect(router.visit).not.toHaveBeenCalled();
        $('[data-testid="ai-notice-confirm"]').click();
        await flushPromises();
        expect(fetchMock).toHaveBeenLastCalledWith(URL, expect.objectContaining({ body: { acknowledged: true, providers: ['codex', 'copilot'] } }));
        expect(router.visit).toHaveBeenCalledWith(`${URL}/${BID}`);
    });

    it.each([['consent_off', 'Turn on AI for this person first'], ['unavailable', 'AI is not available right now']])('shows the %s alert and does not navigate', async (reason, text) => {
        fetchMock.mockResolvedValue({ ok: false, status: 409, data: { reason } });
        mountStart();
        await press();
        expect(wrapper.find('[data-testid="batch-start-alert"]').text()).toBe(text);
        expect(router.visit).not.toHaveBeenCalled();
    });

    it('passes a gate refusal on without navigating to the batch', async () => {
        refusal.mockReturnValue(true);
        fetchMock.mockResolvedValue({ ok: false, status: 423, error: 'reauth_required', data: {} });
        mountStart();
        await press();
        expect(refusal).toHaveBeenCalledWith('reauth_required');
        expect(router.visit).not.toHaveBeenCalled();
    });

    it('speaks Slovak and Polish and has no axe violations', async () => {
        for (const locale of ['sk', 'pl']) {
            mountStart(undefined, locale);
            expect(wrapper.text()).toContain({ sk: 'Nahrať viac dokumentov', pl: 'Wgraj wiele dokumentów' }[locale]);
            wrapper.unmount();
        }
        mountStart();
        await expectNoAxeViolations(document.body);
    });
});

describe('OpenBatchBanner', () => {
    it('shows the progress, the expiry date and a link to the batch', () => {
        wrapper = mount(OpenBatchBanner, { props: { personId: PID, batch: { id: BID, total: 20, done: 7, expiresAt: '2026-12-03T10:00:00+00:00' } }, attachTo: document.body, global: { plugins: [createAppI18n('en', loadMessages('en'))] } });
        expect(wrapper.text()).toContain('7 of 20 documents done');
        expect(wrapper.text()).toMatch(/3 Dec 2026/);
        expect(wrapper.find('[data-testid="open-batch-link"]').attributes('href')).toBe(`${URL}/${BID}`);
    });
});

describe('PersonHeader batch entry point', () => {
    const person = { id: PID, name: 'Ada Novak', ageYears: 11, sex: 'female', bloodType: null, hasAccount: true, aiConsent: true, canEdit: true };
    const header = (over) => mount(PersonHeader, { props: { person: { ...person, ...over }, ai: { noticeAcknowledged: true } }, attachTo: document.body, global: { plugins: [createAppI18n('en', loadMessages('en'))] } });
    it('offers Upload many documents to members who may edit and to nobody else', () => {
        wrapper = header({});
        expect(wrapper.find('[data-testid="header-add-batch"]').exists()).toBe(true);
        wrapper.unmount();
        wrapper = header({ canEdit: false });
        expect(wrapper.find('[data-testid="header-add-batch"]').exists()).toBe(false);
    });
});
