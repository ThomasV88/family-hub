<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiDialog from '../Components/ui/UiDialog.vue';
import HelpTip from '../Components/ui/HelpTip.vue';
import { useToast } from '../Components/ui/useToast.js';
import { httpHandlers } from './failure.js';
import { NAME_MAX, charLength, clip } from './limits.js';
import { formatQuantity } from './units.js';

// Finish shopping. Without unavailable items a plain confirmation (carry_name null). With unavailable items the owner's question
// "Some items could not be bought. Create a new shopping list from them?": "Create new list" sends carry_name "{name} (unbought)",
// "No, just finish" sends null. One request either way; the server archives the list and, with a name, creates the open carry list.
// list: { id, name }; unavailable: items with state unavailable; units: id -> { key, names }.
const props = defineProps({
    open: { type: Boolean, default: false },
    list: { type: Object, default: null },
    unavailable: { type: Array, default: () => [] },
    units: { type: Array, default: () => [] },
    nameMax: { type: Number, default: NAME_MAX },
    // Resolves when every mark of the list has been sent; the request waits for it so Finish never races a mark.
    wait: { type: Function, default: null },
});
const emit = defineEmits(['update:open']);
const { t, locale } = useI18n();

const VISIBLE = 5;
const form = useForm({ carry_name: null });
const failed = ref(false);
const failedKey = ref('shopping.finish.failed');
const toast = useToast();
const safe = ref(null);
const initialFocus = () => safe.value?.$el ?? null;
const carry = computed(() => props.unavailable.length > 0);
const unitById = computed(() => Object.fromEntries(props.units.map((u) => [u.id, u])));
const shown = computed(() => props.unavailable.slice(0, VISIBLE));
const more = computed(() => Math.max(0, props.unavailable.length - VISIBLE));
const lineQuantity = (item) => formatQuantity(item.quantity, unitById.value[item.unitId] ?? null, t, locale.value);

// "{name} (unbought)" in the interface language, the list name cut (by characters, never inside an emoji) so the whole stays
// within the list name limit.
function carryName() {
    const name = props.list?.name ?? '';
    const full = t('shopping.finish.carry_name', { name });
    if (charLength(full) <= props.nameMax) return full;
    const room = props.nameMax - (charLength(full) - charLength(name));

    return t('shopping.finish.carry_name', { name: clip(name, Math.max(1, room)).trimEnd() });
}

watch(() => props.open, (open) => { if (open) { failed.value = false; failedKey.value = 'shopping.finish.failed'; } });

const close = () => emit('update:open', false);

const sending = ref(false);
async function send(name) {
    if (!props.list || form.processing || sending.value) return;
    sending.value = true;
    failed.value = false;
    failedKey.value = 'shopping.finish.failed';
    if (props.wait) {
        try {
            await props.wait();
        } finally {
            sending.value = false;
        }
    } else {
        sending.value = false;
    }
    form.carry_name = name;
    form.post(`/shopping/lists/${props.list.id}/finish`, {
        preserveScroll: true,
        onSuccess: close,
        onError: () => { failed.value = true; },
        ...httpHandlers((key, response) => {
            if ([403, 404, 409].includes(response?.status)) {
                // The list is not what this page shows any more: the page reloads, so say why and close.
                toast.show(t(key));
                close();

                return;
            }
            failedKey.value = key === 'shopping.error.save_failed' ? 'shopping.finish.failed' : key;
            failed.value = true;
        }, 'shopping.finish.failed'),
    });
}
</script>

<template>
    <UiDialog :open="open" :title="carry ? t('shopping.finish.carry_title') : t('shopping.finish.title')" :initial-focus="initialFocus" :dismissable="!form.processing" @update:open="emit('update:open', $event)">
        <div class="finish" data-testid="finish-dialog">
            <div aria-live="polite">
                <UiAlert v-if="failed" variant="danger" data-testid="finish-failed">{{ t(failedKey) }}</UiAlert>
            </div>
            <template v-if="carry">
                <p class="finish__lead">
                    <span>{{ t('shopping.finish.question') }}</span>
                    <HelpTip topic="shopping.carry_over" :label="t('shopping.finish.carry_title')" />
                </p>
                <ul class="finish__items" :aria-label="t('shopping.finish.items')" data-testid="finish-items">
                    <li v-for="item in shown" :key="item.id" class="finish__item">
                        <span class="finish__name">{{ item.name }}</span>
                        <span v-if="lineQuantity(item)" class="finish__qty">{{ lineQuantity(item) }}</span>
                    </li>
                    <li v-if="more > 0" class="finish__item finish__more" data-testid="finish-more">{{ t('shopping.finish.more', { n: more }) }}</li>
                </ul>
                <p class="finish__note">{{ t('shopping.finish.note') }}</p>
            </template>
            <p v-else class="finish__lead">{{ t('shopping.finish.body') }}</p>
        </div>
        <template #footer>
            <template v-if="carry">
                <div class="finish__footer">
                    <UiButton variant="primary" :loading="form.processing || sending" :disabled="form.processing || sending" data-testid="finish-create" @click="send(carryName())">{{ t('shopping.finish.create') }}</UiButton>
                    <UiButton variant="secondary" :disabled="form.processing || sending" data-testid="finish-only" @click="send(null)">{{ t('shopping.finish.just_finish') }}</UiButton>
                    <UiButton ref="safe" variant="ghost" :disabled="form.processing" data-testid="finish-cancel" @click="close">{{ t('shopping.finish.cancel') }}</UiButton>
                </div>
            </template>
            <template v-else>
                <UiButton ref="safe" variant="secondary" :disabled="form.processing" data-testid="finish-cancel" @click="close">{{ t('shopping.finish.not_yet') }}</UiButton>
                <UiButton variant="primary" :loading="form.processing || sending" :disabled="form.processing || sending" data-testid="finish-confirm" @click="send(null)">{{ t('shopping.finish.button') }}</UiButton>
            </template>
        </template>
    </UiDialog>
</template>

<style scoped>
.finish { display: flex; flex-direction: column; gap: var(--space-3); }
.finish__lead { display: flex; align-items: flex-start; gap: var(--space-2); margin: 0; font-size: var(--text-base); }
.finish__items { list-style: none; margin: 0; padding: var(--space-2) var(--space-3); display: flex; flex-direction: column; gap: var(--space-1); background: var(--color-surface-2); border-radius: var(--radius-md); }
.finish__item { display: flex; justify-content: space-between; gap: var(--space-3); font-size: var(--text-sm); }
.finish__name { overflow-wrap: anywhere; font-weight: 600; }
.finish__qty { white-space: nowrap; color: var(--color-text-muted); }
.finish__more { color: var(--color-text-muted); }
.finish__note { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.finish__footer { display: flex; flex-direction: column; gap: var(--space-2); width: 100%; }
@media (min-width: 640px) { .finish__footer { flex-direction: row-reverse; justify-content: flex-start; } }
</style>
