import { router } from '@inertiajs/vue3';
import { inject, nextTick, onBeforeUnmount, onMounted, provide, ref } from 'vue';
import { FRAME_DESKTOP } from '../design/panelMode.js';

export const SIDEBAR_STORAGE_KEY = 'fh-sidebar';

// Sidebar is open by default from the desktop frame width; a stored choice wins.
export function resolveSidebarOpen({ stored, frameWidth }) {
    if (stored === 'open') return true;
    if (stored === 'closed') return false;
    return frameWidth >= FRAME_DESKTOP;
}

export function readSidebar() {
    try {
        return localStorage.getItem(SIDEBAR_STORAGE_KEY);
    } catch {
        return null;
    }
}

export function writeSidebar(open) {
    try {
        localStorage.setItem(SIDEBAR_STORAGE_KEY, open ? 'open' : 'closed');
    } catch {
        // storage unavailable
    }
}

// Measures an element's width (falls back to the window), updated on resize.
export function useFrameWidth(elRef) {
    const measure = () => elRef.value?.getBoundingClientRect().width || window.innerWidth;
    const width = ref(measure());
    let observer = null;
    const update = () => { width.value = measure(); };
    onMounted(() => {
        update();
        if (typeof ResizeObserver !== 'undefined' && elRef.value) {
            observer = new ResizeObserver(update);
            observer.observe(elRef.value);
        }
        window.addEventListener('resize', update);
    });
    onBeforeUnmount(() => {
        observer?.disconnect();
        window.removeEventListener('resize', update);
    });
    return width;
}

const SHELL_KEY = 'fh-shell';

export function provideShell({ panelMode, isPhone }) {
    const panelModule = ref(null);
    let addHandler = null;
    let lastTrigger = null;
    // Translated label of the registered add handler (null = the default "Add task"); lets a module name its own add action.
    const addLabel = ref(null);
    const shell = {
        panelModule,
        panelMode,
        isPhone,
        // The optional element is where focus returns when the panel closes.
        openComingSoon(moduleKey, trigger = null) {
            lastTrigger = trigger ?? (document.activeElement !== document.body ? document.activeElement : null);
            panelModule.value = moduleKey;
        },
        closePanel() {
            panelModule.value = null;
            const target = lastTrigger;
            lastTrigger = null;
            if (target?.isConnected) nextTick(() => target.focus());
        },
        addLabel,
        registerAddHandler(fn, label = null) {
            addHandler = fn;
            addLabel.value = label;
            return () => {
                if (addHandler !== fn) return;
                addHandler = null;
                addLabel.value = null;
            };
        },
        // A registered handler (the board on /todo) wins; elsewhere the add entry points open the create panel.
        triggerAdd() {
            if (addHandler) addHandler();
            else router.visit('/todo?task=new');
        },
    };
    provide(SHELL_KEY, shell);
    return shell;
}

export function useShell() {
    return inject(SHELL_KEY);
}
