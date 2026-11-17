<script setup>
import { useI18n } from 'vue-i18n';
import { Ban, Check, CircleCheck, CircleSlash } from '@lucide/vue';

// The pressed state is never shown by colour alone: the pressed button also swaps to a different (circled) icon, gets a thick
// double border and a visible tick-like marker, and has aria-pressed.
// Bought / Unavailable as a segmented pair for the responsible person while shopping. The active button is pressed;
// tapping it again asks for "pending" (undo, no confirmation). The parent sends the absolute target state and shows it at once.
// state: 'pending' | 'bought' | 'unavailable'. Emits mark(target).
const props = defineProps({
    name: { type: String, required: true },
    state: { type: String, default: 'pending' },
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['mark']);
const { t } = useI18n();

function press(target) {
    if (props.busy) return;
    emit('mark', props.state === target ? 'pending' : target);
}
</script>

<template>
    <div class="mark" role="group" :aria-label="t('shopping.mark.group', { name })" data-testid="mark-buttons">
        <button type="button" class="mark__btn mark__btn--bought" :aria-pressed="state === 'bought'" :aria-label="t('shopping.mark.bought')" data-testid="mark-bought" @click="press('bought')">
            <CircleCheck v-if="state === 'bought'" :size="20" :stroke-width="2.5" aria-hidden="true" data-testid="mark-bought-on" />
            <Check v-else :size="20" :stroke-width="2" aria-hidden="true" />
            <span class="mark__label">{{ t('shopping.mark.bought') }}</span>
        </button>
        <button type="button" class="mark__btn mark__btn--unavailable" :aria-pressed="state === 'unavailable'" :aria-label="t('shopping.mark.unavailable')" data-testid="mark-unavailable" @click="press('unavailable')">
            <CircleSlash v-if="state === 'unavailable'" :size="20" :stroke-width="2.5" aria-hidden="true" data-testid="mark-unavailable-on" />
            <Ban v-else :size="20" :stroke-width="2" aria-hidden="true" />
            <span class="mark__label">{{ t('shopping.mark.unavailable') }}</span>
        </button>
    </div>
</template>

<style scoped>
.mark { display: inline-flex; gap: var(--space-2); }
.mark__btn {
    display: inline-flex; align-items: center; justify-content: center; gap: var(--space-1);
    min-height: 44px; min-width: 44px; padding-inline: var(--space-3);
    border: 1px solid var(--color-border-strong); border-radius: var(--radius-md);
    background: var(--color-surface); color: var(--color-text);
    font: inherit; font-size: var(--text-sm); font-weight: 600; cursor: pointer;
}
.mark__btn:hover { background: var(--color-surface-2); }
.mark__btn:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.mark__btn[aria-pressed='true'] { font-weight: 700; box-shadow: inset 0 0 0 2px currentColor; border-width: 2px; }
.mark__btn--bought[aria-pressed='true'] { background: var(--color-primary); border-color: var(--color-primary); color: var(--color-on-primary); }
.mark__btn--unavailable[aria-pressed='true'] { background: var(--color-warn-bg); border-color: var(--color-warn-text); color: var(--color-warn-text); }
@media (max-width: 359px) {
    .mark__label { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
    .mark__btn { padding-inline: 0; }
}
</style>
