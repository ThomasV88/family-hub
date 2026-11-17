<script setup>
import { useI18n } from 'vue-i18n';
import { Link, PencilLine } from '@lucide/vue';
import { router } from '@inertiajs/vue3';
import UiDialog from '../ui/UiDialog.vue';
import UiList from '../ui/UiList.vue';
import UiListRow from '../ui/UiListRow.vue';

// "New recipe" when reading web pages is available: two rows, type it in (the plain form) or read from a web page (the URL
// step, opened by the page through `url`). The page skips this dialog when no AI agent is enabled.
defineProps({ open: { type: Boolean, default: false } });
const emit = defineEmits(['update:open', 'url']);
const { t } = useI18n();

function typeIn() {
    emit('update:open', false);
    router.visit('/recipes/create');
}
function fromPage() {
    emit('update:open', false);
    emit('url');
}
</script>

<template>
    <UiDialog :open="open" :title="t('recipes.new.title')" @update:open="emit('update:open', $event)">
        <UiList class="rec-new" data-testid="new-recipe-options">
            <UiListRow as="button" class="rec-new__row" data-testid="new-type-it-in" @select="typeIn">
                <template #leading><PencilLine :size="24" :stroke-width="1.75" aria-hidden="true" /></template>
                <span class="rec-new__name">{{ t('recipes.new.type_it_in') }}</span>
                <span class="rec-new__sub">{{ t('recipes.new.type_it_in_hint') }}</span>
            </UiListRow>
            <UiListRow as="button" class="rec-new__row" data-testid="new-from-page" @select="fromPage">
                <template #leading><Link :size="24" :stroke-width="1.75" aria-hidden="true" /></template>
                <span class="rec-new__name">{{ t('recipes.new.from_page') }}</span>
                <span class="rec-new__sub">{{ t('recipes.new.from_page_hint') }}</span>
            </UiListRow>
        </UiList>
    </UiDialog>
</template>

<style scoped>
.rec-new__row { min-height: 64px; }
.rec-new__row :deep(.ui-row__title) { display: flex; flex-direction: column; gap: var(--space-1); }
.rec-new__name { font-weight: 600; }
.rec-new__sub { color: var(--color-text-muted); font-size: var(--text-sm); }
</style>
