import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { createAppI18n } from '../../resources/js/i18n.js';

const post = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'sk' } }),
    router: { post: (...a) => post(...a) },
}));

import LanguageSwitcher from '../../resources/js/Components/LanguageSwitcher.vue';

const sk = JSON.parse(readFileSync(new URL('../../lang/sk.json', import.meta.url), 'utf8'));

describe('LanguageSwitcher', () => {
    const mountIt = () => mount(LanguageSwitcher, { global: { plugins: [createAppI18n('sk', sk)] } });

    it('lists the three languages by native name and marks the current one', () => {
        const options = mountIt().findAll('option');
        expect(options.map((o) => o.text())).toEqual(['Slovenčina', 'Polski', 'English']);
        expect(mountIt().find('select').element.value).toBe('sk');
    });

    it('posts the chosen locale', async () => {
        const w = mountIt();
        await w.find('select').setValue('pl');
        expect(post).toHaveBeenCalledWith('/locale', { locale: 'pl' }, expect.anything());
    });
});
