<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UiAlert from '../ui/UiAlert.vue';
import UiButton from '../ui/UiButton.vue';
import UiCheckbox from '../ui/UiCheckbox.vue';

// Admin picks who may manage (add and edit wishes on) this member's list; managers see the list as the member does.
const props = defineProps({
    member: { type: Object, required: true },
    // Every member of the family: [{ id, name, ... }]
    members: { type: Array, default: () => [] },
});
const emit = defineEmits(['dirty']);
const { t } = useI18n();

const others = computed(() => props.members.filter((m) => m.id !== props.member.id));
const saved = () => [...(props.member.wishlistManagerIds ?? [])].sort((a, b) => a - b);
const form = useForm({ manager_ids: saved() });
const failed = ref(false);

const dirty = computed(() => JSON.stringify([...form.manager_ids].sort((a, b) => a - b)) !== JSON.stringify(saved()));
watch(dirty, (value) => emit('dirty', value), { immediate: true });
watch(() => props.member.wishlistManagerIds, () => {
    if (!dirty.value) form.manager_ids = saved();
}, { deep: true });

const checked = (id) => form.manager_ids.includes(id);
function toggle(id, on) {
    const without = form.manager_ids.filter((x) => x !== id);
    form.manager_ids = on ? [...without, id] : without;
}
function submit() {
    failed.value = false;
    form.put(`/settings/members/${props.member.id}/wishlist-managers`, {
        preserveScroll: true,
        onError: () => { failed.value = true; },
        onHttpException: () => { failed.value = true; },
        onNetworkError: () => { failed.value = true; },
    });
}
</script>

<template>
    <form class="wm-tab" @submit.prevent="submit">
        <UiAlert v-if="failed" variant="danger" live>{{ t('settings.members.wishlist_managers.save_failed') }}</UiAlert>
        <h3 class="wm-tab__title">{{ t('settings.members.wishlist_managers.title') }}</h3>
        <p class="wm-tab__hint">{{ t('settings.members.wishlist_managers.hint', { name: member.name }) }}</p>
        <p v-if="others.length === 0" class="wm-tab__hint">{{ t('settings.members.wishlist_managers.none') }}</p>
        <ul v-else class="wm-tab__list" role="list">
            <li v-for="other in others" :key="other.id">
                <UiCheckbox :model-value="checked(other.id)" @update:model-value="toggle(other.id, $event)">{{ other.name }}</UiCheckbox>
            </li>
        </ul>
        <div v-if="others.length" class="wm-tab__actions">
            <UiButton type="submit" variant="primary" :disabled="!dirty || form.processing" :loading="form.processing">{{ t('settings.members.wishlist_managers.save') }}</UiButton>
        </div>
    </form>
</template>

<style scoped>
.wm-tab { display: flex; flex-direction: column; gap: var(--space-3); }
.wm-tab__title { margin: 0; font-size: var(--text-base); font-weight: 600; }
.wm-tab__hint { margin: 0; color: var(--color-text-muted); font-size: var(--text-sm); }
.wm-tab__list { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: var(--space-1); }
.wm-tab__actions { display: flex; gap: var(--space-2); }
</style>
