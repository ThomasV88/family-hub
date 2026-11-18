<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Ban, Check, Clock, Hourglass, Loader, CircleX } from '@lucide/vue';
import UiTag from '../../Components/ui/UiTag.vue';
import { itemView } from './batchState.js';

// The documents of the batch with a live status each. The current one carries aria-current; choosing another one is
// the page's job (it saves the cursor on the server). Titles wrap, status text never gets an ellipsis.
const props = defineProps({
    items: { type: Array, default: () => [] },
    currentId: { type: String, default: null },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['select']);
const { t, locale } = useI18n();
const appLocale = computed(() => ({ 'sk-SK': 'sk', 'pl-PL': 'pl', 'en-GB': 'en' }[locale.value] ?? locale.value));
const ICONS = { loader: Loader, hourglass: Hourglass, check: Check, x: CircleX, ban: Ban, clock: Clock };

const rows = computed(() => props.items.map((item) => {
    const view = itemView(item, appLocale.value);
    return {
        item,
        icon: ICONS[view.icon] ?? Clock,
        variant: view.variant,
        text: view.plural === null ? t(view.labelKey, view.params) : t(view.labelKey, view.params, view.plural),
    };
}));
</script>

<template>
    <ul class="batch-list" data-testid="batch-items">
        <li v-for="row in rows" :key="row.item.id" class="batch-list__item">
            <button
                type="button"
                class="batch-list__row"
                :aria-current="row.item.id === currentId ? 'true' : undefined"
                :disabled="disabled"
                :data-state="row.item.state"
                data-testid="batch-item"
                @click="emit('select', row.item.id)"
            >
                <span class="batch-list__pos">{{ row.item.position }}</span>
                <span class="batch-list__main">
                    <span class="batch-list__title">{{ row.item.title }}</span>
                    <UiTag :variant="row.variant" :icon="row.icon" class="batch-list__tag" data-testid="batch-item-state">{{ row.text }}</UiTag>
                    <span v-if="row.item.duplicateOf" class="batch-list__note" data-testid="batch-item-duplicate">{{ t('health.batch.already_stored') }}</span>
                </span>
            </button>
        </li>
    </ul>
</template>

<style scoped>
.batch-list { list-style: none; margin: 0; padding: 0; }
.batch-list__item + .batch-list__item { border-top: 1px solid var(--color-border); }
.batch-list__row { display: flex; align-items: flex-start; gap: var(--space-3); width: 100%; min-height: 44px; padding: var(--space-2) var(--space-3); background: none; border: 0; border-inline-start: 3px solid transparent; font: inherit; color: inherit; text-align: start; cursor: pointer; }
.batch-list__row:hover { background: var(--color-surface-2); }
.batch-list__row[aria-current='true'] { border-inline-start-color: var(--color-primary); background: var(--color-surface-2); }
.batch-list__row:focus-visible { outline: 2px solid var(--color-focus); outline-offset: -2px; }
.batch-list__pos { flex: none; min-width: 1.5em; color: var(--color-text-muted); font-size: var(--text-sm); font-variant-numeric: tabular-nums; }
.batch-list__main { flex: 1; min-width: 0; display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-1); }
.batch-list__title { overflow-wrap: anywhere; font-weight: 600; }
.batch-list__tag { white-space: normal; }
.batch-list__note { font-size: var(--text-sm); color: var(--color-text-muted); }
</style>
