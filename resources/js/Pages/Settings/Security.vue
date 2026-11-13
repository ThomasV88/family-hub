<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ShieldCheck, ShieldOff, TriangleAlert } from '@lucide/vue';
import SettingsLayout from '../../Layouts/SettingsLayout.vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiCard from '../../Components/ui/UiCard.vue';
import UiTag from '../../Components/ui/UiTag.vue';
import RegenerateCodesDialog from '../../Components/Security/RegenerateCodesDialog.vue';
import PasskeysCard from '../../Components/Security/PasskeysCard.vue';
import TotpDisableDialog from '../../Components/Security/TotpDisableDialog.vue';
import TwoFactorSetupDialog from '../../Components/Security/TwoFactorSetupDialog.vue';

const props = defineProps({
    sections: { type: Array, default: () => [] },
    status: { type: Object, default: () => ({ totp: false, recoveryRemaining: null }) },
    passkeys: { type: Array, default: () => [] },
    required: { type: Boolean, default: false },
    guidance: { type: Boolean, default: false },
    continueTo: { type: String, default: null },
    methods: { type: Object, default: () => ({ password: false, totp: false, passkey: false, google: false }) },
    confirmFresh: { type: Boolean, default: false },
    passkeysEnabled: { type: Boolean, default: false },
});

const { t } = useI18n();
const page = usePage();
const isAdmin = computed(() => !!page.props?.auth?.user?.isAdmin);

const setupOpen = ref(false);
const disableOpen = ref(false);
const regenOpen = ref(false);
// The card keeps its own list as passkeys are added, renamed and removed; props refresh it after a reload.
const passkeyList = ref([...props.passkeys]);
watch(() => props.passkeys, (v) => { passkeyList.value = [...v]; });
const lastFactor = computed(() => props.required && passkeyList.value.length === 0);

const on = computed(() => props.status.totp || passkeyList.value.length > 0);
const tag = computed(() => {
    if (props.guidance) return { variant: 'warn', icon: TriangleAlert, label: t('security.tag.needs') };
    return on.value
        ? { variant: 'success', icon: ShieldCheck, label: t('security.tag.on') }
        : { variant: 'neutral', icon: ShieldOff, label: t('security.tag.off') };
});

function focusHeading(id) {
    nextTick(() => {
        const el = document.getElementById(id);
        el?.setAttribute('tabindex', '-1');
        el?.focus();
    });
}

onMounted(() => {
    if (props.guidance) {
        const h1 = document.querySelector('h1');
        h1?.setAttribute('tabindex', '-1');
        h1?.focus();
    }
});

const PROPS = ['status', 'passkeys', 'guidance', 'continueTo', 'methods', 'confirmFresh', 'sections'];

function reload() {
    router.reload({ only: PROPS, preserveScroll: true, onSuccess: () => focusHeading('security-totp-title') });
}

// Called after the setup dialog closed on its own: under the requirement go on to the page the member asked for.
function setupFinished() {
    router.clearHistory?.();
    if (props.guidance) {
        router.post('/settings/security/continue');
    } else {
        reload();
    }
}
</script>

<template>
    <Head :title="t('security.title')" />
    <SettingsLayout :sections="sections" current="security" title="security.title">
        <p class="security__intro">{{ t('security.intro') }}</p>

        <UiAlert v-if="guidance" variant="warning" class="security__guidance" data-testid="guidance">
            <strong>{{ t('security.guidance.title') }}</strong>
            <span class="security__guidance-body">{{ t('security.guidance.body') }}</span>
        </UiAlert>

        <UiCard class="security__card" data-testid="status-card">
            <div class="security__status">
                <UiTag :variant="tag.variant" :icon="tag.icon" data-testid="status-tag">{{ tag.label }}</UiTag>
                <UiTag v-if="required" variant="neutral" data-testid="required-tag">{{ t('security.tag.required') }}</UiTag>
                <p class="security__status-text">{{ on ? t('security.status.on') : t('security.status.off') }}</p>
            </div>
            <p v-if="required" class="security__note">{{ t('security.required_note') }}</p>
        </UiCard>

        <UiCard class="security__card" data-testid="totp-card">
            <h2 id="security-totp-title" class="security__heading">{{ t('security.totp.title') }}</h2>
            <p class="security__text">{{ t('security.totp.body') }}</p>
            <UiButton v-if="!status.totp" variant="primary" data-testid="setup-totp" @click="setupOpen = true">
                {{ t('twofactor.cta.setup') }}
            </UiButton>
            <UiButton v-else variant="ghost" class="security__danger" data-testid="disable-totp" @click="disableOpen = true">
                {{ t('security.totp.disable') }}
            </UiButton>
        </UiCard>

        <section v-if="passkeysEnabled" class="security__region" data-testid="passkeys-region">
            <PasskeysCard
                v-model:passkeys="passkeyList"
                :methods="methods"
                :confirm-fresh="confirmFresh"
                :is-admin="isAdmin"
                :required="required"
                :guidance="guidance"
                :totp="status.totp"
            />
        </section>

        <UiCard v-if="status.totp" class="security__card" data-testid="recovery-card">
            <h2 class="security__heading">{{ t('twofactor.recovery.title') }}</h2>
            <p class="security__text" data-testid="remaining">
                <template v-if="status.recoveryRemaining === 0">{{ t('twofactor.recovery.none_left') }}</template>
                <template v-else>{{ t('twofactor.recovery.remaining', { n: status.recoveryRemaining }, status.recoveryRemaining) }}</template>
            </p>
            <p v-if="status.recoveryRemaining !== null && status.recoveryRemaining <= 2" class="security__low" data-testid="low">
                <TriangleAlert :size="16" :stroke-width="1.75" aria-hidden="true" />
                {{ t('twofactor.recovery.low') }}
            </p>
            <UiButton variant="secondary" data-testid="create-codes" @click="regenOpen = true">{{ t('twofactor.cta.create_codes') }}</UiButton>
        </UiCard>

        <TotpDisableDialog v-model:open="disableOpen" :methods="methods" :is-admin="isAdmin" :last-factor="lastFactor" @done="reload" />
        <RegenerateCodesDialog v-model:open="regenOpen" :methods="methods" :is-admin="isAdmin" @done="reload" />
        <TwoFactorSetupDialog
            v-model:open="setupOpen"
            :methods="methods"
            :confirm-fresh="confirmFresh"
            :is-admin="isAdmin"
            @finished="setupFinished"
        />
    </SettingsLayout>
</template>

<style scoped>
.security__intro { margin: 0 0 var(--space-4); color: var(--color-text-muted); }
.security__guidance { margin-bottom: var(--space-4); }
.security__guidance-body { display: block; }
.security__card { margin-bottom: var(--space-4); }
.security__status { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2) var(--space-3); }
.security__status-text { margin: 0; }
.security__note { margin: var(--space-3) 0 0; color: var(--color-text-muted); font-size: var(--text-sm); }
.security__heading { margin: 0 0 var(--space-2); font-size: var(--text-lg); font-weight: 600; line-height: 1.3; }
.security__heading:focus { outline: none; }
.security__text { margin: 0 0 var(--space-3); }
.security__danger { color: var(--color-danger); }
.security__low { display: flex; align-items: center; gap: var(--space-2); margin: 0 0 var(--space-3); color: var(--color-warn-text); font-size: var(--text-sm); }
.security__region:empty { display: none; }
</style>
