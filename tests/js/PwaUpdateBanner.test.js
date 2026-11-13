import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { readFileSync } from 'node:fs';
import { createAppI18n } from '../../resources/js/i18n.js';
import PwaUpdateBanner from '../../resources/js/Components/PwaUpdateBanner.vue';

const load = (l) => JSON.parse(readFileSync(`${process.cwd()}/lang/${l}.json`, 'utf8'));
const mountBanner = (locale) =>
    mount(PwaUpdateBanner, { attachTo: document.body, global: { plugins: [createAppI18n(locale, load(locale))] } });
const fire = async (update = vi.fn()) => {
    window.dispatchEvent(new CustomEvent('fh:pwa-need-refresh', { detail: { update } }));
    await nextTick();
    return update;
};

describe('PwaUpdateBanner', () => {
    it('is hidden initially', () => {
        const w = mountBanner('en');
        expect(w.find('[role=status]').exists()).toBe(false);
        w.unmount();
    });

    it.each(['pl', 'en', 'sk'])('shows the translated text after the event (%s)', async (locale) => {
        const w = mountBanner(locale);
        await fire();
        expect(w.find('[role=status]').text()).toContain(load(locale).pwa.update_available);
        w.unmount();
    });

    it('reload calls update once', async () => {
        const w = mountBanner('en');
        const update = await fire();
        await w.find('[data-testid=pwa-reload]').trigger('click');
        expect(update).toHaveBeenCalledTimes(1);
        w.unmount();
    });

    it('dismiss hides the banner', async () => {
        const w = mountBanner('en');
        await fire();
        await w.find('[data-testid=pwa-dismiss]').trigger('click');
        expect(w.find('[role=status]').exists()).toBe(false);
        w.unmount();
    });
});
