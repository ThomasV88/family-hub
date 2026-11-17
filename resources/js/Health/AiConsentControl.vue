<script setup>
import { computed, nextTick, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { router } from '@inertiajs/vue3';
import { Bot } from '@lucide/vue';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiDialog from '../Components/ui/UiDialog.vue';
import UiSwitch from '../Components/ui/UiSwitch.vue';
import UiTag from '../Components/ui/UiTag.vue';
import HelpTip from '../Components/ui/HelpTip.vue';

// Per-person AI consent. Off is the safe default: turning on always goes through the consent dialog, turning off is
// immediate. The switch shows the saved value only, so a failed request leaves it where it was.
const props = defineProps({
    personId: { type: String, required: true },
    value: { type: Boolean, default: false },
    canEdit: { type: Boolean, default: false },
    // Recognised text can exist (full-text reading on or text already stored): turning AI off deletes it, so say so first.
    purgesText: { type: Boolean, default: false },
});
const { t } = useI18n();
const explainId = useId();
const checked = ref(props.value);
const busy = ref(false);
const failed = ref(false);
const confirmOpen = ref(false);
const offOpen = ref(false);
const keepOn = ref(null);
const keepOff = ref(null);

watch(() => props.value, (v) => { checked.value = v; });

const url = computed(() => `/health/people/${props.personId}/ai-consent`);

function send(value) {
    busy.value = true;
    failed.value = false;
    router.patch(url.value, { value }, {
        preserveScroll: true,
        onSuccess: () => { checked.value = value; },
        onError: () => { failed.value = true; checked.value = props.value; },
        onFinish: () => { busy.value = false; },
    });
}

function toggle(next) {
    if (busy.value) return;
    if (next) {
        // keep the switch visually off until the person confirms
        checked.value = false;
        confirmOpen.value = true;
        return;
    }
    if (props.purgesText) {
        // keep the switch on until the member confirms what turning it off deletes
        checked.value = true;
        offOpen.value = true;
        return;
    }
    send(false);
}

// The native checkbox flips on click; put it back on the saved value (a confirm or a successful save moves it).
function syncDom(event) {
    const input = event.target;
    nextTick(() => { input.checked = checked.value; });
}

function confirm() {
    confirmOpen.value = false;
    send(true);
}
function cancel() {
    confirmOpen.value = false;
    checked.value = props.value;
}
function confirmOff() {
    if (busy.value) return;
    offOpen.value = false;
    send(false);
}
function cancelOff() {
    offOpen.value = false;
    checked.value = props.value;
}
</script>

<template>
    <section class="ai-consent" data-testid="ai-consent">
        <template v-if="canEdit">
            <UiSwitch
                :model-value="checked"
                :disabled="busy"
                :aria-busy="busy ? 'true' : undefined"
                :aria-describedby="explainId"
                data-testid="ai-switch"
                @update:model-value="toggle"
                @change="syncDom"
            >
                <Bot :size="16" :stroke-width="1.75" aria-hidden="true" /> {{ t('health.ai.label') }}
            </UiSwitch>
            <HelpTip topic="health.ai_consent" :label="t('health.ai.label')" />
        </template>
        <UiTag v-else :variant="value ? 'success' : 'neutral'" :icon="Bot" data-testid="ai-tag">{{ value ? t('health.ai.tag_on') : t('health.ai.tag_off') }}</UiTag>
        <p :id="explainId" class="ai-consent__text">{{ t('health.ai.explain') }}</p>
        <UiAlert v-if="failed" variant="danger" live data-testid="ai-failed">{{ t('health.ai.failed') }}</UiAlert>

        <UiDialog :open="confirmOpen" :title="t('health.ai.confirm_title')" :initial-focus="() => keepOff" @update:open="(v) => { if (!v) cancel(); }">
            <p class="ai-consent__body">{{ t('health.ai.confirm_body') }}</p>
            <template #footer>
                <UiButton ref="keepOff" variant="secondary" data-testid="ai-keep-off" @click="cancel">{{ t('health.ai.keep_off') }}</UiButton>
                <UiButton variant="primary" data-testid="ai-allow" @click="confirm">{{ t('health.ai.allow') }}</UiButton>
            </template>
        </UiDialog>

        <UiDialog :open="offOpen" :title="t('health.ai.off_title')" :initial-focus="() => keepOn" @update:open="(v) => { if (!v) cancelOff(); }">
            <p class="ai-consent__body" data-testid="ai-off-purge">{{ t('health.ai.off_text_purge') }}</p>
            <template #footer>
                <UiButton ref="keepOn" variant="secondary" data-testid="ai-keep-on" @click="cancelOff">{{ t('health.ai.off_keep') }}</UiButton>
                <UiButton variant="primary" data-testid="ai-turn-off" @click="confirmOff">{{ t('health.ai.off_confirm') }}</UiButton>
            </template>
        </UiDialog>
    </section>
</template>

<style scoped>
.ai-consent { flex-basis: 100%; display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-2); }
.ai-consent__text { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); max-width: 64ch; }
.ai-consent__body { margin: 0; font-size: var(--text-base); }
</style>
