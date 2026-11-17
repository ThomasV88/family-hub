<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { router } from '@inertiajs/vue3';
import { Archive, FileDown, IdCard, Pencil, Plus, Trash2, Upload, UserX } from '@lucide/vue';
import UiAvatar from '../Components/ui/UiAvatar.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiConfirmDialog from '../Components/ui/UiConfirmDialog.vue';
import UiMenu from '../Components/ui/UiMenu.vue';
import UiTag from '../Components/ui/UiTag.vue';
import AiConsentControl from './AiConsentControl.vue';
import { useRemoval } from './useRemoval.js';
import HelpTip from '../Components/ui/HelpTip.vue';

// Name, age line, blood type and the "No account" marker. Free text is interpolated, never rendered as HTML.
const props = defineProps({
    person: { type: Object, required: true },
    // The page's search.enabled: recognised text can exist, so turning AI consent off says it deletes it.
    textStored: { type: Boolean, default: false },
});
const emit = defineEmits(['add-visit', 'add-document', 'edit-person', 'open-archive']);
const removal = useRemoval('person', () => props.person);
const { t } = useI18n();

const ageLine = computed(() => {
    const parts = [];
    if (props.person.ageYears !== null && props.person.ageYears !== undefined) {
        parts.push(t('health.age', { n: props.person.ageYears }, props.person.ageYears));
    }
    if (props.person.sex) parts.push(t(`health.sex.${props.person.sex}`));
    return parts.join(' · ');
});
const bloodLabel = computed(() => {
    const type = props.person.bloodType;
    if (!type) return '';
    return t(`health.blood.${type.toLowerCase().replace('+', '_pos').replace('-', '_neg')}`);
});

const deleteOpen = ref(false);
const menuItems = computed(() => [
    { type: 'item', key: 'edit', label: t('health.person.edit'), icon: Pencil },
    { type: 'item', key: 'archive', label: t('health.archive.open'), icon: Archive },
    { type: 'item', key: 'delete', label: removal.action.value, icon: Trash2 },
]);
const onMenu = ({ key }) => {
    if (key === 'edit') emit('edit-person');
    else if (key === 'archive') emit('open-archive');
    else if (key === 'delete') deleteOpen.value = true;
};
const remove = () => router.delete(`/health/people/${props.person.id}`);
</script>

<template>
    <header class="person-header" data-testid="person-header">
        <UiAvatar :name="person.name" :size="40" :user-id="person.photoUserId ?? null" decorative />
        <div class="person-header__main">
            <h2 class="person-header__name">{{ person.name }}</h2>
            <p v-if="ageLine" class="person-header__line">{{ ageLine }}</p>
        </div>
        <div class="person-header__tags">
            <UiTag v-if="bloodLabel">{{ t('health.blood.label') }} {{ bloodLabel }}</UiTag>
            <UiTag v-if="!person.hasAccount" :icon="UserX">{{ t('health.person.no_account') }}</UiTag>
        </div>
        <div class="person-header__actions">
            <template v-if="person.canEdit">
                <UiButton variant="primary" :icon="Plus" class="person-header__edit-action" data-testid="header-add-visit" @click="emit('add-visit')">{{ t('health.visit.add') }}</UiButton>
                <UiButton variant="secondary" :icon="Upload" class="person-header__edit-action" data-testid="header-add-document" @click="emit('add-document')">{{ t('health.doc.add') }}</UiButton>
                <UiButton variant="secondary" :icon="FileDown" class="person-header__edit-action" :href="`/health/people/${person.id}/export`" data-testid="header-export">{{ t('health.export.title') }}</UiButton>
            </template>
            <UiButton variant="secondary" :icon="IdCard" :href="`/health/people/${person.id}/emergency-card`" data-testid="header-emergency">{{ t('health.emergency.title') }}</UiButton>
            <HelpTip topic="health.emergency_card" :label="t('health.emergency.title')" />
        </div>
        <div v-if="person.canEdit" class="person-header__menu" data-testid="header-menu">
            <UiMenu :label="t('health.person.more_actions')" :items="menuItems" @select="onMenu" />
        </div>
        <p v-if="!person.canEdit" class="person-header__view-only">{{ t('health.view_only') }}</p>
        <AiConsentControl :person-id="person.id" :value="!!person.aiConsent" :can-edit="!!person.canEdit" :purges-text="textStored" />
        <UiConfirmDialog
            v-model:open="deleteOpen"
            :title="removal.title.value"
            :body="removal.body.value"
            :confirm-label="removal.action.value"
            :cancel-label="t('health.person.delete_keep')"
            @confirm="remove"
        />
    </header>
</template>

<style scoped>
.person-header { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-3); }
.person-header__main { min-width: 0; }
.person-header__name { margin: 0; font-size: var(--text-xl); font-weight: 600; line-height: 1.2; overflow-wrap: anywhere; }
.person-header__line { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.person-header__tags { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.person-header__actions { display: flex; flex-wrap: wrap; gap: var(--space-2); margin-inline-start: auto; }
.person-header__menu { flex: none; }
.person-header__actions + .person-header__menu { margin-inline-start: 0; }
@container (max-width: 640px) { .person-header__edit-action { display: none; } }
.person-header__view-only { flex-basis: 100%; margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
</style>
