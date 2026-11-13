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
