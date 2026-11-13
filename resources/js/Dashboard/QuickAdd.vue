<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Plus } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiField from '../Components/ui/UiField.vue';
import UiInput from '../Components/ui/UiInput.vue';
import { useShell } from '../Shell/shellState.js';

const { t } = useI18n();
const shell = useShell();
const text = ref('');
const input = ref(null);

// Placeholder only in this phase: the text is cleared and never sent or stored (tasks arrive in Phase 3).
function submit(event) {
    if (event.isComposing) return;
    if (!text.value.trim()) return;
    text.value = '';
    shell.openComingSoon('tasks', event.target);
}

let unregister = null;
onMounted(() => { unregister = shell.registerAddHandler(() => input.value?.focus()); });
onBeforeUnmount(() => unregister?.());

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <div class="quick-add" data-testid="quick-add">
        <UiField :label="t('dashboard.quick_add_label')" label-visually-hidden>
            <UiInput
                ref="input"
                v-model="text"
                :icon="Plus"
                :placeholder="t('dashboard.quick_add_placeholder')"
                enterkeyhint="done"
                autocomplete="off"
                @keydown.enter="submit"
            />
        </UiField>
    </div>
</template>

<style scoped>
.quick-add :deep(.ui-field) { margin-bottom: 0; }
</style>
