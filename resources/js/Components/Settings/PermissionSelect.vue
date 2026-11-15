<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Ban, Eye, Pencil } from '@lucide/vue';
import UiSelect from '../ui/UiSelect.vue';

const props = defineProps({
    modelValue: { type: String, default: 'none' },
    ariaLabel: { type: String, required: true },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);
const { t } = useI18n();

const ICONS = { none: Ban, view: Eye, edit: Pencil };
const icon = computed(() => ICONS[props.modelValue] ?? Ban);
const options = computed(() => ['none', 'view', 'edit'].map((value) => ({ value, label: t(`permissions.level.${value}`) })));
</script>

<template>
    <span class="perm-select">
        <component :is="icon" :size="20" :stroke-width="1.75" aria-hidden="true" class="perm-select__icon" />
        <UiSelect :model-value="modelValue" :options="options" :disabled="disabled" :aria-label="ariaLabel" @update:model-value="emit('update:modelValue', $event)" />
    </span>
</template>

<style scoped>
.perm-select { display: inline-flex; align-items: center; gap: var(--space-2); min-width: 0; }
.perm-select__icon { flex: none; color: var(--color-text-muted); }
</style>
