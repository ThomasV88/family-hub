<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Check, Trash2 } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiChip from '../Components/ui/UiChip.vue';
import UiConfirmDialog from '../Components/ui/UiConfirmDialog.vue';
import UiDetailPanel from '../Components/ui/UiDetailPanel.vue';
import UiField from '../Components/ui/UiField.vue';
import UiInput from '../Components/ui/UiInput.vue';
import UiSelect from '../Components/ui/UiSelect.vue';
import UiTextarea from '../Components/ui/UiTextarea.vue';
import LinksEditor from './LinksEditor.vue';
import WishImage from './WishImage.vue';
import { isValidPrice, normalizePrice } from './money.js';

// Create and edit form for the owner and designated managers. Props are the wish and the list owner only.
const props = defineProps({
    open: { type: Boolean, default: false },
    // 'create' | 'edit'
    mode: { type: String, default: 'edit' },
    wish: { type: Object, default: null },
    ownerId: { type: Number, required: true },
    ownerName: { type: String, default: '' },
    managing: { type: Boolean, default: false },
});
const emit = defineEmits(['close']);
const { t } = useI18n();

const CURRENCIES = ['EUR', 'PLN', 'CZK', 'USD', 'GBP'];
const PRIORITIES = ['top', 'love', 'nice'];

const creating = computed(() => props.mode === 'create' || !props.wish);
const received = computed(() => !creating.value && props.wish?.received === true);
const heading = computed(() => (creating.value ? (props.managing ? t('wishlist.panel.new_for', { name: props.ownerName }) : t('wishlist.panel.new')) : t('wishlist.panel.edit')));

function initial() {
    const w = props.wish;
    if (creating.value || !w) {
        return { title: '', notes: '', price: '', currency: 'EUR', size_notes: '', priority: 'love', urls: [] };
    }
    return {
        title: w.title ?? '',
        notes: w.notes ?? '',
        price: w.price ?? '',
        currency: w.currency ?? 'EUR',
        size_notes: w.size_notes ?? '',
        priority: w.priority ?? 'love',
        urls: (w.urls ?? []).map((u) => ({ url: u.url ?? '', label: u.label ?? '' })),
    };
}

const form = ref(initial());
const snapshot = ref(JSON.stringify(form.value));
const errors = ref({});
const linkErrors = ref({});
const serverError = ref(false);
const processing = ref(false);
const body = ref(null);
const titleField = ref(null);
const links = ref(null);
const prioRoot = ref(null);
const discardOpen = ref(false);
const deleteOpen = ref(false);
let pendingAfterDiscard = null;

const dirty = computed(() => JSON.stringify(form.value) !== snapshot.value);
const titleOk = computed(() => form.value.title.trim().length > 0 && form.value.title.length <= 120);
const priceOk = computed(() => form.value.price.trim() === '' || isValidPrice(form.value.price));
const canSave = computed(() => !received.value && (creating.value || dirty.value) && titleOk.value && !processing.value);

function reset() {
    form.value = initial();
    snapshot.value = JSON.stringify(form.value);
    errors.value = {};
    linkErrors.value = {};
    serverError.value = false;
}
watch(() => [props.mode, props.wish?.id], reset);
watch(() => props.wish, () => { if (!dirty.value) reset(); });
watch(() => props.open, async (open) => {
    if (open && creating.value) {
        await nextTick();
        setTimeout(() => titleField.value?.focus(), 50);
    }
}, { immediate: true });

function payload() {
    const f = form.value;
    return {
        ...(creating.value ? { owner_id: props.ownerId } : {}),
        title: f.title.trim(),
        notes: f.notes.trim() === '' ? null : f.notes,
        price: f.price.trim() === '' ? null : normalizePrice(f.price),
        currency: f.currency,
        size_notes: f.size_notes.trim() === '' ? null : f.size_notes.trim(),
        priority: f.priority,
        urls: f.urls.filter((row) => row.url.trim() !== '').map((row) => ({ url: row.url.trim(), label: row.label.trim() === '' ? null : row.label.trim() })),
    };
}

const message = (key) => (typeof key === 'string' && key.startsWith('wishlist.') ? t(key) : t('wishlist.error.save_failed'));
const focusFirstInvalid = () => nextTick(() => body.value?.querySelector('[aria-invalid="true"]')?.focus());

function visitOptions(onOk) {
    return {
        preserveScroll: true,
        onStart: () => { processing.value = true; serverError.value = false; errors.value = {}; linkErrors.value = {}; },
        onSuccess: onOk,
        onError: (errs) => {
            const mapped = {};
            const rows = {};
            for (const [key, value] of Object.entries(errs ?? {})) {
                const row = /^urls\.(\d+)/.exec(key);
                if (row) rows[Number(row[1])] = message(value);
                else if (key === 'urls') rows[0] = message(value);
                else if (['title', 'notes', 'price', 'currency', 'size_notes', 'priority'].includes(key)) mapped[key] = message(value);
                else serverError.value = true;
            }
            errors.value = mapped;
            linkErrors.value = rows;
            if (Object.keys(mapped).length === 0 && Object.keys(rows).length === 0) serverError.value = true;
            focusFirstInvalid();
        },
        onHttpException: () => { serverError.value = true; },
        onNetworkError: () => { serverError.value = true; },
        onFinish: () => { processing.value = false; },
    };
}

function save() {
    if (received.value) return;
    const next = {};
    if (!titleOk.value) next.title = t('wishlist.error.title_required');
    if (!priceOk.value) next.price = t('wishlist.error.price_invalid');
    const linksOk = links.value ? links.value.validateAll() : true;
    errors.value = next;
    if (Object.keys(next).length || !linksOk) {
        focusFirstInvalid();
        return;
    }
    if (!canSave.value) return;
    const sent = JSON.stringify(form.value);
    const done = () => {
        snapshot.value = sent;
        if (creating.value) emit('close');
    };
    if (creating.value) router.post('/wishlist/wishes', payload(), visitOptions(done));
    else router.patch(`/wishlist/wishes/${props.wish.id}`, payload(), visitOptions(done));
}

function markReceived() {
    router.post(`/wishlist/wishes/${props.wish.id}/received`, {}, visitOptions(() => emit('close')));
}
function confirmDelete() {
    router.delete(`/wishlist/wishes/${props.wish.id}`, visitOptions(() => emit('close')));
}

// Runs `then` now, or after the member confirms discarding unsaved edits.
function guard(then) {
    if (props.open && dirty.value && !received.value) {
        pendingAfterDiscard = then;
        discardOpen.value = true;
    } else {
        then();
    }
}
const requestClose = () => guard(() => emit('close'));
function discard() {
    const then = pendingAfterDiscard;
    pendingAfterDiscard = null;
    reset();
    then?.();
}
const keepEditing = () => { pendingAfterDiscard = null; };

// Priority is one roving radiogroup: arrow keys move and select.
function onPriorityKey(event, index) {
    const last = PRIORITIES.length - 1;
    const rtl = document.documentElement.dir === 'rtl';
    const step = { ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1, ArrowDown: 1, ArrowUp: -1 }[event.key];
    let target = null;
    if (step !== undefined) target = Math.min(last, Math.max(0, index + step));
    else if (event.key === 'Home') target = 0;
    else if (event.key === 'End') target = last;
    if (target === null) return;
    event.preventDefault();
    form.value.priority = PRIORITIES[target];
    nextTick(() => prioRoot.value?.querySelectorAll('button')[target]?.focus());
}

// Enter in the title (or any single-line field) never submits this multi-field form.
const onEnter = (event) => { if (event.target?.tagName === 'INPUT') event.preventDefault(); };

const currencyOptions = CURRENCIES.map((code) => ({ value: code, label: code }));
const imageHint = computed(() => !creating.value && ['none', 'failed'].includes(props.wish?.image?.status ?? 'none'));

defineExpose({ guard, dirty });
</script>

<template>
    <UiDetailPanel :open="open" :title="heading" @update:open="(v) => (v ? null : requestClose())">
        <div ref="body" data-testid="own-wish-panel">
            <form novalidate @submit.prevent="save" @keydown.enter="onEnter">
                <UiAlert v-if="serverError" variant="danger" live class="wish-panel__alert">{{ t('wishlist.error.save_failed') }}</UiAlert>

                <fieldset class="wish-panel__fields" :disabled="received">
                    <UiField :label="t('wishlist.field.title')" required :error="errors.title">
                        <UiInput ref="titleField" v-model="form.title" maxlength="120" autocomplete="off" />
                    </UiField>

                    <div class="wish-panel__block">
                        <WishImage :image="wish?.image ?? { status: 'none' }" size="panel" />
                        <p v-if="imageHint" class="wish-panel__hint">{{ t('wishlist.image.none') }}</p>
                    </div>

                    <LinksEditor ref="links" v-model="form.urls" :server-errors="linkErrors" />

                    <div class="wish-panel__price">
                        <UiField :label="t('wishlist.field.price')" :hint="t('wishlist.field.price_hint')" :error="errors.price" class="wish-panel__amount">
                            <UiInput v-model="form.price" inputmode="decimal" autocomplete="off" />
                        </UiField>
                        <UiField :label="t('wishlist.field.currency')" class="wish-panel__currency">
                            <UiSelect v-model="form.currency" :options="currencyOptions" />
                        </UiField>
                    </div>

                    <UiField :label="t('wishlist.field.variant')" :error="errors.size_notes">
                        <UiInput v-model="form.size_notes" :placeholder="t('wishlist.field.variant_ph')" maxlength="200" autocomplete="off" />
                    </UiField>

                    <div class="wish-panel__block">
                        <span class="wish-panel__label" aria-hidden="true">{{ t('wishlist.priority.label') }}</span>
                        <div ref="prioRoot" class="wish-panel__priority" role="radiogroup" :aria-label="t('wishlist.priority.label')">
                            <UiChip
                                v-for="(priority, index) in PRIORITIES"
                                :key="priority"
                                :model-value="form.priority === priority"
                                :tabindex="form.priority === priority ? 0 : -1"
                                @update:model-value="form.priority = priority"
                                @keydown="onPriorityKey($event, index)"
                            >{{ t(`wishlist.priority.${priority}`) }}</UiChip>
                        </div>
                    </div>

                    <UiField :label="t('wishlist.field.notes')" :error="errors.notes">
                        <UiTextarea v-model="form.notes" :rows="4" />
                    </UiField>
                </fieldset>
            </form>
        </div>

        <template #footer>
            <UiButton v-if="!creating" variant="ghost" :icon="Trash2" class="wish-panel__delete" @click="deleteOpen = true">{{ t('wishlist.delete.action') }}</UiButton>
            <UiButton v-if="!creating && !received" variant="secondary" :icon="Check" @click="markReceived">{{ t('wishlist.received.mark') }}</UiButton>
            <UiButton variant="secondary" @click="requestClose">{{ t('wishlist.close') }}</UiButton>
            <UiButton v-if="!received" variant="primary" :disabled="!canSave" :loading="processing" @click="save">{{ t('wishlist.save') }}</UiButton>
        </template>
    </UiDetailPanel>

    <UiConfirmDialog
        v-model:open="discardOpen"
        :title="t('wishlist.discard.title')"
        :body="t('wishlist.discard.body')"
        :confirm-label="t('wishlist.discard.confirm')"
        :cancel-label="t('wishlist.discard.keep')"
        @confirm="discard"
        @cancel="keepEditing"
    />
    <UiConfirmDialog
        v-model:open="deleteOpen"
        :title="t('wishlist.delete.action')"
        :body="t('wishlist.delete.body', { title: wish?.title ?? '' })"
        :confirm-label="t('wishlist.delete.action')"
        :cancel-label="t('wishlist.delete.keep')"
        @confirm="confirmDelete"
    />
</template>

<style scoped>
.wish-panel__alert { margin-bottom: var(--space-4); }
.wish-panel__fields { min-width: 0; margin: 0; padding: 0; border: 0; display: flex; flex-direction: column; gap: var(--space-4); }
.wish-panel__fields:disabled { opacity: 0.85; }
.wish-panel__block { display: flex; flex-direction: column; gap: var(--space-2); }
.wish-panel__hint { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.wish-panel__label { font-size: var(--text-sm); font-weight: 600; line-height: 1.4; }
.wish-panel__priority { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.wish-panel__price { display: flex; flex-wrap: wrap; align-items: flex-start; gap: var(--space-3); }
.wish-panel__amount { flex: 1 1 140px; min-width: 0; }
.wish-panel__currency { flex: 0 0 112px; }
@media (max-width: 340px) {
    .wish-panel__currency { flex: 1 1 100%; }
}
.wish-panel__delete { margin-inline-end: auto; color: var(--color-danger); }
</style>
