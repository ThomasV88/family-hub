<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UiDetailPanel from '../ui/UiDetailPanel.vue';
import UiRadio from '../ui/UiRadio.vue';
import UiSwitch from '../ui/UiSwitch.vue';
import UiSelect from '../ui/UiSelect.vue';
import UiField from '../ui/UiField.vue';
import UiButton from '../ui/UiButton.vue';
import UiAlert from '../ui/UiAlert.vue';
import UiTag from '../ui/UiTag.vue';
import UiConfirmDialog from '../ui/UiConfirmDialog.vue';

// Who can open this person (None / View / Change per member), the linked account and the own-record switch.
// Names and levels only. The linked-account select never marks members that are linked to another person.
const props = defineProps({
    person: { type: Object, required: true },
    members: { type: Array, default: () => [] },
});
const emit = defineEmits(['close']);
const { t } = useI18n();

const hasModule = (m) => (m.access?.health ?? 'none') !== 'none';
const savedLevel = (m) => props.person.members?.[m.id] ?? 'none';

const levels = ref(Object.fromEntries(props.members.map((m) => [m.id, savedLevel(m)])));
const userId = ref(props.person.linkedUserId === null ? '' : String(props.person.linkedUserId));
const selfAccess = ref(props.person.selfAccessEnabled);
const failed = ref(false);
const linkError = ref('');
const processing = ref(false);
const confirming = ref(false);

const changedLevels = computed(() => Object.fromEntries(
    props.members.filter((m) => levels.value[m.id] !== savedLevel(m)).map((m) => [m.id, levels.value[m.id]]),
));
const savedUserId = computed(() => (props.person.linkedUserId === null ? '' : String(props.person.linkedUserId)));
const linkChanged = computed(() => userId.value !== savedUserId.value || (userId.value !== '' && selfAccess.value !== props.person.selfAccessEnabled));
const dirty = computed(() => Object.keys(changedLevels.value).length > 0 || linkChanged.value);

const accountOptions = computed(() => [
    { value: '', label: t('health.access.no_account_option') },
    ...props.members.map((m) => ({ value: String(m.id), label: m.name })),
]);

function requestClose() {
    if (dirty.value) {
        confirming.value = true;
        return;
    }
    emit('close');
}

function fail(errors) {
    failed.value = true;
    processing.value = false;
    linkError.value = errors?.user_id ? t('health.access.link_failed') : '';
}

function sendLink() {
    router.put(`/settings/health-people/${props.person.id}/link`, {
        user_id: userId.value === '' ? null : Number(userId.value),
        self_access_enabled: userId.value === '' ? true : selfAccess.value,
    }, {
        preserveScroll: true,
        onSuccess: () => { processing.value = false; emit('close'); },
        onError: fail,
        onHttpException: () => fail(null),
        onNetworkError: () => fail(null),
    });
}

function save() {
    failed.value = false;
    linkError.value = '';
    processing.value = true;
    const finish = () => (linkChanged.value ? sendLink() : (processing.value = false, emit('close')));
    if (Object.keys(changedLevels.value).length === 0) {
        finish();
        return;
    }
    router.put(`/settings/health-people/${props.person.id}/members`, { levels: changedLevels.value }, {
        preserveScroll: true,
        onSuccess: finish,
        onError: fail,
        onHttpException: () => fail(null),
        onNetworkError: () => fail(null),
    });
}
</script>

<template>
    <UiDetailPanel :open="true" :title="t('health.access.panel_title')" @update:open="(open) => !open && requestClose()">
        <form class="pap" @submit.prevent="save">
            <p class="pap__person">{{ person.name }}</p>
            <UiAlert v-if="failed" variant="danger" live>{{ t('health.access.failed') }}</UiAlert>
            <p class="pap__legend">{{ t('health.access.legend') }}</p>

            <div v-for="m in members" :key="m.id" class="pap__member" role="radiogroup" :aria-label="m.name">
                <span class="pap__name">
                    {{ m.name }}
                    <UiTag v-if="String(person.linkedUserId) === String(m.id)">{{ t('health.access.own_record') }}</UiTag>
                </span>
                <div class="pap__choices">
                    <UiRadio v-model="levels[m.id]" value="none" :name="`level-${m.id}`" :disabled="!hasModule(m)">{{ t('health.access.none') }}</UiRadio>
                    <UiRadio v-model="levels[m.id]" value="view" :name="`level-${m.id}`" :disabled="!hasModule(m)">{{ t('health.access.view') }}</UiRadio>
                    <UiRadio v-model="levels[m.id]" value="edit" :name="`level-${m.id}`" :disabled="!hasModule(m)">{{ t('health.access.change') }}</UiRadio>
                </div>
                <p v-if="!hasModule(m)" class="pap__hint">
                    {{ t('health.access.needs_module') }}
                    <a :href="`/settings/members?member=${m.id}`">{{ t('health.access.open_permissions') }}</a>
                </p>
            </div>

            <UiField :label="t('health.access.linked_account')" :error="linkError || undefined">
                <UiSelect v-model="userId" :options="accountOptions" />
            </UiField>
            <div v-if="userId !== ''" class="pap__self">
                <UiSwitch v-model="selfAccess">{{ t('health.access.self_switch') }}</UiSwitch>
                <p class="pap__hint">{{ t('health.access.self_hint') }}</p>
            </div>

            <div class="pap__actions">
                <UiButton variant="ghost" @click="requestClose">{{ t('permissions.cancel') }}</UiButton>
                <UiButton type="submit" variant="primary" :disabled="!dirty || processing" :loading="processing">{{ t('health.access.save') }}</UiButton>
            </div>
        </form>
        <UiConfirmDialog
            :open="confirming"
            :title="t('health.discard.title')"
            :body="t('permissions.discard_body', { name: person.name })"
            :confirm-label="t('health.discard.discard')"
            :cancel-label="t('health.discard.keep')"
            @update:open="(v) => { if (!v) confirming = false; }"
            @confirm="emit('close')"
        />
    </UiDetailPanel>
</template>

<style scoped>
.pap { display: flex; flex-direction: column; gap: var(--space-4); padding-block: var(--space-3); }
.pap__person { margin: 0; font-weight: 600; overflow-wrap: anywhere; }
.pap__legend, .pap__hint { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.pap__member { display: flex; flex-direction: column; gap: var(--space-2); padding-block: var(--space-2); border-block-end: 1px solid var(--color-border); }
.pap__name { display: inline-flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); font-weight: 600; overflow-wrap: anywhere; }
.pap__choices { display: flex; flex-wrap: wrap; gap: var(--space-4); }
.pap__actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: var(--space-3); }
</style>
