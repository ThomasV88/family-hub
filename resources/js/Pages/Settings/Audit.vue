<script setup>
import { computed, nextTick, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import SettingsLayout from '../../Layouts/SettingsLayout.vue';
import UiCard from '../../Components/ui/UiCard.vue';
import UiField from '../../Components/ui/UiField.vue';
import UiSelect from '../../Components/ui/UiSelect.vue';
import UiInput from '../../Components/ui/UiInput.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiEmptyState from '../../Components/ui/UiEmptyState.vue';
import AuditTable from '../../Components/Settings/AuditTable.vue';

const props = defineProps({
    sections: { type: Array, default: () => [] },
    entries: { type: Array, default: () => [] },
    pagination: { type: Object, default: () => ({ page: 1, lastPage: 1, total: 0 }) },
    members: { type: Array, default: () => [] },
    groups: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    error: { type: String, default: null },
});

const { t } = useI18n();
const table = ref(null);

const form = reactive({
    member: props.filters.member ? String(props.filters.member) : '',
    event: props.filters.event ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

const memberOptions = computed(() => [
    { value: '', label: t('audit.filter.all_members') },
    ...props.members.map((m) => ({ value: String(m.id), label: m.name })),
]);
const eventOptions = computed(() => [
    { value: '', label: t('audit.filter.all_events') },
    ...props.groups.map((g) => ({ value: g, label: t(`audit.event.${g}`) })),
]);

const query = (page) => {
    const q = {};
    for (const key of ['member', 'event', 'from', 'to']) {
        if (form[key]) q[key] = form[key];
    }
    if (page && page > 1) q.page = page;
    return q;
};

function apply() {
    router.get('/settings/audit', query(), { preserveState: true, preserveScroll: true });
}
function clear() {
    Object.assign(form, { member: '', event: '', from: '', to: '' });
    router.get('/settings/audit', {}, { preserveState: true });
}
function goTo(page) {
    router.get('/settings/audit', query(page), {
        preserveState: true,
        preserveScroll: true,
        onFinish: () => nextTick(() => table.value?.focusCaption()),
    });
}
</script>

<template>
    <Head :title="t('audit.title')" />
    <SettingsLayout :sections="sections" current="audit" title="audit.title">
        <p class="audit__intro">{{ t('audit.intro') }}</p>

        <UiCard class="audit__filters">
            <form class="audit__form" @submit.prevent="apply">
                <UiField :label="t('audit.filter.member')">
                    <UiSelect v-model="form.member" :options="memberOptions" />
                </UiField>
                <UiField :label="t('audit.filter.event')">
                    <UiSelect v-model="form.event" :options="eventOptions" />
                </UiField>
                <UiField :label="t('audit.filter.from')">
                    <UiInput v-model="form.from" type="date" />
                </UiField>
                <UiField :label="t('audit.filter.to')">
                    <UiInput v-model="form.to" type="date" />
                </UiField>
                <div class="audit__actions">
                    <UiButton type="submit">{{ t('audit.filter.apply') }}</UiButton>
                    <UiButton variant="ghost" @click="clear">{{ t('audit.filter.clear') }}</UiButton>
                </div>
            </form>
        </UiCard>

        <UiAlert v-if="error" variant="danger" live class="audit__alert">{{ t(error) }}</UiAlert>

        <UiEmptyState v-if="!entries.length" :heading-level="2" :title="t('audit.empty_title')" :body="t('audit.empty_body')">
            <template #action>
                <UiButton @click="clear">{{ t('audit.filter.clear') }}</UiButton>
            </template>
        </UiEmptyState>
        <AuditTable v-else ref="table" :entries="entries" :pagination="pagination" @page="goTo" />
    </SettingsLayout>
</template>

<style scoped>
.audit__intro { margin: 0 0 var(--space-4); color: var(--color-text-muted); }
.audit__filters { margin-bottom: var(--space-4); }
.audit__form { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: var(--space-3); align-items: end; }
.audit__actions { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.audit__alert { margin-bottom: var(--space-4); }
</style>
