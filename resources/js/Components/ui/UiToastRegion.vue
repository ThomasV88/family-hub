<script setup>
import { onBeforeUnmount, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { X } from '@lucide/vue';
import UiButton from './UiButton.vue';
import { useToast } from './useToast.js';

const { t } = useI18n();
const { toasts, dismiss } = useToast();
// id -> { timer, remaining, started }
const timers = new Map();

function start(id, remaining) {
    clear(id);
    const entry = { remaining, started: Date.now(), timer: setTimeout(() => { timers.delete(id); dismiss(id); }, remaining) };
    timers.set(id, entry);
}
function clear(id) {
    const entry = timers.get(id);
    if (entry) clearTimeout(entry.timer);
}
function pause(id) {
    const entry = timers.get(id);
    if (!entry || entry.paused) return;
    clearTimeout(entry.timer);
    entry.remaining -= Date.now() - entry.started;
    entry.paused = true;
}
function resume(id) {
    const entry = timers.get(id);
    if (!entry || !entry.paused || entry.hover || entry.focus) return;
    start(id, Math.max(entry.remaining, 1000));
}
// Hover and keyboard focus hold the toast independently; it resumes only when neither is active.
function hold(id, kind, on) {
    const entry = timers.get(id);
    if (!entry) return;
    entry[kind] = on;
    if (on) pause(id);
    else resume(id);
}
function onFocusOut(id, event) {
    if (event.currentTarget.contains(event.relatedTarget)) return;
    hold(id, 'focus', false);
}

watch(
    () => toasts.map((toast) => toast.id),
    (ids) => {
        for (const toast of toasts) if (!timers.has(toast.id)) start(toast.id, toast.duration);
        for (const id of [...timers.keys()]) if (!ids.includes(id)) { clear(id); timers.delete(id); }
    },
    { immediate: true, flush: 'sync' },
);
onBeforeUnmount(() => timers.forEach((entry) => clearTimeout(entry.timer)));
</script>

<template>
    <div class="ui-toasts" role="status" aria-live="polite">
        <div
            v-for="toast in toasts"
            :key="toast.id"
            class="ui-toast"
            @mouseenter="hold(toast.id, 'hover', true)"
            @mouseleave="hold(toast.id, 'hover', false)"
            @focusin="hold(toast.id, 'focus', true)"
            @focusout="onFocusOut(toast.id, $event)"
        >
            <span class="ui-toast__message">{{ toast.message }}</span>
            <UiButton variant="ghost" size="sm" icon-only :icon="X" :label="t('ui.close')" @click="dismiss(toast.id)" />
        </div>
    </div>
</template>

<style scoped>
.ui-toasts {
    position: fixed;
    inset-inline: 0;
    inset-block-end: var(--fh-toast-offset, 16px);
    z-index: 60;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-2);
    pointer-events: none;
}
.ui-toast {
    pointer-events: auto;
    display: flex;
    align-items: center;
    gap: var(--space-3);
    max-width: calc(100% - 32px);
    padding: var(--space-2) var(--space-3) var(--space-2) var(--space-4);
    background: var(--color-surface);
    color: var(--color-text);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-md);
    font-size: var(--text-base);
}
</style>
