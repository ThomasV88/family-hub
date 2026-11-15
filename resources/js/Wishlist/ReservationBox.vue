<script setup>
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiCard from '../Components/ui/UiCard.vue';
import UiConfirmDialog from '../Components/ui/UiConfirmDialog.vue';
import ContributorList from './ContributorList.vue';
import ShareForm from './ShareForm.vue';

// Giver-side state machine. Everything comes from wish.reservation (server contract); nothing is summed here.
const props = defineProps({
    wish: { type: Object, required: true },
    ownerName: { type: String, default: '' },
    canReserve: { type: Boolean, default: false },
});
const { t } = useI18n();
const page = usePage();

const r = computed(() => props.wish.reservation ?? null);
const base = computed(() => `/wishlist/wishes/${props.wish.id}/reservation`);
const cobuy = computed(() => r.value?.cobuy_state ?? 'none');
const mine = computed(() => r.value?.is_mine === true);
const joined = computed(() => r.value?.i_joined === true);
const organizerName = computed(() => r.value?.organizer?.name ?? '');
const showContributors = computed(() => r.value !== null && cobuy.value !== 'none');

const sentence = computed(() => {
    if (!r.value) return t('wishlist.res.free');
    if (mine.value) return cobuy.value === 'open' ? t('wishlist.res.you_cobuy') : t('wishlist.res.you');
    if (cobuy.value === 'open') return t('wishlist.res.other_open', { name: organizerName.value });
    if (cobuy.value === 'closed') return t('wishlist.res.other_closed', { name: organizerName.value });
    return t('wishlist.res.other', { name: organizerName.value });
});

const conflict = computed(() => {
    const data = page.props?.flash?.data?.reservation_conflict;
    return data && data.wish_id === props.wish.id ? data : null;
});

const busy = ref(null);
const failed = ref(false);
const sharing = ref(false);
const confirming = ref(false);

// A different reservation state closes the inline form and clears a stale server error.
watch(() => [r.value?.cobuy_state, r.value?.i_joined, r.value === null], () => { sharing.value = false; });

function send(key, method, url, data, after) {
    const options = {
        preserveScroll: true,
        onStart: () => { busy.value = key; failed.value = false; },
        onSuccess: () => { sharing.value = false; after?.(); },
        onError: () => { failed.value = true; },
        onFinish: () => { busy.value = null; },
    };
    if (method === 'delete') router.delete(url, options);
    else router[method](url, data, options);
}

const reserve = () => send('reserve', 'post', base.value, {});
const openCobuy = () => send('open', 'post', `${base.value}/cobuy`, {});
const closeCobuy = () => send('close', 'delete', `${base.value}/cobuy`);
const leave = () => send('leave', 'delete', `${base.value}/contribution`);
const saveShare = (amount) => send('share', 'put', `${base.value}/contribution`, { amount });
const doCancel = () => send('cancel', 'delete', base.value);
function cancel() {
    if ((r.value?.people ?? 1) > 1) confirming.value = true;
    else doCancel();
}
</script>

<template>
    <UiCard class="res-box">
        <h3 class="res__heading">{{ t('wishlist.res.title') }}</h3>
        <div class="res" aria-live="polite" data-testid="reservation-box">
            <UiAlert v-if="conflict" variant="warning" live data-testid="res-conflict">{{ t('wishlist.res.conflict', { name: conflict.name }) }}</UiAlert>
            <UiAlert v-if="failed" variant="danger" live data-testid="res-error">{{ t('wishlist.res.error') }}</UiAlert>

            <p class="res__text">{{ sentence }}</p>
            <p v-if="!r && ownerName" class="res__hint">{{ t('wishlist.res.free_hint', { name: ownerName }) }}</p>

            <ContributorList v-if="showContributors" :reservation="r" :price="wish.price" :currency="wish.currency" />

            <ShareForm
                v-if="canReserve && sharing"
                :initial="r?.my_amount ?? null"
                :currency="wish.currency"
                :joined="joined"
                :busy="busy === 'share'"
                @submit="saveShare"
                @cancel="sharing = false"
            />

            <div v-else-if="canReserve" class="res__actions">
                <template v-if="!r">
                    <UiButton variant="primary" :loading="busy === 'reserve'" @click="reserve">{{ t('wishlist.res.reserve') }}</UiButton>
                </template>
                <template v-else-if="mine">
                    <UiButton v-if="cobuy === 'open'" variant="secondary" :loading="busy === 'close'" @click="closeCobuy">{{ t('wishlist.res.close_cobuy') }}</UiButton>
                    <UiButton v-else variant="secondary" :loading="busy === 'open'" @click="openCobuy">{{ t('wishlist.res.open_cobuy') }}</UiButton>
                    <UiButton variant="ghost" :loading="busy === 'cancel'" @click="cancel">{{ t('wishlist.res.cancel') }}</UiButton>
                </template>
                <template v-else-if="cobuy === 'open'">
                    <UiButton v-if="!joined" variant="primary" @click="sharing = true">{{ t('wishlist.res.join') }}</UiButton>
                    <template v-else>
                        <UiButton variant="secondary" @click="sharing = true">{{ t('wishlist.res.change_share') }}</UiButton>
                        <UiButton variant="ghost" :loading="busy === 'leave'" @click="leave">{{ t('wishlist.res.leave') }}</UiButton>
                    </template>
                </template>
            </div>
        </div>

        <UiConfirmDialog
            v-model:open="confirming"
            :title="t('wishlist.res.cancel_title')"
            :body="t('wishlist.res.cancel_body', { title: wish.title })"
            :confirm-label="t('wishlist.res.cancel')"
            :cancel-label="t('wishlist.res.cancel_keep')"
            @confirm="doCancel"
        />
    </UiCard>
</template>

<style scoped>
.res { display: flex; flex-direction: column; gap: var(--space-3); min-width: 0; }
.res__heading { margin: 0 0 var(--space-3); font-size: var(--text-sm); font-weight: 600; line-height: 1.4; }
.res__text { margin: 0; overflow-wrap: anywhere; }
.res__hint { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); overflow-wrap: anywhere; }
.res__actions { display: flex; flex-wrap: wrap; gap: var(--space-2); }
@media (pointer: coarse) {
    .res__actions :deep(.ui-button) { min-height: var(--control-touch); }
}
</style>
