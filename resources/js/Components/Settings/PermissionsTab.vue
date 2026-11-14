<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Lock } from '@lucide/vue';
import UiField from '../ui/UiField.vue';
import UiSelect from '../ui/UiSelect.vue';
import UiButton from '../ui/UiButton.vue';
import UiAlert from '../ui/UiAlert.vue';
import UiTag from '../ui/UiTag.vue';
import PermissionSelect from './PermissionSelect.vue';
import { MODULE_KEYS, applyPreset } from '../../Settings/presets.js';

const props = defineProps({
    member: { type: Object, required: true },
    presets: { type: Object, required: true },
});
const emit = defineEmits(['dirty']);
const { t } = useI18n();

const RESTRICTED = ['finance', 'health'];
const presetKeys = computed(() => Object.keys(props.presets));
const preset = ref(presetKeys.value[0] ?? '');
const applied = ref(false);
const failed = ref(false);

const current = () => Object.fromEntries(MODULE_KEYS.map((key) => [key, props.member.access?.[key] ?? 'none']));
const form = useForm(current());

const dirty = computed(() => MODULE_KEYS.some((key) => form[key] !== (props.member.access?.[key] ?? 'none')));
watch(dirty, (value) => emit('dirty', value), { immediate: true });
// A saved page reload brings new access levels for this member: follow them when nothing is being edited.
watch(() => props.member.access, () => {
    if (!dirty.value) Object.assign(form, current());
}, { deep: true });

const presetOptions = computed(() => presetKeys.value.map((key) => ({ value: key, label: t(`permissions.preset.${key}`) })));
const presetHint = computed(() => (preset.value ? t(`permissions.preset.${preset.value}_hint`) : ''));

function apply() {
    const levels = applyPreset(preset.value, props.presets);
    if (!levels) return;
    Object.assign(form, levels);
    applied.value = true;
}
function chooseHealthPeople() {
    const card = document.getElementById('health-access-card');
    if (!card) return;
    card.scrollIntoView?.({ block: 'start' });
    card.focus();
}
function cancel() {
    Object.assign(form, current());
    applied.value = false;
    failed.value = false;
}
function submit() {
    failed.value = false;
    form.put(`/settings/members/${props.member.id}/permissions`, {
        preserveScroll: true,
        onSuccess: () => {
            applied.value = false;
        },
        onError: () => {
            failed.value = true;
        },
    });
}
</script>

<template>
    <form class="perm-tab" @submit.prevent="submit">
        <UiAlert v-if="failed" variant="danger" live>{{ t('permissions.save_failed') }}</UiAlert>

        <div class="perm-tab__preset">
            <UiField :label="t('permissions.apply_label')" :hint="presetHint">
                <UiSelect v-model="preset" :options="presetOptions" />
            </UiField>
            <UiButton variant="secondary" @click="apply">{{ t('permissions.apply_label') }}</UiButton>
        </div>
        <UiAlert v-if="applied" variant="info" live>{{ t('permissions.applied') }}</UiAlert>

        <ul class="perm-tab__rows" role="list">
            <li v-for="key in MODULE_KEYS" :key="key" class="perm-tab__row">
                <div class="perm-tab__module">
                    <span class="perm-tab__name">{{ t(`permissions.module.${key}`) }}</span>
                    <span v-if="RESTRICTED.includes(key)" class="perm-tab__hint">
                        <Lock :size="16" :stroke-width="1.75" aria-hidden="true" />{{ t('permissions.restricted') }}
                    </span>
                </div>
                <PermissionSelect
                    v-model="form[key]"
                    :aria-label="t('permissions.select_aria', { member: member.name, module: t(`permissions.module.${key}`) })"
                />
                <p v-if="key === 'health' && form.health !== 'none'" class="perm-tab__people">
                    {{ t('health.access.choose_people_hint') }}
                    <a href="#health-access-card" @click.prevent="chooseHealthPeople">{{ t('health.access.choose_people_link') }}</a>
                </p>
            </li>
            <li class="perm-tab__row perm-tab__row--static">
                <div class="perm-tab__module">
                    <span class="perm-tab__name">{{ t('permissions.module.settings') }}</span>
                    <span v-if="member.isAdmin" class="perm-tab__hint">{{ t('permissions.admin_no_sensitive') }}</span>
                </div>
                <UiTag :variant="member.isAdmin ? 'success' : 'neutral'">{{ member.isAdmin ? t('permissions.settings_always') : t('permissions.settings_admin_only') }}</UiTag>
            </li>
        </ul>
        <p v-if="member.isSelf" class="perm-tab__note">{{ t('permissions.self_note') }}</p>

        <div class="perm-tab__actions">
            <UiButton variant="ghost" :disabled="!dirty" @click="cancel">{{ t('permissions.cancel') }}</UiButton>
            <UiButton type="submit" variant="primary" :disabled="!dirty" :loading="form.processing">{{ t('permissions.save') }}</UiButton>
        </div>
    </form>
</template>

<style scoped>
.perm-tab { display: flex; flex-direction: column; gap: var(--space-4); padding-block: var(--space-3); }
.perm-tab__preset { display: flex; flex-wrap: wrap; align-items: flex-end; gap: var(--space-3); }
.perm-tab__preset :deep(.ui-field) { flex: 1; min-width: 160px; }
.perm-tab__rows { margin: 0; padding: 0; list-style: none; }
.perm-tab__row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-3); padding-block: var(--space-3); }
.perm-tab__row + .perm-tab__row { border-block-start: 1px solid var(--color-border); }
.perm-tab__module { display: flex; flex-direction: column; gap: var(--space-1); min-width: 0; overflow-wrap: anywhere; }
.perm-tab__name { font-weight: 600; }
.perm-tab__hint { display: inline-flex; align-items: center; gap: var(--space-1); font-size: var(--text-sm); color: var(--color-text-muted); }
.perm-tab__people { flex-basis: 100%; margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.perm-tab__note { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.perm-tab__actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: var(--space-3); }
</style>
