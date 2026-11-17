<script setup>
import { computed, nextTick, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiConfirmDialog from '../../Components/ui/UiConfirmDialog.vue';
import UiDialog from '../../Components/ui/UiDialog.vue';
import UiField from '../../Components/ui/UiField.vue';
import UiInput from '../../Components/ui/UiInput.vue';
import UiList from '../../Components/ui/UiList.vue';
import UiListRow from '../../Components/ui/UiListRow.vue';
import UiTag from '../../Components/ui/UiTag.vue';
import HelpTip from '../../Components/ui/HelpTip.vue';
import { unitLabel } from '../units.js';

// Settings > Shopping, tab "Units": the built-in units are read-only; administrators add their own, named in three languages.
const props = defineProps({
    // [{ id, key|null, names|null, builtIn, usedOn }]
    units: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
    nameMax: { type: Number, default: 12 },
});
const { t, locale } = useI18n();

const open = ref(false);
const editing = ref(null);
const toDelete = ref(null);
const confirmOpen = ref(false);
const deleteFailed = ref(false);
const firstInput = ref(null);
const form = useForm({ name_en: '', name_sk: '', name_pl: '' });

const builtIn = computed(() => props.units.filter((u) => u.builtIn));
const custom = computed(() => props.units.filter((u) => !u.builtIn));
const label = (unit) => unitLabel(unit, 1, t, locale.value);
const initialFocus = () => firstInput.value?.$el?.querySelector?.('input') ?? null;

const translate = (message) => (String(message).startsWith('shopping.') ? t(message) : String(message));
const err = (field) => (form.errors?.[field] ? translate(form.errors[field]) : '');
const failed = computed(() => Boolean(err('name_en') || err('name_sk') || err('name_pl') || err('unit')));
const ready = computed(() => ['name_en', 'name_sk', 'name_pl'].every((k) => form[k].trim() !== ''));

function openDialog(unit = null) {
    editing.value = unit;
    form.clearErrors();
    form.name_en = unit?.names?.en ?? '';
    form.name_sk = unit?.names?.sk ?? '';
    form.name_pl = unit?.names?.pl ?? '';
    open.value = true;
}
const close = () => { open.value = false; };

function submit() {
    if (!ready.value || form.processing) return;
    const opts = { preserveScroll: true, onSuccess: close, onError: () => nextTick(() => initialFocus()?.focus()) };
    if (editing.value) form.put(`/settings/shopping/units/${editing.value.id}`, opts);
    else form.post('/settings/shopping/units', opts);
}

function askDelete(unit) {
    toDelete.value = unit;
    deleteFailed.value = false;
    confirmOpen.value = true;
}
function doDelete() {
    const unit = toDelete.value;
    if (!unit) return;
    router.delete(`/settings/shopping/units/${unit.id}`, {
        preserveScroll: true,
        onError: () => { deleteFailed.value = true; },
    });
}
</script>

<template>
    <div class="shop-tab">
        <div class="shop-tab__head">
            <h2 class="shop-tab__title">{{ t('shopping.settings.tab_units') }}</h2>
            <HelpTip topic="shopping.units" :label="t('shopping.settings.tab_units')" />
        </div>
        <UiAlert v-if="deleteFailed" variant="danger" live class="shop-tab__alert">{{ t('shopping.settings.save_failed') }}</UiAlert>
        <div v-if="canManage" class="shop-tab__toolbar">
            <UiButton variant="primary" :icon="Plus" class="shop-tab__add" @click="openDialog()">{{ t('shopping.settings.add_unit') }}</UiButton>
        </div>
        <p v-else class="shop-tab__note" data-testid="units-admin-note">{{ t('shopping.settings.units.admin_note') }}</p>

        <UiList>
            <UiListRow v-for="unit in builtIn" :key="unit.id" data-testid="unit-row">
                {{ label(unit) }}
                <template #trailing><UiTag>{{ t('shopping.settings.builtin') }}</UiTag></template>
            </UiListRow>
        </UiList>
        <UiList v-if="custom.length" class="shop-tab__custom">
            <UiListRow v-for="unit in custom" :key="unit.id" data-testid="unit-row">
                {{ label(unit) }}
                <template #meta>{{ t('shopping.settings.used_on_items', { n: unit.usedOn }, unit.usedOn) }}</template>
                <template v-if="canManage" #trailing>
                    <UiButton variant="ghost" icon-only :icon="Pencil" :label="t('shopping.settings.edit', { name: label(unit) })" @click="openDialog(unit)" />
                    <UiButton variant="ghost" icon-only :icon="Trash2" :label="t('shopping.settings.delete', { name: label(unit) })" @click="askDelete(unit)" />
                </template>
            </UiListRow>
        </UiList>

        <UiDialog :open="open" :title="editing ? t('shopping.settings.units.edit_title') : t('shopping.settings.units.add_title')" :initial-focus="initialFocus" @update:open="open = $event">
            <form id="unit-form" class="shop-form" novalidate @submit.prevent="submit">
                <div aria-live="polite">
                    <UiAlert v-if="failed" variant="danger" data-testid="save-failed">{{ err('unit') || t('shopping.settings.save_failed') }}</UiAlert>
                </div>
                <UiField :label="t('shopping.settings.units.name_en')" :hint="t('shopping.settings.units.name_hint')" :error="err('name_en')" required>
                    <UiInput ref="firstInput" v-model="form.name_en" autocomplete="off" :maxlength="nameMax" :disabled="form.processing" />
                </UiField>
                <UiField :label="t('shopping.settings.units.name_sk')" :error="err('name_sk')" required>
                    <UiInput v-model="form.name_sk" autocomplete="off" :maxlength="nameMax" :disabled="form.processing" />
                </UiField>
                <UiField :label="t('shopping.settings.units.name_pl')" :error="err('name_pl')" required>
                    <UiInput v-model="form.name_pl" autocomplete="off" :maxlength="nameMax" :disabled="form.processing" />
                </UiField>
            </form>
            <template #footer>
                <UiButton variant="ghost" @click="close">{{ t('shopping.settings.cancel') }}</UiButton>
                <UiButton type="submit" form="unit-form" variant="primary" :disabled="!ready || form.processing" :loading="form.processing">{{ t('shopping.settings.units.save') }}</UiButton>
            </template>
        </UiDialog>

        <UiConfirmDialog
            v-model:open="confirmOpen"
            :title="t('shopping.settings.units.delete_title', { name: toDelete ? label(toDelete) : '' })"
            :body="toDelete && toDelete.usedOn > 0 ? t('shopping.settings.units.delete_body_used', { n: toDelete.usedOn }, toDelete.usedOn) : t('shopping.settings.units.delete_body_free')"
            :confirm-label="t('shopping.settings.units.delete_confirm')"
            :cancel-label="t('shopping.settings.units.delete_cancel')"
            @confirm="doDelete"
        />
    </div>
</template>

<style scoped>
.shop-tab__head { display: flex; align-items: center; gap: var(--space-2); margin-block-end: var(--space-3); }
.shop-tab__title { margin: 0; font-size: var(--text-lg); font-weight: 600; }
.shop-tab__alert { margin-block-end: var(--space-3); }
.shop-tab__toolbar { display: flex; margin-block-end: var(--space-3); }
.shop-tab__note { margin: 0 0 var(--space-3); color: var(--color-text-muted); }
.shop-tab__custom { margin-block-start: var(--space-4); }
.shop-form { display: flex; flex-direction: column; }
</style>
