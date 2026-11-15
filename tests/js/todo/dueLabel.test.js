import { describe, it, expect } from 'vitest';
import { dueInfo } from '../../../resources/js/Todo/dueLabel.js';

const TZ = 'Europe/Bratislava';
// 2026-11-13 17:00 UTC is 18:00 in Bratislava (CET, UTC+1).
const NOW = '2026-11-13T17:00:00Z';
const TODAY = '2026-11-13';
const info = (task, locale = 'en', now = NOW) => dueInfo(task, TODAY, TZ, now, locale);

describe('dueInfo', () => {
    it('has no label without a due date', () => {
        expect(info({ dueDate: null, dueTime: null }).kind).toBe('none');
    });

    it('a date-only task due today is today (warn), not overdue', () => {
        expect(info({ dueDate: TODAY, dueTime: null })).toMatchObject({ kind: 'today', tone: 'warn', time: null });
    });

    it('a timed task earlier today is overdue; later today is today', () => {
        expect(info({ dueDate: TODAY, dueTime: '17:59' })).toMatchObject({ kind: 'overdue', tone: 'danger', time: '17:59' });
        expect(info({ dueDate: TODAY, dueTime: '18:30:00' })).toMatchObject({ kind: 'today', time: '18:30' });
    });

    it('uses the family zone for "now", not UTC', () => {
        // 23:30 UTC is 00:30 the next day in Bratislava, so a 00:10 task on the 14th is overdue.
        const late = '2026-11-13T23:30:00Z';
        expect(dueInfo({ dueDate: '2026-11-14', dueTime: '00:10' }, '2026-11-14', TZ, late, 'en').kind).toBe('overdue');
    });

    it('tomorrow is neutral and a past date is overdue', () => {
        expect(info({ dueDate: '2026-11-14', dueTime: null })).toMatchObject({ kind: 'tomorrow', tone: 'neutral' });
        expect(info({ dueDate: '2026-11-12', dueTime: null }).kind).toBe('overdue');
    });

    it('rolls tomorrow over a month end', () => {
        expect(dueInfo({ dueDate: '2026-12-01', dueTime: null }, '2026-11-30', TZ, '2026-11-30T10:00:00Z', 'en').kind).toBe('tomorrow');
    });

    it('later dates are "on" and format per locale without shifting the day', () => {
        expect(info({ dueDate: '2026-12-24', dueTime: null }, 'en')).toMatchObject({ kind: 'on', date: 'Dec 24' });
        expect(info({ dueDate: '2026-12-24', dueTime: null }, 'sk').date).toMatch(/24\.\s*12\./);
        expect(info({ dueDate: '2026-12-24', dueTime: null }, 'pl').date).toMatch(/24\s+gru/);
    });
});
