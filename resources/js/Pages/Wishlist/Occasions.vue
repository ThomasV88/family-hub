<script setup>
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Pencil, Plus } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import AppShell from '../../Layouts/AppShell.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiList from '../../Components/ui/UiList.vue';
import UiListRow from '../../Components/ui/UiListRow.vue';
import MemberDatesTable from '../../Wishlist/MemberDatesTable.vue';
import OccasionList from '../../Wishlist/OccasionList.vue';
import OccasionPanel from '../../Wishlist/OccasionPanel.vue';
import ShellAdd from '../../Wishlist/ShellAdd.vue';
import { formatDayMonth } from '../../Wishlist/occasionIcons.js';

const props = defineProps({
    upcoming: { type: Array, default: () => [] },
    members: { type: Array, default: () => [] },
    custom: { type: Array, default: () => [] },
    can_add: { type: Boolean, default: false },
    today: { type: String, default: '' },
});
const { t, locale } = useI18n();

// Panel state mirrors ?panel=dates&member={id} or ?panel=custom[&occasion={id}] (deep-linkable, server-validated by the lists).
function fromUrl() {
    const raw = usePage()?.url ?? '';
    const query = new URLSearchParams(raw.includes('?') ? raw.slice(raw.indexOf('?') + 1) : '');
    const panel = query.get('panel');
    if (panel === 'dates') {
        const id = Number(query.get('member'));
        return Number.isInteger(id) && props.members.some((m) => m.id === id && m.can_edit_dates) ? { mode: 'dates', member: id } : null;
    }
    if (panel === 'custom') {
        const raw = query.get('occasion');
        if (raw === null) return props.can_add ? { mode: 'custom', occasion: null } : null;
        const id = Number(raw);
        return props.custom.some((o) => o.id === id && o.can_edit) ? { mode: 'custom', occasion: id } : null;
    }
    return null;
}
const state = ref(fromUrl());
// Keeps the last panel target while the panel animates closed, so its form does not switch mode mid-animation.
const shown = ref(state.value);
watch(state, (next) => { if (next !== null) shown.value = next; });

const selectedMember = computed(() => (shown.value?.mode === 'dates' ? (props.members.find((m) => m.id === shown.value.member) ?? null) : null));
const selectedOccasion = computed(() => (shown.value?.mode === 'custom' && shown.value.occasion !== null ? (props.custom.find((o) => o.id === shown.value.occasion) ?? null) : null));
const panelOpen = computed(() => state.value !== null);

const panel = ref(null);
const guarded = (fn) => (panel.value ? panel.value.guard(fn) : fn());

function syncUrl() {
    const params = {};
    if (state.value?.mode === 'dates') Object.assign(params, { panel: 'dates', member: state.value.member });
    if (state.value?.mode === 'custom') {
        params.panel = 'custom';
        if (state.value.occasion !== null) params.occasion = state.value.occasion;
    }
    router.get('/wishlist/occasions', params, { preserveState: true, preserveScroll: true, replace: true, only: ['today'] });
}
function open(next) {
    guarded(() => {
        state.value = next;
        syncUrl();
    });
}
function close() {
    state.value = null;
    syncUrl();
}

const openAdd = () => open({ mode: 'custom', occasion: null });
const openDates = (id) => open({ mode: 'dates', member: id });
const openCustom = (id) => open({ mode: 'custom', occasion: id });
const customDate = (o) => formatDayMonth(o.month, o.day, locale.value) + (o.year ? ` ${o.year}` : '');
</script>

<template>
    <AppShell current="wishlist">
        <ShellAdd :enabled="can_add" :label="t('occasions.add')" @add="openAdd" />
        <div class="occasions" data-testid="occasions-page">
            <div class="occasions__main">
                <header class="occasions__header">
                    <h1 class="occasions__title">{{ t('occasions.title') }}</h1>
                    <UiButton v-if="can_add" variant="primary" :icon="Plus" class="occasions__add" @click="openAdd">{{ t('occasions.add') }}</UiButton>
                </header>

                <OccasionList :upcoming="upcoming" :can-add="can_add" @add="openAdd" />

                <MemberDatesTable :members="members" @edit="openDates" />

                <section v-if="custom.length > 0" class="custom" aria-labelledby="occasions-custom">
                    <h2 id="occasions-custom" class="custom__heading">{{ t('occasions.custom.title') }}</h2>
                    <UiList data-testid="custom-list">
                        <UiListRow v-for="o in custom" :key="o.id">
                            {{ o.title }}
                            <template #meta>
                                {{ customDate(o) }}<template v-if="o.owner">, {{ o.owner.name }}</template>
                            </template>
                            <template v-if="o.can_edit" #trailing>
                                <UiButton variant="ghost" :icon="Pencil" :aria-label="`${t('occasions.custom.edit')}: ${o.title}`" @click="openCustom(o.id)">{{ t('occasions.custom.edit') }}</UiButton>
                            </template>
                        </UiListRow>
                    </UiList>
                </section>
            </div>

            <OccasionPanel
                ref="panel"
                :open="panelOpen"
                :mode="shown?.mode ?? 'custom'"
                :member="selectedMember"
                :occasion="selectedOccasion"
                :members="members"
                :today="today"
                @close="close"
            />
        </div>
    </AppShell>
</template>

<style scoped>
.occasions { display: flex; align-items: flex-start; gap: var(--space-4); }
.occasions__main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: var(--space-6, var(--space-4)); }
.occasions__header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-3); }
.occasions__title { margin: 0; font-size: var(--text-xl); font-weight: 600; line-height: 1.2; }
.custom { display: flex; flex-direction: column; gap: var(--space-3); }
.custom__heading { margin: 0; font-size: var(--text-lg); font-weight: 600; line-height: 1.3; }
@media (min-width: 640px) {
    .occasions__add { display: none; }
}
</style>
