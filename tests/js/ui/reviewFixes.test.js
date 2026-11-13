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
