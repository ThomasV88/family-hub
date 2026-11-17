import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

const { routerMock } = vi.hoisted(() => ({ routerMock: { visit: vi.fn(), get: vi.fn(), reload: vi.fn(), on: vi.fn(() => vi.fn()) } }));
vi.mock('@inertiajs/vue3', () => ({ router: routerMock }));

import NewRecipeDialog from '../../../resources/js/Components/Recipes/NewRecipeDialog.vue';

let current;
function mountDialog(locale = 'en') {
    window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    current = mount(NewRecipeDialog, { props: { open: true }, attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });

    return current;
}
const $ = (sel) => document.body.querySelector(sel);

beforeEach(() => routerMock.visit.mockClear());
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; });

describe('NewRecipeDialog', () => {
    it('offers Type it in and Read from a web page with their hints', async () => {
        mountDialog();
        await flushPromises();
        expect($('[role="dialog"]').getAttribute('aria-labelledby')).toBeTruthy();
        expect($('.ui-dialog__title').textContent).toBe('New recipe');
        expect($('[data-testid="new-type-it-in"]').textContent).toContain('Type it in');
        expect($('[data-testid="new-type-it-in"]').textContent).toContain('Fill in a blank form.');
        expect($('[data-testid="new-from-page"]').textContent).toContain('Read from a web page');
        expect($('[data-testid="new-from-page"]').textContent).toContain('Paste a link and the app fills in the form for you to check.');
        expect(document.querySelectorAll('[data-testid="new-recipe-options"] button')).toHaveLength(2);
    });

    it('Type it in closes the dialog and opens the blank form', async () => {
        mountDialog();
        await flushPromises();
        $('[data-testid="new-type-it-in"] button').click();
        expect(routerMock.visit).toHaveBeenCalledWith('/recipes/create');
        expect(current.emitted('update:open').at(-1)).toEqual([false]);
        expect(current.emitted('url')).toBeUndefined();
    });

    it('Read from a web page closes the dialog and asks the page for the URL step', async () => {
        mountDialog();
        await flushPromises();
        $('[data-testid="new-from-page"] button').click();
        expect(routerMock.visit).not.toHaveBeenCalled();
        expect(current.emitted('url')).toHaveLength(1);
        expect(current.emitted('update:open').at(-1)).toEqual([false]);
    });

    it.each(['sk', 'pl'])('is translated in %s without raw keys', async (locale) => {
        mountDialog(locale);
        await flushPromises();
        expect(document.body.textContent).not.toMatch(/recipes\.new\./);
    });

    it('has no axe violations', async () => {
        mountDialog();
        await flushPromises();
        await expectNoAxeViolations(document.body);
    });
});
