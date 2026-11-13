import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

// Quick add: title only. Visibility, assignee and the rest are decided by the server.
// The success toast is the server flash ("todo.quick.added"), rendered once by the shell.
export function useQuickAdd({ onAdded } = {}) {
    const { t } = useI18n();
    const text = ref('');
    const error = ref('');
    const processing = ref(false);

    function submit(event) {
        if (event?.isComposing || processing.value) return;
        const title = text.value.trim();
        if (!title) {
            error.value = t('todo.error.title_required');
            return;
        }
        error.value = '';
        router.post('/todo/tasks', { title }, {
            preserveScroll: true,
            onStart: () => { processing.value = true; },
            onSuccess: () => { text.value = ''; onAdded?.(); },
            onError: (errors) => { error.value = errors?.title ? t(errors.title) : t('todo.error.title_required'); },
            onFinish: () => { processing.value = false; },
        });
    }

    return { text, error, processing, submit };
}
