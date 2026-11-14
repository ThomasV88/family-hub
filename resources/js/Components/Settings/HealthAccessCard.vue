<script setup>
import { useI18n } from 'vue-i18n';
import UiCard from '../ui/UiCard.vue';
import UiList from '../ui/UiList.vue';
import UiListRow from '../ui/UiListRow.vue';
import UiAvatar from '../ui/UiAvatar.vue';
import UiTag from '../ui/UiTag.vue';
import UiButton from '../ui/UiButton.vue';
import UiEmptyState from '../ui/UiEmptyState.vue';

// Admin-only card: person names and how many members can view or change them. Never records or record counts.
defineProps({
    people: { type: Array, default: () => [] },
    selectedId: { type: String, default: null },
});
const emit = defineEmits(['manage']);
const { t } = useI18n();

const count = (person, level) => Object.values(person.members ?? {}).filter((l) => l === level).length;
const tint = (index) => (index % 6) + 1;
</script>

<template>
    <UiCard id="health-access-card" tabindex="-1" :title="t('health.access.card_title')">
        <p class="health-access__intro">{{ t('health.access.intro') }}</p>
        <UiEmptyState v-if="!people.length" :title="t('health.access.empty_title')" :body="t('health.access.empty_body')" />
        <UiList v-else>
            <UiListRow v-for="(person, index) in people" :key="person.id" :selected="person.id === selectedId">
                <template #leading><UiAvatar :name="person.name" :tint="tint(index)" decorative /></template>
                <span class="health-access__name">{{ person.name }}</span>
                <template #meta>
                    <UiTag v-if="!person.hasAccount">{{ t('health.person.no_account') }}</UiTag>
                    <span class="health-access__counts">
                        {{ t('health.access.can_view', { n: count(person, 'view') }, count(person, 'view')) }}
                        |
                        {{ t('health.access.can_change', { n: count(person, 'edit') }, count(person, 'edit')) }}
                    </span>
                </template>
                <template #trailing>
                    <UiButton size="sm" :aria-label="`${t('health.access.manage')}: ${person.name}`" @click="emit('manage', person.id)">{{ t('health.access.manage') }}</UiButton>
                </template>
            </UiListRow>
        </UiList>
    </UiCard>
</template>

<style scoped>
.health-access__intro { margin: 0 0 var(--space-3); color: var(--color-text-muted); font-size: var(--text-sm); }
.health-access__name { overflow-wrap: anywhere; }
.health-access__counts { margin-inline-start: var(--space-2); }
#health-access-card:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
</style>
