import { describe, it, expect, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { createAppI18n } from '../../../resources/js/i18n.js';
import AuditTable from '../../../resources/js/Components/Settings/AuditTable.vue';
import { AuditEvents } from './auditSecurityFixtures.js';

const load = (l) => JSON.parse(readFileSync(`${process.cwd()}/lang/${l}.json`, 'utf8'));
let current;
const mountTable = (entries, locale = 'en') => {
    current = mount(AuditTable, { props: { entries }, attachTo: document.body, global: { plugins: [createAppI18n(locale, load(locale))] } });
    return current;
};
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; });

describe('Audit table security rows', () => {
    it('renders the detail text of each of the 11 security events', () => {
        mountTable(AuditEvents);
        const text = current.text();
        for (const expected of [
            'Authenticator app turned on', 'Authenticator app turned off', 'Passkey added', 'Passkey renamed', 'Passkey removed',
            'Failed two-step check', 'Recovery code used', 'New recovery codes created', 'Two-step verification reset for Bob',
            'Requirement turned on', 'Requirement turned off', 'Identity confirmed for sensitive data',
        ]) expect(text).toContain(expected);
    });

    it('marks failed checks as danger with an icon and uses a neutral Security tag otherwise', () => {
        mountTable(AuditEvents);
        const rows = current.findAll('tbody tr');
        const failed = rows.find((r) => r.text().includes('Failed two-step check'));
        const tag = failed.find('.ui-tag');
        expect(tag.classes()).toContain('ui-tag--danger');
        expect(tag.find('svg').exists()).toBe(true);
        const ok = rows.find((r) => r.text().includes('Passkey added'));
        expect(ok.find('.ui-tag').classes()).toContain('ui-tag--neutral');
        expect(ok.find('.ui-tag').text()).toBe('Security');
        expect(ok.find('.ui-tag svg').exists()).toBe(true);
    });

    it('prints only mapped text, never other meta values', () => {
        mountTable(AuditEvents.map((e) => ({ ...e, meta: { ...e.meta, secret: 'LEAK-1234', method: 'totp', context: 'login' } })));
        expect(current.text()).not.toContain('LEAK-1234');
        expect(current.text()).not.toContain('totp');
    });

    it('translates in SK and PL', () => {
        for (const locale of ['sk', 'pl']) {
            current?.unmount();
            mountTable(AuditEvents, locale);
            expect(current.text()).toContain(load(locale).audit.detail.challenge_failed);
            expect(current.text()).toContain(load(locale).audit.detail.requirement_off);
        }
    });
});

describe('Audit filter groups', () => {
    it('offers Security in every language', () => {
        for (const l of ['en', 'sk', 'pl']) expect(load(l).audit.event.security).toBeTruthy();
    });
});
