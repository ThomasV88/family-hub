import { describe, it, expect, afterEach } from 'vitest';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { loadMessages, mountUi } from '../helpers/mount.js';
import { pseudoLong } from '../helpers/pseudoLong.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { mount } from '@vue/test-utils';

import AiStatusTag from '../../../resources/js/Health/Ai/AiStatusTag.vue';
import AiProvenanceLine from '../../../resources/js/Health/Ai/AiProvenanceLine.vue';
import { formatWhen } from '../../../resources/js/Health/Ai/aiWhen.js';

// 2026-11-14 is a Saturday; Europe/Bratislava is UTC+1 then.
const NOW = new Date('2026-11-14T00:00:00Z');
const TODAY = '2026-11-14T02:10:00Z';
const TUESDAY = '2026-11-17T02:10:00Z';
const PAST = '2026-11-13T22:00:00Z';

let current;
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; document.documentElement.classList.remove('dark'); });

describe('formatWhen', () => {
    it('prints the time only for today in the family time zone, 24 h in sk and pl', () => {
        expect(formatWhen(TODAY, 'sk', undefined, NOW)).toBe('03:10');
        expect(formatWhen(TODAY, 'pl', undefined, NOW)).toBe('03:10');
        expect(formatWhen(TODAY, 'en', undefined, NOW)).toBe('03:10');
    });

    it('prints a short weekday plus the time for another day', () => {
        expect(formatWhen(TUESDAY, 'en', undefined, NOW)).toMatch(/^Tue,? 03:10$/);
        expect(formatWhen(TUESDAY, 'sk', undefined, NOW)).toMatch(/03:10$/);
        expect(formatWhen(TUESDAY, 'sk', undefined, NOW).length).toBeGreaterThan(5);
        expect(formatWhen(TUESDAY, 'pl', undefined, NOW)).toMatch(/03:10$/);
    });

    it('respects an explicit time zone and returns an empty string for a bad date', () => {
        expect(formatWhen(TODAY, 'en', 'UTC', NOW)).toBe('02:10');
        expect(formatWhen('nope', 'en')).toBe('');
        expect(formatWhen(null, 'en')).toBe('');
    });
});

const tag = (props, locale = 'en', messages = undefined) => {
    current = mount(AiStatusTag, { props: { now: NOW, ...props }, attachTo: document.body, global: { plugins: [createAppI18n(locale, messages ?? loadMessages(locale))] } });
    return current;
};

describe('AiStatusTag waiting', () => {
    it('shows the full sentence with the time inside <time datetime> for a future resume time', () => {
        const w = tag({ status: 'waiting', availableAt: TODAY });
        expect(w.text()).toBe('Waiting for AI quota, retrying after 03:10');
        const time = w.find('time');
        expect(time.text()).toBe('03:10');
        expect(time.attributes('datetime')).toBe(TODAY);
        expect(w.find('svg').attributes('aria-hidden')).toBe('true');
        expect(w.find('[data-testid="ai-status-tag"]').classes()).toContain('ui-tag--neutral');
    });

    it('reads "retrying soon" once the time passed and the short text without a time', () => {
        expect(tag({ status: 'waiting', availableAt: PAST }).text()).toBe('Waiting for AI quota, retrying soon');
        current.unmount();
        const short = tag({ status: 'waiting' });
        expect(short.text()).toBe('Waiting for AI quota');
        expect(short.find('time').exists()).toBe(false);
    });

    it('keeps the Phase 8 tag for a quota-timeout failure', () => {
        expect(tag({ status: 'failed', failureCode: 'quota_timeout' }).text()).toBe('AI could not read this');
    });

    it('translates the sentence in sk and pl and keeps the time in <time>', () => {
        for (const locale of ['sk', 'pl']) {
            const w = tag({ status: 'waiting', availableAt: TODAY }, locale);
            expect(w.find('time').text()).toBe('03:10');
            expect(w.text()).toContain('03:10');
            w.unmount();
        }
    });

    it('wraps +40% pseudo-long waiting text without truncating', () => {
        const w = tag({ status: 'waiting', availableAt: TODAY }, 'en', pseudoLong(loadMessages('en')));
        expect(w.text().length).toBeGreaterThan('Waiting for AI quota, retrying after 03:10'.length * 1.3);
        expect(w.find('time').text()).toBe('03:10');
    });

    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`has no axe violations for every waiting state in ${locale} ${dark ? 'dark' : 'light'}`, async () => {
                document.documentElement.classList.toggle('dark', dark);
                const ws = [{ status: 'waiting', availableAt: TODAY }, { status: 'waiting', availableAt: PAST }, { status: 'waiting' }]
                    .map((props) => mount(AiStatusTag, { props: { now: NOW, ...props }, attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } }));
                for (const w of ws) await expectNoAxeViolations(w.element);
                ws.forEach((w) => w.unmount());
            });
        }
    }
});

describe('AiProvenanceLine', () => {
    const line = (props, locale = 'en') => { current = mountUi(AiProvenanceLine, { props, locale }); return current; };

    it('names the agent and model, or only the agent', () => {
        expect(line({ agent: 'copilot', model: 'gpt-5' }).text()).toBe('Read by GitHub Copilot gpt-5');
        current.unmount();
        expect(line({ agent: 'copilot', model: null }).text()).toBe('Read by GitHub Copilot');
        current.unmount();
        expect(line({ agent: 'codex', model: '' }).text()).toBe('Read by OpenAI Codex');
    });

    it('renders a model containing markup literally and renders nothing for an unknown agent', () => {
        const w = line({ agent: 'codex', model: '<b>x</b>' });
        expect(w.find('b').exists()).toBe(false);
        expect(w.text()).toContain('<b>x</b>');
        w.unmount();
        current = line({ agent: 'evil<script>', model: 'gpt-5' });
        expect(current.html()).toBe('<!--v-if-->');
    });

    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`is axe clean in ${locale} ${dark ? 'dark' : 'light'}`, async () => {
                document.documentElement.classList.toggle('dark', dark);
                const w = line({ agent: 'copilot', model: 'gpt-5' }, locale);
                await expectNoAxeViolations(w.element);
            });
        }
    }
});
