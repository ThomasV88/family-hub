import { describe, it, expect, beforeEach, vi } from 'vitest';
import { SIDEBAR_STORAGE_KEY, resolveSidebarOpen, readSidebar, writeSidebar } from '../../resources/js/Shell/shellState.js';
import { pseudoLong } from './helpers/pseudoLong.js';
import { loadMessages } from './helpers/mount.js';

describe('resolveSidebarOpen', () => {
    it('opens by default from 1024 and not below', () => {
        expect(resolveSidebarOpen({ stored: null, frameWidth: 1280 })).toBe(true);
        expect(resolveSidebarOpen({ stored: null, frameWidth: 800 })).toBe(false);
    });
    it('stored choice wins, other values are ignored', () => {
        expect(resolveSidebarOpen({ stored: 'closed', frameWidth: 1280 })).toBe(false);
        expect(resolveSidebarOpen({ stored: 'open', frameWidth: 800 })).toBe(true);
        expect(resolveSidebarOpen({ stored: 'junk', frameWidth: 1280 })).toBe(true);
        expect(resolveSidebarOpen({ stored: 'junk', frameWidth: 800 })).toBe(false);
    });
});

describe('sidebar persistence', () => {
    beforeEach(() => localStorage.clear());
    it('writes open/closed to fh-sidebar', () => {
        expect(SIDEBAR_STORAGE_KEY).toBe('fh-sidebar');
        writeSidebar(true);
        expect(localStorage.getItem('fh-sidebar')).toBe('open');
        writeSidebar(false);
        expect(readSidebar()).toBe('closed');
    });
    it('survives storage exceptions', () => {
        const spy = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('denied'); });
        const spyGet = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('denied'); });
        expect(() => writeSidebar(true)).not.toThrow();
        expect(readSidebar()).toBeNull();
        spy.mockRestore();
        spyGet.mockRestore();
    });
});

describe('pseudoLong', () => {
    it('lengthens strings, keeps tokens and plural segments', () => {
        const out = pseudoLong({ a: 'Hello world', b: 'Hi {name}!', c: 'one item | {count} items' });
        expect(out.a.length).toBeGreaterThanOrEqual('Hello world'.length * 1.4);
        expect(out.b).toContain('{name}');
        expect(out.c.split(' | ')).toHaveLength(2);
        expect(out.c).toContain('{count}');
    });
    it('deep copies real messages without mutating', () => {
        const en = loadMessages('en');
        const copy = pseudoLong(en);
        expect(copy.nav.tasks.length).toBeGreaterThanOrEqual(en.nav.tasks.length * 1.4);
        expect(en.nav.tasks).toBe('Tasks');
    });
});
