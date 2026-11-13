import { describe, it, expect, afterEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { mountUi } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';
import UiTag from '../../../resources/js/Components/ui/UiTag.vue';
import UiAvatar from '../../../resources/js/Components/ui/UiAvatar.vue';
import UiChip from '../../../resources/js/Components/ui/UiChip.vue';
import UiCard from '../../../resources/js/Components/ui/UiCard.vue';
import UiEmptyState from '../../../resources/js/Components/ui/UiEmptyState.vue';
import UiErrorState from '../../../resources/js/Components/ui/UiErrorState.vue';
import UiAlert from '../../../resources/js/Components/ui/UiAlert.vue';
import UiProgress from '../../../resources/js/Components/ui/UiProgress.vue';
import UiSkeleton from '../../../resources/js/Components/ui/UiSkeleton.vue';
import Display from '../../../resources/js/StyleGuide/Sections/40-Display.vue';

let wrapper;
afterEach(() => wrapper?.unmount());
const m = (c, o = {}) => (wrapper = mountUi(c, o));

describe('UiTag', () => {
    it.each(['neutral', 'warn', 'danger', 'success'])('renders %s with text', (variant) => {
        m(UiTag, { props: { variant }, slots: { default: 'Due' } });
        expect(wrapper.text()).toBe('Due');
        expect(wrapper.classes()).toContain(`ui-tag--${variant}`);
    });
    it('never uses the primary colour', () => {
        expect(readFileSync('resources/js/Components/ui/UiTag.vue', 'utf8')).not.toContain('color-primary');
    });
});

describe('UiAvatar', () => {
    it('shows the uppercase first letter with role img', () => {
        m(UiAvatar, { props: { name: 'anna', tint: 2 } });
        expect(wrapper.text()).toBe('A');
        expect(wrapper.attributes('role')).toBe('img');
        expect(wrapper.attributes('aria-label')).toBe('anna');
    });
    it('decorative hides from AT', () => {
        m(UiAvatar, { props: { name: 'Peter', decorative: true } });
        expect(wrapper.attributes('aria-hidden')).toBe('true');
        expect(wrapper.attributes('role')).toBeUndefined();
    });
});

describe('UiChip', () => {
    it('toggles aria-pressed via v-model', async () => {
        m(UiChip, { props: { modelValue: false, 'onUpdate:modelValue': (v) => wrapper.setProps({ modelValue: v }) }, slots: { default: 'All' } });
        expect(wrapper.attributes('aria-pressed')).toBe('false');
        await wrapper.trigger('click');
        expect(wrapper.attributes('aria-pressed')).toBe('true');
    });
});

describe('UiCard', () => {
    it('renders heading level and action slot', () => {
        m(UiCard, { props: { title: 'Tasks', headingLevel: 3 }, slots: { default: 'Body', action: '<a href="#x">Open</a>' } });
        expect(wrapper.find('h3').text()).toBe('Tasks');
        expect(wrapper.text()).toContain('Open');
        expect(wrapper.text()).toContain('Body');
    });
    it('defaults to h2', () => {
        m(UiCard, { props: { title: 'Tasks' } });
        expect(wrapper.find('h2').exists()).toBe(true);
    });
});

describe('UiEmptyState and UiErrorState', () => {
    it('empty state shows heading, body and action', () => {
        m(UiEmptyState, { props: { title: 'No tasks', body: 'Add one' }, slots: { action: '<button>Add</button>' } });
        expect(wrapper.find('h3').text()).toBe('No tasks');
        expect(wrapper.text()).toContain('Add one');
        expect(wrapper.find('button').exists()).toBe(true);
    });
    it('error state defaults to generic copy and emits retry', async () => {
        m(UiErrorState, { props: { live: true } });
        expect(wrapper.text()).toContain('Something went wrong');
        expect(wrapper.attributes('role')).toBe('alert');
        await wrapper.find('button').trigger('click');
        expect(wrapper.emitted('retry')).toHaveLength(1);
        expect(wrapper.find('button').text()).toBe('Try again');
    });
});

describe('UiAlert', () => {
    it('danger live uses role alert, others status', () => {
        m(UiAlert, { props: { variant: 'danger', live: true }, slots: { default: 'Bad' } });
        expect(wrapper.attributes('role')).toBe('alert');
        wrapper.unmount();
        m(UiAlert, { props: { variant: 'info', live: true }, slots: { default: 'Hi' } });
        expect(wrapper.attributes('role')).toBe('status');
    });
    it('dismissible emits dismiss', async () => {
        m(UiAlert, { props: { variant: 'warning', dismissible: true }, slots: { default: 'Offline' } });
        const btn = wrapper.find('button');
        expect(btn.attributes('aria-label')).toBe('Dismiss');
        await btn.trigger('click');
        expect(wrapper.emitted('dismiss')).toHaveLength(1);
    });
});

describe('UiProgress and UiSkeleton', () => {
    it('progress exposes aria values', () => {
        m(UiProgress, { props: { value: 40, label: 'Budget used' } });
        const el = wrapper.find('[role="progressbar"]');
        expect(el.attributes('aria-valuenow')).toBe('40');
        expect(el.attributes('aria-valuemin')).toBe('0');
        expect(el.attributes('aria-valuemax')).toBe('100');
        expect(el.attributes('aria-label')).toBe('Budget used');
    });
    it('skeleton is aria-hidden and respects reduced motion', () => {
        m(UiSkeleton);
        expect(wrapper.attributes('aria-hidden')).toBe('true');
        expect(readFileSync('resources/js/Components/ui/UiSkeleton.vue', 'utf8')).toContain('prefers-reduced-motion');
    });
});

describe('Display section', () => {
    it.each(['en', 'sk'])('has no axe violations in %s', async (locale) => {
        m(Display, { locale });
        expect(wrapper.find('#display').exists()).toBe(true);
        await expectNoAxeViolations(wrapper.element);
    });
});
