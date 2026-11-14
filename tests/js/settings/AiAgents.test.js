import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { pseudoLong } from '../helpers/pseudoLong.js';
import { createAppI18n } from '../../../resources/js/i18n.js';

const post = vi.fn();
const reload = vi.fn();
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({ props: { locale: 'en', flash: {}, availableLocales: ['sk', 'pl', 'en'], auth: { user: { id: 1, name: 'Anna', isAdmin: true }, access: {} }, app: { env: 'local' } } }),
        router: { get: vi.fn(), post: (...a) => post(...a), reload: (...a) => reload(...a), visit: vi.fn(), on: vi.fn() },
        Head: { render: () => null },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});
const fetchMock = vi.fn();
vi.mock('../../../resources/js/Security/securityFetch.js', () => ({ securityFetch: (...a) => fetchMock(...a) }));

import AiAgents from '../../../resources/js/Pages/Settings/AiAgents.vue';
import AiLoginHealthCard from '../../../resources/js/Settings/AiCalls/AiLoginHealthCard.vue';
import SettingsLayout from '../../../resources/js/Layouts/SettingsLayout.vue';
import SettingsIndex from '../../../resources/js/Pages/Settings/Index.vue';
import { useToast } from '../../../resources/js/Components/ui/useToast.js';

const load = (l) => JSON.parse(readFileSync(`${process.cwd()}/lang/${l}.json`, 'utf8'));
const src = (p) => readFileSync(`${process.cwd()}/${p}`, 'utf8');
const SECTIONS = [
    { key: 'ai_calls', href: '/settings/ai-calls', labelKey: 'settings.section.ai_calls', icon: 'bot' },
    { key: 'ai_agents', href: '/settings/ai-agents', labelKey: 'settings.section.ai_agents', icon: 'bot' },
];
const HEALTH = (state = 'logged_in', checkedAt = '2026-11-14T10:30:00+00:00') => ({ state, checkedAt });
const codex = (extra = {}) => ({ key: 'codex', enabled: true, position: 1, model: null, health: HEALTH(), cooldownUntil: null, ...extra });
const copilot = (extra = {}) => ({ key: 'copilot', enabled: false, position: 2, model: null, health: HEALTH('unknown', null), cooldownUntil: null, ...extra });
const AGENTS = () => [codex(), copilot()];
const ok = (agents) => ({ ok: true, status: 200, data: { agents } });

let current;
function mountPage({ locale = 'en', messages, ...props } = {}) {
    window.matchMedia = (query) => ({ matches: false, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    current = mount(AiAgents, {
        props: { sections: SECTIONS, agents: AGENTS(), providers: ['codex'], checkRoute: 'settings.ai_calls.check', ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, messages ?? load(locale))] },
    });
    return current;
}
const card = (key) => current.find(`li[data-agent="${key}"]`);
const control = (key, name) => current.find(`[data-agent="${key}"][data-control="${name}"]`);
const status = () => current.find('p.visually-hidden[role="status"]').text();
const button = (text, scope = null) => (scope ?? current).findAll('button').find((b) => b.text() === text);

beforeEach(() => {
    post.mockImplementation((url, data, opts) => opts?.onSuccess?.());
    useToast().toasts.splice(0);
});
afterEach(() => {
    current?.unmount(); current = null; document.body.innerHTML = '';
    fetchMock.mockReset(); post.mockReset(); reload.mockReset(); vi.useRealTimers(); vi.restoreAllMocks();
});

describe('AI agents page: list', () => {
    it('renders the cards in the prop order and reversing the props reverses the DOM', async () => {
        mountPage();
        expect(current.find('h1').text()).toBe('AI agents');
        expect(current.find('ol').attributes('aria-label')).toBe('AI agents in order');
        expect(current.findAll('ol > li').map((l) => l.attributes('data-agent'))).toEqual(['codex', 'copilot']);
        expect(current.findAll('h3').map((h) => h.text())).toEqual(['OpenAI Codex', 'GitHub Copilot']);
        await current.setProps({ agents: [codex({ position: 2 }), copilot({ position: 1 })] });
        expect(current.findAll('ol > li').map((l) => l.attributes('data-agent'))).toEqual(['copilot', 'codex']);
    });

    it('each card is labelled by its h3, has a model field without placeholder and no credential input', () => {
        mountPage();
        for (const key of ['codex', 'copilot']) {
            const li = card(key);
            expect(li.find(`#${li.attributes('aria-labelledby')}`).element.tagName).toBe('H3');
            const input = control(key, 'model');
            expect(input.attributes('placeholder') ?? '').toBe('');
            expect(input.attributes('autocomplete')).toBe('off');
            expect(input.attributes('spellcheck')).toBe('false');
            expect(li.find('input[type="password"]').exists()).toBe(false);
            expect(li.text()).toContain(`Position ${key === 'codex' ? 1 : 2} of 2`);
        }
        expect(current.text()).toContain('Order and switches apply to new runs.');
        expect(current.findAll('a').find((a) => a.text() === 'Open AI calls').attributes('href')).toBe('/settings/ai-calls');
    });

    it('the AI agents nav entry is shown with an icon and marked current', () => {
        mountPage();
        const link = current.findAll('a').find((a) => a.attributes('href') === '/settings/ai-agents');
        expect(link.text()).toBe('AI agents');
        expect(link.find('svg').exists()).toBe(true);
        expect(link.attributes('aria-current')).toBe('page');
        expect(SettingsLayout).toBeTruthy();
        expect(src('resources/js/Layouts/SettingsLayout.vue')).toMatch(/bot: Bot/);
        expect(src('resources/js/Pages/Settings/Index.vue')).toMatch(/bot: Bot/);
        expect(SettingsIndex).toBeTruthy();
    });
});

describe('AI agents page: enable switch', () => {
    it('Copilot on sends PATCH, shows saving and aria-busy while pending, then announces and toasts', async () => {
        let resolve;
        fetchMock.mockReturnValue(new Promise((r) => { resolve = r; }));
        mountPage();
        await control('copilot', 'switch').setValue(true);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe('/settings/ai-agents/copilot');
        expect(fetchMock.mock.calls[0][1]).toEqual({ method: 'PATCH', body: { enabled: true } });
        expect(control('copilot', 'switch').attributes('aria-busy')).toBe('true');
        expect(control('copilot', 'down').attributes('aria-disabled')).toBe('true');
        expect(control('copilot', 'model').attributes('aria-disabled')).toBe('true');
        expect(card('copilot').text()).toContain('Saving');
        expect(card('copilot').find('.agent__saving[role="status"]').exists()).toBe(true);

        expect(card('copilot').text()).not.toContain('Documents may then be sent to GitHub');
        resolve(ok([codex(), copilot({ enabled: true })]));
        await flushPromises();
        expect(card('copilot').text()).not.toContain('Saving');
        expect(control('copilot', 'switch').attributes('aria-busy')).toBeUndefined();
        expect(control('copilot', 'switch').element.checked).toBe(true);
        expect(status()).toBe('GitHub Copilot is now on.');
        expect(useToast().toasts.map((x) => x.message)).toEqual(['GitHub Copilot is now on.']);
        expect(card('copilot').text()).toContain('Documents may then be sent to GitHub');
    });

    it('turning an agent off announces it', async () => {
        fetchMock.mockResolvedValue(ok([codex(), copilot({ enabled: false })]));
        mountPage({ agents: [codex(), copilot({ enabled: true })] });
        await control('copilot', 'switch').setValue(false);
        expect(fetchMock.mock.calls[0][1].body).toEqual({ enabled: false });
        await flushPromises();
        expect(status()).toBe('GitHub Copilot is now off.');
    });

    it('the only enabled agent cannot be switched off and says why', async () => {
        mountPage();
        const sw = control('codex', 'switch');
        expect(sw.attributes('aria-disabled')).toBe('true');
        expect(card('codex').text()).toContain('At least one tool must stay on');
        expect(card('copilot').text()).not.toContain('At least one tool must stay on');
        await sw.setValue(false);
        expect(fetchMock).not.toHaveBeenCalled();
        expect(sw.element.checked).toBe(true);
    });

    it('a 422 last_enabled reverts the control and shows the lock message', async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 422, error: 'invalid', data: { reason: 'last_enabled', agents: [codex(), copilot({ enabled: true })] } });
        mountPage({ agents: [codex(), copilot({ enabled: true })] });
        await control('copilot', 'switch').setValue(false);
        await flushPromises();
        expect(control('copilot', 'switch').element.checked).toBe(true);
        expect(card('copilot').find('[role="alert"]').text()).toContain('At least one tool must stay on');
    });

    it('a 500 shows the save error alert once and reverts the switch', async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 500, error: 'failed', data: {} });
        mountPage();
        await control('copilot', 'switch').setValue(true);
        await flushPromises();
        expect(control('copilot', 'switch').element.checked).toBe(false);
        expect(current.findAll('[role="alert"]')).toHaveLength(1);
        expect(card('copilot').find('[role="alert"]').text()).toBe('We could not save this. Nothing was changed. Try again.');
        expect(useToast().toasts).toHaveLength(0);
    });

    it('a network error behaves like a save error', async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 0, error: 'network', data: {} });
        mountPage();
        await control('copilot', 'switch').setValue(true);
        await flushPromises();
        expect(card('copilot').find('[role="alert"]').exists()).toBe(true);
    });
});

describe('AI agents page: not signed in warning', () => {
    for (const state of ['unknown', 'expired', 'not_responding']) {
        it(`enabled Copilot with ${state} shows the warning (not role=alert) with the state`, () => {
            mountPage({ agents: [codex(), copilot({ enabled: true, health: HEALTH(state) })] });
            const labels = { unknown: 'Not checked yet', expired: 'Login expired', not_responding: 'AI service not responding' };
            const warn = card('copilot').findAll('.ui-alert--warning');
            expect(warn).toHaveLength(1);
            expect(warn[0].text()).toContain(`GitHub Copilot is switched on but is not signed in yet (${labels[state]}).`);
            expect(warn[0].attributes('role')).toBeUndefined();
            expect(button('Check again', warn[0])).toBeTruthy();
        });
    }

    it('logged in or switched off shows no warning', () => {
        mountPage({ agents: [codex(), copilot({ enabled: true, health: HEALTH('logged_in') })] });
        expect(current.find('.ui-alert--warning').exists()).toBe(false);
        current.unmount();
        mountPage();
        expect(current.find('.ui-alert--warning').exists()).toBe(false);
    });

    it('the warning Check again posts the login check', async () => {
        mountPage({ agents: [codex(), copilot({ enabled: true, health: HEALTH('unknown', null) })] });
        await button('Check again', card('copilot').find('.ui-alert--warning')).trigger('click');
        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe('/settings/ai-calls/check');
    });
});

describe('AI agents page: order', () => {
    const swapped = [copilot({ position: 1 }), codex({ position: 2 })];

    it('Move up on position 2 posts, then focus lands on its Move down and the move is announced', async () => {
        fetchMock.mockResolvedValue(ok(swapped));
        mountPage();
        expect(control('copilot', 'up').attributes('aria-label')).toBe('Move GitHub Copilot up, now position 1 of 2');
        await control('copilot', 'up').trigger('click');
        expect(fetchMock.mock.calls[0][0]).toBe('/settings/ai-agents/copilot/move');
        expect(fetchMock.mock.calls[0][1]).toEqual({ method: 'POST', body: { direction: 'up', expectedPosition: 2 } });
        await flushPromises();
        expect(current.findAll('ol > li').map((l) => l.attributes('data-agent'))).toEqual(['copilot', 'codex']);
        expect(document.activeElement).toBe(control('copilot', 'down').element);
        expect(status()).toBe('GitHub Copilot moved to position 1 of 2.');
        expect(useToast().toasts[0].message).toBe('GitHub Copilot moved to position 1 of 2.');
    });

    it('Move down in the middle keeps focus on Move down; focus is never lost to body', async () => {
        fetchMock.mockResolvedValue(ok([codex({ position: 2 }), copilot({ position: 1 })]));
        mountPage();
        await control('codex', 'down').trigger('click');
        await flushPromises();
        expect(document.activeElement).toBe(control('codex', 'up').element);
        expect(document.activeElement).not.toBe(document.body);
    });

    it('buttons at the ends are aria-disabled, focusable and do nothing', async () => {
        mountPage();
        const up = control('codex', 'up');
        expect(up.attributes('aria-disabled')).toBe('true');
        expect(up.attributes('disabled')).toBeUndefined();
        await up.trigger('click');
        await control('copilot', 'down').trigger('click');
        expect(fetchMock).not.toHaveBeenCalled();
        expect(control('codex', 'down').attributes('aria-disabled')).toBeUndefined();
    });

    it('a stale 409 replaces the list and announces it', async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 409, error: 'failed', data: { reason: 'stale', agents: swapped } });
        mountPage();
        await control('copilot', 'up').trigger('click');
        await flushPromises();
        expect(current.findAll('ol > li').map((l) => l.attributes('data-agent'))).toEqual(['copilot', 'codex']);
        expect(status()).toBe('The order was changed by someone else. It is shown as it is now.');
        expect(current.find('[role="alert"]').exists()).toBe(false);
    });

    it('a failed move keeps the order and shows the error', async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 500, error: 'failed', data: {} });
        mountPage();
        await control('copilot', 'up').trigger('click');
        await flushPromises();
        expect(current.findAll('ol > li').map((l) => l.attributes('data-agent'))).toEqual(['codex', 'copilot']);
        expect(card('copilot').find('[role="alert"]').exists()).toBe(true);
    });
});

describe('AI agents page: model', () => {
    it('refuses an invalid model on save without sending, with aria-invalid, and clears on change', async () => {
        mountPage();
        const input = control('copilot', 'model');
        await input.setValue('a/b');
        await button('Save model', card('copilot')).trigger('click');
        expect(fetchMock).not.toHaveBeenCalled();
        expect(card('copilot').text()).toContain('Use letters, numbers, dots, dashes or colons only, up to 60 characters.');
        expect(input.attributes('aria-invalid')).toBe('true');
        expect(input.attributes('aria-describedby')).toContain('-error');
        await input.setValue('a-b');
        expect(input.attributes('aria-invalid')).toBeUndefined();
    });

    it('refuses 61 characters but accepts 60', async () => {
        fetchMock.mockResolvedValue(ok(AGENTS()));
        mountPage();
        const input = control('copilot', 'model');
        await input.setValue('a'.repeat(61));
        await input.trigger('submit');
        await card('copilot').find('form').trigger('submit');
        expect(fetchMock).not.toHaveBeenCalled();
        await input.setValue('a'.repeat(60));
        await card('copilot').find('form').trigger('submit');
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('saves a valid model with Enter (form submit) and announces it', async () => {
        fetchMock.mockResolvedValue(ok([codex(), copilot({ model: 'gpt-5.1' })]));
        mountPage();
        await control('copilot', 'model').setValue('gpt-5.1');
        await card('copilot').find('form').trigger('submit');
        expect(fetchMock.mock.calls[0][0]).toBe('/settings/ai-agents/copilot');
        expect(fetchMock.mock.calls[0][1]).toEqual({ method: 'PATCH', body: { model: 'gpt-5.1' } });
        await flushPromises();
        expect(status()).toBe('Model for GitHub Copilot saved.');
        expect(control('copilot', 'model').element.value).toBe('gpt-5.1');
    });

    it('an empty value is allowed when a model was set; an unchanged value is aria-disabled and sends nothing', async () => {
        fetchMock.mockResolvedValue(ok(AGENTS()));
        mountPage({ agents: [codex({ model: 'gpt-5' }), copilot()] });
        const save = button('Save model', card('codex'));
        expect(save.attributes('aria-disabled')).toBe('true');
        await card('codex').find('form').trigger('submit');
        expect(fetchMock).not.toHaveBeenCalled();
        await control('codex', 'model').setValue('');
        expect(button('Save model', card('codex')).attributes('aria-disabled')).toBeUndefined();
        await card('codex').find('form').trigger('submit');
        expect(fetchMock.mock.calls[0][1].body).toEqual({ model: '' });
    });

    it('a server invalid_model shows the field error and no alert', async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 422, error: 'invalid', data: { reason: 'invalid_model', agents: AGENTS() } });
        mountPage();
        await control('copilot', 'model').setValue('ok-model');
        await card('copilot').find('form').trigger('submit');
        await flushPromises();
        expect(card('copilot').text()).toContain('Use letters, numbers, dots');
        expect(current.find('[role="alert"]').exists()).toBe(false);
    });

    it('renders a model string as text, never as HTML', () => {
        mountPage({ agents: [codex({ model: '<b>x</b>' }), copilot()] });
        expect(current.find('b').exists()).toBe(false);
        expect(control('codex', 'model').element.value).toBe('<b>x</b>');
        expect(src('resources/js/Settings/AiAgents/AgentCard.vue')).not.toMatch(/v-html/);
        expect(src('resources/js/Pages/Settings/AiAgents.vue')).not.toMatch(/v-html/);
    });
});

describe('AI agents page: cooldown', () => {
    it('shows the tag and a time element when the cooldown is in the future', () => {
        const until = new Date(Date.now() + 3 * 3600 * 1000).toISOString();
        mountPage({ agents: [codex({ cooldownUntil: until }), copilot()] });
        const li = card('codex');
        expect(li.text()).toContain('Usage limit reached');
        expect(li.text()).toContain('Paused until');
        expect(li.text()).toContain('It is skipped until then.');
        expect(li.find('.agent__cooldown time').attributes('datetime')).toBe(until);
        expect(card('copilot').text()).not.toContain('Usage limit reached');
    });

    it('shows nothing for a cooldown in the past or null', () => {
        mountPage({ agents: [codex({ cooldownUntil: new Date(Date.now() - 60000).toISOString() }), copilot()] });
        expect(current.text()).not.toContain('Usage limit reached');
        expect(current.text()).not.toContain('Paused until');
    });
});

describe('AI agents page: states', () => {
    it('empty list', () => {
        mountPage({ agents: [] });
        expect(current.text()).toContain('No AI tools found');
        expect(current.text()).toContain('Ask whoever runs the server to check the AI setup.');
        expect(current.find('ol').exists()).toBe(false);
    });

    it('loading renders two skeleton cards', () => {
        mountPage({ agents: null });
        expect(current.find('[aria-busy="true"]').exists()).toBe(true);
        expect(current.findAll('.ui-skeleton').length).toBeGreaterThanOrEqual(4);
        expect(current.find('ol').exists()).toBe(false);
    });

    it('load error offers Try loading again, which reloads only agents', async () => {
        mountPage({ agents: null });
        reload.mockImplementation((o) => o.onError?.({}));
        // A failed reload from the loading state surfaces the error state.
        const wrapper = current;
        wrapper.vm.$.setupState.reload?.();
        await flushPromises();
        expect(current.text()).toContain('Something went wrong');
        reload.mockReset();
        await button('Try loading again').trigger('click');
        expect(reload).toHaveBeenCalledTimes(1);
        expect(reload.mock.calls[0][0]).toMatchObject({ only: ['agents'] });
    });
});

describe('AI agents page: storage', () => {
    it('writes nothing to any storage API during interaction', async () => {
        const set = vi.spyOn(Storage.prototype, 'setItem');
        fetchMock.mockResolvedValue(ok([codex(), copilot({ enabled: true })]));
        mountPage();
        await control('copilot', 'switch').setValue(true);
        await flushPromises();
        expect(set).not.toHaveBeenCalled();
        expect(src('resources/js/Pages/Settings/AiAgents.vue') + src('resources/js/Settings/AiAgents/AgentCard.vue')).not.toMatch(/localStorage|sessionStorage|indexedDB|remember/);
    });
});

describe('login health card per agent', () => {
    const CASES = [
        ['logged_in', 'Logged in', null],
        ['expired', 'Login expired', 'GitHub Copilot needs to be signed in again'],
        ['not_responding', 'AI service not responding', 'did not answer'],
        ['unknown', 'Not checked yet', 'has not been checked yet'],
    ];
    for (const [state, tag, help] of CASES) {
        it(`renders ${state}`, () => {
            mountPage({ agents: [codex(), copilot({ health: HEALTH(state, state === 'unknown' ? null : undefined) })] });
            const health = card('copilot').find('.login-card');
            expect(health.find('h4').text()).toBe('GitHub Copilot login');
            expect(health.text()).toContain(tag);
            if (help) expect(health.text()).toContain(help);
            if (state === 'unknown') expect(health.text()).not.toContain('Last checked');
            else expect(health.find('time').exists()).toBe(true);
            expect(current.text()).not.toContain('AI documents are read by OpenAI Codex.');
            expect(health.find('[role="alert"]').exists()).toBe(false);
        });
    }

    const mountCard = (login, props = {}) => mount(AiLoginHealthCard, {
        props: { agent: 'copilot', login, ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n('en', load('en'))] },
    });

    it('Check again posts once, reloads only agents every 3 s and gives up after 30 s', async () => {
        vi.useFakeTimers();
        const w = mountCard(HEALTH('unknown', null));
        const check = () => w.findAll('button').find((b) => b.text() === 'Check again');
        await check().trigger('click');
        await check().trigger('click');
        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe('/settings/ai-calls/check');
        expect(check().attributes('aria-busy')).toBe('true');
        vi.advanceTimersByTime(3000);
        expect(reload).toHaveBeenCalledTimes(1);
        expect(reload.mock.calls[0][0]).toMatchObject({ only: ['agents'] });
        vi.advanceTimersByTime(27000);
        expect(reload).toHaveBeenCalledTimes(10);
        await flushPromises();
        expect(w.text()).toContain('No answer yet');
        expect(check().attributes('aria-busy')).toBeUndefined();
        w.unmount();
    });

    it('stops polling when a newer result arrives and announces the transition once', async () => {
        vi.useFakeTimers();
        const w = mountCard(HEALTH('unknown', null));
        await w.findAll('button').find((b) => b.text() === 'Check again').trigger('click');
        vi.advanceTimersByTime(3000);
        await w.setProps({ login: HEALTH('logged_in') });
        vi.advanceTimersByTime(9000);
        expect(reload).toHaveBeenCalledTimes(1);
        expect(w.text()).not.toContain('No answer yet');
        expect(w.find('.visually-hidden[role="status"]').text()).toBe('GitHub Copilot: Logged in');
        w.unmount();
    });

    it('announces nothing when a poll changes nothing', async () => {
        const w = mountCard(HEALTH('expired'));
        await w.setProps({ login: HEALTH('expired', '2026-11-14T11:00:00+00:00') });
        expect(w.find('.visually-hidden[role="status"]').text()).toBe('');
        w.unmount();
    });

    it('has no form, password or token field', () => {
        mountPage({ agents: [codex(), copilot({ health: HEALTH('expired') })] });
        expect(current.find('input[type="password"]').exists()).toBe(false);
        expect(current.find('textarea').exists()).toBe(false);
        // The only text inputs are the two model fields.
        expect(current.findAll('input[type="text"], input:not([type])')).toHaveLength(2);
    });
});

describe('accessibility and layout', () => {
    const future = () => new Date(Date.now() + 3 * 3600 * 1000).toISOString();
    const STATES = {
        both: {},
        'expired + warn': { agents: [codex(), copilot({ enabled: true, health: HEALTH('expired') })] },
        'not responding': { agents: [codex({ health: HEALTH('not_responding') }), copilot({ enabled: true, health: HEALTH('not_responding') })] },
        unknown: { agents: [codex({ health: HEALTH('unknown', null) }), copilot({ enabled: true })] },
        cooldown: { agents: [codex({ cooldownUntil: future() }), copilot({ enabled: true })] },
        'both on': { agents: [codex(), copilot({ enabled: true, model: 'gpt-5' })] },
        empty: { agents: [] },
        loading: { agents: null },
    };
    for (const locale of ['en', 'sk', 'pl']) {
        for (const theme of ['light', 'dark']) {
            it(`axe clean in ${locale} ${theme} for every state`, async () => {
                document.documentElement.dataset.theme = theme;
                for (const [name, props] of Object.entries(STATES)) {
                    mountPage({ locale, ...props });
                    await expectNoAxeViolations(current.element).catch((e) => { throw new Error(`${name}: ${e.message}`); });
                    current.unmount();
                    document.body.innerHTML = '';
                }
                delete document.documentElement.dataset.theme;
            });
        }
    }

    it('axe clean while saving, with a save error, a model error and the load error state', async () => {
        fetchMock.mockReturnValue(new Promise(() => {}));
        mountPage({ agents: [codex(), copilot({ enabled: true })] });
        await control('copilot', 'switch').setValue(false);
        await expectNoAxeViolations(current.element);
        current.unmount(); document.body.innerHTML = '';

        fetchMock.mockResolvedValue({ ok: false, status: 500, error: 'failed', data: {} });
        mountPage();
        await control('copilot', 'switch').setValue(true);
        await flushPromises();
        await control('codex', 'model').setValue('a/b');
        await card('codex').find('form').trigger('submit');
        await expectNoAxeViolations(current.element);
        current.unmount(); document.body.innerHTML = '';

        mountPage({ agents: null });
        reload.mockImplementation((o) => o.onError?.({}));
        current.vm.$.setupState.reload?.();
        await flushPromises();
        await expectNoAxeViolations(current.element);
    });

    it('pseudo-long text renders in full at 320 px without truncation hooks', () => {
        document.body.style.width = '320px';
        const messages = pseudoLong(load('sk'));
        mountPage({ messages, locale: 'sk', agents: [codex({ cooldownUntil: future() }), copilot({ enabled: true, health: HEALTH('expired') })] });
        expect(current.html()).toContain(messages.settings.ai_agents.switch_helper);
        const css = src('resources/js/Settings/AiAgents/AgentCard.vue') + src('resources/js/Pages/Settings/AiAgents.vue') + src('resources/js/Settings/AiCalls/AiLoginHealthCard.vue');
        expect(css).not.toMatch(/text-overflow|line-clamp|ellipsis/);
        document.body.style.width = '';
    });

    it('every control is a 44 px target and tags keep a border in forced colors', () => {
        const css = src('resources/js/Settings/AiAgents/AgentCard.vue');
        expect(css).toMatch(/min-height: 44px/);
        expect(css).toMatch(/@media \(forced-colors: active\)[\s\S]*\.ui-tag[\s\S]*border: 1px solid currentColor/);
        expect((css.match(/aria-disabled/g) ?? []).length).toBeGreaterThanOrEqual(3);
        expect(src('resources/js/Settings/AiCalls/AiLoginHealthCard.vue')).toMatch(/@media \(forced-colors: active\)[\s\S]*border: 1px solid currentColor/);
    });
});
