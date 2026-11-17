<script setup>
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Ellipsis, ImagePlus, Star } from '@lucide/vue';
import UiAlert from '../ui/UiAlert.vue';
import UiConfirmDialog from '../ui/UiConfirmDialog.vue';
import UiMenu from '../ui/UiMenu.vue';
import UiSkeleton from '../ui/UiSkeleton.vue';
import UiTag from '../ui/UiTag.vue';
import { ACCEPTED_TYPES, makeMain, movePhoto, photoUrl, removePhoto, uploadPhoto } from './recipePhotosApi.js';
import { isGone } from './recipeFailure.js';

// The photos of a recipe. Create mode (recipeId null): the files stay in this browser as the `queue` (v-model:queue, items
// { key, file, url, isMain }) and the form uploads them after the recipe is saved. Edit mode: every action goes to the server at
// once and the list is replaced from its answer. The first photo is main unless the member picks another.
const props = defineProps({
    recipeId: { type: Number, default: null },
    // Photos of the saved recipe: [{ id, position, isMain, version }].
    photos: { type: Array, default: () => [] },
    queue: { type: Array, default: () => [] },
    max: { type: Number, default: 10 },
    maxBytes: { type: Number, default: 5 * 1024 * 1024 },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:queue', 'gone']);
const { t } = useI18n();

const uid = useId();
const hintId = `${uid}-hint`;
const input = ref(null);
const saved = ref([...props.photos]);
const busy = ref(0);
const uploading = ref(0);
const error = ref('');
const removeIndex = ref(null);
const editing = computed(() => props.recipeId !== null);

watch(() => props.photos, (list) => { saved.value = [...list]; });

const items = computed(() => (editing.value
    ? saved.value.map((p) => ({ key: `p${p.id}`, url: photoUrl(props.recipeId, p), isMain: p.isMain }))
    : props.queue.map((q) => ({ key: q.key, url: q.url, isMain: q.isMain }))));
const count = computed(() => items.value.length);
const atCap = computed(() => count.value >= props.max);
const mb = computed(() => Math.round(props.maxBytes / 1024 / 1024));
const locked = computed(() => props.disabled || busy.value > 0);

// ---- Create mode: local files with object URLs (revoked when removed and on unmount).
let serial = 0;
const urls = new Set();
function objectUrl(file) {
    if (typeof URL === 'undefined' || typeof URL.createObjectURL !== 'function') return null;
    const url = URL.createObjectURL(file);
    urls.add(url);

    return url;
}
function revoke(url) {
    if (url && urls.delete(url)) URL.revokeObjectURL(url);
}
onBeforeUnmount(() => { for (const url of [...urls]) revoke(url); });

const setQueue = (next) => {
    const withMain = next.length > 0 && !next.some((q) => q.isMain) ? next.map((q, i) => ({ ...q, isMain: i === 0 })) : next;
    emit('update:queue', withMain);
};

// ---- Failures.
function report(result) {
    if (isGone(result.status)) emit('gone');
    error.value = t(result.messageKey);
}

// ---- Choosing files.
function choose() {
    input.value?.click();
}
function validate(file) {
    if (!ACCEPTED_TYPES.includes(file.type)) return t('recipes.image.err_type');
    if (file.size > props.maxBytes) return t('recipes.image.err_size');

    return '';
}
async function onFiles(event) {
    const files = [...(event.target.files ?? [])];
    if (input.value) input.value.value = '';
    if (files.length === 0 || locked.value) return;
    error.value = '';
    const room = props.max - count.value;
    const accepted = [];
    for (const file of files) {
        const problem = validate(file);
        if (problem) { error.value = problem; continue; }
        accepted.push(file);
    }
    if (accepted.length > room) error.value = t('recipes.photos.cap', { n: props.max }, props.max);
    const taking = accepted.slice(0, Math.max(room, 0));
    if (taking.length === 0) return;

    if (!editing.value) {
        setQueue([...props.queue, ...taking.map((file) => ({ key: `q${Date.now().toString(36)}-${serial++}`, file, url: objectUrl(file), isMain: false }))]);

        return;
    }
    busy.value += 1;
    try {
        for (const file of taking) {
            uploading.value += 1;
            const result = await uploadPhoto(props.recipeId, file);
            uploading.value -= 1;
            if (result.ok) {
                saved.value = result.photos;
            } else {
                report(result);
                // 403, 404 and 429 refuse the rest too; a bad file (422) does not.
                if (result.status !== 422) break;
            }
        }
    } finally {
        uploading.value = 0;
        busy.value -= 1;
    }
}

// ---- Photo actions.
async function server(call) {
    if (locked.value) return;
    error.value = '';
    busy.value += 1;
    try {
        const result = await call();
        if (result.ok) saved.value = result.photos;
        else report(result);
    } finally {
        busy.value -= 1;
    }
}
function setMain(index) {
    if (!editing.value) { setQueue(props.queue.map((q, i) => ({ ...q, isMain: i === index }))); return; }
    server(() => makeMain(props.recipeId, saved.value[index].id));
}
function move(index, by) {
    const to = index + by;
    if (to < 0 || to >= count.value) return;
    if (!editing.value) {
        const next = [...props.queue];
        [next[index], next[to]] = [next[to], next[index]];
        setQueue(next);

        return;
    }
    server(() => movePhoto(props.recipeId, saved.value[index].id, by < 0 ? 'earlier' : 'later'));
}
function confirmRemove() {
    const index = removeIndex.value;
    removeIndex.value = null;
    if (index === null) return;
    if (!editing.value) {
        const gone = props.queue[index];
        revoke(gone?.url);
        const next = props.queue.filter((_, i) => i !== index);
        // The main photo was removed: the first one that is left becomes main.
        setQueue(gone?.isMain ? next.map((q) => ({ ...q, isMain: false })) : next);

        return;
    }
    server(() => removePhoto(props.recipeId, saved.value[index].id));
}

function menuItems(index) {
    return [
        ...(items.value[index].isMain ? [] : [{ type: 'item', key: 'main', label: t('recipes.photos.make_main') }]),
        ...(index > 0 ? [{ type: 'item', key: 'earlier', label: t('recipes.photos.move_earlier') }] : []),
        ...(index < count.value - 1 ? [{ type: 'item', key: 'later', label: t('recipes.photos.move_later') }] : []),
        { type: 'separator', key: 'sep' },
        { type: 'item', key: 'remove', label: t('recipes.photos.remove') },
    ];
}
function onMenu(index, { key }) {
    if (key === 'main') setMain(index);
    else if (key === 'earlier') move(index, -1);
    else if (key === 'later') move(index, 1);
    else if (key === 'remove') removeIndex.value = index;
}
</script>

<template>
    <div class="rec-photos" :aria-busy="busy > 0 ? 'true' : undefined">
        <ul class="rec-photos__grid" :aria-label="t('recipes.photos.title')">
            <li v-for="(item, index) in items" :key="item.key" class="rec-photos__item" :class="{ 'rec-photos__item--main': item.isMain }" data-testid="photo-tile">
                <div class="rec-photos__thumb">
                    <img v-if="item.url" :src="item.url" alt="" class="rec-photos__img" />
                    <span v-if="item.isMain" class="rec-photos__star" aria-hidden="true"><Star :size="16" :stroke-width="1.75" /></span>
                    <UiMenu
                        class="rec-photos__menu" :label="t('recipes.photos.actions', { n: index + 1 })" :icon="Ellipsis" icon-only trigger-variant="ghost"
                        :items="menuItems(index)" @select="onMenu(index, $event)"
                    />
                </div>
                <UiTag v-if="item.isMain" variant="neutral" class="rec-photos__tag">{{ t('recipes.photos.main') }}</UiTag>
            </li>
            <li v-for="n in uploading" :key="`up${n}`" class="rec-photos__item" data-testid="photo-uploading">
                <UiSkeleton width="96px" height="96px" />
            </li>
            <li v-if="!atCap" class="rec-photos__item">
                <button type="button" class="rec-photos__add" :disabled="locked" :aria-describedby="hintId" data-testid="photo-add" @click="choose">
                    <ImagePlus :size="24" :stroke-width="1.75" aria-hidden="true" />
                    <span>{{ t('recipes.photos.add') }}</span>
                </button>
            </li>
        </ul>
        <input
            ref="input" type="file" class="visually-hidden" multiple accept="image/jpeg,image/png,image/webp" tabindex="-1"
            :aria-label="t('recipes.photos.add')" :aria-describedby="hintId" :disabled="locked" data-testid="photo-input" @change="onFiles"
        />
        <p :id="hintId" class="rec-photos__hint">{{ atCap ? t('recipes.photos.cap', { n: max }, max) : t('recipes.photos.hint', { n: max, mb }, max) }}</p>
        <p v-if="uploading > 0" class="visually-hidden" role="status">{{ t('recipes.photos.uploading') }}</p>
        <div aria-live="polite">
            <UiAlert v-if="error" variant="danger" data-testid="photo-error">{{ error }}</UiAlert>
        </div>
        <UiConfirmDialog
            :open="removeIndex !== null"
            :title="t('recipes.photos.remove_title')"
            :body="t('recipes.photos.remove_body')"
            :confirm-label="t('recipes.photos.remove_confirm')"
            :cancel-label="t('recipes.photos.remove_cancel')"
            @update:open="(v) => { if (!v) removeIndex = null; }"
            @confirm="confirmRemove"
        />
    </div>
</template>

<style scoped>
.rec-photos { display: flex; flex-direction: column; gap: var(--space-2); margin-block-end: var(--space-4); }
.rec-photos__grid { display: flex; flex-wrap: wrap; gap: var(--space-3); margin: 0; padding: 0; list-style: none; }
.rec-photos__item { display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-1); }
.rec-photos__thumb { position: relative; width: 96px; height: 96px; border-radius: var(--radius-md); background: var(--color-surface-2); box-shadow: inset 0 0 0 1px var(--color-border); }
.rec-photos__item--main .rec-photos__thumb { box-shadow: 0 0 0 2px var(--color-primary); }
.rec-photos__img { display: block; width: 96px; height: 96px; object-fit: cover; border-radius: var(--radius-md); }
.rec-photos__star { position: absolute; inset-block-start: 4px; inset-inline-start: 4px; display: inline-flex; padding: 3px; border-radius: 50%; background: var(--color-primary); color: var(--color-on-primary); }
.rec-photos__menu { position: absolute; inset-block-end: 0; inset-inline-end: 0; }
.rec-photos__menu :deep(.ui-button) { background: var(--color-surface); border-radius: var(--radius-md); }
.rec-photos__add { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: var(--space-1); width: 96px; height: 96px; padding: var(--space-2); border: 2px dashed var(--color-border); border-radius: var(--radius-md); background: transparent; color: var(--color-text); font: inherit; font-size: var(--text-sm); text-align: center; cursor: pointer; }
.rec-photos__add:hover:not(:disabled) { background: var(--color-surface-2); }
.rec-photos__add:disabled { opacity: 0.6; cursor: not-allowed; }
.rec-photos__hint { margin: 0; color: var(--color-text-muted); font-size: var(--text-sm); }
</style>
