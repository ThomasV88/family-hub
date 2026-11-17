import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

let mockProps = {};
const { routerMock, fetchMock } = vi.hoisted(() => ({
    routerMock: { get: vi.fn(), put: vi.fn(), delete: vi.fn(), visit: vi.fn(), reload: vi.fn(), on: vi.fn(() => vi.fn()) },
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

const RECIPE = {
    id: 12, title: 'Tomato soup', description: 'Warm and simple', servings: 4, prepMinutes: 10, cookMinutes: 30, visibility: 'private',
    sourceUrl: null, sourceHost: null, createdVia: 'manual', author: { id: 2, name: 'Mira', isMe: true }, categories: [],
    ingredients: [{ id: 101, name: 'Flour', quantity: '200', unitId: 2, note: null }, { id: 102, name: 'Eggs', quantity: '2', unitId: 1, note: null }],
    steps: [{ id: 201, body: 'Boil' }, { id: 202, body: 'Serve' }, { id: 203, body: 'Eat' }],
    photos: [
        { id: 31, position: 1, isMain: true, version: 1, thumbUrl: '/t/31', fullUrl: '/f/31' },
        { id: 32, position: 2, isMain: false, version: 1, thumbUrl: '/t/32', fullUrl: '/f/32' },
    ],
};
const STATE = { favourite: false, note: null, servings: null, tickedIngredientIds: [101], doneStepIds: [201, 202] };
const UNITS = [{ id: 1, key: 'pcs', names: null }, { id: 2, key: 'g', names: null }];

let current;
function mountPage({ locale = 'en', width = 400, ...props } = {}) {
    window.innerWidth = width;
    mockProps = {
        locale, flash: { status: null, toast: null, data: null }, availableLocales: ['sk', 'pl', 'en'],
        auth: { user: { id: 2, name: 'Mira', email: 'm@example.test', isAdmin: false }, access: { recipes: 'edit' } },
        app: { env: 'local', styleGuide: false },
    };
    window.matchMedia = (q) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    current = mount(Show, {
        props: { recipe: RECIPE, state: STATE, abilities: { edit: true, delete: true, changeVisibility: true }, units: UNITS, ...props },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, loadMessages(locale))] },
    });

    return current;
}
const $ = (sel) => current.find(sel);
const flush = async () => { await flushPromises(); await flushPromises(); };
function setWakeLock(request) {
    Object.defineProperty(navigator, 'wakeLock', { value: request ? { request } : undefined, configurable: true });
}

beforeEach(() => {
    Object.values(routerMock).forEach((f) => f.mockClear());
    routerMock.on.mockImplementation(() => vi.fn());
    fetchMock.mockReset();
    fetchMock.mockResolvedValue({ ok: true, status: 204, data: {} });
    window.history.replaceState(null, '', '/recipes/12');
    delete navigator.wakeLock;
});
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; delete navigator.wakeLock; });

describe('Cooking mode on a phone', () => {
    it('shows the toggle in the bottom bar, not in the header, and cooking is off by default', async () => {
        mountPage();
        await flushPromises();
        expect($('[data-testid="cooking-toggle-bar"]').attributes('aria-pressed')).toBe('false');
        expect($('[data-testid="cooking-toggle"]').exists()).toBe(false);
        expect($('[data-testid="recipe-detail"]').classes()).not.toContain('rec-show--cooking');
        expect($('[data-testid="recipe-description"]').exists()).toBe(true);
    });

    it('turning it on hides the description and the strip, enlarges the rows and shows the cooking bar with the counts', async () => {
        mountPage();
        await flushPromises();
        expect($('[data-testid="gallery-strip"]').exists()).toBe(true);
        await $('[data-testid="cooking-toggle-bar"]').trigger('click');
        expect($('[data-testid="recipe-detail"]').classes()).toContain('rec-show--cooking');
        expect($('[data-testid="recipe-description"]').exists()).toBe(false);
        expect($('[data-testid="gallery-strip"]').exists()).toBe(false);
        expect(current.findAll('.ing-row--cooking')).toHaveLength(2);
        expect(current.findAll('.step-row--cooking')).toHaveLength(3);
        expect($('[data-testid="cooking-progress"]').text()).toContain('Ingredients 1/2');
        expect($('[data-testid="cooking-progress"]').text()).toContain('Steps 2/3');
        expect($('[data-testid="cooking-anchors"]').text()).toContain('Ingredients');
        expect($('[data-testid="cooking-anchors"]').text()).toContain('Steps');
        expect($('[role="tablist"]').exists()).toBe(false);
    });

    it('updates the bar counts as rows are ticked', async () => {
        mountPage();
        await flushPromises();
        await $('[data-testid="cooking-toggle-bar"]').trigger('click');
        await current.findAll('[data-testid="ingredient-row"]').find((r) => r.find('.ing-row__name').text() === 'Eggs').find('input').setValue(true);
        await current.findAll('[data-testid="step-row"]')[2].find('input').setValue(true);
        expect($('[data-testid="cooking-progress"]').text()).toContain('Ingredients 2/2');
        expect($('[data-testid="cooking-progress"]').text()).toContain('Steps 3/3');
    });

    it('Leave cooking mode turns it off again', async () => {
        mountPage();
        await flushPromises();
        await $('[data-testid="cooking-toggle-bar"]').trigger('click');
        await $('[data-testid="cooking-leave"]').trigger('click');
        expect($('[data-testid="recipe-detail"]').classes()).not.toContain('rec-show--cooking');
        expect($('[data-testid="cooking-toggle-bar"]').exists()).toBe(true);
        expect($('[data-testid="recipe-description"]').exists()).toBe(true);
    });

    it('jumps to a section with the anchors', async () => {
        mountPage();
        await flushPromises();
        await $('[data-testid="cooking-toggle-bar"]').trigger('click');
        const scroll = vi.fn();
        document.getElementById('steps-heading').scrollIntoView = scroll;
        await current.findAll('[data-testid="cooking-anchors"] button')[1].trigger('click');
        expect(scroll).toHaveBeenCalledTimes(1);
    });
});

describe('Cooking mode with a wide page', () => {
    it('has the toggle in the header with aria-pressed and no bottom bar', async () => {
        mountPage({ width: 1280 });
        await flushPromises();
        expect($('[data-testid="recipe-bar"]').exists()).toBe(false);
        const toggle = $('[data-testid="cooking-toggle"]');
        expect(toggle.attributes('aria-pressed')).toBe('false');
        await toggle.trigger('click');
        expect($('[data-testid="cooking-toggle"]').attributes('aria-pressed')).toBe('true');
        expect($('[data-testid="recipe-description"]').exists()).toBe(false);
    });
});

describe('Screen Wake Lock', () => {
    it('requests a lock and shows the hint when supported, and releases on leaving', async () => {
        const release = vi.fn().mockResolvedValue(undefined);
        const request = vi.fn().mockResolvedValue({ release, addEventListener() {} });
        setWakeLock(request);
        mountPage({ width: 1280 });
        await flushPromises();
        expect($('[data-testid="wake-hint"]').exists()).toBe(false);
        expect(request).not.toHaveBeenCalled();
        await $('[data-testid="cooking-toggle"]').trigger('click');
        await flush();
        expect(request).toHaveBeenCalledWith('screen');
        expect($('[data-testid="wake-hint"]').text()).toBe('The screen stays on while you cook.');
        await $('[data-testid="cooking-toggle"]').trigger('click');
        await flush();
        expect(release).toHaveBeenCalled();
    });

    it('shows no hint and breaks nothing without support', async () => {
        mountPage({ width: 1280 });
        await flushPromises();
        await $('[data-testid="cooking-toggle"]').trigger('click');
        await flush();
        expect($('[data-testid="wake-hint"]').exists()).toBe(false);
        expect($('[data-testid="recipe-detail"]').classes()).toContain('rec-show--cooking');
    });

    it('stays silent when the browser refuses the lock', async () => {
        setWakeLock(vi.fn().mockRejectedValue(new DOMException('no', 'NotAllowedError')));
        mountPage({ width: 1280 });
        await flushPromises();
        await $('[data-testid="cooking-toggle"]').trigger('click');
        await flush();
        expect($('[data-testid="recipe-detail"]').classes()).toContain('rec-show--cooking');
    });

    it('releases the lock when the page is left', async () => {
        const release = vi.fn().mockResolvedValue(undefined);
        setWakeLock(vi.fn().mockResolvedValue({ release, addEventListener() {} }));
        mountPage({ width: 1280 });
        await flushPromises();
        await $('[data-testid="cooking-toggle"]').trigger('click');
        await flush();
        current.unmount();
        current = null;
        expect(release).toHaveBeenCalled();
    });
});

describe('Cooking mode: accessibility', () => {
    it.each(['en', 'sk', 'pl'])('has no axe violations while cooking on a phone (%s)', async (locale) => {
        mountPage({ locale });
        await flushPromises();
        await $('[data-testid="cooking-toggle-bar"]').trigger('click');
        await expectNoAxeViolations(current.element);
    });
});
