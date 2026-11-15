import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';

const put = vi.fn();
vi.mock('@inertiajs/vue3', () => ({ router: { put: (...a) => put(...a) } }));

import GiftReminderPrefs from '../../../resources/js/Components/Settings/GiftReminderPrefs.vue';

const load = (l) => JSON.parse(readFileSync(`${process.cwd()}/lang/${l}.json`, 'utf8'));
let current;
function mountCard({ locale = 'en', props = {} } = {}) {
    current = mount(GiftReminderPrefs, {
        props: { leadDays: [14, 3], allowed: [14, 7, 3, 1], ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, load(locale))] },
    });
    return current;
}
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; vi.clearAllMocks(); });

describe('GiftReminderPrefs', () => {
    it('shows the four checkboxes with 14 and 3 on by default', () => {
        mountCard();
        expect(current.text()).toContain('Gift reminders');
        expect(current.text()).toContain('Remind me');
        const on = (d) => current.find(`[data-testid=gift-${d}]`).element.checked;
        expect([on(14), on(7), on(3), on(1)]).toEqual([true, false, true, false]);
    });

    it('saves immediately with a sorted list', async () => {
        mountCard();
        await current.find('[data-testid=gift-7]').setValue(true);
        expect(put).toHaveBeenCalledWith('/settings/notifications/gifts', { lead_days: [14, 7, 3] }, expect.objectContaining({ preserveScroll: true }));
        expect(current.find('[data-testid=gift-7]').element.checked).toBe(true);
    });

    it('reverts and shows the danger alert when saving fails', async () => {
        mountCard();
        await current.find('[data-testid=gift-14]').setValue(false);
        put.mock.calls[0][2].onError({});
        await flushPromises();
        expect(current.find('[data-testid=gift-14]').element.checked).toBe(true);
        expect(current.text()).toContain('We could not save this setting.');
    });

    it('explains when nothing is ticked or both channels are off', async () => {
        mountCard({ props: { leadDays: [] } });
        expect(current.find('[data-testid=gifts-none-on]').text()).toContain('No gift reminders are on');
        current.unmount();
        mountCard({ props: { channelsOff: true } });
        expect(current.find('[data-testid=gifts-both-off]').text()).toContain('Both channels are off');
    });

    it.each(['en', 'sk', 'pl'])('has no axe violations in %s', async (locale) => {
        mountCard({ locale, props: { channelsOff: true } });
        await expectNoAxeViolations(current.element);
    });
});
