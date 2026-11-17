<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useShell } from './shellState.js';

const props = defineProps({
    module: { type: Object, required: true },
    current: { type: Boolean, default: false },
    // 'side' = sidebar row, 'bottom' = bottom bar tab
    variant: { type: String, default: 'side' },
    // Rail mode: label becomes visually hidden text plus a visual tooltip.
    rail: { type: Boolean, default: false },
    // Bottom bar density by item count: 'normal' | 'dense' | 'tight'
    density: { type: String, default: 'normal' },
});
const { t } = useI18n();
const shell = useShell();
const dense = computed(() => props.variant === 'bottom' && props.density !== 'normal');
const tight = computed(() => props.variant === 'bottom' && props.density === 'tight');
// At 7 or more items the labels cannot all fit whole: only the current item shows its label, the others are icon-only.
const iconOnly = computed(() => tight.value && !props.current);

function open(event) {
    shell.openComingSoon(props.module.key, event.currentTarget);
}
</script>

<template>
    <li :class="['nav-item', { 'nav-item--current': current }]">
        <component
            :is="module.href ? Link : 'button'"
            :href="module.href ?? undefined"
            :type="module.href ? undefined : 'button'"
            :aria-current="current ? 'page' : undefined"
            :aria-label="iconOnly ? t(module.labelKey) : undefined"
            :class="['nav-item__link', `nav-item__link--${variant}`, { 'is-current': current, 'is-soon': !module.href, 'is-rail': rail, 'is-dense': dense, 'is-tight': tight }]"
            @click="module.href ? undefined : open($event)"
        >
            <component :is="module.icon" :size="dense ? 20 : 24" :stroke-width="1.75" aria-hidden="true" class="nav-item__icon" />
            <span :class="['nav-item__label', { 'visually-hidden': rail || iconOnly }]" :title="variant === 'bottom' ? t(module.labelKey) : undefined">{{ t(module.labelKey) }}</span>
            <span v-if="rail" class="nav-item__tip" aria-hidden="true">{{ t(module.labelKey) }}</span>
        </component>
    </li>
</template>

<style scoped>
.nav-item { list-style: none; position: relative; }
.nav-item__link {
    position: relative;
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-height: 44px;
    width: 100%;
    padding: var(--space-2) var(--space-3);
    border: 0;
    border-radius: var(--radius-md);
    background: transparent;
    color: var(--color-text);
    font: inherit;
    font-size: var(--text-sm);
    font-weight: 600;
    line-height: 1.3;
    text-align: start;
    text-decoration: none;
    cursor: pointer;
}
.nav-item__link:hover { background: var(--color-surface-2); }
.nav-item__link.is-soon { color: var(--color-text-muted); }
.nav-item__link.is-current { background: var(--color-primary-soft); color: var(--color-primary); }
.nav-item__link--side.is-current::before {
    content: '';
    position: absolute;
    inset-block: var(--space-2);
    inset-inline-start: 0;
    width: 2px;
    background: var(--color-primary);
    border-radius: var(--radius-full);
}
.nav-item__link.is-rail { justify-content: center; padding-inline: 0; }
.nav-item__icon { flex: none; }
.nav-item__link--side .nav-item__label { overflow-wrap: anywhere; min-width: 0; }

.nav-item__link--bottom {
    flex-direction: column;
    justify-content: flex-start;
    gap: 2px;
    padding: var(--space-1);
    text-align: center;
    min-width: 0;
}
.nav-item__link--bottom .nav-item__label {
    display: block;
    min-width: 0;
    max-width: 100%;
    text-align: center;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    overflow-wrap: normal;
    word-break: normal;
    hyphens: manual;
}
.nav-item__link--bottom.is-dense { padding-inline: 1px; }
.nav-item__link--bottom.is-dense .nav-item__label { font-size: 0.6875rem; letter-spacing: -0.02em; }
.nav-item__link--bottom.is-tight { padding-inline: 2px; }
.nav-item__link--bottom.is-tight .nav-item__label { font-size: 0.6875rem; letter-spacing: -0.02em; }
/* Current item at 7+: the whole label, never ellipsised. */
.nav-item--current > .nav-item__link--bottom.is-tight .nav-item__label { overflow: visible; text-overflow: clip; }

.nav-item__tip {
    position: absolute;
    inset-inline-start: calc(100% + var(--space-2));
    inset-block-start: 50%;
    transform: translateY(-50%);
    z-index: 30;
    padding: var(--space-1) var(--space-2);
    border-radius: var(--radius-sm);
    background: var(--color-text);
    color: var(--color-bg);
    font-size: var(--text-xs);
    font-weight: 600;
    width: max-content;
    max-width: 200px;
    overflow-wrap: anywhere;
    opacity: 0;
    pointer-events: none;
}
.nav-item__link:hover .nav-item__tip,
.nav-item__link:focus-visible .nav-item__tip { opacity: 1; }
</style>
