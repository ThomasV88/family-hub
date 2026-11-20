<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShell from '../../Layouts/AppShell.vue';
import GiftListView from '../../Wishlist/GiftListView.vue';
import GiftWishPanel from '../../Wishlist/GiftWishPanel.vue';
import GroupHeader from '../../Wishlist/GroupHeader.vue';
import GroupChips from '../../Wishlist/GroupChips.vue';
import GroupPanel from '../../Wishlist/GroupPanel.vue';
import OwnListView from '../../Wishlist/OwnListView.vue';
import OwnWishPanel from '../../Wishlist/OwnWishPanel.vue';
import OccasionStrip from '../../Wishlist/OccasionStrip.vue';
import PersonChips from '../../Wishlist/PersonChips.vue';
import SharedListView from '../../Wishlist/SharedListView.vue';
import ShellAdd from '../../Wishlist/ShellAdd.vue';

const props = defineProps({
    members: { type: Array, default: () => [] },
    list: { type: Object, required: true },
    occasions: { type: Array, default: () => [] },
    // [{ id, name, mode, is_member }] group lists the viewer may open; group_candidates: people who can be added ([] without Wishlist edit)
    groups: { type: Array, default: () => [] },
    group_candidates: { type: Array, default: () => [] },
    // null | 'new' | wish id (server-validated; it mirrors ?wish= in the URL)
    selected: { default: null },
});
const { t } = useI18n();

// The single branch between the two views: a recipient (owner or designated manager of this list) never gets giver UI.
const recipient = computed(() => props.list.viewer_is_recipient === true);
const managing = computed(() => props.list.viewer_is_manager === true);
const ownerId = computed(() => props.list.owner.id);
const ownerName = computed(() => props.list.owner.name);
const viewerId = computed(() => props.members.find((m) => m.is_viewer)?.id ?? null);
// A shared-mode group list is shown to its members with reservation data (and may be edited by them); everyone else
// who is not on the recipient side gets the giver view.
const group = computed(() => props.list.group ?? null);
const sharedMember = computed(() => !recipient.value && group.value?.mode === 'shared' && group.value.is_member === true);
const ownSide = computed(() => recipient.value || sharedMember.value);
const canAdd = computed(() => ownSide.value && props.list.can_edit === true);
const addLabel = computed(() => (group.value ? t('wishlist.group.add', { name: group.value.name }) : managing.value ? t('wishlist.add_for', { name: ownerName.value }) : t('wishlist.add')));
// Shared members open a wish read-only (with reservations); Edit wish switches this to its id.
const editingWishId = ref(null);

const allWishes = computed(() => [...(props.list.wishes ?? []), ...(props.list.received ?? [])]);
const selectedWish = computed(() => (Number.isInteger(props.selected) ? (allWishes.value.find((w) => w.id === props.selected) ?? null) : null));
const panelMode = computed(() => {
    if (recipient.value) {
        if (props.selected === 'new') return canAdd.value ? 'create' : null;
        return selectedWish.value ? 'edit' : null;
    }
    if (sharedMember.value) {
        if (props.selected === 'new') return canAdd.value ? 'create' : null;
        if (!selectedWish.value) return null;
        return editingWishId.value === selectedWish.value.id && props.list.can_edit === true ? 'edit' : 'gift';
    }
    return selectedWish.value ? 'gift' : null;
});
const closing = ref(false);
const panelOpen = computed(() => panelMode.value !== null && !closing.value);
watch(panelMode, (mode) => { if (mode === null) { closing.value = false; editingWishId.value = null; } });
const ownPanelOpen = computed(() => panelOpen.value && (panelMode.value === 'create' || panelMode.value === 'edit'));
const giftPanelOpen = computed(() => panelOpen.value && panelMode.value === 'gift');

const panel = ref(null);
const groupPanelRef = ref(null);
// null | 'create' | 'edit'; mutually exclusive with the wish panels
const groupPanel = ref(null);
const groupPanelOpen = computed(() => groupPanel.value !== null && (groupPanel.value === 'create' || props.list.group != null));
const canCreateGroup = computed(() => props.group_candidates.length > 0);
const selectedGroupId = computed(() => props.list.group?.id ?? null);
const guarded = (fn) => {
    const inner = () => (groupPanelRef.value ? groupPanelRef.value.guard(fn) : fn());
    return panel.value ? panel.value.guard(inner) : inner();
};

// ?member= is omitted for the viewer's own list.
function navigate(wish = null) {
    const params = {};
    if (props.list.group) params.group = props.list.group.id;
    else if (ownerId.value !== viewerId.value) params.member = ownerId.value;
    if (wish !== null) params.wish = wish;
    router.get('/wishlist', params, { preserveState: true, preserveScroll: true, replace: true, onFinish: () => { if (wish === null) closing.value = false; } });
}
const openWish = (id) => guarded(() => navigate(id));
const openCreate = () => guarded(() => navigate('new'));
// The group panel replaces an open wish panel (after the unsaved-changes check on both).
function openGroupPanel(mode) {
    guarded(() => {
        groupPanel.value = mode;
        if (panelMode.value !== null) navigate();
    });
}
const openGroupCreate = () => openGroupPanel('create');
// 16-07's list header emits this to open the settings of the group list on screen.
const openGroupManage = () => { if (props.list.group) openGroupPanel('edit'); };
const closeGroupPanel = () => { groupPanel.value = null; };
watch(panelMode, (mode) => { if (mode !== null) groupPanel.value = null; });
watch(selectedGroupId, (id, before) => { if (id !== before && groupPanel.value === 'edit') groupPanel.value = null; });
defineExpose({ openGroupManage });

function closePanel() {
    closing.value = true;
    navigate();
}
</script>

<template>
    <AppShell current="wishlist">
        <ShellAdd :enabled="canAdd" :label="addLabel" @add="openCreate" />
        <div class="wishlist" data-testid="wishlist-page">
            <div class="wishlist__main">
                <h1 class="wishlist__title">{{ t('wishlist.title') }}</h1>
                <OwnListView v-if="recipient" :list="list" :selected-id="Number.isInteger(selected) ? selected : null" @open="openWish" @add="openCreate">
                    <template #context>
                        <PersonChips :members="members" :selected-id="ownerId" :guard="guarded" />
                        <GroupChips :groups="groups" :selected-id="selectedGroupId" :can-create="canCreateGroup" :guard="guarded" @create="openGroupCreate" />
                        <OccasionStrip :occasions="occasions" />
                        <GroupHeader v-if="group" :list="list" @manage="openGroupManage" />
                    </template>
                </OwnListView>
                <SharedListView v-else-if="sharedMember" :list="list" :selected-id="Number.isInteger(selected) ? selected : null" @open="openWish" @add="openCreate">
                    <template #context>
                        <PersonChips :members="members" :selected-id="ownerId" :guard="guarded" />
                        <GroupChips :groups="groups" :selected-id="selectedGroupId" :can-create="canCreateGroup" :guard="guarded" @create="openGroupCreate" />
                        <OccasionStrip :occasions="occasions" />
                        <GroupHeader :list="list" @manage="openGroupManage" />
                    </template>
                </SharedListView>
                <GiftListView v-else :list="list" :selected-id="Number.isInteger(selected) ? selected : null" @open="openWish">
                    <template #context>
                        <PersonChips :members="members" :selected-id="ownerId" :guard="guarded" />
                        <GroupChips :groups="groups" :selected-id="selectedGroupId" :can-create="canCreateGroup" :guard="guarded" @create="openGroupCreate" />
                        <OccasionStrip :occasions="occasions" />
                    </template>
                </GiftListView>
            </div>
            <OwnWishPanel
                v-if="ownSide"
                ref="panel"
                :open="ownPanelOpen"
                :mode="panelMode === 'create' ? 'create' : 'edit'"
                :wish="panelMode === 'edit' ? selectedWish : null"
                :owner-id="group ? null : ownerId"
                :group-id="group ? group.id : null"
                :owner-name="ownerName"
                :managing="managing"
                @close="closePanel"
            />
            <GiftWishPanel v-if="!recipient" :open="giftPanelOpen" :wish="selectedWish" :editable="sharedMember && list.can_edit === true" @edit="editingWishId = $event" @close="closePanel" />
            <GroupPanel
                ref="groupPanelRef"
                :open="groupPanelOpen"
                :mode="groupPanel === 'edit' ? 'edit' : 'create'"
                :group="groupPanel === 'edit' ? list.group : null"
                :candidates="group_candidates"
                :viewer-id="viewerId"
                @close="closeGroupPanel"
            />
        </div>
    </AppShell>
</template>

<style scoped>
.wishlist { display: flex; align-items: flex-start; gap: var(--space-4); }
.wishlist__main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: var(--space-4); }
.wishlist__title { margin: 0; font-size: var(--text-xl); font-weight: 600; line-height: 1.2; }
</style>
