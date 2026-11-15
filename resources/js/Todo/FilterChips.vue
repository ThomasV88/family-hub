<script setup>
import { useI18n } from 'vue-i18n';
import UiChip from '../Components/ui/UiChip.vue';

defineProps({ modelValue: { type: String, default: 'all' } });
defineEmits(['update:modelValue']);
const { t } = useI18n();
const FILTERS = ['all', 'mine', 'shared', 'personal'];
</script>

<template>
    <div class="filters" role="group" :aria-label="t('todo.filter.label')">
        <UiChip v-for="f in FILTERS" :key="f" :model-value="modelValue === f" @update:model-value="$emit('update:modelValue', f)">
            {{ t(`todo.filter.${f}`) }}
        </UiChip>
    </div>
</template>

<style scoped>
.filters { display: flex; flex-wrap: wrap; gap: var(--space-2); }
</style>
