import { describe, it, expect, afterEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { mountUi } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';
import UiList from '../../../resources/js/Components/ui/UiList.vue';
import UiListRow from '../../../resources/js/Components/ui/UiListRow.vue';
import UiTable from '../../../resources/js/Components/ui/UiTable.vue';
import Lists from '../../../resources/js/StyleGuide/Sections/45-Lists.vue';

let wrapper;
afterEach(() => wrapper?.unmount());
const m = (c, o = {}) => (wrapper = mountUi(c, o));
const columns = [
    { key: 'date', label: 'Date' },
    { key: 'amount', label: 'Amount', numeric: true },
];

describe('UiList / UiListRow', () => {
    it('renders ul with role list', () => {
        m(UiList, { slots: { default: '<li>a</li>' } });
        expect(wrapper.element.tagName).toBe('UL');
        expect(wrapper.attributes('role')).toBe('list');
    });
    it('as=button emits select and keeps slots outside the button', async () => {
        m(UiListRow, { props: { as: 'button', title: 'Buy milk' }, slots: { default: 'Buy milk', leading: '<span data-t="lead">x</span>', trailing: '<span data-t="trail">y</span>' } });
        const btn = wrapper.find('button');
        expect(btn.attributes('type')).toBe('button');
        expect(btn.find('[data-t="lead"]').exists()).toBe(false);
        expect(btn.find('[data-t="trail"]').exists()).toBe(false);
        await btn.trigger('click');
        expect(wrapper.emitted('select')).toHaveLength(1);
        expect(btn.attributes('title')).toBe('Buy milk');
    });
    it('as=link renders an anchor', () => {
        m(UiListRow, { props: { as: 'link', href: '/x' }, slots: { default: 'T' } });
        expect(wrapper.find('a').attributes('href')).toBe('/x');
    });
    it('selected sets aria-current and disabled disables', () => {
        m(UiListRow, { props: { as: 'button', selected: true, disabled: true }, slots: { default: 'T' } });
        expect(wrapper.find('button').attributes('aria-current')).toBe('true');
        expect(wrapper.find('button').attributes('disabled')).toBeDefined();
    });
});

describe('UiTable', () => {
    it('renders caption, scoped headers and data-label cells', () => {
        m(UiTable, { props: { caption: 'Tx', columns, rows: [{ date: 'a', amount: '1' }] } });
        expect(wrapper.find('caption').text()).toBe('Tx');
        expect(wrapper.findAll('th[scope="col"]')).toHaveLength(2);
        const tds = wrapper.findAll('tbody td');
        expect(tds[1].attributes('data-label')).toBe('Amount');
        expect(tds[1].classes()).toContain('is-numeric');
    });
    it('loading sets aria-busy', () => {
        m(UiTable, { props: { caption: 'Tx', columns, rows: [], loading: true } });
        expect(wrapper.find('table').attributes('aria-busy')).toBe('true');
    });
    it('empty shows a single full-width empty state', () => {
        m(UiTable, { props: { caption: 'Tx', columns, rows: [] } });
        const td = wrapper.find('tbody td');
        expect(td.attributes('colspan')).toBe('2');
        expect(td.text()).toContain('Nothing here yet');
    });
    it('css has sticky header and container query', () => {
        const src = readFileSync('resources/js/Components/ui/UiTable.vue', 'utf8');
        expect(src).toContain('position: sticky');
        expect(src).toContain('@container table');
        expect(src).toContain('scope="col"');
    });
});

describe('Lists section', () => {
    it.each(['en', 'sk'])('has no axe violations in %s', async (locale) => {
        m(Lists, { locale });
        expect(wrapper.find('#lists').exists()).toBe(true);
        await expectNoAxeViolations(wrapper.element);
    });
});
