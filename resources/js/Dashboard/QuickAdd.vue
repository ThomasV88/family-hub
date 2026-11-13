<script setup>
import { ref } from 'vue';
import { Plus } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiField from '../Components/ui/UiField.vue';
import UiInput from '../Components/ui/UiInput.vue';
import { useQuickAdd } from '../Todo/useQuickAdd.js';

const { t } = useI18n();
const input = ref(null);
// Same create call as the board: title only, the server defaults to Family visibility with the creator as assignee.
// The "Task added" toast is the server flash shown by the shell. The shell Add button opens /todo (plan 03-06).
const { text, error, submit } = useQuickAdd({ onAdded: () => input.value?.focus() });

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <div class="quick-add" data-testid="quick-add">
        <UiField :label="t('dashboard.quick_add_label')" label-visually-hidden :error="error">
            <UiInput
                ref="input"
                v-model="text"
                :icon="Plus"
                :placeholder="t('dashboard.quick_add_placeholder')"
                enterkeyhint="done"
                autocomplete="off"
                @keydown.enter.prevent="submit"
            />
        </UiField>
    </div>
</template>

<style scoped>
.quick-add :deep(.ui-field) { margin-bottom: 0; }
</style>
