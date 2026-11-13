import { describe, it, expect, afterEach } from 'vitest';
import { defineComponent, h, ref, nextTick } from 'vue';
import { mountUi } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';
import UiDialog from '../../../resources/js/Components/ui/UiDialog.vue';
import UiConfirmDialog from '../../../resources/js/Components/ui/UiConfirmDialog.vue';
import UiDetailPanel from '../../../resources/js/Components/ui/UiDetailPanel.vue';
import { useFocusTrap } from '../../../resources/js/Components/ui/useFocusTrap.js';

let wrapper;
afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    document.body.style.overflow = '';
});

const key = (el, k, extra = {}) => el.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true, ...extra }));

describe('useFocusTrap', () => {
    it('wraps Tab and Shift+Tab and restores focus', async () => {
        const opener = document.createElement('button');
        document.body.append(opener);
        opener.focus();
        const container = document.createElement('div');
        container.innerHTML = '<button id="a">a</button><button id="b">b</button>';
        document.body.append(container);
        const trap = useFocusTrap({ value: container });
        trap.activate();
        expect(document.activeElement.id).toBe('a');
        container.querySelector('#b').focus();
        key(container.querySelector('#b'), 'Tab');
        expect(document.activeElement.id).toBe('a');
        key(container.querySelector('#a'), 'Tab', { shiftKey: true });
        expect(document.activeElement.id).toBe('b');
        trap.deactivate();
        expect(document.activeElement).toBe(opener);
    });
});

function dialogHost(extra = {}) {
    return defineComponent({
        setup() {
            const open = ref(false);
            return () =>
                h('div', [
                    h('button', { id: 'opener', onClick: () => (open.value = true) }, 'open'),
                    h(UiDialog, { open: open.value, title: 'Edit task', 'onUpdate:open': (v) => (open.value = v), ...extra }, { default: () => h('button', { id: 'inner' }, 'inner') }),
                ]);
        },
    });
}

describe('UiDialog', () => {
    it('is a labelled modal, focuses inside, closes on Esc and returns focus', async () => {
        wrapper = mountUi(dialogHost());
        const opener = document.getElementById('opener');
        opener.focus();
        opener.click();
        await nextTick();
        await nextTick();
        const dialog = document.querySelector('[role=dialog]');
        expect(dialog.getAttribute('aria-modal')).toBe('true');
        expect(document.getElementById(dialog.getAttribute('aria-labelledby')).tagName).toBe('H2');
        expect(dialog.contains(document.activeElement)).toBe(true);
        expect(document.body.style.overflow).toBe('hidden');
        await expectNoAxeViolations(document.body);
        key(document.activeElement, 'Escape');
        await nextTick();
        await nextTick();
        expect(document.querySelector('[role=dialog]')).toBeNull();
        expect(document.activeElement).toBe(opener);
        expect(document.body.style.overflow).toBe('');
    });

    it('closes on scrim click', async () => {
        wrapper = mountUi(dialogHost());
        document.getElementById('opener').click();
        await nextTick();
        document.querySelector('.ui-dialog__scrim').click();
        await nextTick();
        await nextTick();
        expect(document.querySelector('[role=dialog]')).toBeNull();
    });
});

describe('UiConfirmDialog', () => {
    it('focuses the safe button, uses named labels and emits confirm', async () => {
        wrapper = mountUi(UiConfirmDialog, {
            props: { open: true, title: 'Delete task', body: 'Delete this task? This cannot be undone.', confirmLabel: 'Delete task', cancelLabel: 'Keep task' },
        });
        await nextTick();
        await nextTick();
        const buttons = [...document.querySelectorAll('[role=dialog] footer button')];
        expect(buttons.map((b) => b.textContent.trim())).toEqual(['Keep task', 'Delete task']);
        expect(document.activeElement).toBe(buttons[0]);
        expect(buttons[1].className).toContain('ui-button--danger');
        await expectNoAxeViolations(document.body);
        buttons[1].click();
        expect(wrapper.emitted('confirm')).toHaveLength(1);
        buttons[0].click();
        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });
});

describe('UiDetailPanel', () => {
    it.each(['sheet', 'overlay'])('%s is a teleported modal with Close and Esc', async (mode) => {
        wrapper = mountUi(UiDetailPanel, { props: { open: true, title: 'Panel', mode } });
        await nextTick();
        await nextTick();
        const dialog = document.querySelector('[role=dialog]');
        expect(dialog.getAttribute('aria-modal')).toBe('true');
        expect(dialog.querySelector('[aria-label=Close]')).not.toBeNull();
        expect(!!dialog.querySelector('.ui-panel__handle')).toBe(mode === 'sheet');
        if (mode === 'sheet') expect(dialog.querySelector('.ui-panel__handle').getAttribute('aria-hidden')).toBe('true');
        await expectNoAxeViolations(document.body);
        key(document.activeElement, 'Escape');
        expect(wrapper.emitted('update:open')[0]).toEqual([false]);
    });

    it('docked renders an aside in place, not modal', async () => {
        wrapper = mountUi(UiDetailPanel, { props: { open: true, title: 'Panel', mode: 'docked' } });
        await nextTick();
        const aside = wrapper.find('aside');
        expect(aside.exists()).toBe(true);
        expect(aside.attributes('aria-modal')).toBeUndefined();
        expect(document.getElementById(aside.attributes('aria-labelledby')).tagName).toBe('H2');
        await expectNoAxeViolations(wrapper.element);
        aside.element.querySelector('button').focus();
        await aside.trigger('keydown', { key: 'Escape' });
        expect(wrapper.emitted('update:open')[0]).toEqual([false]);
    });

    it('uses the injected fh-panel-mode when mode is absent', async () => {
        wrapper = mountUi(UiDetailPanel, { props: { open: true, title: 'Panel' }, global: { provide: { 'fh-panel-mode': 'docked' } } });
        await nextTick();
        expect(wrapper.find('aside').exists()).toBe(true);
    });
});
