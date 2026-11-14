<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiConfirmDialog from '../Components/ui/UiConfirmDialog.vue';
import UiDetailPanel from '../Components/ui/UiDetailPanel.vue';
import UiField from '../Components/ui/UiField.vue';
import UiInput from '../Components/ui/UiInput.vue';
import UiSelect from '../Components/ui/UiSelect.vue';
import UiTextarea from '../Components/ui/UiTextarea.vue';

// Create form for a person. The draft lives in component memory only: no Inertia form-state persistence, no browser storage.
const props = defineProps({
    open: { type: Boolean, default: false },
});
const emit = defineEmits(['close']);
const { t, te } = useI18n();

const BLOOD = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
const blank = () => ({ name: '', birth_date: '', sex: 'unspecified', blood_type: '', notes: '' });
const form = ref(blank());
const initial = ref(JSON.stringify(blank()));
const errors = ref({});
const serverError = ref(false);
const processing = ref(false);
const discardOpen = ref(false);

const sexOptions = computed(() => ['female', 'male', 'unspecified'].map((value) => ({ value, label: t(`health.sex.${value}`) })));
const bloodOptions = computed(() => [
    { value: '', label: t('health.blood.unknown') },
    ...BLOOD.map((value) => ({ value, label: t(`health.blood.${value.toLowerCase().replace('+', '_pos').replace('-', '_neg')}`) })),
]);
const dirty = computed(() => JSON.stringify(form.value) !== initial.value);

watch(() => props.open, (open) => {
    if (!open) return;
    form.value = blank();
    initial.value = JSON.stringify(form.value);
    errors.value = {};
    serverError.value = false;
}, { immediate: true });

function requestClose() {
    if (dirty.value) {
        discardOpen.value = true;
        return;
    }
    emit('close');
}

const FIELDS = ['name', 'birth_date', 'sex', 'blood_type', 'notes'];
const message = (value) => (typeof value === 'string' && te(value) ? t(value) : t('health.error.load'));

function save() {
    if (form.value.name.trim() === '') {
        errors.value = { name: t('health.person.err_name') };
        return;
    }
    errors.value = {};
    router.post('/health/people', {
        name: form.value.name,
        birth_date: form.value.birth_date || null,
        sex: form.value.sex || null,
        blood_type: form.value.blood_type || null,
        notes: form.value.notes || null,
    }, {
        preserveScroll: true,
        onStart: () => { processing.value = true; serverError.value = false; },
        onSuccess: () => emit('close'),
        onError: (errs) => {
            const mapped = {};
            let unknown = false;
            for (const [key, value] of Object.entries(errs ?? {})) {
                if (FIELDS.includes(key)) mapped[key] = message(value);
                else unknown = true;
            }
            errors.value = mapped;
            serverError.value = unknown || Object.keys(mapped).length === 0;
        },
        onHttpException: () => { serverError.value = true; },
        onNetworkError: () => { serverError.value = true; },
        onFinish: () => { processing.value = false; },
    });
}
const onEnter = (event) => { if (event.target?.tagName === 'INPUT') event.preventDefault(); };
</script>

<template>
    <UiDetailPanel :open="open" :title="t('health.person.title_new')" @update:open="(v) => (v ? null : requestClose())">
        <form novalidate data-testid="person-form" class="person-form" @submit.prevent="save" @keydown.enter="onEnter">
            <UiAlert v-if="serverError" variant="danger" live>{{ t('health.error.load') }}</UiAlert>

            <UiField :label="`${t('health.person.name')} ${t('health.required')}`" :error="errors.name">
                <UiInput v-model="form.name" autocomplete="off" maxlength="120" />
            </UiField>
            <UiField :label="t('health.person.birth_date')" :error="errors.birth_date">
                <UiInput v-model="form.birth_date" type="date" />
            </UiField>
            <UiField :label="t('health.person.sex')" :error="errors.sex">
                <UiSelect v-model="form.sex" :options="sexOptions" />
            </UiField>
            <UiField :label="t('health.person.blood_type')" :error="errors.blood_type">
                <UiSelect v-model="form.blood_type" :options="bloodOptions" />
            </UiField>
            <UiField :label="t('health.person.notes')" :error="errors.notes">
                <UiTextarea v-model="form.notes" :rows="4" />
            </UiField>
        </form>
        <template #footer>
            <UiButton variant="secondary" @click="requestClose">{{ t('ui.close') }}</UiButton>
            <UiButton variant="primary" :loading="processing" @click="save">{{ t('health.person.save') }}</UiButton>
        </template>
    </UiDetailPanel>

    <UiConfirmDialog
        v-model:open="discardOpen"
        :title="t('health.discard.title')"
        :body="t('finance.discard.body')"
        :confirm-label="t('health.discard.discard')"
        :cancel-label="t('health.discard.keep')"
        @confirm="emit('close')"
    />
</template>

<style scoped>
.person-form { display: flex; flex-direction: column; }
</style>
