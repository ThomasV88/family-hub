import { computed, inject, provide, useId } from 'vue';

const KEY = Symbol('ui-field');

// Called by UiField. Arguments are refs/computeds so the context stays reactive.
export function provideField({ id, describedBy, invalid }) {
    provide(KEY, { id, describedBy, invalid });
}

// Called by controls. Falls back to a generated id when used outside UiField.
export function useFieldContext() {
    const ctx = inject(KEY, null);
    if (ctx) return ctx;
    const id = useId();
    return { id: computed(() => id), describedBy: computed(() => undefined), invalid: computed(() => false) };
}
