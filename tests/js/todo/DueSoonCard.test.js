import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';

const visit = vi.fn();
let mockProps = { auth: { access: { todo: 'edit' } } };
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockProps }),
    router: { visit: (...a) => visit(...a) },
}));

import DueSoonCard from '../../../resources/js/Dashboard/DueSoonCard.vue';

let w;
const mountCard = (dueSoon, locale = 'en') => {
    w = mount(DueSoonCard, { props: { dueSoon }, attachTo: document.body, global: { plugins: [createAppI18n(locale, loadMessages(locale))] } });
    return w;
};
afterEach(() => { w?.unmount(); visit.mockClear(); });

const data = (extra = {}) => ({
    items: [{ id: 3, title: 'Selected one', dueDate: '2999-05-05', dueTime: null, visibility: 'selected' }],
    more: 0, hasAnyTasks: true, today: '2026-11-13', timezone: 'Europe/Bratislava', ...extra,
});

describe('DueSoonCard', () => {
    it('tolerates a missing dueSoon prop', () => {
        const card = mountCard(null);
        expect(card.text()).toContain('No tasks yet');
    });

    it('navigates through the router on a plain click and leaves modified clicks alone', async () => {
        const card = mountCard(data());
        const row = card.find('a[href="/todo?task=3"]');
        await row.trigger('click');
        expect(visit).toHaveBeenCalledWith('/todo?task=3');
        visit.mockClear();
        await row.trigger('click', { ctrlKey: true });
        expect(visit).not.toHaveBeenCalled();
    });

    it('Add task in the empty state opens the create panel', async () => {
        const card = mountCard(data({ items: [], hasAnyTasks: false }));
        await card.find('button').trigger('click');
        expect(visit).toHaveBeenCalledWith('/todo?task=new');
    });

    it('marks the selected-members icon and has no more footer at 0', () => {
        const card = mountCard(data());
        expect(card.find('[aria-label="Shared with selected members"]').exists()).toBe(true);
        expect(card.find('[data-testid=due-soon-more]').exists()).toBe(false);
    });

    it('is axe clean', async () => {
        await expectNoAxeViolations(mountCard(data({ more: 1 })).element);
    });
});
