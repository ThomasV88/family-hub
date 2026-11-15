<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Bot, Check, TriangleAlert } from '@lucide/vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiTag from '../Components/ui/UiTag.vue';

// AI provenance of one record: "AI-extracted" plus "Unverified" or "Verified". Records a person entered
// (verification 'manual') render nothing. Text and icon always, never colour alone.
const props = defineProps({
    provenance: { type: Object, default: null },
    // Panel only: an editor of an unverified record gets the "Mark as verified" button, which emits `verify`.
    canVerify: { type: Boolean, default: false },
    verifying: { type: Boolean, default: false },
});
const emit = defineEmits(['verify']);
const { t } = useI18n();

const state = computed(() => {
    const v = props.provenance?.verification;
    return v === 'unverified' || v === 'verified' ? v : null;
});
</script>

<template>
    <span v-if="state" class="prov" data-testid="provenance-tags">
        <UiTag :icon="Bot" class="prov__tag" data-testid="provenance-ai">{{ t('health.ai.provenance.ai_extracted') }}</UiTag>
        <UiTag v-if="state === 'unverified'" variant="warn" :icon="TriangleAlert" class="prov__tag" data-testid="provenance-unverified">{{ t('health.ai.provenance.unverified') }}</UiTag>
        <UiTag v-else variant="success" :icon="Check" class="prov__tag" data-testid="provenance-verified">{{ t('health.ai.provenance.verified') }}</UiTag>
        <UiButton v-if="canVerify && state === 'unverified'" variant="secondary" :loading="verifying" data-testid="provenance-verify" @click="emit('verify')">{{ t('health.ai.provenance.verify_action') }}</UiButton>
    </span>
</template>

<style scoped>
.prov { display: flex; flex-wrap: wrap; gap: var(--space-2); min-width: 0; }
.prov__tag { white-space: normal; border: 1px solid transparent; }
@media (forced-colors: active) {
    .prov__tag { border-color: currentColor; }
}
</style>
