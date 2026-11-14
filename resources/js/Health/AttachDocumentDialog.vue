<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiButton from '../Components/ui/UiButton.vue';
import UiDialog from '../Components/ui/UiDialog.vue';
import UiRadio from '../Components/ui/UiRadio.vue';

// Pick one of this person's documents that has no visit yet, or go and upload a new one for the visit. The parent
// does the PATCH (it must send visit_id explicitly); nothing is kept here after the dialog closes.
const props = defineProps({
    open: { type: Boolean, default: false },
    documents: { type: Array, default: () => [] },
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['update:open', 'attach', 'upload']);
const { t } = useI18n();
const selected = ref('');

watch(() => props.open, (open) => { if (open) selected.value = ''; });
</script>

<template>
    <UiDialog :open="open" :title="t('health.visit.attach_title')" @update:open="(v) => emit('update:open', v)">
        <fieldset v-if="documents.length > 0" class="attach__list" data-testid="attach-list">
            <legend class="visually-hidden">{{ t('health.visit.attach_title') }}</legend>
            <UiRadio v-for="doc in documents" :key="doc.id" v-model="selected" name="attach-document" :value="doc.id">{{ doc.title }}</UiRadio>
        </fieldset>
        <template #footer>
            <UiButton variant="secondary" data-testid="attach-upload" @click="emit('upload')">{{ t('health.visit.upload_new') }}</UiButton>
            <UiButton variant="primary" :disabled="selected === ''" :loading="busy" data-testid="attach-confirm" @click="emit('attach', selected)">{{ t('health.visit.attach') }}</UiButton>
        </template>
    </UiDialog>
</template>

<style scoped>
.attach__list { display: flex; flex-direction: column; gap: var(--space-2); margin: 0; padding: 0; border: 0; min-width: 0; }
</style>
