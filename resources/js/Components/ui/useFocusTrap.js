import { isRef } from 'vue';

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), summary, audio[controls], video[controls], [contenteditable]:not([contenteditable="false"]), [tabindex]:not([tabindex="-1"])';

// Computed-style based so it also works where layout is unavailable (jsdom has no offsetParent).
function isVisible(el, container) {
    if (el.closest('[hidden], [inert]')) return false;
    if (el.type === 'hidden') return false;
    const own = getComputedStyle(el);
    if (own.visibility === 'hidden' || own.visibility === 'collapse') return false;
    for (let node = el; node && node !== container?.parentElement; node = node.parentElement) {
        if (getComputedStyle(node).display === 'none') return false;
    }
    return true;
}

// Keeps Tab inside the container while active and restores focus to the previously focused element on deactivate.
export function useFocusTrap(containerRef, { initialFocus } = {}) {
    let previous = null;

    const items = () => [...(containerRef.value?.querySelectorAll(FOCUSABLE) ?? [])].filter((el) => isVisible(el, containerRef.value));

    function onKeydown(event) {
        if (event.key !== 'Tab') return;
        const list = items();
        if (!list.length) {
            event.preventDefault();
            return;
        }
        const first = list[0];
        const last = list[list.length - 1];
        const current = document.activeElement;
        if (event.shiftKey && (current === first || !containerRef.value.contains(current))) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && (current === last || !containerRef.value.contains(current))) {
            event.preventDefault();
            first.focus();
        }
    }

    function activate() {
        previous = document.activeElement;
        const container = containerRef.value;
        if (!container) return;
        container.addEventListener('keydown', onKeydown);
        const target = resolve(initialFocus) ?? items()[0] ?? container;
        target.focus?.();
    }

    function deactivate() {
        containerRef.value?.removeEventListener('keydown', onKeydown);
        if (previous && document.contains(previous)) previous.focus();
        previous = null;
    }

    return { activate, deactivate };
}

function resolve(initialFocus) {
    const value = typeof initialFocus === 'function' ? initialFocus() : initialFocus;
    const raw = isRef(value) ? value.value : value;
    const el = raw?.$el ?? raw ?? null;
    return el instanceof HTMLElement ? el : null;
}
