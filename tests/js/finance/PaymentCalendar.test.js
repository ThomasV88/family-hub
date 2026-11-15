import { describe, it, expect, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import PaymentCalendar from '../../../resources/js/Finance/PaymentCalendar.vue';
import { calendar } from './recurringFixtures.js';

let current;
function mountCalendar(props = {}, { locale = 'en' } = {}) {
    current = mount(PaymentCalendar, {
        props: { month: '2026-03', today: '2026-03-14', days: calendar(), selected: null, ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, loadMessages(locale))] },
    });
    return current;
}
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; document.documentElement.classList.remove('dark'); });
const day = (date) => document.querySelector(`button[data-date="${date}"]`);
const key = (date, k) => day(date).dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true }));

describe('PaymentCalendar markup', () => {
    it('is a semantic table with seven column headers carrying full names, one button per day and blank outside days', () => {
        mountCalendar();
        const heads = [...document.querySelectorAll('thead th')];
        expect(heads).toHaveLength(7);
        expect(heads.every((th) => th.getAttribute('scope') === 'col')).toBe(true);
        expect(heads[0].querySelector('abbr').getAttribute('title')).toBe('Monday');
        expect(document.querySelectorAll('tbody button')).toHaveLength(31);
        // 1 March 2026 is a Sunday; the week starts on Monday in en-GB: six blanks before it.
        const firstRow = [...document.querySelectorAll('tbody tr')[0].querySelectorAll('td')];
        expect(firstRow.slice(0, 6).every((td) => td.querySelector('button') === null)).toBe(true);
        expect(firstRow[6].querySelector('button').dataset.date).toBe('2026-03-01');
        expect(document.querySelector('table').getAttribute('aria-label')).toBe('Payment calendar, March 2026');
    });

    it('labels each day with its payments and overdue count, or "no payments", and marks today', () => {
        mountCalendar();
        expect(day('2026-03-01').getAttribute('aria-label')).toBe('Sunday 1 March, 1 payment, 1 overdue');
        expect(day('2026-03-20').getAttribute('aria-label')).toBe('Friday 20 March, 2 payments');
        expect(day('2026-03-02').getAttribute('aria-label')).toBe('Monday 2 March, no payments');
        expect(day('2026-03-14').getAttribute('aria-current')).toBe('date');
        expect(document.querySelectorAll('[aria-current="date"]')).toHaveLength(1);
    });

    it('shows a count chip with the worst status icon (icon plus number, not colour)', () => {
        mountCalendar();
        expect(day('2026-03-01').querySelector('svg.lucide-circle-alert, svg.lucide-alert-circle')).not.toBeNull();
        expect(day('2026-03-01').textContent).toContain('1');
        expect(day('2026-03-20').querySelector('svg.lucide-clock')).not.toBeNull();
        expect(day('2026-03-20').textContent).toContain('+1');
        expect(day('2026-03-05').querySelector('svg.lucide-check')).not.toBeNull();
        expect(day('2026-03-08').querySelector('svg.lucide-triangle-alert, svg.lucide-alert-triangle')).not.toBeNull();
        expect(day('2026-03-02').querySelector('svg')).toBeNull();
    });

    it('renders payment names as text only', () => {
        const odd = { ...calendar()[0], occurrences: [{ ...calendar()[0].occurrences[0], name: '<img src=x onerror=alert(1)>', status: 'upcoming', dueDate: '2026-03-01', key: 'x' }] };
        mountCalendar({ days: [odd, ...calendar().slice(1)] });
        expect(document.querySelector('img')).toBeNull();
        expect(document.body.textContent).toContain('<img src=x onerror=alert(1)>');
    });
});

describe('PaymentCalendar keyboard', () => {
    it('has one Tab stop: today, or the selected day', () => {
        mountCalendar();
        const stops = [...document.querySelectorAll('tbody button')].filter((b) => b.tabIndex === 0);
        expect(stops.map((b) => b.dataset.date)).toEqual(['2026-03-14']);
    });

    it('starts on the first day when today is outside the month', () => {
        mountCalendar({ today: '2026-05-01' });
        expect([...document.querySelectorAll('tbody button')].filter((b) => b.tabIndex === 0).map((b) => b.dataset.date)).toEqual(['2026-03-01']);
    });

    it('moves by day and week with arrows and to the week edges with Home and End', async () => {
        mountCalendar();
        day('2026-03-14').focus();
        key('2026-03-14', 'ArrowRight');
        await Promise.resolve(); await Promise.resolve();
        expect(document.activeElement.dataset.date).toBe('2026-03-15');
        key('2026-03-15', 'ArrowDown');
        await Promise.resolve(); await Promise.resolve();
        expect(document.activeElement.dataset.date).toBe('2026-03-22');
        key('2026-03-22', 'Home');
        await Promise.resolve(); await Promise.resolve();
        expect(document.activeElement.dataset.date).toBe('2026-03-16');
        key('2026-03-16', 'End');
        await Promise.resolve(); await Promise.resolve();
        expect(document.activeElement.dataset.date).toBe('2026-03-22');
        key('2026-03-22', 'ArrowLeft');
        await Promise.resolve(); await Promise.resolve();
        expect(document.activeElement.dataset.date).toBe('2026-03-21');
        expect([...document.querySelectorAll('tbody button')].filter((b) => b.tabIndex === 0).map((b) => b.dataset.date)).toEqual(['2026-03-21']);
    });

    it('stays inside the month at the edges and asks for another month on PageUp and PageDown', () => {
        const wrapper = mountCalendar({ today: '2026-03-01' });
        key('2026-03-01', 'ArrowLeft');
        expect(document.querySelectorAll('tbody button').length).toBe(31);
        key('2026-03-01', 'PageDown');
        key('2026-03-01', 'PageUp');
        expect(wrapper.emitted('month')).toEqual([[1], [-1]]);
    });

    it('opens the day on click (Enter and Space activate the button)', () => {
        const wrapper = mountCalendar();
        day('2026-03-20').click();
        expect(wrapper.emitted('open')).toEqual([['2026-03-20']]);
    });

    it('marks the selected day with a class and keeps it the Tab stop', () => {
        mountCalendar({ selected: '2026-03-20' });
        expect(day('2026-03-20').classList.contains('is-selected')).toBe(true);
        expect(day('2026-03-20').tabIndex).toBe(0);
    });
});

describe('PaymentCalendar accessibility', () => {
    for (const locale of ['en', 'sk', 'pl']) {
        for (const dark of [false, true]) {
            it(`passes axe with an overdue month in ${locale}${dark ? ' dark' : ''}`, async () => {
                if (dark) document.documentElement.classList.add('dark');
                mountCalendar({ selected: '2026-03-01' }, { locale });
                await expectNoAxeViolations(document.body);
            });
        }
    }
});
