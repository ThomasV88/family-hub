<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { BellOff, Share } from '@lucide/vue';
import UiAlert from '../ui/UiAlert.vue';
import UiButton from '../ui/UiButton.vue';
import { useToast } from '../ui/useToast.js';
import { currentSubscription, disable, enable, isIosNotInstalled, isPushSupported, resync, sendTest } from '../../push.js';

const props = defineProps({ vapidPublicKey: { type: String, default: null } });
const emit = defineEmits(['enabled']);

const { t } = useI18n();
const toast = useToast();

const supported = isPushSupported();
const ios = isIosNotInstalled();
const on = ref(false);
const denied = ref(typeof Notification !== 'undefined' && Notification.permission === 'denied');
const busy = ref(false);
const failure = ref('');

async function refresh() {
    on.value = Boolean(await currentSubscription());
}

onMounted(async () => {
    if (!supported || ios || !props.vapidPublicKey) return;
    try {
        if (Notification.permission === 'granted') await resync(props.vapidPublicKey);
        await refresh();
    } catch {
        on.value = false;
    }
});

async function turnOn() {
    busy.value = true;
    failure.value = '';
    try {
        const result = await enable(props.vapidPublicKey);
        if (result.ok) {
            on.value = true;
            denied.value = false;
            emit('enabled');
        } else if (result.reason === 'denied') {
            denied.value = true;
        } else if (result.reason === 'server') {
            failure.value = t('notifications.enable_failed');
        }
    } catch {
        failure.value = t('notifications.enable_failed');
    } finally {
        busy.value = false;
    }
}

async function turnOff() {
    busy.value = true;
    failure.value = '';
    try {
        await disable();
        on.value = false;
    } finally {
        busy.value = false;
    }
}

async function test() {
    busy.value = true;
    failure.value = '';
    try {
        const result = await sendTest();
        if (result.ok) toast.show(t('notifications.test_sent'));
        else failure.value = t('notifications.test_failed');
    } catch {
        failure.value = t('notifications.test_failed');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="push-device" data-testid="push-device-row">
        <h3 class="push-device__title">{{ t('notifications.device') }}</h3>
        <div role="status" aria-live="polite" class="push-device__state">
            <p v-if="!supported" class="push-device__text" data-testid="state-unsupported">{{ t('notifications.unsupported') }}</p>
            <UiAlert v-else-if="ios" variant="info" data-testid="state-ios">
                <div class="push-device__ios">
                    <Share :size="20" :stroke-width="1.75" aria-hidden="true" />
                    <div>
                        <strong>{{ t('notifications.ios_title') }}</strong>
                        <p class="push-device__text">{{ t('notifications.ios_body') }}</p>
                    </div>
                </div>
            </UiAlert>
            <p v-else-if="!vapidPublicKey" class="push-device__text" data-testid="state-unavailable">{{ t('notifications.push_unavailable') }}</p>
            <UiAlert v-else-if="denied" variant="warning" data-testid="state-denied">
                <div class="push-device__ios">
                    <BellOff :size="20" :stroke-width="1.75" aria-hidden="true" />
                    <span>{{ t('notifications.denied') }}</span>
                </div>
            </UiAlert>
            <p v-else-if="on" class="push-device__text" data-testid="state-on">{{ t('notifications.device_on') }}</p>
            <p v-else class="push-device__text" data-testid="state-off">{{ t('notifications.device_off') }}</p>
        </div>
        <p v-if="failure" class="push-device__error" data-testid="push-failure">{{ failure }}</p>
        <div v-if="supported && !ios && vapidPublicKey && !denied" class="push-device__actions">
            <UiButton v-if="!on" variant="secondary" :loading="busy" data-testid="push-enable" @click="turnOn">{{ t('notifications.enable') }}</UiButton>
            <template v-else>
                <UiButton variant="secondary" :disabled="busy" data-testid="push-test" @click="test">{{ t('notifications.test') }}</UiButton>
                <UiButton variant="ghost" :disabled="busy" data-testid="push-disable" @click="turnOff">{{ t('notifications.disable') }}</UiButton>
            </template>
        </div>
    </div>
</template>

<style scoped>
.push-device { margin-inline-start: var(--space-6); margin-block-start: var(--space-2); }
.push-device__title { margin: 0 0 var(--space-2); font-size: var(--text-base); font-weight: 600; }
.push-device__text { margin: 0; color: var(--color-text-muted); }
.push-device__error { margin: var(--space-2) 0 0; color: var(--color-danger); }
.push-device__ios { display: flex; gap: var(--space-3); align-items: flex-start; }
.push-device__ios p { margin: var(--space-1) 0 0; color: var(--color-text); }
.push-device__actions { display: flex; flex-wrap: wrap; gap: var(--space-2); margin-block-start: var(--space-3); }
</style>
