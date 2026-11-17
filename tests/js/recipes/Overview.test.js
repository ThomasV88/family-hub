import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

let mockProps = {};
const { routerMock, fetchMock } = vi.hoisted(() => ({
    routerMock: { get: vi.fn(), post: vi.fn(), put: vi.fn(), visit: vi.fn(), replace: vi.fn(), reload: vi.fn(), on: vi.fn() },
    fetchMock: vi.fn(),
}));
vi.mock('../../../resources/js/Security/securityFetch.js', () => ({ securityFetch: fetchMock }));
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

beforeEach(() => { vi.useRealTimers(); Object.values(routerMock).forEach((f) => f.mockClear()); fetchMock.mockReset(); useToast().toasts.splice(0); });
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
        // A stable name (with the recipe) and the state in aria-pressed only: the label never flips with the state.
        expect(fav.attributes('aria-label')).toBe('Favourite: <img src=x onerror=alert(1)>');
        expect(cards()[0].find('[data-testid="recipe-favourite"]').attributes('aria-label')).toBe('Favourite: Recipe 1');
    });
});

describe('Favourite toggle', () => {
    beforeEach(() => { fetchMock.mockReset(); });

    it('flips at once and sends a plain PUT (not an Inertia visit, which the next visit would cancel)', async () => {
        fetchMock.mockResolvedValue({ ok: true, status: 204, data: {} });
        mountPage();
        const fav = cards()[0].find('[data-testid="recipe-favourite"]');
        await fav.trigger('click');
        expect(fav.attributes('aria-pressed')).toBe('true');
        expect(fetchMock).toHaveBeenCalledWith('/recipes/1/favourite', { method: 'PUT', body: { favourite: true } });
        expect(routerMock.put).not.toHaveBeenCalled();
    });

    it('survives a filter visit started while it is in flight and does not cancel it', async () => {
        let settle;
        fetchMock.mockImplementation(() => new Promise((resolve) => { settle = resolve; }));
        mountPage();
        const fav = cards()[0].find('[data-testid="recipe-favourite"]');
        await fav.trigger('click');
        await current.find('[data-testid="favourites-toggle"]').trigger('click');
        expect(routerMock.get).toHaveBeenCalledTimes(1);
        settle({ ok: true, status: 204, data: {} });
        await flushPromises();
        expect(fav.attributes('aria-pressed')).toBe('true');
        expect(useToast().toasts).toHaveLength(0);
    });

    it.each([{ ok: false, status: 500, data: {} }, { ok: false, status: 0, data: {} }, 'throws'])('rolls back and shows the toast on %j', async (answer) => {
        if (answer === 'throws') fetchMock.mockRejectedValue(new Error('offline'));
        else fetchMock.mockResolvedValue(answer);
        mountPage();
        const fav = cards()[0].find('[data-testid="recipe-favourite"]');
        await fav.trigger('click');
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
    it('asks for the next page; the answer holds every loaded card (page N = pages 1..N) and the new ones are announced', async () => {
        mountPage({ hasMore: true });
        await current.find('[data-testid="load-more"]').trigger('click');
        expect(routerMock.get).toHaveBeenCalledWith('/recipes', { page: 2 }, expect.objectContaining({ only: ['recipes', 'hasMore', 'page'], preserveState: true }));
        await current.setProps({ recipes: [...RECIPES, recipe(10), recipe(11)], page: 2, hasMore: false });
        expect(cards()).toHaveLength(5);
        expect(current.find('[data-testid="recipes-announce"]').text()).toBe('2 more recipes loaded');
        expect(current.find('[data-testid="load-more"]').exists()).toBe(false);
    });

    it('shows the whole list when the page is restored at ?page=2 (Back, reload) and does not announce', async () => {
        mountPage({ hasMore: false, page: 2, recipes: [...RECIPES, recipe(10), recipe(11)] });
        expect(cards()).toHaveLength(5);
        expect(current.find('[data-testid="recipes-announce"]').text()).toBe('');
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

describe('New recipe and import drafts', () => {
    const IMPORT = { available: true, acknowledged: true, providers: ['codex'] };
    const D1 = '44444444-4444-4444-8444-444444444441';
    const D2 = '44444444-4444-4444-8444-444444444442';
    const D3 = '44444444-4444-4444-8444-444444444443';
    const body = (sel) => document.body.querySelector(sel);
    const newButton = () => current.find('.rec-index__head a, .rec-index__head button');

    it('opens the choice dialog only when reading pages is available', async () => {
        mountPage({ import: IMPORT });
        await newButton().trigger('click');
        await flushPromises();
        expect(routerMock.visit).not.toHaveBeenCalled();
        expect(body('[data-testid="new-from-page"]')).not.toBeNull();
        expect(body('[data-testid="new-type-it-in"]')).not.toBeNull();
    });

    it('goes straight to the form when no AI agent is enabled', async () => {
        mountPage({ import: { available: false, acknowledged: false, providers: [] } });
        await newButton().trigger('click');
        await flushPromises();
        expect(routerMock.visit).toHaveBeenCalledWith('/recipes/create');
        expect(body('[data-testid="new-from-page"]')).toBeNull();
    });

    it('goes straight to the form when the overview has no import prop at all', async () => {
        mountPage();
        await newButton().trigger('click');
        expect(routerMock.visit).toHaveBeenCalledWith('/recipes/create');
    });

    it.each([{ metaKey: true }, { ctrlKey: true }, { shiftKey: true }, { altKey: true }, { button: 1 }])('leaves a modified click %j to the browser (new tab)', async (mods) => {
        mountPage({ import: IMPORT });
        const event = new MouseEvent('click', { bubbles: true, cancelable: true, ...mods });
        newButton().element.dispatchEvent(event);
        await flushPromises();
        expect(event.defaultPrevented).toBe(false);
        expect(body('[data-testid="new-from-page"]')).toBeNull();
        expect(routerMock.visit).not.toHaveBeenCalled();
    });

    it('the empty state button opens the choice dialog too', async () => {
        mountPage({ import: IMPORT, recipes: [] });
        await current.find('.ui-empty a, .ui-empty button').trigger('click');
        await flushPromises();
        expect(body('[data-testid="new-from-page"]')).not.toBeNull();
    });

    it('Read from a web page continues in the URL step', async () => {
        mountPage({ import: IMPORT });
        await newButton().trigger('click');
        await flushPromises();
        body('[data-testid="new-from-page"] button').click();
        await flushPromises();
        expect(body('[data-testid="import-url"]')).not.toBeNull();
        expect(body('[data-testid="new-from-page"]')).toBeNull();
    });

    it('renders no draft rows without drafts, and only for members who can create', () => {
        mountPage({ import: IMPORT, drafts: [] });
        expect(current.find('[data-testid="import-drafts"]').exists()).toBe(false);
        current.unmount();
        mountPage({ import: IMPORT, canCreate: false, drafts: [{ id: D1, status: 'ready', host: 'x.example', reason: null }] });
        expect(current.find('[data-testid="import-drafts"]').exists()).toBe(false);
    });

    it('a ready draft says which site, links Review to the prefilled form and renders the site as text', () => {
        mountPage({ import: IMPORT, drafts: [{ id: D1, status: 'ready', host: '<b>recepty.example</b>', reason: null }] });
        const row = current.find('[data-testid="draft-ready"]');
        expect(row.text()).toContain('Your recipe from <b>recepty.example</b> is ready to review.');
        expect(row.find('b').exists()).toBe(false);
        expect(row.find('[data-testid="draft-review"]').attributes('href')).toBe(`/recipes/create?import=${D1}`);
    });

    it('Discard asks first, then sends DELETE once and reloads the drafts', async () => {
        fetchMock.mockResolvedValue({ ok: true, status: 204, data: {} });
        mountPage({ import: IMPORT, drafts: [{ id: D1, status: 'ready', host: 'recepty.example', reason: null }] });
        await current.find('[data-testid="draft-discard"]').trigger('click');
        await flushPromises();
        expect(fetchMock).not.toHaveBeenCalled();
        expect(body('.ui-dialog__title').textContent).toBe('Discard this draft?');
        const confirm = [...document.body.querySelectorAll('.ui-dialog button')].find((b) => b.textContent.trim() === 'Discard draft');
        confirm.click();
        confirm.click();
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock).toHaveBeenCalledWith(`/recipes/imports/${D1}`, { method: 'DELETE' });
        expect(routerMock.reload).toHaveBeenCalledWith(expect.objectContaining({ only: ['drafts', 'import'] }));
    });

    it('Keep draft sends nothing', async () => {
        mountPage({ import: IMPORT, drafts: [{ id: D1, status: 'ready', host: 'recepty.example', reason: null }] });
        await current.find('[data-testid="draft-discard"]').trigger('click');
        await flushPromises();
        [...document.body.querySelectorAll('.ui-dialog button')].find((b) => b.textContent.trim() === 'Keep draft').click();
        await flushPromises();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it.each([[403, 'You are not allowed'], [419, 'Your session expired'], [429, 'Too many attempts'], [500, 'could not discard']])('a failed discard (HTTP %s) says so', async (status, expected) => {
        fetchMock.mockResolvedValue({ ok: false, status, error: 'failed', data: {} });
        mountPage({ import: IMPORT, drafts: [{ id: D1, status: 'ready', host: 'recepty.example', reason: null }] });
        await current.find('[data-testid="draft-discard"]').trigger('click');
        await flushPromises();
        [...document.body.querySelectorAll('.ui-dialog button')].find((b) => b.textContent.trim() === 'Discard draft').click();
        await flushPromises();
        expect(useToast().toasts.map((t) => t.message).join(' ')).toContain(expected);
        expect(routerMock.reload).not.toHaveBeenCalled();
    });

    it('a draft that is already gone (404) is simply reloaded away', async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 404, error: 'failed', data: {} });
        mountPage({ import: IMPORT, drafts: [{ id: D1, status: 'failed', host: 'recepty.example', reason: 'fetch' }] });
        await current.find('[data-testid="draft-dismiss"]').trigger('click');
        await flushPromises();
        expect(routerMock.reload).toHaveBeenCalled();
        expect(useToast().toasts).toHaveLength(0);
    });

    it('a failed draft offers Try again (the URL step) and Type it in', async () => {
        mountPage({ import: IMPORT, drafts: [{ id: D2, status: 'failed', host: 'recepty.example', reason: 'no_recipe' }] });
        const row = current.find('[data-testid="draft-failed"]');
        expect(row.text()).toContain('Reading recepty.example did not work.');
        expect(row.find('[data-testid="draft-type"]').attributes('href')).toBe('/recipes/create');
        await row.find('[data-testid="draft-retry"]').trigger('click');
        await flushPromises();
        expect(body('[data-testid="import-url"]')).not.toBeNull();
    });

    describe('active drafts', () => {
        beforeEach(() => { vi.useFakeTimers(); });

        it('are polled every 2 s, ask the overview for fresh drafts when they end, and stop polling after that', async () => {
            fetchMock.mockResolvedValueOnce({ ok: true, status: 200, data: { id: D3, status: 'running', reason: null, host: 'recepty.example', textCut: false } })
                .mockResolvedValueOnce({ ok: true, status: 200, data: { id: D3, status: 'ready', reason: null, host: 'recepty.example', textCut: false } });
            mountPage({ import: IMPORT, drafts: [{ id: D3, status: 'running', host: 'recepty.example', reason: null }] });
            expect(current.find('[data-testid="draft-active"]').text()).toContain('Reading recepty.example...');
            await vi.advanceTimersByTimeAsync(2000);
            expect(fetchMock).toHaveBeenCalledWith(`/recipes/imports/${D3}`);
            expect(routerMock.reload).not.toHaveBeenCalled();
            await vi.advanceTimersByTimeAsync(2000);
            expect(routerMock.reload).toHaveBeenCalledTimes(1);
            const calls = fetchMock.mock.calls.length;
            await vi.advanceTimersByTimeAsync(20000);
            expect(fetchMock.mock.calls.length).toBe(calls);
        });

        it('offer Cancel on a reading draft, which sends the discard request and asks for fresh drafts', async () => {
            fetchMock.mockResolvedValue({ ok: true, status: 204, data: {} });
            mountPage({ import: IMPORT, drafts: [{ id: D3, status: 'running', host: 'recepty.example', reason: null }] });
            await current.find('[data-testid="draft-cancel"]').trigger('click');
            await vi.advanceTimersByTimeAsync(0);
            expect(fetchMock).toHaveBeenCalledWith(`/recipes/imports/${D3}`, { method: 'DELETE' });
            expect(routerMock.reload).toHaveBeenCalledTimes(1);
        });

        it('poll again after the give-up when the reloaded drafts still say reading', async () => {
            fetchMock.mockResolvedValue({ ok: true, status: 200, data: { id: D3, status: 'running', reason: null, host: 'recepty.example', textCut: false } });
            const draft = () => ({ id: D3, status: 'running', host: 'recepty.example', reason: null });
            mountPage({ import: IMPORT, drafts: [draft()] });
            await vi.advanceTimersByTimeAsync(3 * 60 * 1000 + 6000);
            expect(routerMock.reload).toHaveBeenCalledTimes(1);
            const stopped = fetchMock.mock.calls.length;
            await vi.advanceTimersByTimeAsync(10000);
            expect(fetchMock.mock.calls.length).toBe(stopped);
            // The overview's reload brings a fresh copy that is still "running": the row polls again.
            await current.setProps({ drafts: [draft()] });
            await vi.advanceTimersByTimeAsync(2000);
            expect(fetchMock.mock.calls.length).toBeGreaterThan(stopped);
        });

        it('stop polling when the page unmounts', async () => {
            fetchMock.mockResolvedValue({ ok: true, status: 200, data: { id: D3, status: 'running', reason: null, host: 'recepty.example', textCut: false } });
            mountPage({ import: IMPORT, drafts: [{ id: D3, status: 'running', host: 'recepty.example', reason: null }] });
            await vi.advanceTimersByTimeAsync(2000);
            const calls = fetchMock.mock.calls.length;
            current.unmount();
            current = null;
            await vi.advanceTimersByTimeAsync(20000);
            expect(fetchMock.mock.calls.length).toBe(calls);
        });

        it('announce a draft that became ready in a live region', async () => {
            mountPage({ import: IMPORT, drafts: [{ id: D3, status: 'running', host: 'recepty.example', reason: null }] });
            await current.setProps({ drafts: [{ id: D3, status: 'ready', host: 'recepty.example', reason: null }] });
            await vi.advanceTimersByTimeAsync(0);
            expect(current.find('[data-testid="drafts-announce"]').text()).toBe('Your recipe from recepty.example is ready to review.');
            expect(current.find('[data-testid="drafts-announce"]').attributes('aria-live')).toBe('polite');
        });
    });

    it('has no axe violations with drafts of every kind and the choice dialog open', async () => {
        mountPage({
            import: IMPORT,
            drafts: [{ id: D1, status: 'ready', host: 'a.example', reason: null }, { id: D2, status: 'failed', host: 'b.example', reason: 'fetch' }, { id: D3, status: 'running', host: 'c.example', reason: null }],
        });
        await expectNoAxeViolations(current.element);
    });
});
