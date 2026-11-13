<script setup>
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
});
const { t } = useI18n();
const shell = useShell();

function open(event) {
    shell.openComingSoon(props.module.key, event.currentTarget);
}
</script>

<template>
    <li class="nav-item">
        <component
            :is="module.href ? Link : 'button'"
            :href="module.href ?? undefined"
            :type="module.href ? undefined : 'button'"
            :aria-current="current ? 'page' : undefined"
            :class="['nav-item__link', `nav-item__link--${variant}`, { 'is-current': current, 'is-soon': !module.href, 'is-rail': rail }]"
            @click="module.href ? undefined : open($event)"
        >
            <component :is="module.icon" :size="24" :stroke-width="1.75" aria-hidden="true" class="nav-item__icon" />
            <span :class="['nav-item__label', { 'visually-hidden': rail }]">{{ t(module.labelKey) }}</span>
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
.nav-item__label { overflow-wrap: anywhere; min-width: 0; }

.nav-item__link--bottom {
    flex-direction: column;
    justify-content: flex-start;
    gap: 2px;
    padding: var(--space-1);
    text-align: center;
}
.nav-item__link--bottom .nav-item__label { text-align: center; }

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
