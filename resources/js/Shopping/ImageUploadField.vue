<script setup>
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Camera, Trash2 } from '@lucide/vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiSkeleton from '../Components/ui/UiSkeleton.vue';
import CatalogImage from './CatalogImage.vue';

// Picture or logo field of a catalog item or store (ProfilePhotoEditor pattern). The chosen file stays here as a preview
// and is handed to the parent, which saves the item first and then posts the file to the image endpoint. Removing an
// existing picture is also applied by the parent on Save, so cancelling the dialog changes nothing.
const props = defineProps({
    kind: { type: String, default: 'catalog', validator: (v) => ['catalog', 'store'].includes(v) },
    // Id of the saved item or store, or null while it is being created.
    id: { type: Number, default: null },
    // Version of the stored picture, or null when there is none.
    version: { type: Number, default: null },
    label: { type: String, required: true },
    hint: { type: String, required: true },
    maxBytes: { type: Number, default: 5 * 1024 * 1024 },
    // The chosen file (v-model:file) and whether the stored picture is to be removed (v-model:remove).
    file: { type: [Object, null], default: null },
    remove: { type: Boolean, default: false },
    // Error from the upload the parent made (already translated).
    error: { type: String, default: '' },
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['update:file', 'update:remove']);
const { t } = useI18n();

const uid = useId();
const hintId = `${uid}-hint`;
const input = ref(null);
const preview = ref(null);
const localError = ref('');

function revoke() {
    if (preview.value) URL.revokeObjectURL(preview.value);
    preview.value = null;
}
watch(() => props.file, (file) => {
    revoke();
    if (file && typeof URL.createObjectURL === 'function') preview.value = URL.createObjectURL(file);
}, { immediate: true });
onBeforeUnmount(revoke);

const stored = computed(() => props.id !== null && props.version !== null && !props.remove);
const hasPicture = computed(() => props.file !== null || stored.value);
const shownError = computed(() => localError.value || props.error);

function choose() {
    input.value?.click();
}

function onFile(event) {
    const file = event.target.files?.[0];
    if (!file) return;
    localError.value = '';
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) localError.value = t('shopping.image.err_type');
    else if (file.size > props.maxBytes) localError.value = t('shopping.image.err_size');
    if (localError.value === '') {
        emit('update:file', file);
        emit('update:remove', false);
    }
    if (input.value) input.value.value = '';
}

function clear() {
    localError.value = '';
    if (props.file) emit('update:file', null);
    else emit('update:remove', true);
}
</script>

<template>
    <div class="image-field">
        <div class="image-field__preview" :aria-busy="busy ? 'true' : undefined">
            <img v-if="preview" :src="preview" alt="" class="image-field__img" :class="{ 'image-field__img--logo': kind === 'store' }" />
            <CatalogImage v-else :kind="kind" :id="remove ? null : id" :version="remove ? null : version" :size="96" />
            <UiSkeleton v-if="busy" class="image-field__skeleton" width="96px" height="96px" />
        </div>
        <div class="image-field__side">
            <span class="image-field__label">{{ label }}</span>
            <div class="image-field__buttons">
                <input
                    ref="input"
                    type="file"
                    class="visually-hidden"
                    accept="image/jpeg,image/png,image/webp"
                    tabindex="-1"
                    :aria-describedby="hintId"
                    :aria-label="hasPicture ? t('shopping.image.replace') : t('shopping.image.upload')"
                    data-testid="image-input"
                    @change="onFile"
                />
                <UiButton variant="secondary" :icon="Camera" :disabled="busy" :aria-describedby="hintId" @click="choose">
                    {{ hasPicture ? t('shopping.image.replace') : t('shopping.image.upload') }}
                </UiButton>
                <UiButton v-if="hasPicture" variant="ghost" :icon="Trash2" :disabled="busy" data-testid="image-remove" @click="clear">
                    {{ t('shopping.image.remove') }}
                </UiButton>
            </div>
            <p :id="hintId" class="image-field__hint">{{ hint }}</p>
            <p v-if="busy" class="visually-hidden" role="status">{{ t('shopping.image.uploading') }}</p>
            <UiAlert v-if="shownError" variant="danger" live class="image-field__alert" data-testid="image-error">{{ shownError }}</UiAlert>
        </div>
    </div>
</template>

<style scoped>
.image-field { container-type: inline-size; display: flex; flex-wrap: wrap; align-items: flex-start; gap: var(--space-4); margin-bottom: var(--space-4); }
.image-field__preview { position: relative; flex: none; width: 96px; height: 96px; line-height: 0; }
.image-field__img { display: block; width: 96px; height: 96px; object-fit: cover; border-radius: var(--radius-md); background: var(--color-surface-2); box-shadow: inset 0 0 0 1px var(--color-border); }
.image-field__img--logo { object-fit: contain; }
.image-field__skeleton { position: absolute; inset: 0; border-radius: var(--radius-md); opacity: 0.6; }
.image-field__side { flex: 1 1 220px; min-width: 0; }
.image-field__label { display: block; margin-block-end: var(--space-2); font-size: var(--text-sm); font-weight: 600; }
.image-field__buttons { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.image-field__hint { margin: var(--space-2) 0 0; color: var(--color-text-muted); font-size: var(--text-sm); }
.image-field__alert { margin-block-start: var(--space-3); }
</style>
