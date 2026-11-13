<script setup>
import { nextTick, ref, useId, watch, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';
import { X } from '@lucide/vue';
import UiButton from './UiButton.vue';
import { useFocusTrap } from './useFocusTrap.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, required: true },
    // Function returning the element (or component instance) to focus first; defaults to the first focusable.
    initialFocus: { type: Function, default: null },
});
const emit = defineEmits(['update:open']);
const { t } = useI18n();
const titleId = useId();
const panel = ref(null);
const trap = useFocusTrap(panel, { initialFocus: () => props.initialFocus?.() });
let previousOverflow = null;

function close() {
    emit('update:open', false);
}

function lock() {
    if (previousOverflow === null) {
        previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
    }
}
function unlock() {
    if (previousOverflow !== null) {
        document.body.style.overflow = previousOverflow;
        previousOverflow = null;
    }
}

watch(
    () => props.open,
    async (open) => {
        if (open) {
            lock();
            await nextTick();
            trap.activate();
        } else {
            trap.deactivate();
            unlock();
        }
    },
    { immediate: true, flush: 'post' },
);
onBeforeUnmount(() => {
    if (props.open) trap.deactivate();
    unlock();
});
</script>

<template>
    <Teleport to="body">
        <Transition name="ui-dialog">
            <div v-if="open" class="ui-dialog__scrim" @click.self="close" @keydown.esc.stop="close">
                <div ref="panel" class="ui-dialog" role="dialog" aria-modal="true" :aria-labelledby="titleId" tabindex="-1">
                    <header class="ui-dialog__header">
                        <h2 :id="titleId" class="ui-dialog__title">{{ title }}</h2>
                        <UiButton variant="ghost" icon-only :icon="X" :label="t('ui.close')" @click="close" />
                    </header>
                    <div class="ui-dialog__body"><slot /></div>
                    <footer v-if="$slots.footer" class="ui-dialog__footer"><slot name="footer" /></footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.ui-dialog__scrim {
    position: fixed;
    inset: 0;
    z-index: 50;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, 0.4);
}
.ui-dialog {
    width: calc(100% - 32px);
    max-width: 480px;
    max-height: calc(100% - 32px);
    overflow-y: auto;
    padding: var(--space-6);
    background: var(--color-surface);
    color: var(--color-text);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
}
.ui-dialog:focus { outline: none; }
.ui-dialog__header { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--space-3); margin-block-end: var(--space-4); }
.ui-dialog__title { margin: 0; font-size: var(--text-xl); font-weight: 600; line-height: 1.3; }
.ui-dialog__footer { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: var(--space-3); margin-block-start: var(--space-6); }
.ui-dialog-enter-active, .ui-dialog-leave-active { transition: opacity 175ms; }
.ui-dialog-enter-active .ui-dialog, .ui-dialog-leave-active .ui-dialog { transition: transform 175ms; }
.ui-dialog-enter-from, .ui-dialog-leave-to { opacity: 0; }
.ui-dialog-enter-from .ui-dialog, .ui-dialog-leave-to .ui-dialog { transform: scale(0.96); }
@media (prefers-reduced-motion: reduce) {
    .ui-dialog-enter-active, .ui-dialog-leave-active, .ui-dialog-enter-active .ui-dialog, .ui-dialog-leave-active .ui-dialog { transition: none; }
}
</style>
