import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

let mockProps = {};
const { routerMock } = vi.hoisted(() => ({
    routerMock: { get: vi.fn(), post: vi.fn(), put: vi.fn(), visit: vi.fn(), replace: vi.fn(), reload: vi.fn(), on: vi.fn() },
}));
vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue');
    return {
        usePage: () => ({ props: mockProps, url: '/recipes' }),
        router: routerMock,
        Head: { render: () => null },
        Link: { props: ['href'], render() { return h('a', { href: this.href }, this.$slots.default?.()); } },
    };
});

import Index from '../../../resources/js/Pages/Recipes/Index.vue';
import { useToast } from '../../../resources/js/Components/ui/useToast.js';

const ME = 2;
const recipe = (id, extra = {}) => ({
    id, title: `Recipe ${id}`, mainPhoto: null, prepMinutes: 10, cookMinutes: 20, servings: 4, visibility: 'shared', categories: ['Soup', 'Vegetarian'],
    author: { id: ME, name: 'Mira Nováková', isMe: true }, favourite: false, ...extra,
});
const RECIPES = [
    recipe(1, { mainPhoto: { id: 7, version: 3 } }),
    recipe(2, { author: { id: 5, name: 'Anna Kováčová', isMe: false }, visibility: 'private', categories: ['Dessert'], prepMinutes: null, cookMinutes: null, servings: null }),
    recipe(3, { favourite: true, title: '<img src=x onerror=alert(1)>', categories: [] }),
];
const OPTIONS = { categories: [{ name: 'Soup', key: 'soup' }, { name: 'Dessert', key: 'dessert' }], authors: [{ id: 5, name: 'Anna Kováčová' }, { id: ME, name: 'Mira Nováková' }] };

let current;
function mountPage({ locale = 'en', messages = null, ...props } = {}) {
    window.innerWidth = 1024;
    mockProps = {
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: ME, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { recipes: 'edit' } },
        app: { env: 'local', styleGuide: false },
    };
    window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    current = mount(Index, {
        props: { recipes: RECIPES, hasMore: false, page: 1, filters: {}, filterOptions: OPTIONS, canCreate: true, ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, messages ?? loadMessages(locale))] },
    });
    return current;
}
const cards = () => current.findAll('[data-testid="recipe-card"]');
const btn = (name) => current.findAll('button').find((b) => b.text() === name);

beforeEach(() => { vi.useRealTimers(); Object.values(routerMock).forEach((f) => f.mockClear()); useToast().toasts.splice(0); });
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; vi.useRealTimers(); });

describe('Recipes overview cards', () => {
    it('renders a card per recipe, each one link to the detail', () => {
        mountPage();
        expect(current.find('h1').text()).toBe('Recipes');
        expect(cards()).toHaveLength(3);
        expect(cards()[0].find('a').attributes('href')).toBe('/recipes/1');
        expect(cards()[0].text()).toContain('Recipe 1');
        expect(cards()[0].text()).toContain('30 min');
        expect(cards()[0].text()).toContain('4 servings');
        expect(cards()[0].text()).toContain('Shared');
        expect(cards()[0].text()).toContain('Soup');
        expect(cards()[0].text()).toContain('+1');
    });

    it('shows the thumbnail with an empty alt and lazy loading, or the chef-hat placeholder', () => {
        mountPage();
        const img = cards()[0].find('img');
        expect(img.attributes('src')).toBe('/recipes/1/photos/7/thumb?v=3');
        expect(img.attributes('alt')).toBe('');
        expect(img.attributes('loading')).toBe('lazy');
        expect(cards()[1].find('img').exists()).toBe(false);
        expect(cards()[1].find('[data-testid="recipe-placeholder"]').exists()).toBe(true);
    });

    it("shows the author of another member's recipe only, and omits unset parts of the meta line", () => {
        mountPage();
        expect(cards()[1].find('[data-testid="recipe-author"]').text()).toContain('by Anna');
        expect(cards()[1].text()).toContain('Private');
        expect(cards()[1].text()).not.toContain('min');
        expect(cards()[1].text()).not.toContain('servings');
        expect(cards()[0].find('[data-testid="recipe-author"]').exists()).toBe(false);
    });

    it('renders markup in a title as text, never as HTML', () => {
        mountPage();
        expect(cards()[2].find('.recipe-card__title').text()).toBe('<img src=x onerror=alert(1)>');
        expect(cards()[2].find('.recipe-card__title img').exists()).toBe(false);
        expect(document.body.querySelector('img[onerror]')).toBeNull();
    });

    it('puts the favourite button after the link with the right name and pressed state', () => {
        mountPage();
        const card = cards()[2];
        const kids = [...card.element.children].map((c) => c.tagName);
        expect(kids).toEqual(['A', 'BUTTON']);
        const fav = card.find('[data-testid="recipe-favourite"]');
        expect(fav.attributes('aria-pressed')).toBe('true');
        expect(fav.attributes('aria-label')).toBe('Remove from favourites');
        expect(cards()[0].find('[data-testid="recipe-favourite"]').attributes('aria-label')).toBe('Add to favourites');
    });
});

describe('Favourite toggle', () => {
    it('flips at once and sends PUT with the new value', async () => {
        mountPage();
        const fav = cards()[0].find('[data-testid="recipe-favourite"]');
        await fav.trigger('click');
        expect(fav.attributes('aria-pressed')).toBe('true');
        expect(routerMock.put).toHaveBeenCalledWith('/recipes/1/favourite', { favourite: true }, expect.objectContaining({ preserveState: true }));
    });

    it.each(['onError', 'onHttpException', 'onNetworkError'])('rolls back and shows the toast on %s', async (cb) => {
        mountPage();
        const fav = cards()[0].find('[data-testid="recipe-favourite"]');
        await fav.trigger('click');
        routerMock.put.mock.calls[0][2][cb]({});
        routerMock.put.mock.calls[0][2].onFinish();
        await flushPromises();
        expect(fav.attributes('aria-pressed')).toBe('false');
        expect(useToast().toasts.map((x) => x.message)).toEqual(['Could not save. Try again.']);
    });
});

describe('Search, filters and chips', () => {
    it('searches the server once, 300 ms after the last keystroke', async () => {
        vi.useFakeTimers();
        mountPage();
        const input = current.find('input[type="search"]');
        await input.setValue('so');
        await input.setValue('soup');
        vi.advanceTimersByTime(299);
        expect(routerMock.get).not.toHaveBeenCalled();
        vi.advanceTimersByTime(2);
        expect(routerMock.get).toHaveBeenCalledTimes(1);
        expect(routerMock.get).toHaveBeenCalledWith('/recipes', { q: 'soup' }, expect.objectContaining({ preserveState: true, replace: true }));
    });

    it('shows a removable chip per active filter; removing one asks the server without it', async () => {
        mountPage({ filters: { categories: ['Soup'], author: 5, show: 'mine' } });
        const chips = current.findAll('[data-testid="filter-chip"]');
        expect(chips.map((c) => c.text())).toEqual(['Category: Soup', 'Author: Anna Kováčová', 'Mine']);
        await chips[1].trigger('click');
        expect(routerMock.get).toHaveBeenLastCalledWith('/recipes', { categories: ['Soup'], show: 'mine' }, expect.anything());
    });

    it('Clear filters removes every filter and the search', async () => {
        mountPage({ filters: { q: 'egg', categories: ['Soup'], favourites: true } });
        await current.find('[data-testid="clear-filters"]').trigger('click');
        expect(routerMock.get).toHaveBeenLastCalledWith('/recipes', {}, expect.anything());
        expect(current.find('input[type="search"]').element.value).toBe('');
    });

    it('the favourites toggle mirrors the favourites filter and is always visible', async () => {
        mountPage();
        const toggle = current.find('[data-testid="favourites-toggle"]');
        expect(toggle.attributes('aria-pressed')).toBe('false');
        await toggle.trigger('click');
        expect(routerMock.get).toHaveBeenLastCalledWith('/recipes', { favourites: 1 }, expect.anything());
        expect(toggle.attributes('aria-pressed')).toBe('true');
    });

    it('counts the active filters on the Filters button and opens them in a dialog', async () => {
        mountPage({ filters: { categories: ['Soup', 'Dessert'], show: 'shared' } });
        const button = current.find('[data-testid="filters-button"]');
        expect(button.text()).toBe('Filters (3 active)');
        await button.trigger('click');
        expect(document.body.querySelector('[role="dialog"]')).not.toBeNull();
    });

    it('applies a category chosen in the panel at once', async () => {
        mountPage();
        const chip = current.find('[data-testid="inline-filters"]').findAll('button').find((b) => b.text() === 'Dessert');
        await chip.trigger('click');
        expect(routerMock.get).toHaveBeenLastCalledWith('/recipes', { categories: ['Dessert'] }, expect.anything());
    });

    it('shows the loading skeletons while the request runs and the error state with Try again when it fails', async () => {
        mountPage();
        await current.find('[data-testid="favourites-toggle"]').trigger('click');
        expect(current.find('[data-testid="recipes-loading"]').exists()).toBe(true);
        const opts = routerMock.get.mock.calls.at(-1)[2];
        expect(opts.onHttpException({ status: 500 })).toBe(false);
        opts.onFinish();
        await flushPromises();
        expect(current.text()).toContain('We could not load the recipes.');
        await btn('Try again').trigger('click');
        expect(routerMock.get).toHaveBeenCalledTimes(2);
    });
});

describe('Load more', () => {
    it('asks for the next page, keeps the shown cards, appends the new ones and announces them', async () => {
        mountPage({ hasMore: true });
        await current.find('[data-testid="load-more"]').trigger('click');
        expect(routerMock.get).toHaveBeenCalledWith('/recipes', { page: 2 }, expect.objectContaining({ only: ['recipes', 'hasMore', 'page'], preserveState: true }));
        await current.setProps({ recipes: [recipe(10), recipe(11)], page: 2, hasMore: false });
        expect(cards()).toHaveLength(5);
        expect(current.find('[data-testid="recipes-announce"]').text()).toBe('2 more recipes loaded');
        expect(current.find('[data-testid="load-more"]').exists()).toBe(false);
    });

    it('is not shown when there is nothing more', () => {
        mountPage();
        expect(current.find('[data-testid="load-more"]').exists()).toBe(false);
    });
});

describe('Empty states', () => {
    it('no recipes: invites to add the first one when allowed', () => {
        mountPage({ recipes: [], canCreate: true });
        expect(current.text()).toContain('No recipes yet');
        expect(current.findAll('a').filter((a) => a.attributes('href') === '/recipes/create').length).toBe(2);
        current.unmount();
        mountPage({ recipes: [], canCreate: false });
        expect(current.findAll('a').some((a) => a.attributes('href') === '/recipes/create')).toBe(false);
    });

    it('no match: offers Clear filters', () => {
        mountPage({ recipes: [], filters: { q: 'zzz' } });
        expect(current.text()).toContain('No recipe matches');
        expect(btn('Clear filters')).toBeTruthy();
    });

    it('no favourites', () => {
        mountPage({ recipes: [], filters: { favourites: true } });
        expect(current.text()).toContain('No favourites yet');
    });
});

describe('Grid CSS', () => {
    const src = readFileSync('resources/js/Pages/Recipes/Index.vue', 'utf8');

    it('uses 1, 2 or 3 columns by container width and never more than 3', () => {
        const counts = [...src.matchAll(/repeat\((\d+), minmax\(0, 1fr\)\)/g)].map((m) => Number(m[1]));
        expect(counts).toEqual([2, 3]);
        expect(Math.max(...counts)).toBe(3);
        expect(src).not.toMatch(/auto-fill|auto-fit/);
        expect(src).toContain('container-type: inline-size');
        expect(src).toContain('@container rec (min-width: 480px)');
        expect(src).toContain('@container rec (min-width: 840px)');
    });
});

describe('Overview accessibility', () => {
    it.each(['en', 'sk', 'pl'])('has no axe violations (%s)', async (locale) => {
        mountPage({ locale });
        await expectNoAxeViolations(current.element);
    });
});
