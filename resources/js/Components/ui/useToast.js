import { reactive } from 'vue';

const toasts = reactive([]);
let nextId = 1;

function dismiss(id) {
    const index = toasts.findIndex((toast) => toast.id === id);
    if (index !== -1) toasts.splice(index, 1);
}

function show(message, { duration = 5000 } = {}) {
    const id = nextId++;
    toasts.push({ id, message, duration });
    return id;
}

export function useToast() {
    return { toasts, show, dismiss };
}
