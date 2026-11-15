<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import UiButton from '../../Components/ui/UiButton.vue';
import UiField from '../../Components/ui/UiField.vue';
import UiSelect from '../../Components/ui/UiSelect.vue';

// Step 1. The File object is held by the parent in memory only; this component never reads its content.
const props = defineProps({
    accounts: { type: Array, required: true },
    profiles: { type: Array, default: () => [] },
    account: { type: String, default: '' },
    profile: { type: String, default: '' },
    file: { type: Object, default: null },
    fileError: { type: String, default: '' },
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['update:account', 'update:profile', 'file', 'read']);
const { t } = useI18n();

const input = ref(null);
const accountOptions = computed(() => [{ value: '', label: '' }, ...props.accounts.map((a) => ({ value: a.id, label: `${a.name} (${a.currency})` }))]);
const profileOptions = computed(() => [
    ...props.profiles.map((p) => ({ value: p.id, label: p.name })),
    { value: '', label: t('finance.imp.profile_new') },
]);
const ready = computed(() => Boolean(props.file) && props.account !== '' && !props.fileError);

function onChange(event) {
    emit('file', event.target.files?.[0] ?? null);
}
defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <form class="imp-step" data-testid="import-step-file" @submit.prevent="ready && emit('read')">
        <UiField :label="t('finance.imp.account')" required>
            <UiSelect :model-value="account" :options="accountOptions" @update:model-value="emit('update:account', $event)" />
        </UiField>
        <UiField :label="t('finance.imp.profile')">
            <UiSelect :model-value="profile" :options="profileOptions" @update:model-value="emit('update:profile', $event)" />
        </UiField>
        <UiField id="imp-file" :label="t('finance.imp.file')" :hint="t('finance.imp.file_note')" :error="fileError" required>
            <input id="imp-file" ref="input" class="imp-file" type="file" accept=".csv,.txt,text/csv" @change="onChange" />
        </UiField>
        <div class="imp-step__footer">
            <UiButton variant="primary" type="submit" :disabled="!ready" :loading="busy">{{ t('finance.imp.read') }}</UiButton>
        </div>
    </form>
</template>

<style scoped>
.imp-step { display: flex; flex-direction: column; gap: var(--space-4); max-width: 560px; }
.imp-step__footer { display: flex; gap: var(--space-2); }
.imp-file { min-height: 44px; padding-block: var(--space-2); max-width: 100%; color: var(--color-text); }
</style>
