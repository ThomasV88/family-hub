<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { CircleCheck, Copy, Ellipsis, ListPlus, Lock, LockOpen, Mail, Plus, Settings, Trash2 } from '@lucide/vue';
import AppShell from '../../Layouts/AppShell.vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiConfirmDialog from '../../Components/ui/UiConfirmDialog.vue';
import UiDialog from '../../Components/ui/UiDialog.vue';
import HelpTip from '../../Components/ui/HelpTip.vue';
import { useToast } from '../../Components/ui/useToast.js';
import UiEmptyState from '../../Components/ui/UiEmptyState.vue';
import UiList from '../../Components/ui/UiList.vue';
import UiMenu from '../../Components/ui/UiMenu.vue';
import CopyListDialog from '../../Shopping/CopyListDialog.vue';
import EmailListDialog from '../../Shopping/EmailListDialog.vue';
import FinishShoppingDialog from '../../Shopping/FinishShoppingDialog.vue';
import ItemEditDialog from '../../Shopping/ItemEditDialog.vue';
import ItemPickerDialog from '../../Shopping/ItemPickerDialog.vue';
import ListHeader from '../../Shopping/ListHeader.vue';
import ListSettingsPanel from '../../Shopping/ListSettingsPanel.vue';
import ShoppingItemRow from '../../Shopping/ShoppingItemRow.vue';
import ShoppingProgress from '../../Shopping/ShoppingProgress.vue';
import StickyActionBar from '../../Shopping/StickyActionBar.vue';
import { failureKey, httpHandlers, reconcile } from '../../Shopping/failure.js';
import { useMarkQueue } from '../../Shopping/useMarkQueue.js';

// List detail. The server decides what a person may do (`abilities`); the page only renders controls that are allowed.
// Plan mode (open list): items can be added, edited and removed. Locked mode (shopping): the responsible person marks items Bought or
// Unavailable and finishes. Finished lists render read-only.
const props = defineProps({
    list: { type: Object, required: true },
    items: { type: Array, default: () => [] },
    abilities: { type: Object, default: () => ({}) },
    members: { type: Object, default: () => ({ contributors: [], viewers: [] }) },
    units: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    catalog: { type: Array, default: null },
    candidates: { type: Object, default: null },
    responsiblePushEnabled: { type: Boolean, default: null },
});
const { t } = useI18n();
const page = usePage();
const toast = useToast();

const unitById = computed(() => Object.fromEntries(props.units.map((u) => [u.id, u])));
const canEdit = computed(() => props.abilities.editItems === true && props.list.status === 'open');
const archived = computed(() => props.list.status === 'done');
const locked = computed(() => props.list.status === 'locked');

const pickerOpen = ref(false);
const editOpen = ref(false);
const editTarget = ref(null);
const copyOpen = ref(false);
const deleteOpen = ref(false);
const settingsOpen = ref(false);
const mailOpen = ref(false);
const finishOpen = ref(false);

// Shopping mode. Marks are shown at once (an override per item) and sent as an absolute target state, one request at a time in
// tap order (see useMarkQueue: a second tap must never abort the first). An item shows its mark until its own request settles; a
// failure rolls back that item only and shows a toast. Unlock and Finish wait until every mark has been sent.
function markFailed(itemId, response) {
    toast.show(t(failureKey(response, 'shopping.error.mark_failed')));
    reconcile(response);
}
const marks = useMarkQueue({ url: (itemId) => `/shopping/lists/${props.list.id}/items/${itemId}/state`, onFail: markFailed });
const overrides = marks.overrides;
const shownItems = computed(() => props.items.map((i) => (overrides[i.id] ? { ...i, state: overrides[i.id] } : i)));
const count = (state) => shownItems.value.filter((i) => i.state === state).length;
const bought = computed(() => count('bought'));
const unavailable = computed(() => count('unavailable'));
const left = computed(() => count('pending'));
const unavailableItems = computed(() => shownItems.value.filter((i) => i.state === 'unavailable'));
const markable = computed(() => locked.value && props.abilities.mark === true);
const canFinish = computed(() => locked.value && props.abilities.finish === true);
const hasBar = computed(() => canFinish.value && shownItems.value.length > 0);
const marking = computed(() => marks.busy.value);
// Announced politely when the last item gets marked, so Finish becoming available is not silent.
const allDone = computed(() => markable.value && shownItems.value.length > 0 && left.value === 0);

function mark(item, target) {
    marks.mark(item.id, target);
}

// Lock and unlock, each behind a confirmation (not destructive, so the accent button).
const lockDialog = ref(null);
const lockBusy = ref(false);
const lockError = ref('');
const lockCopy = computed(() => (lockDialog.value === 'unlock'
    ? { title: t('shopping.lock.unlock_title'), body: t('shopping.lock.unlock_body'), confirm: t('shopping.lock.unlock_confirm') }
    : { title: t('shopping.lock.title'), body: t('shopping.lock.body'), confirm: t('shopping.lock.confirm') }));
const lockHint = computed(() => (props.items.length === 0 ? t('shopping.lock.empty_hint') : ''));
const onlyResponsible = computed(() => props.list.status === 'open' && props.abilities.lock !== true);

function askLock(kind) {
    lockError.value = '';
    lockDialog.value = kind;
}

function submitLock() {
    if (lockBusy.value) return;
    lockBusy.value = true;
    lockError.value = '';
    router.put(`/shopping/lists/${props.list.id}/lock`, { locked: lockDialog.value === 'lock' }, {
        preserveScroll: true,
        onSuccess: () => { lockDialog.value = null; },
        onError: (errors) => { lockError.value = String(errors?.list ?? '').startsWith('shopping.') ? t(errors.list) : t('shopping.error.mark_failed'); },
        ...httpHandlers((key) => { lockError.value = t(key); }, 'shopping.error.mark_failed'),
        onFinish: () => { lockBusy.value = false; },
    });
}

// Catalog items already pending on the list (the server raises their quantity instead of adding a second row).
const onListIds = computed(() => props.items.filter((i) => i.catalogItemId != null && i.state === 'pending').map((i) => i.catalogItemId));

// Creating a list flashes open_picker once: an empty list opens the picker straight away for people who may add items.
onMounted(() => {
    if (page.props.flash?.data?.open_picker && canEdit.value && props.items.length === 0) pickerOpen.value = true;
});

function editItem(item) {
    if (!canEdit.value) return;
    editTarget.value = item;
    editOpen.value = true;
}

const takeover = computed(() => (props.list.responsibleFallback?.previousName ? t('shopping.panel.takeover', { name: props.list.responsibleFallback?.previousName }) : t('shopping.panel.takeover_unknown')));
const menuItems = computed(() => {
    const entries = archived.value ? [] : [{ type: 'item', key: 'settings', label: t('shopping.panel.title'), icon: Settings }];
    if (props.abilities.mail) entries.push({ type: 'item', key: 'mail', label: t('shopping.mail.title'), icon: Mail });
    if (props.abilities.copy) entries.push({ type: 'item', key: 'copy', label: t('shopping.overview.copy'), icon: Copy });
    if (props.abilities.delete) entries.push({ type: 'item', key: 'delete', label: t('shopping.detail.delete_list'), icon: Trash2 });

    return entries;
});

function onMenu({ key }) {
    if (key === 'settings') settingsOpen.value = true;
    if (key === 'mail') mailOpen.value = true;
    if (key === 'copy') copyOpen.value = true;
    if (key === 'delete') deleteOpen.value = true;
}

const deleting = ref(false);
function deleteList() {
    if (deleting.value) return;
    deleting.value = true;
    router.delete(`/shopping/lists/${props.list.id}`, {
        ...httpHandlers((key) => toast.show(t(key)), 'shopping.error.save_failed'),
        onFinish: () => { deleting.value = false; },
    });
}
</script>

<template>
    <Head :title="list.name" />
    <AppShell current="shopping">
        <div :class="['shop-show', { 'shop-show--bar': hasBar }]" data-testid="shopping-list-page">
            <ListHeader :list="list">
                <UiButton v-if="canEdit" variant="primary" :icon="Plus" data-testid="add-items" @click="pickerOpen = true">{{ t('shopping.detail.add_items') }}</UiButton>
                <UiButton v-if="abilities.lock" variant="secondary" :icon="Lock" :disabled="items.length === 0" :aria-describedby="lockHint ? 'lock-hint' : undefined" data-testid="lock-list" @click="askLock('lock')">{{ t('shopping.lock.button') }}</UiButton>
                <UiButton v-if="abilities.unlock" variant="secondary" :icon="LockOpen" :disabled="marking" data-testid="unlock-list" @click="askLock('unlock')">{{ t('shopping.lock.unlock_button') }}</UiButton>
                <UiMenu v-if="menuItems.length > 0" :label="t('shopping.detail.more')" :icon="Ellipsis" :items="menuItems" @select="onMenu" />
            </ListHeader>

            <p v-if="abilities.lock && lockHint" id="lock-hint" class="shop-show__hint" data-testid="lock-empty-hint">
                <span>{{ lockHint }}</span>
                <HelpTip topic="shopping.lock" :label="t('shopping.lock.button')" />
            </p>
            <p v-else-if="onlyResponsible" id="lock-hint" class="shop-show__hint" data-testid="lock-only-hint">
                <span>{{ t('shopping.lock.only', { name: list.responsible.name }) }}</span>
                <HelpTip topic="shopping.lock" :label="t('shopping.lock.button')" />
            </p>
            <p v-else-if="abilities.lock || abilities.unlock" class="shop-show__hint shop-show__hint--tip">
                <HelpTip topic="shopping.lock" :label="locked ? t('shopping.lock.unlock_button') : t('shopping.lock.button')" />
            </p>

            <UiAlert v-if="list.responsibleFallback" variant="info" data-testid="takeover-alert">{{ takeover }}</UiAlert>
            <UiAlert v-if="archived" variant="info" data-testid="archived-banner">{{ t('shopping.archived.banner') }}</UiAlert>
            <UiAlert v-else-if="locked" variant="info" data-testid="locked-banner">{{ t('shopping.detail.locked_note') }}</UiAlert>

            <ShoppingProgress v-if="locked && items.length > 0" :bought="bought" :unavailable="unavailable" :left="left" />
            <p v-if="markable" class="visually-hidden" role="status" aria-live="polite" data-testid="all-done-live">{{ allDone ? t('shopping.progress.all_done') : '' }}</p>

            <UiEmptyState v-if="items.length === 0" :heading-level="2" :icon="ListPlus" :title="t('shopping.detail.empty_title')" :body="canEdit ? t('shopping.detail.empty_body') : ''">
                <template v-if="canEdit" #action>
                    <UiButton variant="primary" :icon="Plus" @click="pickerOpen = true">{{ t('shopping.detail.add_items') }}</UiButton>
                </template>
            </UiEmptyState>
            <UiList v-else :aria-label="t('shopping.detail.items_label')">
                <ShoppingItemRow v-for="item in shownItems" :key="item.id" :item="item" :unit="unitById[item.unitId] ?? null" :interactive="canEdit" :markable="markable" :busy="Boolean(marks.pending[item.id])" @edit="editItem" @mark="mark" />
            </UiList>
            <StickyActionBar v-if="hasBar" :left="left">
                <UiButton variant="primary" :icon="CircleCheck" :disabled="marking" data-testid="finish-shopping" @click="finishOpen = true">{{ t('shopping.finish.button') }}</UiButton>
            </StickyActionBar>
        </div>

        <UiDialog :open="lockDialog !== null" :title="lockCopy.title" :dismissable="!lockBusy" @update:open="(v) => { if (!v) lockDialog = null; }">
            <div class="shop-show__lock" data-testid="lock-dialog">
                <div aria-live="polite">
                    <UiAlert v-if="lockError" variant="danger" data-testid="lock-error">{{ lockError }}</UiAlert>
                </div>
                <p class="shop-show__lock-body">{{ lockCopy.body }}</p>
            </div>
            <template #footer>
                <UiButton variant="secondary" :disabled="lockBusy" data-testid="lock-cancel" @click="lockDialog = null">{{ t('shopping.lock.cancel') }}</UiButton>
                <UiButton variant="primary" :loading="lockBusy" :disabled="lockBusy" data-testid="lock-confirm" @click="submitLock">{{ lockCopy.confirm }}</UiButton>
            </template>
        </UiDialog>
        <FinishShoppingDialog v-if="canFinish" v-model:open="finishOpen" :list="{ id: list.id, name: list.name }" :unavailable="unavailableItems" :units="units" :wait="marks.drained" />
        <ItemPickerDialog v-if="canEdit" v-model:open="pickerOpen" :list-id="list.id" :catalog="catalog" :on-list-ids="onListIds" :units="units" :can-manage-catalog="abilities.manageCatalog === true" />
        <ItemEditDialog v-if="canEdit" v-model:open="editOpen" :list-id="list.id" :item="editTarget" :units="units" />
        <ListSettingsPanel v-model:open="settingsOpen" :list="list" :abilities="abilities" :members="members" :stores="stores" :candidates="candidates" :responsible-push-enabled="responsiblePushEnabled" />
        <EmailListDialog v-if="abilities.mail" v-model:open="mailOpen" :list="{ id: list.id, name: list.name }" />
        <CopyListDialog v-if="abilities.copy" v-model:open="copyOpen" :list="{ id: list.id, name: list.name }" />
        <UiConfirmDialog
            v-if="abilities.delete"
            v-model:open="deleteOpen"
            :title="t('shopping.delete.title', { name: list.name })"
            :body="t('shopping.delete.body')"
            :confirm-label="t('shopping.delete.confirm')"
            :cancel-label="t('shopping.delete.cancel')"
            @confirm="deleteList"
        />
    </AppShell>
</template>

<style scoped>
.shop-show__hint { display: flex; align-items: center; gap: var(--space-2); margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.shop-show__lock { display: flex; flex-direction: column; gap: var(--space-3); }
.shop-show__lock-body { margin: 0; font-size: var(--text-base); }
.shop-show { display: flex; flex-direction: column; gap: var(--space-4); max-width: 840px; margin-inline: auto; width: 100%; }
@media (max-width: 639px) { .shop-show--bar { padding-block-end: 72px; } }
</style>
