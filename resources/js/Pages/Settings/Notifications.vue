<script setup>
import { reactive, ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import SettingsLayout from '../../Layouts/SettingsLayout.vue';
import UiCard from '../../Components/ui/UiCard.vue';
import UiSwitch from '../../Components/ui/UiSwitch.vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import PushDeviceRow from '../../Components/Settings/PushDeviceRow.vue';

const props = defineProps({
    sections: { type: Array, default: () => [] },
    email: { type: String, default: '' },
    preferences: { type: Object, default: () => ({ mail: true, push: false }) },
    vapidPublicKey: { type: String, default: null },
});

const { t } = useI18n();
const state = reactive({ mail: props.preferences.mail, push: props.preferences.push });
const failed = ref(false);
const bothOff = computed(() => !state.mail && !state.push);

function save(channel, enabled) {
    const previous = state[channel];
    state[channel] = enabled;
    failed.value = false;
    router.put(`/settings/notifications/${channel}`, { enabled }, {
        preserveScroll: true,
        onError: () => { state[channel] = previous; failed.value = true; },
        onFinish: () => {},
    });
}
</script>

<template>
    <Head :title="t('notifications.nav')" />
    <SettingsLayout :sections="sections" current="notifications" title="notifications.nav">
        <p class="notifications__intro">{{ t('notifications.intro') }}</p>
        <UiAlert v-if="failed" variant="danger" live class="notifications__alert">{{ t('notifications.save_failed') }}</UiAlert>
        <UiCard :title="t('notifications.card_title')">
            <div class="notifications__row">
                <UiSwitch :model-value="state.mail" data-testid="switch-mail" @update:model-value="save('mail', $event)">{{ t('notifications.email') }}</UiSwitch>
                <p class="notifications__hint">{{ t('notifications.email_hint', { email }) }}</p>
            </div>
            <div class="notifications__row">
                <UiSwitch :model-value="state.push" data-testid="switch-push" @update:model-value="save('push', $event)">{{ t('notifications.push') }}</UiSwitch>
                <p class="notifications__hint">{{ t('notifications.push_hint') }}</p>
                <PushDeviceRow :vapid-public-key="vapidPublicKey" @enabled="save('push', true)" />
            </div>
        </UiCard>
        <UiAlert v-if="bothOff" variant="info" live class="notifications__alert" data-testid="both-off">{{ t('notifications.both_off') }}</UiAlert>
    </SettingsLayout>
</template>

<style scoped>
.notifications__intro { margin: 0 0 var(--space-4); color: var(--color-text-muted); }
.notifications__alert { margin-block: var(--space-4); }
.notifications__row { margin-block-end: var(--space-4); }
.notifications__row:last-child { margin-block-end: 0; }
.notifications__hint { margin: var(--space-1) 0 0; color: var(--color-text-muted); font-size: var(--text-sm); }
</style>
