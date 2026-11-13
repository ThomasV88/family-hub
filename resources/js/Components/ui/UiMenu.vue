<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { Check } from '@lucide/vue';
import UiButton from './UiButton.vue';

const props = defineProps({
    label: { type: String, required: true },
    icon: { type: [Object, Function], default: null },
    // { type: 'item', key, label, icon? } | { type: 'radio-group', key, label, value, options: [{ value, label }] }
    // | { type: 'header', key, label, sublabel? } (non-interactive) | { type: 'separator', key }
    items: { type: Array, required: true },
});
const emit = defineEmits(['select']);
const uid = useId();
const open = ref(false);
const root = ref(null);
const trigger = ref(null);
const active = ref(0);
const els = ref([]);

// Flat list of actionable entries; radio groups contribute one entry per option.
const flat = computed(() => {
    const out = [];
    for (const item of props.items) {
        if (item.type === 'header' || item.type === 'separator') continue;
        if (item.type === 'radio-group') for (const option of item.options) out.push({ key: item.key, value: option.value });
        else out.push({ key: item.key });
    }
    return out;
});
const indexOf = (key, value) => flat.value.findIndex((e) => e.key === key && e.value === value);

async function focusItem(index) {
    const count = flat.value.length;
    active.value = (index + count) % count;
    await nextTick();
    els.value[active.value]?.focus();
}
async function openMenu() {
    open.value = true;
    await focusItem(0);
}
function closeMenu(returnFocus = false) {
    open.value = false;
    if (returnFocus) nextTick(() => trigger.value?.$el?.focus());
}
function choose(entry) {
    emit('select', entry.value === undefined ? { key: entry.key } : { key: entry.key, value: entry.value });
    closeMenu(true);
}
function onKeydown(event) {
    if (event.key === 'ArrowDown') focusItem(active.value + 1);
    else if (event.key === 'ArrowUp') focusItem(active.value - 1);
    else if (event.key === 'Home') focusItem(0);
    else if (event.key === 'End') focusItem(flat.value.length - 1);
    else if (event.key === 'Escape') closeMenu(true);
    else if (event.key === 'Tab') closeMenu(false);
    else return;
    if (event.key !== 'Tab') event.preventDefault();
}
function onTriggerKeydown(event) {
    if (event.key === 'ArrowDown' && !open.value) {
        event.preventDefault();
        openMenu();
    }
}
function onDocumentClick(event) {
    if (root.value && !root.value.contains(event.target)) closeMenu(false);
}
watch(open, (value) => {
    if (value) document.addEventListener('click', onDocumentClick);
    else document.removeEventListener('click', onDocumentClick);
});
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick));
</script>

<template>
    <div ref="root" class="ui-menu">
        <UiButton
            ref="trigger"
            variant="secondary"
            :icon="icon"
            aria-haspopup="menu"
            :aria-expanded="open ? 'true' : 'false'"
            :aria-controls="open ? `${uid}-menu` : undefined"
            @click="open ? closeMenu(false) : openMenu()"
            @keydown="onTriggerKeydown"
        >
            {{ label }}
        </UiButton>
        <div v-if="open" :id="`${uid}-menu`" class="ui-menu__popover" role="menu" :aria-label="label" @keydown="onKeydown">
            <template v-for="(item, i) in items" :key="item.key">
                <div v-if="item.type === 'separator' || (i > 0 && items[i - 1].type !== 'separator')" class="ui-menu__separator" role="separator"></div>
                <div v-if="item.type === 'header'" class="ui-menu__header" data-testid="menu-header">
                    <span class="ui-menu__header-name">{{ item.label }}</span>
                    <span v-if="item.sublabel" class="ui-menu__header-sub">{{ item.sublabel }}</span>
                </div>
                <button
                    v-else-if="item.type === 'item'"
                    :ref="(el) => (els[indexOf(item.key)] = el)"
                    type="button"
                    role="menuitem"
                    class="ui-menu__item"
                    :tabindex="active === indexOf(item.key) ? 0 : -1"
                    @click="choose({ key: item.key })"
                >
                    <component :is="item.icon" v-if="item.icon" :size="20" :stroke-width="1.75" aria-hidden="true" />
                    <span>{{ item.label }}</span>
                </button>
                <div v-else-if="item.type === 'radio-group'" role="group" :aria-label="item.label">
                    <div class="ui-menu__group-label" aria-hidden="true">{{ item.label }}</div>
                    <button
                        v-for="option in item.options"
                        :key="option.value"
                        :ref="(el) => (els[indexOf(item.key, option.value)] = el)"
                        type="button"
                        role="menuitemradio"
                        class="ui-menu__item"
                        :aria-checked="item.value === option.value ? 'true' : 'false'"
                        :tabindex="active === indexOf(item.key, option.value) ? 0 : -1"
                        @click="choose({ key: item.key, value: option.value })"
                    >
                        <span class="ui-menu__check"><Check v-if="item.value === option.value" :size="20" :stroke-width="1.75" aria-hidden="true" /></span>
                        <span>{{ option.label }}</span>
                    </button>
                </div>
            </template>
        </div>
    </div>
</template>

<style scoped>
.ui-menu { position: relative; display: inline-block; }
.ui-menu__popover {
    position: absolute;
    inset-block-start: calc(100% + var(--space-1));
    inset-inline-end: 0;
    z-index: 40;
    min-width: 220px;
    padding: var(--space-1);
    background: var(--color-surface);
    color: var(--color-text);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-md);
}
.ui-menu__item {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    width: 100%;
    min-height: var(--control-md);
    padding: var(--space-2) var(--space-3);
    background: none;
    border: 0;
    border-radius: var(--radius-sm);
    color: inherit;
    font: inherit;
    font-size: var(--text-base);
    text-align: start;
    cursor: pointer;
}
@media (pointer: coarse) { .ui-menu__item { min-height: var(--control-touch); } }
.ui-menu__item:hover { background: var(--color-surface-2); }
.ui-menu__item:focus-visible { outline: 2px solid var(--color-focus); outline-offset: -2px; }
.ui-menu__check { display: inline-flex; width: 20px; height: 20px; }
.ui-menu__group-label { padding: var(--space-2) var(--space-3) var(--space-1); color: var(--color-text-muted); font-size: var(--text-sm); font-weight: 600; }
.ui-menu__header { display: flex; flex-direction: column; padding: var(--space-2) var(--space-3); min-width: 0; }
.ui-menu__header-name { font-size: var(--text-base); font-weight: 600; overflow-wrap: anywhere; }
.ui-menu__header-sub { font-size: var(--text-sm); font-weight: 400; color: var(--color-text-muted); overflow-wrap: anywhere; }
.ui-menu__separator { height: 1px; margin-block: var(--space-1); background: var(--color-border); }
</style>
