import { reactive } from 'vue';

const toasts = reactive([]);
let nextId = 1;

function dismiss(id) {
    const index = toasts.findIndex((toast) => toast.id === id);
    if (index !== -1) toasts.splice(index, 1);
}

// action: optional { label, onSelect }, shown as a button; selecting it calls onSelect and dismisses the toast.
function show(message, { duration = 5000, action = null } = {}) {
    const id = nextId++;
    toasts.push({ id, message, duration, action: action?.label && typeof action.onSelect === 'function' ? action : null });
    return id;
}

export function useToast() {
    return { toasts, show, dismiss };
}
