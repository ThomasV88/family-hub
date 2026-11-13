import { describe, it, expect, afterEach } from 'vitest';
import { lockScroll, unlockScroll } from '../../../resources/js/Components/ui/useScrollLock.js';

afterEach(() => {
    document.body.innerHTML = '';
    document.body.style.overflow = '';
});

describe('WR-01 scroll lock', () => {
    it('stays locked until the last overlay closes, in any close order', () => {
        lockScroll();
        lockScroll();
        expect(document.body.style.overflow).toBe('hidden');
        unlockScroll();
        expect(document.body.style.overflow).toBe('hidden');
        unlockScroll();
        expect(document.body.style.overflow).toBe('');
    });
    it('ignores unbalanced unlock', () => {
        unlockScroll();
        expect(document.body.style.overflow).toBe('');
    });
});

import { nextTick } from 'vue';
import { mountUi } from '../helpers/mount.js';
import UiDetailPanel from '../../../resources/js/Components/ui/UiDetailPanel.vue';

describe('WR-02 docked panel does not trap Tab', () => {
    it('focuses the heading and lets Tab leave (no preventDefault)', async () => {
        const outside = document.createElement('button');
        document.body.append(outside);
        outside.focus();
        const w = mountUi(UiDetailPanel, { props: { open: true, title: 'Panel', mode: 'docked' } });
        await nextTick();
        await nextTick();
        const h = w.find('h2').element;
        expect(document.activeElement).toBe(h);
        const btn = w.find('aside button').element;
        btn.focus();
        const ev = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
        btn.dispatchEvent(ev);
        expect(ev.defaultPrevented).toBe(false);
        await w.setProps({ open: false });
        await nextTick();
        expect(document.activeElement).toBe(outside);
        w.unmount();
    });
});

describe('WR-03 mode change while open', () => {
    it('releases the scroll lock when switching overlay to docked and re-locks on the way back', async () => {
        const w = mountUi(UiDetailPanel, { props: { open: true, title: 'Panel', mode: 'overlay' } });
        await nextTick();
        await nextTick();
        expect(document.body.style.overflow).toBe('hidden');
        await w.setProps({ mode: 'docked' });
        await nextTick();
        await nextTick();
        expect(document.body.style.overflow).toBe('');
        expect(w.find('aside').exists()).toBe(true);
        await w.setProps({ mode: 'overlay' });
        await nextTick();
        await nextTick();
        expect(document.body.style.overflow).toBe('hidden');
        expect(document.querySelector('[role=dialog]').contains(document.activeElement)).toBe(true);
        w.unmount();
        expect(document.body.style.overflow).toBe('');
    });
});

import { useFocusTrap } from '../../../resources/js/Components/ui/useFocusTrap.js';

describe('WR-04 / WR-05 focus trap', () => {
    const tabKey = (el, shiftKey = false) => {
        const ev = new KeyboardEvent('keydown', { key: 'Tab', shiftKey, bubbles: true, cancelable: true });
        el.dispatchEvent(ev);
        return ev;
    };
    it('skips hidden elements when wrapping', () => {
        const c = document.createElement('div');
        c.innerHTML = '<button id="a">a</button><button id="b">b</button><div style="display:none"><button id="x">x</button></div><button id="h" hidden>h</button><input type="hidden" tabindex="0">';
        document.body.append(c);
        const trap = useFocusTrap({ value: c });
        trap.activate();
        c.querySelector('#b').focus();
        tabKey(c.querySelector('#b'));
        expect(document.activeElement.id).toBe('a');
        tabKey(c.querySelector('#a'), true);
        expect(document.activeElement.id).toBe('b');
        trap.deactivate();
    });
    it('focuses a plain input returned as initialFocus (not its string value)', () => {
        const c = document.createElement('div');
        c.innerHTML = '<button id="a">a</button><input id="i" value="text">';
        document.body.append(c);
        const trap = useFocusTrap({ value: c }, { initialFocus: () => c.querySelector('#i') });
        trap.activate();
        expect(document.activeElement.id).toBe('i');
        trap.deactivate();
    });
    it('does not throw when initialFocus returns a primitive', () => {
        const c = document.createElement('div');
        c.innerHTML = '<button id="a">a</button>';
        document.body.append(c);
        const trap = useFocusTrap({ value: c }, { initialFocus: () => 'nope' });
        trap.activate();
        expect(document.activeElement.id).toBe('a');
        trap.deactivate();
    });
});
