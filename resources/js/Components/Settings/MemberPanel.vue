<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import UiDetailPanel from '../ui/UiDetailPanel.vue';
import UiTabs from '../ui/UiTabs.vue';
import UiConfirmDialog from '../ui/UiConfirmDialog.vue';
import PermissionsTab from './PermissionsTab.vue';

const props = defineProps({
    member: { type: Object, required: true },
    presets: { type: Object, required: true },
    initialTab: { type: String, default: 'permissions' },
});
const emit = defineEmits(['close']);
const { t } = useI18n();

const tab = ref(props.initialTab);
const dirty = ref(false);
const confirming = ref(false);

// One entry per tab. Plan 08 adds the Sessions tab here (one entry plus one panel slot below).
const tabs = computed(() => [
    { key: 'permissions', label: t('permissions.tab.permissions') },
]);

function requestClose() {
    if (dirty.value) {
        confirming.value = true;
        return;
    }
    emit('close');
}
</script>

<template>
    <UiDetailPanel :open="true" :title="member.name" @update:open="(open) => !open && requestClose()">
        <p class="member-panel__email">{{ member.email }}</p>
        <UiTabs v-model="tab" :tabs="tabs" :label="member.name">
            <template #panel-permissions>
                <PermissionsTab :member="member" :presets="presets" @dirty="dirty = $event" />
            </template>
        </UiTabs>
        <UiConfirmDialog
            v-model:open="confirming"
            :title="t('permissions.discard_title')"
            :body="t('permissions.discard_body', { name: member.name })"
            :confirm-label="t('permissions.discard_confirm')"
            :cancel-label="t('permissions.discard_keep')"
            @confirm="emit('close')"
        />
    </UiDetailPanel>
</template>

<style scoped>
.member-panel__email { margin: 0 0 var(--space-3); color: var(--color-text-muted); overflow-wrap: anywhere; }
</style>
