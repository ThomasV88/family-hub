import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

let mockProps = {};
const { routerMock, fetchMock } = vi.hoisted(() => ({
    routerMock: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), visit: vi.fn(), replace: vi.fn(), reload: vi.fn(), on: vi.fn(() => vi.fn()) },
    fetchMock: vi.fn(),
}));
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({ props: mockProps, url: '/recipes/12' }),
        router: routerMock,
        Head: { render: () => null },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});
vi.mock('../../../resources/js/Security/securityFetch.js', () => ({ securityFetch: fetchMock }));

import Show from '../../../resources/js/Pages/Recipes/Show.vue';
import { useToast } from '../../../resources/js/Components/ui/useToast.js';

const UNITS = [{ id: 1, key: 'pcs', names: null }, { id: 2, key: 'g', names: null }, { id: 8, key: 'tbsp', names: null }, { id: 9, key: 'tsp', names: null }];
const RECIPE = {
    id: 12, title: 'Tomato soup', description: 'Warm and simple', servings: 4, prepMinutes: 10, cookMinutes: 30, visibility: 'shared',
    sourceUrl: 'https://www.example.test/soup', sourceHost: 'www.example.test', createdVia: 'manual',
    author: { id: 2, name: 'Mira', isMe: true }, categories: ['Soup'],
    ingredients: [
        { id: 101, name: 'Flour', quantity: '200', unitId: 2, note: 'sifted' },
        { id: 102, name: 'Eggs', quantity: '2', unitId: 1, note: null },
        { id: 103, name: 'Salt', quantity: null, unitId: null, note: 'to taste' },
        { id: 104, name: 'Pepper', quantity: '0.5', unitId: 9, note: null },
    ],
    steps: [{ id: 201, body: 'Boil <b>water</b>' }, { id: 202, body: 'Serve' }],
    photos: [
        { id: 31, position: 1, isMain: false, version: 1, thumbUrl: '/recipes/12/photos/31/thumb?v=1', fullUrl: '/recipes/12/photos/31/full?v=1' },
        { id: 32, position: 2, isMain: true, version: 1, thumbUrl: '/recipes/12/photos/32/thumb?v=1', fullUrl: '/recipes/12/photos/32/full?v=1' },
    ],
};
const STATE = { favourite: false, note: null, servings: null, tickedIngredientIds: [], doneStepIds: [] };
const AUTHOR = { edit: true, delete: true, changeVisibility: true, addToShopping: true };
const READER = { edit: false, delete: false, changeVisibility: false, addToShopping: true };
const OTHER = { ...RECIPE, author: { id: 3, name: 'Anna', isMe: false } };
const OK = { ok: true, status: 204, data: {} };

let current;
function mountPage({ locale = 'en', width = 1024, ...props } = {}) {
    window.innerWidth = width;
    mockProps = {
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { recipes: 'edit' } },
        app: { env: 'local', styleGuide: false },
    };
    window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    current = mount(Show, {
        props: { recipe: RECIPE, state: STATE, abilities: AUTHOR, units: UNITS, ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, loadMessages(locale))] },
    });

    return current;
}
const $ = (sel) => current.find(sel);
// Rows are named by their visible text now (no aria-label): 'Prepared: Name' picks an ingredient row, 'Done: step N' the Nth step.
const checkbox = (label) => {
    const ing = /^Prepared: (.+)$/.exec(label);
    if (ing) return current.findAll('[data-testid="ingredient-row"]').find((r) => r.find('.ing-row__name').text() === ing[1]).find('input[type="checkbox"]');

    return current.findAll('[data-testid="step-row"]')[Number(/(\d+)$/.exec(label)[1]) - 1].find('input[type="checkbox"]');
};
const quantities = () => current.findAll('[data-testid="ingredient-quantity"]').map((q) => q.text());
const dialog = () => document.body.querySelector('[role="dialog"]');
const dialogButton = (label) => [...dialog().querySelectorAll('button')].find((b) => b.textContent.trim() === label);
const button = (label) => current.findAll('button').find((b) => b.text() === label);
const menuItem = async (label) => {
    await current.find('[data-testid="recipe-detail"] button[aria-haspopup="menu"]').trigger('click');
    await flushPromises();
    [...document.body.querySelectorAll('[role="menu"][aria-label="More actions"] [role="menuitem"]')].find((b) => b.textContent.includes(label)).click();
    await flushPromises();
};
const wait = (ms) => new Promise((r) => setTimeout(r, ms));

beforeEach(() => {
    Object.values(routerMock).forEach((f) => f.mockClear());
    routerMock.on.mockImplementation(() => vi.fn());
    fetchMock.mockReset();
    fetchMock.mockResolvedValue(OK);
    useToast().toasts.splice(0);
    window.history.replaceState(null, '', '/recipes/12');
});
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; });

describe('Recipe detail: content', () => {
    it('shows title, meta, categories, description, lines and the main photo first', () => {
        mountPage();
        expect($('h1').text()).toBe('Tomato soup');
        expect($('[data-testid="recipe-meta"]').text()).toContain('Prep 10 min');
        expect($('[data-testid="recipe-meta"]').text()).toContain('Cook 30 min');
        expect($('[data-testid="recipe-meta"]').text()).toContain('Soup');
        expect($('[data-testid="recipe-description"]').text()).toBe('Warm and simple');
        expect(current.findAll('[data-testid="ingredient-row"]')).toHaveLength(4);
        expect(current.findAll('[data-testid="step-row"]')).toHaveLength(2);
        expect($('[data-testid="gallery-hero"]').attributes('src')).toBe('/recipes/12/photos/32/full?v=1');
        expect($('[data-testid="gallery-hero"]').attributes('alt')).toBe('Tomato soup');
        expect($('[data-testid="gallery-position"]').text()).toBe('1 of 2');
        expect($('[data-testid="visibility-tag"]').text()).toBe('Shared');
    });

    it('links the source by host only and with a safe rel', () => {
        mountPage();
        const link = $('[data-testid="source-link"]');
        expect(link.text()).toBe('Source: www.example.test');
        expect(link.attributes('href')).toBe('https://www.example.test/soup');
        expect(link.attributes('target')).toBe('_blank');
        expect(link.attributes('rel')).toBe('noopener noreferrer nofollow');
    });

    it.each(['javascript:alert(1)', 'ftp://example.test/x', 'data:text/html,x'])('renders no link for a source that is not http(s): %s', (sourceUrl) => {
        mountPage({ recipe: { ...RECIPE, sourceUrl } });
        expect($('[data-testid="source-link"]').exists()).toBe(false);
    });

    it('shows step text as text, never as markup', () => {
        mountPage({ recipe: { ...RECIPE, title: '<img src=x onerror=alert(1)>' } });
        expect(current.findAll('[data-testid="step-row"]')[0].text()).toContain('Boil <b>water</b>');
        expect(current.find('.rec-show b').exists()).toBe(false);
        expect($('h1').text()).toBe('<img src=x onerror=alert(1)>');
        expect(current.find('img[src="x"]').exists()).toBe(false);
    });

    it('shows a placeholder without photos and no gallery controls', () => {
        mountPage({ recipe: { ...RECIPE, photos: [] } });
        expect($('[data-testid="gallery-placeholder"]').exists()).toBe(true);
        expect($('[data-testid="gallery-prev"]').exists()).toBe(false);
    });

    it('renders the unavailable empty state when the recipe is missing', () => {
        mountPage({ recipe: null });
        expect($('[data-testid="recipe-unavailable"]').text()).toContain('This recipe is not available');
        expect($('[data-testid="recipe-unavailable"]').text()).toContain('Back to recipes');
    });
});

describe('Recipe detail: author actions', () => {
    it('shows Edit, Change visibility and Delete to the author only', async () => {
        mountPage();
        expect($('[data-testid="edit-recipe"]').exists()).toBe(true);
        expect($('[data-testid="author-only"]').exists()).toBe(false);
        await current.find('[data-testid="recipe-detail"] button[aria-haspopup="menu"]').trigger('click');
        await flushPromises();
        const items = [...document.body.querySelectorAll('[role="menu"][aria-label="More actions"] [role="menuitem"]')].map((e) => e.textContent.trim());
        expect(items).toEqual(['Change visibility', 'Reset my progress', 'Delete recipe']);
    });

    it('shows a non-author no Edit, Change visibility or Delete, and the read-only line', async () => {
        mountPage({ recipe: OTHER, abilities: READER });
        expect($('[data-testid="edit-recipe"]').exists()).toBe(false);
        expect($('[data-testid="author-only"]').text()).toBe('Only Anna can edit this recipe.');
        expect($('[data-testid="recipe-author"]').text()).toContain('by Anna');
        await current.find('[data-testid="recipe-detail"] button[aria-haspopup="menu"]').trigger('click');
        await flushPromises();
        const items = [...document.body.querySelectorAll('[role="menu"][aria-label="More actions"] [role="menuitem"]')].map((e) => e.textContent.trim());
        expect(items).toEqual(['Reset my progress']);
        expect(current.text()).not.toContain('Delete recipe');
        expect(current.text()).not.toContain('Change visibility');
    });

    it('asks with the exact delete text, then deletes once and ignores a second confirm', async () => {
        mountPage();
        await menuItem('Delete recipe');
        expect(dialog().textContent).toContain('Delete “Tomato soup”?');
        expect(dialog().textContent).toContain("The recipe, its photos and everyone's notes and ticks will be deleted. Shopping lists you already made from it are not changed. This cannot be undone.");
        expect(routerMock.delete).not.toHaveBeenCalled();
        dialogButton('Delete recipe').click();
        await flushPromises();
        expect(routerMock.delete).toHaveBeenCalledTimes(1);
        expect(routerMock.delete.mock.calls[0][0]).toBe('/recipes/12');
    });

    it('guards a double submit of the delete until the request finishes', async () => {
        mountPage();
        await menuItem('Delete recipe');
        dialogButton('Delete recipe').click();
        await flushPromises();
        await menuItem('Delete recipe');
        dialogButton('Delete recipe').click();
        await flushPromises();
        expect(routerMock.delete).toHaveBeenCalledTimes(1);
        routerMock.delete.mock.calls[0][1].onFinish();
        await menuItem('Delete recipe');
        dialogButton('Delete recipe').click();
        await flushPromises();
        expect(routerMock.delete).toHaveBeenCalledTimes(2);
    });

    it('shows the forbidden text on a 403 and keeps the page', async () => {
        mountPage();
        await menuItem('Delete recipe');
        dialogButton('Delete recipe').click();
        await flushPromises();
        expect(routerMock.delete.mock.calls[0][1].onHttpException({ status: 403 })).toBe(false);
        await flushPromises();
        expect(useToast().toasts.map((x) => x.message)).toContain('You are not allowed to do that with this recipe.');
    });

    it('confirms before making a shared recipe private, then sends the visibility', async () => {
        mountPage();
        await menuItem('Change visibility');
        document.body.querySelector('[data-testid="visibility-private"]').click();
        await flushPromises();
        document.body.querySelector('[data-testid="visibility-save"]').click();
        await flushPromises();
        expect(routerMock.put).not.toHaveBeenCalled();
        expect(document.body.querySelectorAll('[role="dialog"]')).toHaveLength(1);
        expect(dialog().textContent).toContain('Make this recipe private?');
        dialogButton('Make private').click();
        await flushPromises();
        expect(routerMock.put.mock.calls[0][0]).toBe('/recipes/12/visibility');
        expect(routerMock.put.mock.calls[0][1]).toEqual({ visibility: 'private' });
    });
});

describe('Recipe detail: per-member ticks', () => {
    it('ticks an ingredient at once, sends the absolute state and updates the progress', async () => {
        mountPage();
        expect($('[data-testid="ingredients-progress"]').text()).toBe('0 of 4 prepared');
        await checkbox('Prepared: Flour').setValue(true);
        expect($('[data-testid="ingredients-progress"]').text()).toBe('1 of 4 prepared');
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledWith('/recipes/12/ingredients/101/tick', { method: 'PUT', body: { ticked: true } });
        expect(checkbox('Prepared: Flour').element.checked).toBe(true);
    });

    it('marks a step done through the step endpoint and keeps the order of the rows', async () => {
        mountPage();
        await checkbox('Done: step 2').setValue(true);
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledWith('/recipes/12/steps/202/done', { method: 'PUT', body: { done: true } });
        expect($('[data-testid="steps-progress"]').text()).toBe('1 of 2 steps done');
        expect(current.findAll('[data-testid="step-row"]').map((r) => r.text())[1]).toContain('Serve');
    });

    it('shows the ticks the server sent and nothing else', () => {
        mountPage({ state: { ...STATE, tickedIngredientIds: [102], doneStepIds: [201] } });
        expect(checkbox('Prepared: Eggs').element.checked).toBe(true);
        expect(checkbox('Prepared: Flour').element.checked).toBe(false);
        expect(checkbox('Done: step 1').element.checked).toBe(true);
    });

    it('rolls back only the failed row and shows the toast; the other tick stays', async () => {
        fetchMock.mockResolvedValueOnce({ ok: false, status: 500, data: {} }).mockResolvedValue(OK);
        mountPage();
        await checkbox('Prepared: Flour').setValue(true);
        await checkbox('Prepared: Eggs').setValue(true);
        await flushPromises();
        expect(checkbox('Prepared: Flour').element.checked).toBe(false);
        expect(checkbox('Prepared: Eggs').element.checked).toBe(true);
        expect(useToast().toasts.map((x) => x.message)).toEqual(['Could not save. Try again.']);
    });

    it('sends several quick ticks one after another and loses none', async () => {
        const sent = [];
        fetchMock.mockImplementation((url) => new Promise((resolve) => { sent.push({ url, resolve }); }));
        mountPage();
        await checkbox('Prepared: Flour').setValue(true);
        await checkbox('Prepared: Eggs').setValue(true);
        await checkbox('Done: step 1').setValue(true);
        expect(sent).toHaveLength(1);
        for (let i = 0; i < 3; i += 1) {
            sent[i].resolve(OK);
            await flushPromises();
        }
        expect(sent.map((s) => s.url)).toEqual(['/recipes/12/ingredients/101/tick', '/recipes/12/ingredients/102/tick', '/recipes/12/steps/201/done']);
        expect($('[data-testid="ingredients-progress"]').text()).toBe('2 of 4 prepared');
        expect($('[data-testid="steps-progress"]').text()).toBe('1 of 2 steps done');
    });

    it('uses the shared failure texts for a refused state write (409 reloads the page)', async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 409, data: {} });
        mountPage();
        await checkbox('Prepared: Flour').setValue(true);
        await flushPromises();
        expect(useToast().toasts.map((x) => x.message)).toEqual(['The recipe changed in the meantime. Reload the page and try again.']);
        expect(routerMock.reload).toHaveBeenCalled();
    });

    it('names each checkbox by its visible text (quantity and name, step text), not by a shorter aria-label', async () => {
        mountPage();
        const flour = checkbox('Prepared: Flour').element;
        const step = checkbox('Done: step 1').element;
        expect(flour.hasAttribute('aria-label')).toBe(false);
        expect(flour.labels[0].textContent.replace(/\s+/g, ' ')).toContain('200 g');
        expect(flour.labels[0].textContent).toContain('Flour');
        expect(step.hasAttribute('aria-label')).toBe(false);
        expect(step.labels[0].textContent).toContain('Boil <b>water</b>');
    });

    it('treats a 404 on one ingredient row as a changed recipe: reload and re-seed, not an unavailable page', async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 404, data: {} });
        mountPage();
        await checkbox('Prepared: Flour').setValue(true);
        await flushPromises();
        expect($('[data-testid="recipe-unavailable"]').exists()).toBe(false);
        expect(useToast().toasts.map((x) => x.message)).toEqual(['The recipe changed in the meantime. Reload the page and try again.']);
        expect(routerMock.reload).toHaveBeenCalledTimes(1);
        // The reload brought fresh state: the tick is shown from it, and the servings follow it.
        await current.setProps({ state: { ...STATE, tickedIngredientIds: [102], servings: 6 } });
        routerMock.reload.mock.calls[0][0].onSuccess();
        await flushPromises();
        expect(checkbox('Prepared: Eggs').element.checked).toBe(true);
        expect(checkbox('Prepared: Flour').element.checked).toBe(false);
        expect($('[data-testid="servings-input"]').element.value).toBe('6');
    });

    it('shows the unavailable state when the recipe itself is gone (favourite or the reload answers 404)', async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 404, data: {} });
        mountPage();
        await $('[data-testid="favourite"]').trigger('click');
        await flushPromises();
        expect($('[data-testid="recipe-unavailable"]').exists()).toBe(true);
    });

    it('shows the unavailable state when the reload after a row 404 finds the recipe gone', async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 404, data: {} });
        mountPage();
        await checkbox('Prepared: Flour').setValue(true);
        await flushPromises();
        expect(routerMock.reload.mock.calls[0][0].onHttpException({ status: 404 })).toBe(false);
        await flushPromises();
        expect($('[data-testid="recipe-unavailable"]').exists()).toBe(true);
    });

    it('toggles the favourite at once and rolls back on failure', async () => {
        let settle;
        fetchMock.mockImplementationOnce(() => new Promise((resolve) => { settle = resolve; }));
        mountPage();
        await $('[data-testid="favourite"]').trigger('click');
        expect($('[data-testid="favourite"]').attributes('aria-pressed')).toBe('true');
        // The name does not change with the state (aria-pressed carries it).
        expect($('[data-testid="favourite"]').attributes('aria-label')).toBe('Favourite');
        settle({ ok: false, status: 500, data: {} });
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledWith('/recipes/12/favourite', { method: 'PUT', body: { favourite: true } });
        expect($('[data-testid="favourite"]').attributes('aria-pressed')).toBe('false');
        expect(useToast().toasts).toHaveLength(1);
    });

    it('resets only my progress after a confirmation and clears the ticks on screen', async () => {
        mountPage({ state: { ...STATE, tickedIngredientIds: [101, 102], doneStepIds: [201] } });
        await menuItem('Reset my progress');
        expect(dialog().textContent).toContain('Reset your progress?');
        expect(dialog().textContent).toContain('Other members are not affected.');
        expect(fetchMock).not.toHaveBeenCalled();
        dialogButton('Reset progress').click();
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledWith('/recipes/12/progress', { method: 'DELETE', body: undefined });
        expect($('[data-testid="ingredients-progress"]').text()).toBe('0 of 4 prepared');
        expect($('[data-testid="steps-progress"]').text()).toBe('0 of 2 steps done');
        expect(useToast().toasts.map((x) => x.message)).toEqual(['Progress reset']);
    });

    it('offers Reset my progress when every step is done', async () => {
        mountPage({ state: { ...STATE, doneStepIds: [201, 202] } });
        expect($('[data-testid="steps-all-done"]').text()).toContain('All steps done. Enjoy your meal.');
        await $('[data-testid="steps-reset"]').trigger('click');
        await flushPromises();
        expect(dialog().textContent).toContain('Reset your progress?');
    });
});

describe('Recipe detail: servings', () => {
    it('shows the quantities as written for the recipe servings and hides Scaled', () => {
        mountPage();
        expect(quantities()).toEqual(['200 g', '2 pcs', '', '1/2 tsp']);
        expect($('[data-testid="servings-scaled"]').exists()).toBe(false);
    });

    it('rescales on screen, shows Scaled and Reset to 4, never touches rows without a quantity, then resets', async () => {
        mountPage();
        await $('[data-testid="servings-plus"]').trigger('click');
        await $('[data-testid="servings-plus"]').trigger('click');
        expect($('[data-testid="servings-input"]').element.value).toBe('6');
        expect(quantities()).toEqual(['300 g', '3 pcs', '', '3/4 tsp']);
        expect($('[data-testid="servings-scaled"]').text()).toBe('Scaled');
        expect(current.text()).toContain('Quantities updated for 6 servings.');
        expect(current.findAll('[data-testid="ingredient-row"]')[2].text()).toContain('to taste');
        await $('[data-testid="servings-reset"]').trigger('click');
        expect(quantities()).toEqual(['200 g', '2 pcs', '', '1/2 tsp']);
        expect($('[data-testid="servings-scaled"]').exists()).toBe(false);
    });

    it('accepts a typed number, clamps to 1..99 and saves debounced (null when equal to the recipe servings)', async () => {
        mountPage();
        const input = $('[data-testid="servings-input"]');
        await input.setValue('8');
        await input.setValue('12');
        expect(quantities()[0]).toBe('600 g');
        expect(fetchMock).not.toHaveBeenCalled();
        await wait(700);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock).toHaveBeenCalledWith('/recipes/12/servings', { method: 'PUT', body: { servings: 12 } });
        await input.setValue('500');
        await input.trigger('blur');
        expect($('[data-testid="servings-input"]').element.value).toBe('99');
        // The pulled-in number is explained on screen, not only changed.
        expect($('[data-testid="servings-clamped"]').text()).toBe('Servings can be from 1 to 99.');
        await $('[data-testid="servings-minus"]').trigger('click');
        expect($('[data-testid="servings-clamped"]').exists()).toBe(false);
        await $('[data-testid="servings-reset"]').trigger('click');
        await wait(700);
        expect(fetchMock).toHaveBeenLastCalledWith('/recipes/12/servings', { method: 'PUT', body: { servings: null } });
    });

    it('says "1 porciu" and "2 porcie" in Slovak (plural forms, not a fixed noun)', async () => {
        mountPage({ locale: 'sk' });
        const input = $('[data-testid="servings-input"]');
        await input.setValue('1');
        expect(current.text()).toContain('Množstvá upravené pre 1 porciu.');
        await input.setValue('2');
        expect(current.text()).toContain('Množstvá upravené pre 2 porcie.');
        await input.setValue('5');
        expect(current.text()).toContain('Množstvá upravené pre 5 porcií.');
    });

    it('keeps a recipe with more than 99 servings reachable with the stepper', async () => {
        mountPage({ recipe: { ...RECIPE, servings: 120 }, state: { ...STATE, servings: null } });
        expect($('[data-testid="servings-input"]').element.value).toBe('120');
        expect($('[data-testid="servings-plus"]').element.disabled).toBe(true);
        await $('[data-testid="servings-minus"]').trigger('click');
        await $('[data-testid="servings-plus"]').trigger('click');
        expect($('[data-testid="servings-input"]').element.value).toBe('120');
        expect($('[data-testid="servings-scaled"]').exists()).toBe(false);
    });

    it('starts from the servings the member chose earlier', () => {
        mountPage({ state: { ...STATE, servings: 8 } });
        expect($('[data-testid="servings-input"]').element.value).toBe('8');
        expect(quantities()[0]).toBe('400 g');
        expect($('[data-testid="servings-scaled"]').exists()).toBe(true);
    });

    it('hides the control and never scales when the recipe has no servings', () => {
        mountPage({ recipe: { ...RECIPE, servings: null } });
        expect($('[data-testid="servings-control"]').exists()).toBe(false);
        expect(quantities()).toEqual(['200 g', '2 pcs', '', '1/2 tsp']);
    });

    it('uses the decimal comma and Slovak unit labels', () => {
        mountPage({ locale: 'sk', state: { ...STATE, servings: 6 }, recipe: { ...RECIPE, ingredients: [{ id: 102, name: 'Vajcia', quantity: '1', unitId: 1, note: null }], servings: 4 } });
        expect(quantities()).toEqual(['1,5 ks']);
    });
});

describe('Recipe detail: notes', () => {
    it('autosaves 800 ms after typing stops with Saving and Saved', async () => {
        mountPage({ width: 400 });
        await current.findAll('[role="tab"]').find((b) => b.text() === 'Notes').trigger('click');
        await $('[data-testid="notes-input"]').setValue('Less salt');
        expect(fetchMock).not.toHaveBeenCalled();
        await wait(850);
        expect(fetchMock).toHaveBeenCalledWith('/recipes/12/note', { method: 'PUT', body: { note: 'Less salt' } });
        await flushPromises();
        expect($('[data-testid="notes-status"]').text()).toBe('Saved');
    });

    it('shows the failure with Try again and saves again on request', async () => {
        fetchMock.mockResolvedValueOnce({ ok: false, status: 500, data: {} });
        mountPage({ width: 400 });
        await current.findAll('[role="tab"]').find((b) => b.text() === 'Notes').trigger('click');
        await $('[data-testid="notes-input"]').setValue('x');
        await wait(850);
        await flushPromises();
        expect($('[data-testid="notes-status"]').text()).toContain('Could not save your notes');
        await $('[data-testid="notes-retry"]').trigger('click');
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect($('[data-testid="notes-status"]').text()).toBe('Saved');
    });

    it('sends null for an empty note and shows the saved note', async () => {
        mountPage({ width: 400, state: { ...STATE, note: 'Mine' } });
        await current.findAll('[role="tab"]').find((b) => b.text() === 'Notes').trigger('click');
        expect($('[data-testid="notes-input"]').element.value).toBe('Mine');
        await $('[data-testid="notes-input"]').setValue('');
        await wait(850);
        expect(fetchMock).toHaveBeenCalledWith('/recipes/12/note', { method: 'PUT', body: { note: null } });
    });
});

describe('Recipe detail: layout', () => {
    it('keeps the chosen tab in the address on a narrow page', async () => {
        mountPage({ width: 400 });
        const tabs = current.findAll('[role="tab"]');
        expect(tabs.map((b) => b.text())).toEqual(['Ingredients', 'Steps', 'Notes']);
        await tabs[1].trigger('click');
        expect(window.location.search).toBe('?tab=steps');
        expect(tabs[1].attributes('aria-selected')).toBe('true');
    });

    it('opens the tab named in the address', () => {
        window.history.replaceState(null, '', '/recipes/12?tab=notes');
        mountPage({ width: 400 });
        expect(current.findAll('[role="tab"]')[2].attributes('aria-selected')).toBe('true');
    });

    it('moves between photos with the buttons and opens the full-screen viewer', async () => {
        mountPage();
        await $('[data-testid="gallery-next"]').trigger('click');
        expect($('[data-testid="gallery-position"]').text()).toBe('2 of 2');
        expect($('[data-testid="gallery-hero"]').attributes('src')).toBe('/recipes/12/photos/31/full?v=1');
        await $('[data-testid="gallery-open"]').trigger('click');
        await flushPromises();
        expect(dialog().querySelector('img').getAttribute('alt')).toBe('Tomato soup');
    });

    it('swipes between photos', async () => {
        mountPage();
        const hero = $('.gallery__hero');
        await hero.trigger('touchstart', { changedTouches: [{ clientX: 200 }] });
        await hero.trigger('touchend', { changedTouches: [{ clientX: 100 }] });
        expect($('[data-testid="gallery-position"]').text()).toBe('2 of 2');
    });
});

describe('Recipe detail: accessibility', () => {
    it.each(['en', 'sk', 'pl'])('has no axe violations in %s', async (locale) => {
        mountPage({ locale, width: 400, state: { ...STATE, doneStepIds: [201, 202] } });
        await expectNoAxeViolations(current.element);
    });
    it('has no axe violations on a wide page for the author', async () => {
        mountPage();
        await expectNoAxeViolations(current.element);
    });
});

describe('Recipe detail: add to shopping list', () => {
    it('shows the button in the header on a wide page and opens the review dialog, which asks for the preview', async () => {
        mountPage();
        await flushPromises();
        expect($('[data-testid="add-to-shopping"]').text()).toBe('Add to shopping list');
        expect($('[data-testid="add-to-shopping-bar"]').exists()).toBe(false);
        await $('[data-testid="add-to-shopping"]').trigger('click');
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledWith('/recipes/12/shopping');
        expect(dialog().querySelector('[data-testid="shopping-dialog"]')).not.toBeNull();
    });

    it('shows the button in the bottom bar on a phone, not in the header, and not while cooking', async () => {
        mountPage({ width: 400 });
        await flushPromises();
        expect($('[data-testid="add-to-shopping-bar"]').exists()).toBe(true);
        expect($('[data-testid="add-to-shopping"]').exists()).toBe(false);
        await $('[data-testid="cooking-toggle-bar"]').trigger('click');
        expect($('[data-testid="add-to-shopping-bar"]').exists()).toBe(false);
    });

    it('hides the button and the dialog from a member who may not add to Shopping', async () => {
        mountPage({ abilities: { ...READER, addToShopping: false } });
        await flushPromises();
        expect($('[data-testid="add-to-shopping"]').exists()).toBe(false);
        mountPage({ width: 400, abilities: { ...READER, addToShopping: false } });
        await flushPromises();
        expect($('[data-testid="add-to-shopping-bar"]').exists()).toBe(false);
    });

    it('shows the button to a reader who may add to Shopping', async () => {
        mountPage({ recipe: OTHER, abilities: READER });
        expect($('[data-testid="add-to-shopping"]').exists()).toBe(true);
    });

    it('leaves the page when the preview says the recipe is gone', async () => {
        mountPage();
        fetchMock.mockResolvedValue({ ok: false, status: 404, error: 'failed', data: {} });
        await $('[data-testid="add-to-shopping"]').trigger('click');
        await flushPromises();
        expect($('[data-testid="recipe-unavailable"]').exists()).toBe(true);
    });
});
