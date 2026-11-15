<script setup>
import { nextTick, ref, useId } from 'vue';

const props = defineProps({
    tabs: { type: Array, required: true },
    modelValue: { type: String, required: true },
    label: { type: String, required: true },
});
const emit = defineEmits(['update:modelValue']);
const uid = useId();
const buttons = ref([]);

const tabId = (key) => `${uid}-tab-${key}`;
const panelId = (key) => `${uid}-panel-${key}`;

async function select(index) {
    const count = props.tabs.length;
    const next = (index + count) % count;
    emit('update:modelValue', props.tabs[next].key);
    await nextTick();
    buttons.value[next]?.focus();
}

function onKeydown(event, index) {
    const rtl = getComputedStyle(event.currentTarget).direction === 'rtl';
    const forward = rtl ? 'ArrowLeft' : 'ArrowRight';
    const back = rtl ? 'ArrowRight' : 'ArrowLeft';
    if (event.key === forward) select(index + 1);
    else if (event.key === back) select(index - 1);
    else if (event.key === 'Home') select(0);
    else if (event.key === 'End') select(props.tabs.length - 1);
    else return;
    event.preventDefault();
}
</script>

<template>
    <div class="ui-tabs">
        <div class="ui-tabs__list" role="tablist" :aria-label="label">
            <button
                v-for="(tab, index) in tabs"
                :id="tabId(tab.key)"
                :key="tab.key"
                :ref="(el) => (buttons[index] = el)"
                type="button"
                role="tab"
                class="ui-tabs__tab"
                :aria-selected="tab.key === modelValue ? 'true' : 'false'"
                :aria-controls="panelId(tab.key)"
                :tabindex="tab.key === modelValue ? 0 : -1"
                @click="emit('update:modelValue', tab.key)"
                @keydown="onKeydown($event, index)"
            >
                {{ tab.label }}
            </button>
        </div>
        <div
            v-for="tab in tabs"
            v-show="tab.key === modelValue"
            :id="panelId(tab.key)"
            :key="tab.key"
            class="ui-tabs__panel"
            role="tabpanel"
            :aria-labelledby="tabId(tab.key)"
            tabindex="0"
        >
            <slot :name="`panel-${tab.key}`" />
        </div>
    </div>
</template>

<style scoped>
.ui-tabs__list { display: flex; gap: var(--space-4); overflow-x: auto; border-block-end: 1px solid var(--color-border); }
.ui-tabs__tab {
    flex: none;
    min-height: var(--control-touch);
    padding: var(--space-2) var(--space-1);
    background: none;
    border: 0;
    border-block-end: 2px solid transparent;
    margin-block-end: -1px;
    color: var(--color-text-muted);
    font: inherit;
    font-size: var(--text-base);
    font-weight: 600;
    white-space: nowrap;
    cursor: pointer;
}
.ui-tabs__tab:hover { color: var(--color-text); }
.ui-tabs__tab[aria-selected='true'] { color: var(--color-primary); border-block-end-color: var(--color-primary); }
.ui-tabs__tab:focus-visible, .ui-tabs__panel:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.ui-tabs__panel { padding-block-start: var(--space-4); }
</style>
