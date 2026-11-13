export const THEME_STORAGE_KEY = 'fh-theme';
export const THEME_COOKIE = 'fh_theme';
export const THEME_OPTIONS = ['system', 'light', 'dark'];

// Surface colours behind the topbar, used for the theme-color meta.
const SURFACE = { light: '#ffffff', dark: '#222220' };

let unsubscribe = null;

const valid = (v) => (THEME_OPTIONS.includes(v) ? v : 'system');

export function getThemePreference() {
    try {
        const stored = localStorage.getItem(THEME_STORAGE_KEY);
        if (stored !== null) return valid(stored);
    } catch {
        // storage unavailable (private mode)
    }
    return valid(document.documentElement.getAttribute('data-theme-pref'));
}

export function applyTheme(pref, { root = document.documentElement, matchMedia = window.matchMedia?.bind(window) } = {}) {
    pref = valid(pref);
    if (unsubscribe) {
        unsubscribe();
        unsubscribe = null;
    }

    const mql = matchMedia ? matchMedia('(prefers-color-scheme: dark)') : null;
    const resolve = (isDark) => {
        const theme = pref === 'system' ? (isDark ? 'dark' : 'light') : pref;
        root.setAttribute('data-theme-pref', pref);
        root.setAttribute('data-theme', theme);
        root.style.colorScheme = theme;
        document.querySelectorAll('meta[name="theme-color"]').forEach((m) => m.remove());
        const meta = document.createElement('meta');
        meta.name = 'theme-color';
        meta.content = SURFACE[theme];
        document.head.appendChild(meta);
    };

    resolve(Boolean(mql?.matches));

    if (pref === 'system' && mql?.addEventListener) {
        const onChange = (e) => resolve(e.matches);
        mql.addEventListener('change', onChange);
        unsubscribe = () => mql.removeEventListener('change', onChange);
    }
}

export function setThemePreference(pref) {
    pref = valid(pref);
    try {
        localStorage.setItem(THEME_STORAGE_KEY, pref);
    } catch {
        // storage unavailable
    }
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${THEME_COOKIE}=${pref}; path=/; max-age=31536000; SameSite=Lax${secure}`;
    applyTheme(pref);
}

export function initTheme() {
    applyTheme(getThemePreference());
}
