<script setup>
import { computed, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Monitor, Smartphone } from '@lucide/vue';
import UiList from '../ui/UiList.vue';
import UiListRow from '../ui/UiListRow.vue';
import UiTag from '../ui/UiTag.vue';
import UiButton from '../ui/UiButton.vue';
import UiConfirmDialog from '../ui/UiConfirmDialog.vue';
import UiEmptyState from '../ui/UiEmptyState.vue';
import UiErrorState from '../ui/UiErrorState.vue';
import UiSkeleton from '../ui/UiSkeleton.vue';

const props = defineProps({
    member: { type: Object, required: true },
});
const { t, locale } = useI18n();

const sessions = ref([]);
const loading = ref(true);
const failed = ref(false);
const target = ref(null); // session pending revoke
const confirmOne = ref(false);
const confirmAll = ref(false);

const base = computed(() => `/settings/members/${props.member.id}/sessions`);
const browserOf = (s) => s.browser ?? t('sessions.unknown_browser');
const osOf = (s) => s.os ?? t('sessions.unknown_os');
const deviceOf = (s) => t('sessions.device', { browser: browserOf(s), os: osOf(s) });
const hasOthers = computed(() => sessions.value.some((s) => !s.isCurrent));

function relative(iso) {
    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const rtf = new Intl.RelativeTimeFormat(locale.value, { numeric: 'auto' });
    const units = [['day', 86400], ['hour', 3600], ['minute', 60]];
    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) return rtf.format(Math.round(seconds / size), unit);
    }
    return rtf.format(0, 'second');
}

async function load() {
    loading.value = true;
    failed.value = false;
    try {
        const response = await fetch(base.value, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(String(response.status));
        sessions.value = (await response.json()).sessions ?? [];
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

function askRevoke(session) {
    target.value = session;
    confirmOne.value = true;
}
function revoke(url) {
    router.delete(url, { preserveScroll: true, preserveState: true, onFinish: load });
}
const revokeOne = () => target.value && revoke(`${base.value}/${target.value.id}`);
const revokeAll = () => revoke(base.value);

onMounted(load);
</script>

<template>
    <div class="sessions-tab">
        <div v-if="loading" class="sessions-tab__loading" aria-busy="true">
            <UiSkeleton v-for="n in 3" :key="n" height="44px" />
        </div>
        <UiErrorState v-else-if="failed" :heading-level="4" live @retry="load" />
        <UiEmptyState v-else-if="!sessions.length" :heading-level="4" :title="t('sessions.empty_title')" :body="t('sessions.empty_body')" />
        <template v-else>
            <UiList :aria-label="t('sessions.caption')">
                <UiListRow v-for="s in sessions" :key="s.id">
                    <template #leading>
                        <component :is="s.mobile ? Smartphone : Monitor" :size="20" :stroke-width="1.75" aria-hidden="true" />
                    </template>
                    {{ deviceOf(s) }}
                    <template #meta>
                        <i18n-t keypath="sessions.last_active" tag="span">
                            <template #time>{{ relative(s.lastActivity) }}</template>
                            <template #ip><span class="sessions-tab__ip">{{ s.ip ?? '-' }}</span></template>
                        </i18n-t>
                        <UiTag v-if="s.isCurrent">{{ t('sessions.this_device') }}</UiTag>
                        <UiTag v-if="s.staysSignedIn">{{ t('sessions.persistent') }}</UiTag>
                    </template>
                    <template #trailing>
                        <UiButton
                            variant="ghost"
                            size="sm"
                            class="sessions-tab__revoke"
                            :aria-label="t('sessions.revoke_aria', { browser: browserOf(s), os: osOf(s) })"
                            @click="askRevoke(s)"
                        >{{ t('sessions.revoke') }}</UiButton>
                    </template>
                </UiListRow>
            </UiList>
            <div v-if="hasOthers" class="sessions-tab__footer">
                <UiButton variant="secondary" @click="confirmAll = true">{{ t('sessions.revoke_all') }}</UiButton>
            </div>
        </template>

        <UiConfirmDialog
            v-model:open="confirmOne"
            :title="t('sessions.revoke_title')"
            :body="target ? t('sessions.revoke_body', { name: member.name, browser: browserOf(target), os: osOf(target) }) : ''"
            :confirm-label="t('sessions.revoke')"
            :cancel-label="t('sessions.revoke_keep')"
            @confirm="revokeOne"
        />
        <UiConfirmDialog
            v-model:open="confirmAll"
            :title="t('sessions.revoke_all')"
            :body="t('sessions.revoke_all_body', { name: member.name })"
            :confirm-label="t('sessions.revoke_all')"
            :cancel-label="t('sessions.revoke_keep')"
            @confirm="revokeAll"
        />
    </div>
</template>

<style scoped>
.sessions-tab__loading { display: flex; flex-direction: column; gap: var(--space-2); }
.sessions-tab__ip { font-family: var(--font-mono, ui-monospace, monospace); }
.sessions-tab__footer { display: flex; justify-content: flex-end; margin-top: var(--space-4); }
</style>
