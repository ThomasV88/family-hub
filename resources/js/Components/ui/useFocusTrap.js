const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

// Keeps Tab inside the container while active and restores focus to the previously focused element on deactivate.
export function useFocusTrap(containerRef, { initialFocus } = {}) {
    let previous = null;

    const items = () => [...(containerRef.value?.querySelectorAll(FOCUSABLE) ?? [])];

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
    const el = value && 'value' in value ? value.value : value;
    return el?.$el ?? el ?? null;
}
