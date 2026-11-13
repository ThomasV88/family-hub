import { describe, it, expect, beforeEach, vi } from 'vitest';
import {
    THEME_STORAGE_KEY,
    getThemePreference,
    setThemePreference,
    applyTheme,
} from '../../resources/js/theme.js';

function stubMatchMedia(initialDark) {
    const listeners = new Set();
    const mql = {
        matches: initialDark,
        addEventListener: (_e, fn) => listeners.add(fn),
        removeEventListener: (_e, fn) => listeners.delete(fn),
    };
    const fn = vi.fn(() => mql);
    return {
        fn,
        listeners,
        set(dark) {
            mql.matches = dark;
            [...listeners].forEach((l) => l({ matches: dark }));
        },
    };
}

const root = document.documentElement;

beforeEach(() => {
    localStorage.clear();
    document.cookie = 'fh_theme=; path=/; max-age=0';
    root.removeAttribute('data-theme');
    root.removeAttribute('data-theme-pref');
    document.head.innerHTML = '<meta name="theme-color" content="#000">';
});

describe('getThemePreference', () => {
    it('defaults to system', () => {
        expect(getThemePreference()).toBe('system');
    });

    it('returns a stored valid value and ignores invalid ones', () => {
        localStorage.setItem(THEME_STORAGE_KEY, 'light');
        expect(getThemePreference()).toBe('light');
        localStorage.setItem(THEME_STORAGE_KEY, '"><b>');
        expect(getThemePreference()).toBe('system');
    });

    it('falls back to the server attribute', () => {
        root.setAttribute('data-theme-pref', 'dark');
        expect(getThemePreference()).toBe('dark');
    });
});

describe('setThemePreference', () => {
    it('persists to localStorage and cookie and sets attributes', () => {
        const mm = stubMatchMedia(false);
        vi.stubGlobal('matchMedia', mm.fn);
        window.matchMedia = mm.fn;
        setThemePreference('dark');
        expect(localStorage.getItem('fh-theme')).toBe('dark');
        expect(document.cookie).toContain('fh_theme=dark');
        expect(root.getAttribute('data-theme')).toBe('dark');
        expect(root.getAttribute('data-theme-pref')).toBe('dark');
    });
});

describe('applyTheme', () => {
    it('follows the system live and stops following when switched away', () => {
        const mm = stubMatchMedia(true);
        applyTheme('system', { root, matchMedia: mm.fn });
        expect(root.getAttribute('data-theme')).toBe('dark');
        mm.set(false);
        expect(root.getAttribute('data-theme')).toBe('light');
        expect(document.querySelectorAll('meta[name="theme-color"]')).toHaveLength(1);
        applyTheme('dark', { root, matchMedia: mm.fn });
        expect(mm.listeners.size).toBe(0);
        mm.set(false);
        expect(root.getAttribute('data-theme')).toBe('dark');
    });
});
