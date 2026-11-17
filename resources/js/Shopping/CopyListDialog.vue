<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiCheckbox from '../Components/ui/UiCheckbox.vue';
import UiDialog from '../Components/ui/UiDialog.vue';
import UiField from '../Components/ui/UiField.vue';
import UiInput from '../Components/ui/UiInput.vue';
import { httpHandlers } from './failure.js';
import { NAME_MAX, charLength, clip } from './limits.js';

// Copy a list (overview row, and the list page in 11-11). Posts { name, keep } to /shopping/lists/{id}/copy; the server redirects to
// the new list with the toast "List copied". list: { id, name }.
const props = defineProps({
    open: { type: Boolean, default: false },
    list: { type: Object, default: null },
    nameMax: { type: Number, default: NAME_MAX },
});
const emit = defineEmits(['update:open']);
const { t } = useI18n();

const form = useForm({ name: '', keep: true });
const nameInput = ref(null);
const httpError = ref('');
const initialFocus = () => nameInput.value?.$el?.querySelector?.('input') ?? null;

function defaultName(name) {
    const full = t('shopping.copy.default_name', { name });
    if (charLength(full) <= props.nameMax) return full;
    const room = props.nameMax - (charLength(full) - charLength(name));

    return t('shopping.copy.default_name', { name: clip(name, Math.max(0, room)).trimEnd() });
}

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        form.clearErrors();
        httpError.value = '';
        form.name = defaultName(props.list?.name ?? '');
        form.keep = true;
    },
    { immediate: true },
);

const translate = (message) => (String(message).startsWith('shopping.') ? t(message) : String(message));
const nameError = computed(() => (form.errors?.name ? translate(form.errors.name) : ''));
const failed = computed(() => Boolean(nameError.value || form.errors?.keep || httpError.value));
const keepError = computed(() => (form.errors?.keep ? translate(form.errors.keep) : ''));
const close = () => emit('update:open', false);

function submit() {
    if (!props.list || form.name.trim() === '' || form.processing) return;
    httpError.value = '';
    form.post(`/shopping/lists/${props.list.id}/copy`, {
        preserveScroll: true,
        onSuccess: close,
        ...httpHandlers((key) => { httpError.value = t(key); }),
    });
}
</script>

<template>
    <UiDialog :open="open" :title="t('shopping.copy.title')" :initial-focus="initialFocus" @update:open="emit('update:open', $event)">
        <form id="copy-list-form" class="shop-copy" novalidate @submit.prevent="submit">
            <div aria-live="polite">
                <UiAlert v-if="failed" variant="danger" data-testid="copy-failed">{{ keepError || nameError || httpError }}</UiAlert>
            </div>
            <UiField :label="t('shopping.copy.name')" :error="nameError" required>
                <UiInput ref="nameInput" v-model="form.name" autocomplete="off" :maxlength="nameMax" :disabled="form.processing" />
            </UiField>
            <UiCheckbox v-model="form.keep" :disabled="form.processing" class="shop-copy__keep">{{ t('shopping.copy.keep') }}</UiCheckbox>
        </form>
        <template #footer>
            <UiButton variant="ghost" @click="close">{{ t('shopping.copy.cancel') }}</UiButton>
            <UiButton type="submit" form="copy-list-form" variant="primary" :disabled="form.name.trim() === '' || form.processing" :loading="form.processing">{{ t('shopping.copy.submit') }}</UiButton>
        </template>
    </UiDialog>
</template>

<style scoped>
.shop-copy { display: flex; flex-direction: column; gap: var(--space-4); }
</style>
