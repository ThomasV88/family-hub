<script setup>
import { ref, watch } from 'vue';
import { Plus } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiButton from '../Components/ui/UiButton.vue';
import UiCard from '../Components/ui/UiCard.vue';
import UiField from '../Components/ui/UiField.vue';
import UiInput from '../Components/ui/UiInput.vue';
import { useQuickAdd } from './useQuickAdd.js';

const { t } = useI18n();
const input = ref(null);
const { text, error, processing, submit } = useQuickAdd({ onAdded: () => input.value?.focus() });

watch(text, () => { error.value = ''; });
defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <UiCard class="quick-bar" data-testid="todo-quick-add">
        <form class="quick-bar__form" novalidate @submit.prevent="submit">
            <div class="quick-bar__field">
                <UiField :label="t('todo.quick.label')" label-visually-hidden :error="error">
                    <UiInput
                        ref="input"
                        v-model="text"
                        :placeholder="t('todo.quick.placeholder')"
                        enterkeyhint="done"
                        autocomplete="off"
                        @keydown.enter.prevent="submit"
                    />
                </UiField>
            </div>
            <UiButton type="submit" variant="primary" :icon="Plus" :loading="processing">{{ t('todo.add') }}</UiButton>
        </form>
    </UiCard>
</template>

<style scoped>
.quick-bar__form { display: flex; align-items: flex-start; gap: var(--space-3); }
.quick-bar__field { flex: 1; min-width: 0; }
.quick-bar__field :deep(.ui-field) { margin-bottom: 0; }
.quick-bar :deep(.ui-card__inner) { padding: var(--space-3); }
</style>
