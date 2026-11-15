import { describe, it, expect, afterEach } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { mountUi } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';
import ReminderField from '../../../resources/js/Todo/ReminderField.vue';

let wrapper;
afterEach(() => { wrapper?.unmount(); wrapper = null; document.body.innerHTML = ''; });

const mountField = (props, locale = 'en') => {
    wrapper = mountUi(ReminderField, { props: { modelValue: 0, hasDate: true, hasTime: true, ...props }, locale });
    return wrapper;
};
const select = () => document.querySelector('select');
const option = (value) => [...select().options].find((o) => o.value === value);

describe('ReminderField', () => {
    it('lists the five choices with At due time as the default', () => {
        mountField({});
        expect([...select().options].map((o) => o.textContent)).toEqual(['At due time', '15 minutes before', '1 hour before', '1 day before', 'No reminder']);
        expect(select().value).toBe('at_due');
        expect(document.body.textContent).toContain('Tasks without a time remind at 08:00.');
    });

    it('maps each choice to remind_before_minutes', async () => {
        mountField({});
        const expected = { before_15m: 15, before_1h: 60, before_1d: 1440, none: null, at_due: 0 };
        let i = 0;
        for (const [key, minutes] of Object.entries(expected)) {
            select().value = key;
            select().dispatchEvent(new Event('change', { bubbles: true }));
            await flushPromises();
            expect(wrapper.emitted('update:modelValue')[i++][0]).toBe(minutes);
        }
    });

    it('shows the stored value', () => {
        mountField({ modelValue: 1440 });
        expect(select().value).toBe('before_1d');
        wrapper.unmount();
        mountField({ modelValue: null });
        expect(select().value).toBe('none');
    });

    it('is disabled with a hint without a due date', () => {
        mountField({ hasDate: false, hasTime: false });
        expect(select().disabled).toBe(true);
        expect(select().value).toBe('at_due');
        expect(document.body.textContent).toContain('Set a due date to get a reminder.');
    });

    it('disables minute and hour choices for a date-only task', () => {
        mountField({ hasTime: false });
        expect(option('before_15m').disabled).toBe(true);
        expect(option('before_1h').disabled).toBe(true);
        expect(option('before_1d').disabled).toBe(false);
        expect(document.body.textContent).toContain('Add a time to remind minutes or hours before.');
    });

    for (const locale of ['en', 'sk', 'pl']) {
        it(`passes axe in ${locale}`, async () => {
            mountField({ hasTime: false }, locale);
            await expectNoAxeViolations(document.body);
        });
    }
});
