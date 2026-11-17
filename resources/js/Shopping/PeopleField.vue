<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { X } from '@lucide/vue';
import UiAvatar from '../Components/ui/UiAvatar.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiField from '../Components/ui/UiField.vue';
import UiSelect from '../Components/ui/UiSelect.vue';
import UiTag from '../Components/ui/UiTag.vue';

// A list of people for one role of a shopping list: chips with a remove button and an "Add person" select.
// modelValue: array of user ids. directory: id -> { id, name, isChild } for every person that may appear.
// candidates: people that may be added; people in `exclude` (creator, responsible person) are never offered.
const props = defineProps({
    label: { type: String, required: true },
    modelValue: { type: Array, default: () => [] },
    directory: { type: Object, default: () => ({}) },
    candidates: { type: Array, default: () => [] },
    exclude: { type: Array, default: () => [] },
    readonly: { type: Boolean, default: false },
    help: { type: String, default: '' },
    error: { type: String, default: '' },
});
const emit = defineEmits(['add', 'remove']);
const { t } = useI18n();

const selected = ref('');
const people = computed(() => props.modelValue.map((id) => props.directory[id]).filter(Boolean));
const offered = computed(() => props.candidates.filter((c) => !props.exclude.includes(c.id) && !props.modelValue.includes(c.id)));

function pick(value) {
    selected.value = '';
    const id = Number(value);
    if (Number.isInteger(id) && id > 0) emit('add', id);
}
</script>

<template>
    <UiField :label="label" :help="help" :error="error">
        <ul v-if="people.length > 0" class="people__list" :aria-label="label" data-testid="people-list">
            <li v-for="person in people" :key="person.id" class="people__chip" data-testid="people-chip">
                <UiAvatar :name="person.name" :user-id="person.id" :size="32" decorative />
                <span class="people__name">{{ person.name }}</span>
                <UiTag v-if="person.isChild">{{ t('shopping.panel.child') }}</UiTag>
                <UiButton v-if="!readonly" class="people__remove" variant="ghost" size="sm" icon-only :icon="X" :label="t('shopping.panel.remove_person', { name: person.name })" @click="emit('remove', person.id)" />
            </li>
        </ul>
        <p v-else class="people__none" data-testid="people-none">{{ t('shopping.panel.nobody') }}</p>
        <UiSelect v-if="!readonly && offered.length > 0" :model-value="selected" :aria-label="t('shopping.panel.add_person_to', { role: label })" @update:model-value="pick">
            <option value="">{{ t('shopping.panel.add_person') }}</option>
            <option v-for="person in offered" :key="person.id" :value="person.id">{{ person.name }}{{ person.isChild ? ` (${t('shopping.panel.child')})` : '' }}</option>
        </UiSelect>
    </UiField>
</template>

<style scoped>
.people__list { display: flex; flex-direction: column; gap: var(--space-2); margin: 0 0 var(--space-2); padding: 0; list-style: none; }
.people__chip { display: flex; align-items: center; gap: var(--space-2); min-height: 44px; padding: var(--space-1) var(--space-2); border: 1px solid var(--color-border); border-radius: var(--radius-full); background: var(--color-surface); }
.people__name { flex: 1 1 auto; min-width: 0; font-size: var(--text-sm); font-weight: 600; overflow-wrap: anywhere; }
.people__remove { flex: none; min-width: 44px; min-height: 44px; }
.people__none { margin: 0 0 var(--space-2); color: var(--color-text-muted); font-size: var(--text-sm); }
</style>
