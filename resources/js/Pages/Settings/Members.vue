<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import SettingsLayout from '../../Layouts/SettingsLayout.vue';
import UiCard from '../../Components/ui/UiCard.vue';
import UiList from '../../Components/ui/UiList.vue';
import UiTabs from '../../Components/ui/UiTabs.vue';
import MemberRow from '../../Components/Settings/MemberRow.vue';
import MemberPanel from '../../Components/Settings/MemberPanel.vue';
import PermissionMatrix from '../../Components/Settings/PermissionMatrix.vue';

const props = defineProps({
    sections: { type: Array, default: () => [] },
    members: { type: Array, default: () => [] },
    presets: { type: Object, default: () => ({}) },
    openMember: { type: Number, default: null },
});

const { t } = useI18n();
const view = ref('members');
const openId = ref(props.openMember);
const openTab = ref('permissions');

const opened = computed(() => props.members.find((m) => m.id === openId.value) ?? null);
const viewTabs = computed(() => [
    { key: 'members', label: t('permissions.members_tab') },
    { key: 'matrix', label: t('permissions.matrix_tab') },
]);

function open(id, tab = 'permissions') {
    openTab.value = tab;
    openId.value = id;
}
</script>

<template>
    <Head :title="t('settings.section.members')" />
    <SettingsLayout :sections="sections" current="members" title="settings.section.members">
        <UiTabs v-model="view" :tabs="viewTabs" :label="t('settings.section.members')">
            <template #panel-members>
                <UiCard :title="t('settings.members.title')" class="members__card">
                    <UiList>
                        <MemberRow v-for="member in members" :key="member.id" :member="member" :selected="member.id === openId" @manage="open(member.id)" />
                    </UiList>
                </UiCard>
            </template>
            <template #panel-matrix>
                <PermissionMatrix :members="members" @open="open($event)" />
            </template>
        </UiTabs>
        <template #panel>
            <MemberPanel v-if="opened" :key="`${opened.id}-${openTab}`" :member="opened" :presets="presets" :initial-tab="openTab" @close="openId = null" />
        </template>
    </SettingsLayout>
</template>

<style scoped>
.members__card { margin-block-start: var(--space-4); }
</style>
