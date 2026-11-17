<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Camera, Trash2 } from '@lucide/vue';
import UiAvatar from '../ui/UiAvatar.vue';
import UiButton from '../ui/UiButton.vue';
import UiAlert from '../ui/UiAlert.vue';
import UiSkeleton from '../ui/UiSkeleton.vue';
import UiConfirmDialog from '../ui/UiConfirmDialog.vue';

// Avatar preview with Upload / Replace and Remove for one person's photo. Choosing a file uploads at once (the server
// re-encodes it, so there is no client cropping). Reused for children in the member panel.
const props = defineProps({
    userId: { type: Number, required: true },
    name: { type: String, required: true },
    // Version of the stored photo, or null when there is none.
    photoVersion: { type: Number, default: null },
    compact: { type: Boolean, default: false },
});
const { t } = useI18n();

const form = useForm({ photo: null });
const input = ref(null);
const preview = ref(null);
const confirming = ref(false);
const hintId = `photo-hint-${props.userId}`;

const hasPhoto = computed(() => props.photoVersion !== null && props.photoVersion !== undefined);
const size = computed(() => (props.compact ? 40 : 96));
const src = computed(() => preview.value ?? (hasPhoto.value ? `/people/${props.userId}/photo/${props.compact ? 'thumb' : 'full'}?v=${props.photoVersion}` : null));
const error = computed(() => {
    const key = form.errors?.photo;
    if (!key) return '';

    return String(key).startsWith('profile.') ? t(key) : String(key);
});

function revokePreview() {
    if (preview.value) URL.revokeObjectURL(preview.value);
    preview.value = null;
}
onBeforeUnmount(revokePreview);

function choose() {
    input.value?.click();
}

function onFile(event) {
    const file = event.target.files?.[0];
    if (!file) return;
    revokePreview();
    preview.value = URL.createObjectURL(file);
    form.clearErrors?.();
    form.photo = file;
    form.post(`/people/${props.userId}/photo`, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            revokePreview();
            form.photo = null;
            if (input.value) input.value.value = '';
        },
    });
}

function remove() {
    router.delete(`/people/${props.userId}/photo`, { preserveScroll: true });
}
</script>

<template>
    <div class="photo-editor" :class="{ 'photo-editor--compact': compact }">
        <div class="photo-editor__avatar" :aria-busy="form.processing ? 'true' : undefined">
            <UiAvatar :name="name" :size="size" :src="src" :tint="2" />
            <UiSkeleton v-if="form.processing" class="photo-editor__skeleton" :width="`${size}px`" :height="`${size}px`" />
        </div>
        <div class="photo-editor__side">
            <div class="photo-editor__buttons">
                <input
                    ref="input"
                    type="file"
                    class="visually-hidden"
                    accept="image/jpeg,image/png,image/webp"
                    tabindex="-1"
                    :aria-describedby="hintId"
                    :aria-label="hasPhoto ? t('profile.photo.replace') : t('profile.photo.upload')"
                    data-testid="photo-input"
                    @change="onFile"
                />
                <UiButton variant="secondary" :icon="Camera" :disabled="form.processing" :aria-describedby="hintId" @click="choose">
                    {{ hasPhoto ? t('profile.photo.replace') : t('profile.photo.upload') }}
                </UiButton>
                <UiButton v-if="hasPhoto" variant="ghost" :icon="Trash2" :disabled="form.processing" @click="confirming = true">
                    {{ t('profile.photo.remove') }}
                </UiButton>
            </div>
            <p :id="hintId" class="photo-editor__hint">{{ t('profile.photo.hint') }}</p>
            <p v-if="form.processing" class="visually-hidden" role="status">{{ t('profile.photo.uploading') }}</p>
            <UiAlert v-if="error" variant="danger" live class="photo-editor__alert" data-testid="photo-error">{{ error }}</UiAlert>
        </div>
        <UiConfirmDialog
            v-model:open="confirming"
            :title="t('profile.photo.remove_title')"
            :body="t('profile.photo.remove_body')"
            :confirm-label="t('profile.photo.remove_confirm')"
            :cancel-label="t('profile.photo.remove_cancel')"
            @confirm="remove"
        />
    </div>
</template>

<style scoped>
.photo-editor { container-type: inline-size; display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-4); }
.photo-editor__avatar { position: relative; flex: none; line-height: 0; }
.photo-editor__skeleton { position: absolute; inset: 0; border-radius: var(--radius-full); opacity: 0.6; }
.photo-editor__side { flex: 1 1 220px; min-width: 0; }
.photo-editor__buttons { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.photo-editor__hint { margin: var(--space-2) 0 0; color: var(--color-text-muted); font-size: var(--text-sm); }
.photo-editor__alert { margin-block-start: var(--space-3); }
@media (max-width: 480px) {
    .photo-editor { flex-direction: column; text-align: center; }
    .photo-editor__side { flex-basis: auto; width: 100%; }
    .photo-editor__buttons { justify-content: center; }
}
</style>
