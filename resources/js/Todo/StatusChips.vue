<script setup>
import { nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import UiChip from '../Components/ui/UiChip.vue';

const props = defineProps({
    modelValue: { type: String, default: 'todo' },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);
const { t } = useI18n();
const STATUSES = ['todo', 'in_progress', 'done'];
const root = ref(null);

// One roving group: the selected chip is the Tab stop, arrow keys move and select.
function onKeydown(event, index) {
    const last = STATUSES.length - 1;
    const rtl = document.documentElement.dir === 'rtl';
    const next = { ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1, ArrowDown: 1, ArrowUp: -1 }[event.key];
    let target = null;
    if (next !== undefined) target = Math.min(last, Math.max(0, index + next));
    else if (event.key === 'Home') target = 0;
    else if (event.key === 'End') target = last;
    if (target === null) return;
    event.preventDefault();
    if (props.disabled) return;
    emit('update:modelValue', STATUSES[target]);
    nextTick(() => root.value?.querySelectorAll('button')[target]?.focus());
}
</script>

<template>
    <div ref="root" class="status-chips" role="radiogroup" :aria-label="t('todo.status.label')">
        <UiChip
            v-for="(status, index) in STATUSES"
            :key="status"
            :model-value="modelValue === status"
            :disabled="disabled"
            :tabindex="modelValue === status ? 0 : -1"
            @update:model-value="emit('update:modelValue', status)"
            @keydown="onKeydown($event, index)"
        >
            {{ t(`todo.status.${status}`) }}
        </UiChip>
    </div>
</template>

<style scoped>
.status-chips { display: flex; flex-wrap: wrap; gap: var(--space-2); }
</style>
