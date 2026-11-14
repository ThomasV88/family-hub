import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { createAppI18n } from '../../resources/js/i18n.js';

const load = (l) => JSON.parse(readFileSync(`${process.cwd()}/lang/${l}.json`, 'utf8'));
const messages = { sk: load('sk'), pl: load('pl'), en: load('en') };

const KEYS = [
    'health.ai.status.waiting',
    'health.ai.status.waiting_soon',
    'health.ai.status.waiting_short',
    'health.ai.status.waiting_detail',
    'health.ai.status.waiting_announce',
    'health.ai.status.quota_timeout_detail',
    'health.ai.status.quota_exhausted_detail',
    'health.ai.status.no_agent_detail',
    'health.ai.status.notice_required_detail',
    'health.ai.status.still_waiting',
    'health.ai.status.newer_waiting',
    'health.ai.provenance.with_model',
    'health.ai.provenance.without_model',
    'health.ai.run.provider_codex',
    'health.ai.run.provider_both',
    'health.ai.run.provider_copilot',
    'health.ai.notice.multi_title',
    'health.ai.notice.multi_body',
    'health.ai.notice.github_title',
    'health.ai.notice.github_body',
    'settings.section.ai_agents',
    'settings.ai_calls.status_quota',
    'settings.ai_calls.filter_quota',
    'settings.ai_calls.agents_pointer',
    'settings.ai_calls.person_bulk',
    'settings.ai_agents.intro',
    'settings.ai_agents.list_label',
    'settings.ai_agents.switch_label',
    'settings.ai_agents.switch_helper',
    'settings.ai_agents.on',
    'settings.ai_agents.off',
    'settings.ai_agents.position_of',
    'settings.ai_agents.move_up',
    'settings.ai_agents.move_down',
    'settings.ai_agents.move_up_name',
    'settings.ai_agents.move_down_name',
    'settings.ai_agents.model_label',
    'settings.ai_agents.model_helper',
    'settings.ai_agents.model_error',
    'settings.ai_agents.save_model',
    'settings.ai_agents.login_title',
    'settings.ai_agents.expired_help',
    'settings.ai_agents.not_signed_in_warning',
    'settings.ai_agents.provider_reminder',
    'settings.ai_agents.last_agent_lock',
    'settings.ai_agents.cooldown_tag',
    'settings.ai_agents.cooldown_line',
    'settings.ai_agents.saving',
    'settings.ai_agents.now_on',
    'settings.ai_agents.now_off',
    'settings.ai_agents.moved_to',
    'settings.ai_agents.model_saved',
    'settings.ai_agents.stale_order',
    'settings.ai_agents.save_error',
    'settings.ai_agents.empty_heading',
    'settings.ai_agents.empty_body',
    'settings.ai_agents.apply_note',
    'settings.ai_agents.open_calls',
    'settings.ai_agents.check_again',
    'settings.ai_bulk.heading',
    'settings.ai_bulk.intro',
    'settings.ai_bulk.scope_legend',
    'settings.ai_bulk.scope_no_proposal',
    'settings.ai_bulk.scope_last_failed',
    'settings.ai_bulk.scope_all',
    'settings.ai_bulk.scope_all_hint',
    'settings.ai_bulk.cap_label',
    'settings.ai_bulk.cap_helper',
    'settings.ai_bulk.cap_error',
    'settings.ai_bulk.check',
    'settings.ai_bulk.checking',
    'settings.ai_bulk.result_heading',
    'settings.ai_bulk.row_eligible',
    'settings.ai_bulk.row_consent',
    'settings.ai_bulk.row_unsupported',
    'settings.ai_bulk.row_decided',
    'settings.ai_bulk.row_active',
    'settings.ai_bulk.days_estimate',
    'settings.ai_bulk.checked_at',
    'settings.ai_bulk.valid_minutes',
    'settings.ai_bulk.check_announce',
    'settings.ai_bulk.zero_eligible',
    'settings.ai_bulk.start',
    'settings.ai_bulk.start_reason_none',
    'settings.ai_bulk.start_reason_stale',
    'settings.ai_bulk.stale_alert',
    'settings.ai_bulk.confirm_title',
    'settings.ai_bulk.confirm_body',
    'settings.ai_bulk.confirm_safe',
    'settings.ai_bulk.providers_codex',
    'settings.ai_bulk.providers_both',
    'settings.ai_bulk.providers_copilot',
    'settings.ai_bulk.no_agent_note',
    'settings.ai_bulk.start_error',
    'settings.ai_bulk.dry_run_error',
    'settings.ai_bulk.active_note',
    'settings.ai_bulk.already_active',
    'settings.ai_bulk.progress_heading',
    'settings.ai_bulk.progress',
    'settings.ai_bulk.count_read',
    'settings.ai_bulk.count_failed',
    'settings.ai_bulk.count_skipped',
    'settings.ai_bulk.count_waiting',
    'settings.ai_bulk.count_left',
    'settings.ai_bulk.today_used',
    'settings.ai_bulk.tag_running',
    'settings.ai_bulk.tag_paused',
    'settings.ai_bulk.tag_next_batch',
    'settings.ai_bulk.tag_waiting',
    'settings.ai_bulk.tag_completed',
    'settings.ai_bulk.tag_cancelled',
    'settings.ai_bulk.tag_stopped',
    'settings.ai_bulk.pause',
    'settings.ai_bulk.resume',
    'settings.ai_bulk.pause_name',
    'settings.ai_bulk.resume_name',
    'settings.ai_bulk.cancel',
    'settings.ai_bulk.toast_started',
    'settings.ai_bulk.toast_paused',
    'settings.ai_bulk.toast_resumed',
    'settings.ai_bulk.toast_cancelled',
    'settings.ai_bulk.cancel_title',
    'settings.ai_bulk.cancel_body',
    'settings.ai_bulk.cancel_safe',
    'settings.ai_bulk.finished',
    'settings.ai_bulk.poll_error',
    'settings.ai_bulk.try_again',
    'settings.ai_bulk.history_heading',
    'settings.ai_bulk.history_note',
    'settings.ai_bulk.col_started',
    'settings.ai_bulk.col_scope',
    'settings.ai_bulk.col_cap',
    'settings.ai_bulk.col_would_read',
    'settings.ai_bulk.col_read',
    'settings.ai_bulk.col_failed',
    'settings.ai_bulk.col_skipped',
    'settings.ai_bulk.col_status',
    'settings.ai_bulk.history_empty_heading',
    'settings.ai_bulk.history_empty_body',
    'audit.detail.ai_agent_setting_changed',
    'audit.detail.ai_agent_field_enabled',
    'audit.detail.ai_agent_field_position',
    'audit.detail.ai_agent_field_model',
    'audit.detail.ai_bulk_run_changed_dry_run',
    'audit.detail.ai_bulk_run_changed_started',
    'audit.detail.ai_bulk_run_changed_paused',
    'audit.detail.ai_bulk_run_changed_resumed',
    'audit.detail.ai_bulk_run_changed_cancelled',
    'audit.detail.ai_bulk_run_changed_completed',
];

const PLURALS = [
    'settings.ai_bulk.days_estimate',
    'settings.ai_bulk.valid_minutes',
    'settings.ai_bulk.confirm_body',
    'settings.ai_bulk.progress',
];

const VARS = ['agent', 'model', 'when', 'n', 'cap', 'max', 'hour', 'providers', 'done', 'total', 'used', 'minutes', 'time', 'days', 'state', 'scope', 'count', 'field', 'value'];
const AUDIT_ONLY = ['scope', 'count', 'field', 'value'];

function flatten(obj, prefix = '') {
    return Object.entries(obj).flatMap(([k, v]) =>
        typeof v === 'object' && v !== null ? flatten(v, `${prefix}${k}.`) : [[`${prefix}${k}`, v]],
    );
}
const flat = Object.fromEntries(['en', 'sk', 'pl'].map((l) => [l, Object.fromEntries(flatten(messages[l]))]));
const phase9 = (l) => Object.fromEntries(KEYS.map((k) => [k, flat[l][k]]));
const placeholders = (s) => [...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1]);
const make = (l) => createAppI18n(l, messages[l]).global;
const segs = (l, key) => flat[l][key].split('|').length;

describe('Phase 9 copy parity', () => {
    it('has every Phase 9 key in en, sk and pl, non-empty', () => {
        expect(KEYS.length).toBeGreaterThanOrEqual(140);
        expect(new Set(KEYS).size).toBe(KEYS.length);
        for (const l of ['en', 'sk', 'pl']) {
            for (const [k, v] of Object.entries(phase9(l))) expect(typeof v === 'string' && v.trim().length > 0, `${l} ${k}`).toBe(true);
        }
    });

    it.each(['sk', 'pl'])('%s keeps every placeholder of en', (l) => {
        for (const k of KEYS) {
            expect([...new Set(placeholders(flat[l][k]))].sort(), k).toEqual([...new Set(placeholders(flat.en[k]))].sort());
        }
    });

    it('uses only allowed interpolation variables, audit-only ones only in audit keys', () => {
        for (const l of ['en', 'sk', 'pl']) {
            for (const [k, v] of Object.entries(phase9(l))) {
                for (const p of placeholders(v)) {
                    expect(VARS, `${l} ${k} {${p}}`).toContain(p);
                    if (AUDIT_ONLY.includes(p)) expect(k.startsWith('audit.detail.'), `${l} ${k} {${p}}`).toBe(true);
                }
            }
        }
    });

    it.each(['en', 'sk', 'pl'])('%s has no exclamation marks, ellipses or markup', (l) => {
        for (const [k, v] of Object.entries(phase9(l))) {
            expect(v.includes('!'), k).toBe(false);
            expect(v.includes('...'), k).toBe(false);
            expect(/<[^>]*>/.test(v), k).toBe(false);
        }
    });

    it.each(['en', 'sk', 'pl'])('%s strings compile and render with params', (l) => {
        const i18n = make(l);
        const params = new Proxy({}, { get: (_, p) => (p === 'n' ? 2 : typeof p === 'string' ? `x${p}` : undefined) });
        for (const k of KEYS) {
            const out = PLURALS.includes(k) ? i18n.t(k, params, 2) : i18n.t(k, params);
            expect(out, k).not.toBe(k);
            expect(out.includes('{'), k).toBe(false);
        }
    });

    it('only the declared plural keys use pipes', () => {
        const pipes = KEYS.filter((k) => flat.en[k].includes('|'));
        expect(pipes.sort()).toEqual([...PLURALS].sort());
        for (const l of ['sk', 'pl']) expect(KEYS.filter((k) => flat[l][k].includes('|')).sort()).toEqual([...PLURALS].sort());
    });

    it('uses the checker follow-up and planner-decision wordings', () => {
        const s = messages.en.settings;
        expect(s.ai_bulk.pause_name).toBe('Pause bulk run');
        expect(s.ai_bulk.resume_name).toBe('Resume bulk run');
        expect(messages.en.health.ai.status.waiting_detail).toContain('We will try again automatically.');
        expect(s.ai_agents.model_error).toContain('60');
        expect(s.ai_agents.model_error.toLowerCase()).not.toContain('slash');
        for (const l of ['sk', 'pl']) expect(messages[l].settings.ai_agents.model_error).toContain('60');
        expect(s.ai_calls.person_bulk).toBe('Not shown (bulk run)');
        expect(s.ai_calls.status_quota).toBe('Usage limit reached');
        expect(messages.en.health.ai.provenance.with_model).toBe('Read by {agent} {model}');
        expect(messages.en.health.ai.notice.github_title).toBe('Send this document to GitHub?');
    });

    it('keeps proper nouns out of the agent name position (names are not keys)', () => {
        expect(flat.en['settings.ai_agents.switch_label']).toContain('{agent}');
        expect(KEYS.some((k) => /agent_(codex|copilot)$/.test(k))).toBe(false);
    });
});

describe('Phase 9 plural forms', () => {
    it.each(PLURALS)('%s has 2 segments in en and 4 in sk and pl, each with a count', (key) => {
        expect(segs('en', key)).toBe(2);
        expect(segs('sk', key)).toBe(4);
        expect(segs('pl', key)).toBe(4);
        for (const l of ['en', 'sk', 'pl']) {
            for (const part of flat[l][key].split('|')) expect(part, `${l} ${key}`).toMatch(/\{n\}/);
        }
    });

    const SK = { 0: 3, 1: 0, 2: 1, 4: 1, 5: 3, 12: 3, 22: 3, 25: 3 };
    const PL = { 0: 2, 1: 0, 2: 1, 4: 1, 5: 2, 12: 2, 22: 1, 25: 2 };
    const cases = [];
    for (const key of PLURALS) {
        for (const [l, map] of [['sk', SK], ['pl', PL]]) {
            for (const [n, idx] of Object.entries(map)) cases.push([key, l, Number(n), idx]);
        }
    }
    const fill = (s, n) => s.replaceAll('{n}', String(n)).replaceAll('{done}', 'D').replaceAll('{cap}', 'C').replaceAll('{providers}', 'P').replaceAll('{hour}', 'H');

    it.each(cases)('%s in %s at n=%i picks segment %i', (key, l, n, idx) => {
        const parts = flat[l][key].split('|').map((s) => s.trim());
        const out = make(l).t(key, { n, done: 'D', cap: 'C', providers: 'P', hour: 'H' }, n);
        expect(out).toBe(fill(parts[idx], n));
        expect(out).toContain(String(n));
    });

    it.each([
        ['en', 'settings.ai_bulk.days_estimate', 1, 'About 1 day at C per day'],
        ['en', 'settings.ai_bulk.days_estimate', 3, 'About 3 days at C per day'],
        ['sk', 'settings.ai_bulk.days_estimate', 1, 'Približne 1 deň pri C za deň'],
        ['sk', 'settings.ai_bulk.days_estimate', 4, 'Približne 4 dni pri C za deň'],
        ['sk', 'settings.ai_bulk.days_estimate', 5, 'Približne 5 dní pri C za deň'],
        ['pl', 'settings.ai_bulk.days_estimate', 1, 'Około 1 dzień przy C dziennie'],
        ['pl', 'settings.ai_bulk.days_estimate', 22, 'Około 22 dni przy C dziennie'],
        ['pl', 'settings.ai_bulk.days_estimate', 12, 'Około 12 dni przy C dziennie'],
        ['sk', 'settings.ai_bulk.valid_minutes', 15, 'Kontrola platí 15 minút.'],
        ['pl', 'settings.ai_bulk.valid_minutes', 15, 'Sprawdzenie jest ważne przez 15 minut.'],
    ])('%s %s at %i reads %s', (l, key, n, expected) => {
        expect(make(l).t(key, { n, cap: 'C' }, n)).toBe(expected);
    });
});
