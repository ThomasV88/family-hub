<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShell from '../../Layouts/AppShell.vue';
import GiftListView from '../../Wishlist/GiftListView.vue';
import GiftWishPanel from '../../Wishlist/GiftWishPanel.vue';
import GroupChips from '../../Wishlist/GroupChips.vue';
import GroupPanel from '../../Wishlist/GroupPanel.vue';
import OwnListView from '../../Wishlist/OwnListView.vue';
import OwnWishPanel from '../../Wishlist/OwnWishPanel.vue';
import OccasionStrip from '../../Wishlist/OccasionStrip.vue';
import PersonChips from '../../Wishlist/PersonChips.vue';
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
const canAdd = computed(() => recipient.value && props.list.can_edit === true);
const addLabel = computed(() => (managing.value ? t('wishlist.add_for', { name: ownerName.value }) : t('wishlist.add')));

const allWishes = computed(() => [...(props.list.wishes ?? []), ...(props.list.received ?? [])]);
const selectedWish = computed(() => (Number.isInteger(props.selected) ? (allWishes.value.find((w) => w.id === props.selected) ?? null) : null));
const panelMode = computed(() => {
    if (recipient.value) {
        if (props.selected === 'new') return canAdd.value ? 'create' : null;
        return selectedWish.value ? 'edit' : null;
    }
    return selectedWish.value ? 'gift' : null;
});
const closing = ref(false);
const panelOpen = computed(() => panelMode.value !== null && !closing.value);
watch(panelMode, (mode) => { if (mode === null) closing.value = false; });

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
                    </template>
                </OwnListView>
                <GiftListView v-else :list="list" :selected-id="Number.isInteger(selected) ? selected : null" @open="openWish">
                    <template #context>
                        <PersonChips :members="members" :selected-id="ownerId" :guard="guarded" />
                        <GroupChips :groups="groups" :selected-id="selectedGroupId" :can-create="canCreateGroup" :guard="guarded" @create="openGroupCreate" />
                        <OccasionStrip :occasions="occasions" />
                    </template>
                </GiftListView>
            </div>
            <OwnWishPanel
                v-if="recipient"
                ref="panel"
                :open="panelOpen"
                :mode="panelMode === 'create' ? 'create' : 'edit'"
                :wish="panelMode === 'edit' ? selectedWish : null"
                :owner-id="ownerId"
                :owner-name="ownerName"
                :managing="managing"
                @close="closePanel"
            />
            <GiftWishPanel v-else :open="panelOpen" :wish="selectedWish" @close="closePanel" />
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
