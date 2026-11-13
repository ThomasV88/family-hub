<script setup>
const props = defineProps({
    as: { type: String, default: 'static', validator: (v) => ['static', 'button', 'link'].includes(v) },
    href: { type: String, default: '' },
    title: { type: String, default: '' },
    selected: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['select']);
</script>

<template>
    <li :class="['ui-row', { 'is-selected': selected, 'is-disabled': disabled }]">
        <div v-if="$slots.leading" class="ui-row__leading"><slot name="leading" /></div>
        <button
            v-if="as === 'button'"
            type="button"
            class="ui-row__main ui-row__main--interactive"
            :title="title || undefined"
            :disabled="disabled"
            :aria-current="selected ? 'true' : undefined"
            @click="emit('select')"
        >
            <span class="ui-row__title"><slot /></span>
            <span v-if="$slots.meta" class="ui-row__meta"><slot name="meta" /></span>
        </button>
        <a
            v-else-if="as === 'link'"
            class="ui-row__main ui-row__main--interactive"
            :href="disabled ? undefined : href"
            :title="title || undefined"
            :aria-disabled="disabled ? 'true' : undefined"
            :aria-current="selected ? 'true' : undefined"
        >
            <span class="ui-row__title"><slot /></span>
            <span v-if="$slots.meta" class="ui-row__meta"><slot name="meta" /></span>
        </a>
        <div v-else class="ui-row__main" :aria-current="selected ? 'true' : undefined">
            <span class="ui-row__title" :title="title || undefined"><slot /></span>
            <span v-if="$slots.meta" class="ui-row__meta"><slot name="meta" /></span>
        </div>
        <div v-if="$slots.trailing" class="ui-row__trailing"><slot name="trailing" /></div>
    </li>
</template>

<style scoped>
.ui-row { display: flex; align-items: center; gap: var(--space-3); min-height: 44px; padding-inline: var(--space-3); }
.ui-row.is-selected { background: var(--color-primary-soft); }
.ui-row__leading, .ui-row__trailing { display: flex; flex: none; align-items: center; }
.ui-row__main {
    display: flex;
    flex: 1;
    flex-direction: column;
    justify-content: center;
    min-width: 0;
    min-height: 44px;
    padding: var(--space-2) 0;
    border: 0;
    background: none;
    color: var(--color-text);
    font: inherit;
    font-size: var(--text-base);
    line-height: 1.5;
    text-align: start;
    text-decoration: none;
}
.ui-row__main--interactive { cursor: pointer; }
.ui-row__main--interactive:is(:focus-visible) { outline: 2px solid var(--color-focus); outline-offset: -2px; }
.ui-row:not(.is-selected):hover { background: var(--color-surface-2); }
.ui-row__title { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ui-row__meta { color: var(--color-text-muted); font-size: var(--text-sm); }
.ui-row.is-disabled { opacity: 0.5; }
.ui-row.is-disabled .ui-row__main { cursor: not-allowed; }
</style>
