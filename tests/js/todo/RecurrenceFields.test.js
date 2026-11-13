import { describe, it, expect, afterEach } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { mountUi } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';
import RecurrenceFields from '../../../resources/js/Todo/RecurrenceFields.vue';
import { emptyRepeat, patternFromRepeat, repeatFromTask } from '../../../resources/js/Todo/recurrenceState.js';

let wrapper;
afterEach(() => { wrapper?.unmount(); wrapper = null; document.body.innerHTML = ''; });

const mountFields = (repeat, { locale = 'en', dueDate = '2027-01-31', errors = {} } = {}) => {
    wrapper = mountUi(RecurrenceFields, { props: { modelValue: { ...emptyRepeat(), ...repeat }, dueDate, errors }, locale });
    return wrapper;
};
const summary = () => document.body.querySelector('[data-testid="repeat-summary"]')?.textContent;

describe('RecurrenceFields', () => {
    it('is disabled with a hint when there is no due date', () => {
        mountFields({}, { dueDate: '' });
        expect(document.querySelector('select').disabled).toBe(true);
        expect(document.body.textContent).toContain('Set a due date to make this task repeat.');
    });

    it('renders seven weekday chips, Monday first, with full names and aria-pressed', () => {
        mountFields({ kind: 'weekly', weekdays: ['TU'] });
        const chips = [...document.querySelectorAll('button[aria-pressed]')];
        expect(chips.map((c) => c.getAttribute('aria-label'))).toEqual(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']);
        expect(chips.map((c) => c.getAttribute('aria-pressed'))).toEqual(['false', 'true', 'false', 'false', 'false', 'false', 'false']);
        expect(document.querySelector('fieldset legend').textContent).toBe('Weekdays');
    });

    it('starts the week on Monday in Slovak and Polish', () => {
        mountFields({ kind: 'weekly', weekdays: [] }, { locale: 'sk' });
        expect(document.querySelectorAll('button[aria-pressed]')[0].getAttribute('aria-label')).toBe('pondelok');
        wrapper.unmount();
        mountFields({ kind: 'weekly', weekdays: [] }, { locale: 'pl' });
        expect(document.querySelectorAll('button[aria-pressed]')[0].getAttribute('aria-label')).toBe('poniedziałek');
    });

    it('emits sorted-independent toggles and a weekday summary', async () => {
        mountFields({ kind: 'weekly', weekdays: ['MO', 'WE'] });
        expect(summary()).toBe('Repeats on Monday and Wednesday');
        document.querySelectorAll('button[aria-pressed]')[4].click();
        await flushPromises();
        expect(wrapper.emitted('update:modelValue')[0][0].weekdays).toEqual(['MO', 'WE', 'FR']);
    });

    it('shows the plural summaries', () => {
        mountFields({ kind: 'custom', interval: '1', unit: 'week' });
        expect(summary()).toBe('Repeats every week');
        wrapper.unmount();
        mountFields({ kind: 'custom', interval: '4', unit: 'day' });
        expect(summary()).toBe('Repeats every 4 days');
        wrapper.unmount();
        mountFields({ kind: 'custom', interval: '3', unit: 'month' }, { locale: 'pl' });
        expect(summary()).toContain('3');
    });

    it('marks the summary as a polite live region and exposes the repeat-from radios', () => {
        mountFields({ kind: 'daily' });
        expect(document.querySelector('[data-testid="repeat-summary"]').getAttribute('aria-live')).toBe('polite');
        expect([...document.querySelectorAll('input[type="radio"]')].map((r) => r.value)).toEqual(['schedule', 'completion']);
        expect(document.body.textContent).toContain('Schedule: the next date follows the rule.');
    });

    it('offers the due date day for monthly repeats', () => {
        mountFields({ kind: 'monthly' });
        const options = [...document.querySelectorAll('select')[1].options].map((o) => o.textContent);
        expect(options).toEqual(['On day 31', 'On the last day of the month']);
        expect(document.body.textContent).toContain('In shorter months this happens on the last day.');
    });

    it('has a numeric interval input for custom repeats and shows its error', () => {
        mountFields({ kind: 'custom', interval: '2', unit: 'day' }, { errors: { interval: 'Enter a whole number from 1 to 99.' } });
        const input = document.querySelector('input[inputmode="numeric"]');
        expect(input.getAttribute('aria-invalid')).toBe('true');
        expect(document.body.textContent).toContain('Enter a whole number from 1 to 99.');
    });

    for (const locale of ['en', 'sk', 'pl']) {
        for (const kind of ['daily', 'weekly', 'monthly', 'custom']) {
            it(`passes axe for ${kind} in ${locale}`, async () => {
                mountFields({ kind, weekdays: ['MO'] }, { locale });
                await expectNoAxeViolations(document.body);
            });
        }
    }
});

describe('recurrenceState', () => {
    it('round-trips server patterns without an RRULE', () => {
        const cases = [
            [{ kind: 'daily', interval: 1 }, 'daily'],
            [{ kind: 'weekly', interval: 1, weekdays: ['MO', 'FR'] }, 'weekly'],
            [{ kind: 'monthly_last', interval: 1 }, 'monthly'],
            [{ kind: 'custom', interval: 3, unit: 'month', monthDay: 31 }, 'custom'],
        ];
        for (const [pattern, kind] of cases) {
            const state = repeatFromTask(pattern, 'schedule');
            expect(state.kind).toBe(kind);
            const back = patternFromRepeat(state, '2027-01-31');
            expect(back.kind).toBe(pattern.kind);
            expect(JSON.stringify(back)).not.toContain('FREQ');
        }
        expect(repeatFromTask(null, null).kind).toBe('none');
        expect(patternFromRepeat(emptyRepeat(), '2027-01-31')).toBeNull();
    });
});
