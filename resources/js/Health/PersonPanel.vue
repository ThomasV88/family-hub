<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiConfirmDialog from '../Components/ui/UiConfirmDialog.vue';
import UiDetailPanel from '../Components/ui/UiDetailPanel.vue';
import UiField from '../Components/ui/UiField.vue';
import UiInput from '../Components/ui/UiInput.vue';
import UiSelect from '../Components/ui/UiSelect.vue';
import UiTextarea from '../Components/ui/UiTextarea.vue';

// Create and edit form for a person, with up to 3 emergency contacts. The draft lives in component memory only: no Inertia form-state persistence, no browser storage.
const props = defineProps({
    open: { type: Boolean, default: false },
    person: { type: Object, default: null },
});
const emit = defineEmits(['close']);
const { t, te } = useI18n();

const BLOOD = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
const MAX_CONTACTS = 3;
const blankContact = () => ({ name: '', relation: '', phone: '' });
const blank = () => ({ name: '', birth_date: '', sex: 'unspecified', blood_type: '', notes: '', contacts: [] });
const fromPerson = (p) => ({
    name: p.name ?? '',
    birth_date: p.birthDate ?? '',
    sex: p.sex ?? 'unspecified',
    blood_type: p.bloodType ?? '',
    notes: p.notes ?? '',
    contacts: (p.contacts ?? []).slice(0, MAX_CONTACTS).map((c) => ({ name: c.name ?? '', relation: c.relation ?? '', phone: c.phone ?? '' })),
});
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
const editing = computed(() => props.person !== null);
const title = computed(() => (editing.value ? t('health.person.title_edit') : t('health.person.title_new')));
const dirty = computed(() => JSON.stringify(form.value) !== initial.value);

watch(() => [props.open, props.person?.id], () => {
    if (!props.open) return;
    form.value = props.person ? fromPerson(props.person) : blank();
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
const CONTACT_KEY = /^contacts\.(\d+)\.(name|relation|phone)$/;

function addContact() {
    if (form.value.contacts.length < MAX_CONTACTS) form.value.contacts.push(blankContact());
}
function removeContact(index) {
    form.value.contacts.splice(index, 1);
    errors.value = { ...errors.value, contacts: undefined };
}
const contactError = (index, field) => errors.value[`contacts.${index}.${field}`];
const message = (value) => (typeof value === 'string' && te(value) ? t(value) : t('health.error.load'));

function save() {
    const f = form.value;
    const local = {};
    if (f.name.trim() === '') local.name = t('health.person.err_name');
    f.contacts.forEach((c, i) => {
        if (c.name.trim() === '') local[`contacts.${i}.name`] = t('health.person.err_name');
        if (c.phone.trim() === '') local[`contacts.${i}.phone`] = t('health.person.err_phone');
    });
    if (Object.keys(local).length > 0) {
        errors.value = local;
        return;
    }
    errors.value = {};
    const data = {
        name: f.name,
        birth_date: f.birth_date || null,
        sex: f.sex || null,
        blood_type: f.blood_type || null,
        notes: f.notes || null,
        contacts: f.contacts.map((c) => ({ name: c.name, relation: c.relation || null, phone: c.phone })),
    };
    const callbacks = {
        preserveScroll: true,
        onStart: () => { processing.value = true; serverError.value = false; },
        onSuccess: () => emit('close'),
        onError: (errs) => {
            const mapped = {};
            let unknown = false;
            for (const [key, value] of Object.entries(errs ?? {})) {
                if (FIELDS.includes(key) || key === 'contacts' || CONTACT_KEY.test(key)) mapped[key] = message(value);
                else unknown = true;
            }
            errors.value = mapped;
            serverError.value = unknown || Object.keys(mapped).length === 0;
        },
        onHttpException: () => { serverError.value = true; },
        onNetworkError: () => { serverError.value = true; },
        onFinish: () => { processing.value = false; },
    };
    if (editing.value) router.patch(`/health/people/${props.person.id}`, data, callbacks);
    else router.post('/health/people', data, callbacks);
}
const onEnter = (event) => { if (event.target?.tagName === 'INPUT') event.preventDefault(); };
</script>

<template>
    <UiDetailPanel :open="open" :title="title" @update:open="(v) => (v ? null : requestClose())">
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

            <fieldset class="person-contacts" data-testid="person-contacts">
                <legend class="person-contacts__legend">{{ t('health.person.contacts') }}</legend>
                <UiAlert v-if="errors.contacts" variant="danger" live>{{ errors.contacts }}</UiAlert>
                <div v-for="(contact, index) in form.contacts" :key="index" class="person-contacts__row" data-testid="contact-row">
                    <UiField :label="t('health.person.contact_name')" :error="contactError(index, 'name')">
                        <UiInput v-model="contact.name" autocomplete="off" maxlength="120" />
                    </UiField>
                    <UiField :label="t('health.person.contact_relation')" :error="contactError(index, 'relation')">
                        <UiInput v-model="contact.relation" autocomplete="off" maxlength="60" />
                    </UiField>
                    <UiField :label="t('health.person.contact_phone')" :error="contactError(index, 'phone')">
                        <UiInput v-model="contact.phone" type="tel" autocomplete="off" maxlength="40" />
                    </UiField>
                    <UiButton variant="ghost" data-testid="contact-remove" @click="removeContact(index)">{{ t('health.person.contact_remove') }}</UiButton>
                </div>
                <UiButton v-if="form.contacts.length < MAX_CONTACTS" variant="ghost" :icon="Plus" data-testid="contact-add" @click="addContact">{{ t('health.person.contact_add') }}</UiButton>
            </fieldset>
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
.person-contacts { margin: 0; padding: 0; border: 0; display: flex; flex-direction: column; gap: var(--space-3); min-width: 0; }
.person-contacts__legend { padding: 0; margin-block-end: var(--space-2); font-size: var(--text-sm); font-weight: 600; }
.person-contacts__row { display: flex; flex-direction: column; align-items: flex-start; padding: var(--space-3); border: 1px solid var(--color-border); border-radius: var(--radius-md); }
</style>
