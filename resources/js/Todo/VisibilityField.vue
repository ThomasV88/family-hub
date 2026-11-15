<script setup>
import { computed, useId } from 'vue';
import { Lock, Users, UserCheck } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiAvatar from '../Components/ui/UiAvatar.vue';
import UiCheckbox from '../Components/ui/UiCheckbox.vue';
import UiRadio from '../Components/ui/UiRadio.vue';

const props = defineProps({
    modelValue: { type: String, default: 'family' },
    memberIds: { type: Array, default: () => [] },
    members: { type: Array, default: () => [] },
    // The creator is always included, so they are not listed in the checklist.
    creatorId: { type: Number, default: null },
    // When false, only the current value is shown.
    editable: { type: Boolean, default: true },
    error: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue', 'update:memberIds']);
const { t } = useI18n();
const uid = useId();

const options = [
    { value: 'personal', icon: Lock },
    { value: 'family', icon: Users },
    { value: 'selected', icon: UserCheck },
];
const others = computed(() => props.members.filter((m) => m.id !== props.creatorId));

function toggle(id, on) {
    const next = on ? [...new Set([...props.memberIds, id])] : props.memberIds.filter((x) => x !== id);
    emit('update:memberIds', next);
}
</script>

<template>
    <p v-if="!editable" class="visibility__readonly" data-testid="visibility-readonly">
        <span class="visibility__label">{{ t('todo.visibility.label') }}:</span>
        {{ t(`todo.visibility.${modelValue}`) }}
    </p>
    <fieldset v-else class="visibility" :aria-describedby="error ? `${uid}-error` : undefined">
        <legend class="visibility__label">{{ t('todo.visibility.label') }}</legend>
        <div class="visibility__cards">
            <div v-for="o in options" :key="o.value" class="visibility__card" :class="{ 'is-selected': modelValue === o.value }">
                <UiRadio :model-value="modelValue" :value="o.value" :name="`${uid}-visibility`" @update:model-value="(v) => emit('update:modelValue', v)">
                    <component :is="o.icon" :size="16" :stroke-width="1.75" aria-hidden="true" />
                    {{ t(`todo.visibility.${o.value}`) }}
                </UiRadio>
                <p class="visibility__hint">{{ t(`todo.visibility.${o.value}_hint`) }}</p>
            </div>
        </div>
        <fieldset v-if="modelValue === 'selected'" class="visibility__members">
            <legend class="visibility__sub">{{ t('todo.visibility.choose') }}</legend>
            <UiCheckbox
                v-for="m in others"
                :key="m.id"
                :model-value="memberIds.includes(m.id)"
                :aria-invalid="error ? 'true' : undefined"
                @update:model-value="(on) => toggle(m.id, on)"
            >
                <UiAvatar :name="m.name" :size="32" decorative />
                {{ m.name }}
            </UiCheckbox>
        </fieldset>
        <p v-if="error" :id="`${uid}-error`" class="visibility__error" role="alert">{{ error }}</p>
    </fieldset>
</template>

<style scoped>
.visibility { border: 0; margin: 0 0 var(--space-4); padding: 0; min-width: 0; }
.visibility__label { font-size: var(--text-sm); font-weight: 600; line-height: 1.4; padding: 0; }
.visibility__cards { display: flex; flex-direction: column; gap: var(--space-2); margin-top: var(--space-1); }
.visibility__card { border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: var(--space-3); background: var(--color-surface); }
.visibility__card.is-selected { border-color: var(--color-primary); background: var(--color-surface-2); }
.visibility__hint { margin: var(--space-1) 0 0; padding-inline-start: 28px; font-size: var(--text-sm); color: var(--color-text-muted); }
.visibility__members { border: 0; margin: var(--space-3) 0 0; padding: 0; display: flex; flex-direction: column; gap: var(--space-2); }
.visibility__sub { font-size: var(--text-sm); font-weight: 600; padding: 0; margin-bottom: var(--space-1); }
.visibility__error { margin: var(--space-2) 0 0; font-size: var(--text-sm); color: var(--color-danger); }
.visibility__readonly { margin: 0 0 var(--space-4); font-size: var(--text-base); }
</style>
