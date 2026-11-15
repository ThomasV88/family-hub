<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiButton from '../ui/UiButton.vue';
import UiDialog from '../ui/UiDialog.vue';
import UiField from '../ui/UiField.vue';
import UiInput from '../ui/UiInput.vue';

// Naming a new passkey (`add`: cannot be dismissed, Save or Skip) and renaming an existing one (`rename`).
const props = defineProps({
    open: { type: Boolean, default: false },
    mode: { type: String, default: 'add', validator: (v) => ['add', 'rename'].includes(v) },
    name: { type: String, default: '' },
    error: { type: String, default: '' },
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['update:open', 'save', 'skip']);
const { t } = useI18n();

const value = ref(props.name);
const input = ref(null);
watch(() => props.open, (open) => { if (open) value.value = props.name; }, { immediate: true });

function save() {
    if (props.busy) return;
    emit('save', value.value.trim());
}
</script>

<template>
    <UiDialog
        :open="open"
        :title="mode === 'add' ? t('passkey.name_title') : t('passkey.rename_title')"
        :dismissable="mode === 'rename'"
        :initial-focus="() => input?.$el?.querySelector('input') ?? null"
        @update:open="(v) => emit('update:open', v)"
    >
        <form id="passkey-name-form" novalidate @submit.prevent="save">
            <UiField :label="t('passkey.name_label')" :hint="mode === 'add' ? t('passkey.name_hint') : ''" :error="error ? t(error) : ''">
                <UiInput ref="input" v-model="value" maxlength="60" autocomplete="off" :disabled="busy" />
            </UiField>
        </form>
        <template #footer>
            <UiButton v-if="mode === 'add'" variant="secondary" :disabled="busy" data-testid="skip-naming" @click="emit('skip')">
                {{ t('passkey.cta.skip_naming') }}
            </UiButton>
            <UiButton variant="primary" :loading="busy" data-testid="save-name" @click="save">
                {{ t('passkey.cta.save_name') }}
            </UiButton>
        </template>
    </UiDialog>
</template>
