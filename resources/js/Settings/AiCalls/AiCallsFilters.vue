<script setup>
import { computed, reactive, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiField from '../../Components/ui/UiField.vue';
import UiSelect from '../../Components/ui/UiSelect.vue';
import UiButton from '../../Components/ui/UiButton.vue';

const STATUSES = ['queued', 'running', 'succeeded', 'failed', 'cancelled'];

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({ people: [], members: [] }) },
});
const emit = defineEmits(['change', 'clear']);
const { t } = useI18n();

const form = reactive({ person: '', member: '', status: '' });
watch(() => props.filters, (f) => {
    form.person = f?.person ? String(f.person) : '';
    form.member = f?.member ? String(f.member) : '';
    form.status = f?.status ?? '';
}, { immediate: true, deep: true });

const personOptions = computed(() => [
    { value: '', label: t('settings.ai_calls.all_people') },
    ...(props.options.people ?? []).map((p) => ({ value: String(p.id), label: p.name })),
]);
const memberOptions = computed(() => [
    { value: '', label: t('settings.ai_calls.all_members') },
    ...(props.options.members ?? []).map((m) => ({ value: String(m.id), label: m.name })),
]);
const statusOptions = computed(() => [
    { value: '', label: t('settings.ai_calls.all_statuses') },
    ...STATUSES.map((s) => ({ value: s, label: t(`settings.ai_calls.status_${s}`) })),
    { value: 'quota', label: t('settings.ai_calls.filter_quota') },
]);
const anySet = computed(() => Boolean(form.person || form.member || form.status));

function set(key, value) {
    form[key] = value;
    emit('change', { person: form.person, member: form.member, status: form.status });
}
</script>

<template>
    <div class="ai-filters" role="group" :aria-label="t('settings.ai_calls.filter_label')">
        <UiField :label="t('settings.ai_calls.person')">
            <UiSelect :model-value="form.person" :options="personOptions" @update:model-value="set('person', $event)" />
        </UiField>
        <UiField :label="t('settings.ai_calls.member')">
            <UiSelect :model-value="form.member" :options="memberOptions" @update:model-value="set('member', $event)" />
        </UiField>
        <UiField :label="t('settings.ai_calls.status')">
            <UiSelect :model-value="form.status" :options="statusOptions" @update:model-value="set('status', $event)" />
        </UiField>
        <div v-if="anySet" class="ai-filters__clear">
            <UiButton variant="ghost" @click="emit('clear')">{{ t('settings.ai_calls.clear') }}</UiButton>
        </div>
    </div>
</template>

<style scoped>
.ai-filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 180px), 1fr)); gap: var(--space-4); align-items: end; margin-bottom: var(--space-6); }
.ai-filters__clear { display: flex; align-items: end; }
</style>
