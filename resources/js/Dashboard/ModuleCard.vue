<script setup>
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import UiCard from '../Components/ui/UiCard.vue';
import UiEmptyState from '../Components/ui/UiEmptyState.vue';
import UiButton from '../Components/ui/UiButton.vue';
import { MODULES } from '../Shell/modules.js';
import { useShell } from '../Shell/shellState.js';

const props = defineProps({ moduleKey: { type: String, required: true } });
defineEmits(['add']);
const { t } = useI18n();
const shell = useShell();
const uid = useId();
const headingId = `mc-${uid}`;
const mod = computed(() => MODULES.find((m) => m.key === props.moduleKey));
</script>

<template>
    <UiCard :title="t(mod.titleKey)" :heading-level="2" :title-id="headingId">
        <template #action>
            <button type="button" class="module-card__open" :aria-describedby="headingId" @click="shell.openComingSoon(moduleKey, $event.currentTarget)">
                {{ t('ui.open') }}
            </button>
        </template>
        <UiEmptyState :icon="mod.icon" :title="t(`empty.${moduleKey}.title`)" :body="t(`empty.${moduleKey}.body`)" :heading-level="3">
            <template v-if="moduleKey === 'tasks'" #action>
                <UiButton variant="secondary" @click="$emit('add')">{{ t('nav.add_task') }}</UiButton>
            </template>
        </UiEmptyState>
    </UiCard>
</template>

<style scoped>
.module-card__open {
    min-height: 44px;
    padding: 0 var(--space-2);
    border: 0;
    background: transparent;
    color: var(--color-primary);
    font: inherit;
    font-weight: 600;
    cursor: pointer;
}
.module-card__open:hover { text-decoration: underline; }
.module-card__open:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 1px; border-radius: var(--radius-md); }
</style>
