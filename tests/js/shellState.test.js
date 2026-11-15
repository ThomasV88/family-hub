import { describe, it, expect, beforeEach, vi } from 'vitest';
import { createApp, h } from 'vue';
import { provideShell, SIDEBAR_STORAGE_KEY, resolveSidebarOpen, readSidebar, writeSidebar } from '../../resources/js/Shell/shellState.js';
const visit = vi.fn();
vi.mock('@inertiajs/vue3', () => ({ router: { visit: (...a) => visit(...a) } }));

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


describe('triggerAdd', () => {
    function shellInstance() {
        let shell;
        createApp({ setup() { shell = provideShell({ panelMode: 'sheet', isPhone: true }); return () => h('div'); } }).mount(document.createElement('div'));
        return shell;
    }
    beforeEach(() => visit.mockClear());

    it('falls back to /todo?task=new without a registered handler', () => {
        const shell = shellInstance();
        shell.triggerAdd();
        expect(visit).toHaveBeenCalledWith('/todo?task=new');
        expect(shell.panelModule.value).toBeNull();
    });

    it('a registered handler wins and unregisters', () => {
        const shell = shellInstance();
        const handler = vi.fn();
        const off = shell.registerAddHandler(handler);
        shell.triggerAdd();
        expect(handler).toHaveBeenCalledTimes(1);
        expect(visit).not.toHaveBeenCalled();
        off();
        shell.triggerAdd();
        expect(visit).toHaveBeenCalledWith('/todo?task=new');
    });
});
