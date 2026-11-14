<script setup>
import { computed, inject, nextTick, ref, useId, watch, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';
import { X } from '@lucide/vue';
import UiButton from './UiButton.vue';
import { useFocusTrap } from './useFocusTrap.js';
import { lockScroll, unlockScroll } from './useScrollLock.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, required: true },
    mode: { type: String, default: null, validator: (v) => v === null || ['sheet', 'overlay', 'docked'].includes(v) },
});
const emit = defineEmits(['update:open']);
const { t } = useI18n();
const injected = inject('fh-panel-mode', 'overlay');
const effective = computed(() => props.mode ?? unwrap(injected));
const modal = computed(() => effective.value !== 'docked');
const titleId = useId();
const root = ref(null);
const trap = useFocusTrap(root);
let locked = false;

function unwrap(v) {
    return v && typeof v === 'object' && 'value' in v ? v.value : v;
}
function close() {
    emit('update:open', false);
}
function lock() {
    if (modal.value && !locked) {
        locked = true;
        lockScroll();
    }
}
function unlock() {
    if (locked) {
        locked = false;
        unlockScroll();
    }
}

let dockedPrevious = null;
let activeAs = null; // 'trap' | 'docked' | null: how the current container was activated

function activate() {
    if (modal.value) {
        activeAs = 'trap';
        trap.activate();
        return;
    }
    // Docked panel is non-modal: move focus to the heading without trapping Tab.
    activeAs = 'docked';
    dockedPrevious = document.activeElement;
    document.getElementById(titleId)?.focus();
}
function deactivate() {
    const was = activeAs;
    activeAs = null;
    if (was === 'trap') {
        trap.deactivate();
    } else if (was === 'docked') {
        if (dockedPrevious && document.contains(dockedPrevious)) dockedPrevious.focus();
        dockedPrevious = null;
    }
}

// Watches open and the effective modality together: when the layout switches between overlay and
// docked while open, tear down the old trap/lock and set them up again on the new container.
watch(
    [() => props.open, modal],
    async ([open]) => {
        deactivate();
        unlock();
        if (open) {
            lock();
            await nextTick();
            if (props.open) activate();
        }
    },
    { immediate: true, flush: 'post' },
);
onBeforeUnmount(() => {
    deactivate();
    unlock();
});
</script>

<template>
    <aside v-if="open && !modal" :ref="(el) => (root = el)" :class="['ui-panel', 'ui-panel--docked']" :aria-labelledby="titleId" @keydown.esc.stop="close">
        <header class="ui-panel__header">
            <h2 :id="titleId" class="ui-panel__title" tabindex="-1">{{ title }}</h2>
            <UiButton variant="ghost" icon-only :icon="X" :label="t('ui.close')" @click="close" />
        </header>
        <div class="ui-panel__body"><slot /></div>
        <footer v-if="$slots.footer" class="ui-panel__footer"><slot name="footer" /></footer>
    </aside>
    <Teleport v-else-if="modal" to="body">
        <Transition :name="`ui-panel-${effective}`">
            <div v-if="open" :class="['ui-panel__scrim', `ui-panel__scrim--${effective}`]" @click.self="close" @keydown.esc.stop="close">
                <div :ref="(el) => (root = el)" :class="['ui-panel', `ui-panel--${effective}`]" role="dialog" aria-modal="true" :aria-labelledby="titleId" tabindex="-1">
                    <span v-if="effective === 'sheet'" class="ui-panel__handle" aria-hidden="true"></span>
                    <div class="ui-panel__header">
                        <h2 :id="titleId" class="ui-panel__title">{{ title }}</h2>
                        <UiButton variant="ghost" icon-only :icon="X" :label="t('ui.close')" @click="close" />
                    </div>
                    <div class="ui-panel__body"><slot /></div>
                    <div v-if="$slots.footer" class="ui-panel__footer"><slot name="footer" /></div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.ui-panel { display: flex; flex-direction: column; background: var(--color-surface); color: var(--color-text); overflow: hidden; }
.ui-panel:focus, .ui-panel__title:focus { outline: none; }
.ui-panel__header { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--space-3); padding: var(--space-4) var(--space-4) var(--space-2); }
.ui-panel__title { margin: 0; font-size: var(--text-xl); font-weight: 600; line-height: 1.3; }
.ui-panel__body { flex: 1; overflow-y: auto; padding: var(--space-2) var(--space-4) var(--space-4); }
.ui-panel__footer { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: var(--space-3); padding: var(--space-3) var(--space-4); border-block-start: 1px solid var(--color-border); }
.ui-panel__scrim { position: fixed; inset: 0; z-index: 50; background: rgba(0, 0, 0, 0.4); display: flex; }
.ui-panel__scrim--sheet { align-items: flex-end; }
.ui-panel__scrim--overlay { justify-content: flex-end; }
.ui-panel--sheet {
    width: 100%;
    max-height: 85vh;
    max-height: 85dvh;
    border-start-start-radius: var(--radius-lg);
    border-start-end-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
    padding-block-end: env(safe-area-inset-bottom);
}
.ui-panel--overlay { width: var(--shell-panel); max-width: calc(100% - 32px); height: 100%; box-shadow: var(--shadow-lg); }
.ui-panel--docked { width: var(--shell-panel); height: 100%; border-inline-start: 1px solid var(--color-border); }
.ui-panel__handle { align-self: center; width: 40px; height: 4px; margin-block-start: var(--space-2); border-radius: var(--radius-full); background: var(--color-border-strong); }
.ui-panel-sheet-enter-active, .ui-panel-sheet-leave-active, .ui-panel-overlay-enter-active, .ui-panel-overlay-leave-active { transition: opacity var(--duration); }
.ui-panel-sheet-enter-active .ui-panel, .ui-panel-sheet-leave-active .ui-panel,
.ui-panel-overlay-enter-active .ui-panel, .ui-panel-overlay-leave-active .ui-panel { transition: transform var(--duration); }
.ui-panel-sheet-enter-from, .ui-panel-sheet-leave-to, .ui-panel-overlay-enter-from, .ui-panel-overlay-leave-to { opacity: 0; }
.ui-panel-sheet-enter-from .ui-panel, .ui-panel-sheet-leave-to .ui-panel { transform: translateY(100%); }
.ui-panel-overlay-enter-from .ui-panel, .ui-panel-overlay-leave-to .ui-panel { transform: translateX(100%); }
[dir='rtl'] .ui-panel-overlay-enter-from .ui-panel, [dir='rtl'] .ui-panel-overlay-leave-to .ui-panel { transform: translateX(-100%); }
@media (prefers-reduced-motion: reduce) {
    .ui-panel-sheet-enter-active, .ui-panel-sheet-leave-active, .ui-panel-overlay-enter-active, .ui-panel-overlay-leave-active,
    .ui-panel-sheet-enter-active .ui-panel, .ui-panel-sheet-leave-active .ui-panel,
    .ui-panel-overlay-enter-active .ui-panel, .ui-panel-overlay-leave-active .ui-panel { transition: none; }
}
</style>
