<script setup>
import { computed } from 'vue';
import { Info, Check, TriangleAlert, CircleAlert, X } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiButton from './UiButton.vue';

const props = defineProps({
    variant: { type: String, default: 'info', validator: (v) => ['info', 'success', 'warning', 'danger'].includes(v) },
    dismissible: { type: Boolean, default: false },
    live: { type: Boolean, default: false },
});
defineEmits(['dismiss']);
const { t } = useI18n();

const icons = { info: Info, success: Check, warning: TriangleAlert, danger: CircleAlert };
const icon = computed(() => icons[props.variant]);
const role = computed(() => (props.live ? (props.variant === 'danger' ? 'alert' : 'status') : undefined));
</script>

<template>
    <div :class="['ui-alert', `ui-alert--${variant}`]" :role="role">
        <component :is="icon" :size="20" :stroke-width="1.75" aria-hidden="true" class="ui-alert__icon" />
        <div class="ui-alert__body"><slot /></div>
        <div v-if="$slots.actions" class="ui-alert__actions"><slot name="actions" /></div>
        <UiButton v-if="dismissible" variant="ghost" size="sm" icon-only :icon="X" :label="t('ui.dismiss')" @click="$emit('dismiss')" />
    </div>
</template>

<style scoped>
.ui-alert {
    --ui-alert-bar: var(--color-text-muted);
    display: flex;
    align-items: center;
    gap: var(--space-3);
    padding: var(--space-3) var(--space-4);
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-inline-start: 3px solid var(--ui-alert-bar);
    border-radius: var(--radius-md);
    color: var(--color-text);
    font-size: var(--text-base);
    line-height: 1.5;
}
.ui-alert--success { --ui-alert-bar: var(--color-success); }
.ui-alert--warning { --ui-alert-bar: var(--color-warn-text); }
.ui-alert--danger { --ui-alert-bar: var(--color-danger); }
.ui-alert__icon { flex: none; }
.ui-alert__body { flex: 1; min-width: 0; }
.ui-alert__actions { display: flex; gap: var(--space-2); }
</style>
