import { describe, it, expect, afterEach, vi } from 'vitest';
import { defineComponent, h, ref, nextTick } from 'vue';
import { mountUi } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';
import UiMenu from '../../../resources/js/Components/ui/UiMenu.vue';
import UiTabs from '../../../resources/js/Components/ui/UiTabs.vue';
import UiToastRegion from '../../../resources/js/Components/ui/UiToastRegion.vue';
import { useToast } from '../../../resources/js/Components/ui/useToast.js';
import Overlays from '../../../resources/js/StyleGuide/Sections/50-Overlays.vue';

let wrapper;
afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    vi.useRealTimers();
    const { toasts } = useToast();
    toasts.splice(0);
});

const press = (k) => document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true }));
const flush = async () => { await nextTick(); await nextTick(); };

const items = [
    { type: 'item', key: 'one', label: 'One' },
    { type: 'item', key: 'two', label: 'Two' },
    { type: 'radio-group', key: 'theme', label: 'Theme', value: 'light', options: [{ value: 'light', label: 'Light' }, { value: 'dark', label: 'Dark' }] },
];

describe('UiMenu', () => {
    it('opens, navigates with arrows, wraps, selects and returns focus', async () => {
        wrapper = mountUi(UiMenu, { props: { label: 'Menu', items } });
        const trigger = wrapper.find('button');
        expect(trigger.attributes('aria-haspopup')).toBe('menu');
        expect(trigger.attributes('aria-expanded')).toBe('false');
        await trigger.trigger('click');
        await flush();
        expect(trigger.attributes('aria-expanded')).toBe('true');
        const entries = [...document.querySelectorAll('[role=menuitem],[role=menuitemradio]')];
        expect(entries).toHaveLength(4);
        expect(document.activeElement).toBe(entries[0]);
        expect(entries.filter((e) => e.tabIndex === 0)).toHaveLength(1);
        press('ArrowDown'); await flush();
        expect(document.activeElement).toBe(entries[1]);
        press('ArrowUp'); press('ArrowUp'); await flush();
        expect(document.activeElement).toBe(entries[3]);
        press('Home'); await flush();
        expect(document.activeElement).toBe(entries[0]);
        press('End'); await flush();
        expect(document.activeElement).toBe(entries[3]);
        expect(entries[2].getAttribute('aria-checked')).toBe('true');
        expect(entries[3].getAttribute('aria-checked')).toBe('false');
        expect(document.querySelector('[role=group]').getAttribute('aria-label')).toBe('Theme');
        await expectNoAxeViolations(document.body);
        entries[3].click();
        expect(wrapper.emitted('select')[0]).toEqual([{ key: 'theme', value: 'dark' }]);
        await flush();
        expect(document.querySelector('[role=menu]')).toBeNull();
        expect(document.activeElement).toBe(trigger.element);
    });

    it('closes on Esc, Tab and outside click', async () => {
        wrapper = mountUi(UiMenu, { props: { label: 'Menu', items } });
        const trigger = wrapper.find('button');
        await trigger.trigger('click'); await flush();
        press('Escape'); await flush();
        expect(document.querySelector('[role=menu]')).toBeNull();
        expect(document.activeElement).toBe(trigger.element);
        await trigger.trigger('click'); await flush();
        press('Tab'); await flush();
        expect(document.querySelector('[role=menu]')).toBeNull();
        await trigger.trigger('click'); await flush();
        document.body.click(); await flush();
        expect(document.querySelector('[role=menu]')).toBeNull();
    });
});

describe('UiTabs', () => {
    const Host = defineComponent({
        setup() {
            const tab = ref('a');
            return () =>
                h(UiTabs, { tabs: [{ key: 'a', label: 'A' }, { key: 'b', label: 'B' }, { key: 'c', label: 'C' }], modelValue: tab.value, label: 'Tabs', 'onUpdate:modelValue': (v) => (tab.value = v) }, {
                    'panel-a': () => 'pa', 'panel-b': () => 'pb', 'panel-c': () => 'pc',
                });
        },
    });

    it('has tab semantics and arrow/Home/End navigation', async () => {
        wrapper = mountUi(Host);
        const tabs = () => [...document.querySelectorAll('[role=tab]')];
        expect(document.querySelector('[role=tablist]').getAttribute('aria-label')).toBe('Tabs');
        expect(tabs().map((t) => t.getAttribute('aria-selected'))).toEqual(['true', 'false', 'false']);
        expect(tabs().map((t) => t.tabIndex)).toEqual([0, -1, -1]);
        const panel = document.querySelector('[role=tabpanel]:not([style*="display: none"])');
        expect(panel.getAttribute('aria-labelledby')).toBe(tabs()[0].id);
        expect(tabs()[0].getAttribute('aria-controls')).toBe(panel.id);
        tabs()[0].focus();
        press('ArrowRight'); await flush();
        expect(tabs()[1].getAttribute('aria-selected')).toBe('true');
        expect(document.activeElement).toBe(tabs()[1]);
        press('ArrowLeft'); await flush(); press('ArrowLeft'); await flush();
        expect(document.activeElement).toBe(tabs()[2]);
        press('Home'); await flush();
        expect(document.activeElement).toBe(tabs()[0]);
        press('End'); await flush();
        expect(tabs()[2].getAttribute('aria-selected')).toBe('true');
        await expectNoAxeViolations(document.body);
    });
});

describe('toasts', () => {
    it('auto-dismisses after 5000ms and pauses on hover and focus', async () => {
        vi.useFakeTimers();
        wrapper = mountUi(UiToastRegion);
        const region = wrapper.find('[role=status]');
        expect(region.attributes('aria-live')).toBe('polite');
        const { show } = useToast();
        show('Saved');
        await nextTick();
        expect(wrapper.text()).toContain('Saved');
        expect(wrapper.find('.ui-toast button').attributes('aria-label')).toBe('Close');
        vi.advanceTimersByTime(5000);
        await nextTick();
        expect(wrapper.text()).not.toContain('Saved');

        show('Hover');
        await nextTick();
        vi.advanceTimersByTime(3000);
        await wrapper.find('.ui-toast').trigger('mouseenter');
        vi.advanceTimersByTime(20000);
        await nextTick();
        expect(wrapper.text()).toContain('Hover');
        await wrapper.find('.ui-toast').trigger('mouseleave');
        vi.advanceTimersByTime(2500);
        await nextTick();
        expect(wrapper.text()).not.toContain('Hover');

        show('Focus');
        await nextTick();
        await wrapper.find('.ui-toast').trigger('focusin');
        vi.advanceTimersByTime(20000);
        await nextTick();
        expect(wrapper.text()).toContain('Focus');
    });

    it('dismiss button removes the toast and region is axe clean', async () => {
        wrapper = mountUi(UiToastRegion);
        useToast().show('Gone');
        await nextTick();
        await expectNoAxeViolations(document.body);
        await wrapper.find('.ui-toast button').trigger('click');
        expect(wrapper.text()).not.toContain('Gone');
    });
});

describe('Overlays section', () => {
    it('renders, opens the confirm dialog with the safe button focused, and is axe clean', async () => {
        wrapper = mountUi(Overlays);
        const section = wrapper.find('section#overlays');
        expect(section.exists()).toBe(true);
        const open = wrapper.findAll('button').find((b) => b.text() === 'Delete task');
        open.element.focus();
        await open.trigger('click');
        await flush();
        const dialog = document.querySelector('[role=dialog]');
        expect(dialog.querySelector('h2').textContent).toBe('Delete task');
        expect(document.activeElement.textContent.trim()).toBe('Keep task');
        await expectNoAxeViolations(document.body);
    });
});
