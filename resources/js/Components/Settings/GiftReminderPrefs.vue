<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UiCard from '../ui/UiCard.vue';
import UiCheckbox from '../ui/UiCheckbox.vue';
import UiAlert from '../ui/UiAlert.vue';

const props = defineProps({
    leadDays: { type: Array, default: () => [14, 3] },
    allowed: { type: Array, default: () => [14, 7, 3, 1] },
    channelsOff: { type: Boolean, default: false },
});

const { t } = useI18n();
const selected = ref([...props.leadDays]);
const failed = ref(false);
const noneOn = computed(() => selected.value.length === 0);

function toggle(day, on) {
    const previous = [...selected.value];
    const next = on ? [...new Set([...previous, day])] : previous.filter((d) => d !== day);
    next.sort((a, b) => b - a);
    selected.value = next;
    failed.value = false;
    router.put('/settings/notifications/gifts', { lead_days: next }, {
        preserveScroll: true,
        onError: () => { selected.value = previous; failed.value = true; },
    });
}
</script>

<template>
    <UiCard :title="t('notifications.gifts_title')" class="gift-prefs" data-testid="gift-reminders">
        <p class="gift-prefs__text">{{ t('notifications.gifts_intro') }}</p>
        <p class="gift-prefs__hint">{{ t('notifications.gifts_channels') }}</p>
        <UiAlert v-if="failed" variant="danger" live class="gift-prefs__alert">{{ t('notifications.save_failed') }}</UiAlert>
        <fieldset class="gift-prefs__group">
            <legend class="gift-prefs__legend">{{ t('notifications.gifts_legend') }}</legend>
            <UiCheckbox
                v-for="day in allowed"
                :key="day"
                :model-value="selected.includes(day)"
                :data-testid="`gift-${day}`"
                class="gift-prefs__option"
                @update:model-value="toggle(day, $event)"
            >{{ t(`notifications.gifts_${day}`) }}</UiCheckbox>
        </fieldset>
        <UiAlert v-if="noneOn" variant="info" live class="gift-prefs__alert" data-testid="gifts-none-on">{{ t('notifications.gifts_none_on') }}</UiAlert>
        <UiAlert v-else-if="channelsOff" variant="info" live class="gift-prefs__alert" data-testid="gifts-both-off">{{ t('notifications.gifts_both_off') }}</UiAlert>
    </UiCard>
</template>

<style scoped>
.gift-prefs { margin-block-start: var(--space-4); }
.gift-prefs__text { margin: 0; }
.gift-prefs__hint { margin: var(--space-1) 0 var(--space-4); color: var(--color-text-muted); font-size: var(--text-sm); }
.gift-prefs__alert { margin-block: var(--space-3); }
.gift-prefs__group { margin: 0; padding: 0; border: 0; display: flex; flex-direction: column; gap: var(--space-1); }
.gift-prefs__legend { padding: 0; margin-block-end: var(--space-2); font-weight: 600; }
.gift-prefs__option { min-height: 44px; }
</style>
