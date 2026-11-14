<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Plus } from '@lucide/vue';
import UiButton from '../Components/ui/UiButton.vue';

// One chip per person the server sent; nothing is drawn for anyone else. Chips are real links to the person page
// (the id is an opaque uuid), the current one is checked. Arrow keys only move focus, Enter follows the link.
const props = defineProps({
    people: { type: Array, default: () => [] },
    currentId: { type: String, default: null },
    canCreate: { type: Boolean, default: false },
});
const emit = defineEmits(['add']);
const { t } = useI18n();
const group = ref(null);

function stops() {
    return [...(group.value?.querySelectorAll('[role="radio"]') ?? [])];
}
function move(event, step) {
    const items = stops();
    const index = items.indexOf(event.target);
    if (index === -1 || items.length === 0) return;
    event.preventDefault();
    items[(index + step + items.length) % items.length].focus();
}
// With no current person the first chip is the single tab stop.
const tabbable = (person, index) => (props.currentId ? person.id === props.currentId : index === 0);
</script>

<template>
    <div class="person-switcher">
        <div ref="group" class="person-switcher__chips" role="radiogroup" :aria-label="t('health.people_label')" @keydown.right="move($event, 1)" @keydown.down="move($event, 1)" @keydown.left="move($event, -1)" @keydown.up="move($event, -1)">
            <Link
                v-for="(person, index) in people"
                :key="person.id"
                :href="`/health/people/${person.id}`"
                role="radio"
                class="person-switcher__chip"
                :class="{ 'is-on': person.id === currentId }"
                :aria-checked="person.id === currentId ? 'true' : 'false'"
                :tabindex="tabbable(person, index) ? 0 : -1"
            >
                <span class="person-switcher__initials" aria-hidden="true">{{ person.initials }}</span>
                <span class="person-switcher__name">{{ person.name }}</span>
            </Link>
        </div>
        <UiButton v-if="canCreate && people.length > 0" variant="ghost" size="sm" :icon="Plus" data-testid="add-person" @click="emit('add')">{{ t('health.person.add') }}</UiButton>
    </div>
</template>

<style scoped>
.person-switcher { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); min-width: 0; }
.person-switcher__chips { display: flex; flex-wrap: wrap; gap: var(--space-2); min-width: 0; }
.person-switcher__chip {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    min-height: var(--control-sm);
    max-width: 100%;
    padding: var(--space-1) var(--space-3);
    border: 1px solid var(--color-border-strong);
    border-radius: var(--radius-full);
    background: var(--color-surface);
    color: var(--color-text);
    font-size: var(--text-sm);
    font-weight: 600;
    text-decoration: none;
}
@media (pointer: coarse) {
    .person-switcher__chip { min-height: var(--control-touch); }
}
.person-switcher__chip:is(:hover, .is-hover) { background: var(--color-surface-2); }
.person-switcher__chip.is-on { background: var(--color-primary-soft); color: var(--color-primary); border-color: var(--color-primary); }
.person-switcher__chip:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.person-switcher__initials { font-size: var(--text-xs, 0.75rem); opacity: 0.8; }
.person-switcher__name { overflow-wrap: anywhere; text-align: start; }
</style>
