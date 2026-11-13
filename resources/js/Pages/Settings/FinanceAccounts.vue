<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Banknote, CreditCard, Landmark, Lock, PiggyBank, Plus } from '@lucide/vue';
import SettingsLayout from '../../Layouts/SettingsLayout.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiCard from '../../Components/ui/UiCard.vue';
import UiEmptyState from '../../Components/ui/UiEmptyState.vue';
import UiList from '../../Components/ui/UiList.vue';
import UiListRow from '../../Components/ui/UiListRow.vue';
import AccountPanel from '../../Finance/AccountPanel.vue';

// Metadata only: who can see each account. No balance, no transaction figure, no count of transactions.
const props = defineProps({
    sections: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    members: { type: Array, default: () => [] },
    panel: { type: String, default: null },
});

const { t } = useI18n();
const ICONS = { bank: Landmark, cash: Banknote, savings: PiggyBank, card: CreditCard };
const openId = ref(props.panel);
const opened = computed(() => (openId.value === 'new' ? null : props.accounts.find((a) => a.id === openId.value) ?? null));
const showPanel = computed(() => openId.value === 'new' || opened.value !== null);

const typeLabel = (a) => t(`finance.acc.type_${a.type}`);
</script>

<template>
    <Head :title="t('settings.section.finance_accounts')" />
    <SettingsLayout :sections="sections" current="finance_accounts" title="settings.section.finance_accounts">
        <p class="fin-acc__intro">{{ t('finance.acc.settings_intro') }}</p>
        <div class="fin-acc__header">
            <UiButton variant="primary" :icon="Plus" @click="openId = 'new'">{{ t('finance.acc.add') }}</UiButton>
        </div>
        <UiCard :title="t('finance.acc.title')" class="fin-acc__card">
            <UiEmptyState v-if="!accounts.length" :title="t('finance.empty_account.title')" :body="t('finance.empty_account.body')">
                <template #action>
                    <UiButton variant="primary" :icon="Plus" @click="openId = 'new'">{{ t('finance.acc.add') }}</UiButton>
                </template>
            </UiEmptyState>
            <UiList v-else>
                <UiListRow v-for="account in accounts" :key="account.id" as="button" :selected="account.id === openId" @select="openId = account.id">
                    <template #leading><component :is="ICONS[account.type] ?? Landmark" :size="20" :stroke-width="1.75" aria-hidden="true" /></template>
                    {{ account.name }}
                    <template #meta>
                        <span>{{ typeLabel(account) }}</span>
                        <span class="fin-acc__sep" aria-hidden="true"> · </span>
                        <span v-if="account.memberCount === 0" class="fin-acc__shared">
                            <Lock :size="14" :stroke-width="1.75" aria-hidden="true" />{{ t('finance.acc.shared_none') }}
                        </span>
                        <span v-else>{{ t('finance.acc.visible_to', account.memberCount, { named: { n: account.memberCount } }) }}</span>
                        <template v-if="!account.sharedWithMe">
                            <span class="fin-acc__sep" aria-hidden="true"> · </span>
                            <span class="fin-acc__muted">{{ t('finance.acc.not_shared_you') }}</span>
                        </template>
                    </template>
                </UiListRow>
            </UiList>
        </UiCard>
        <template #panel>
            <AccountPanel v-if="showPanel" :key="openId" :account="opened" :members="members" @close="openId = null" />
        </template>
    </SettingsLayout>
</template>

<style scoped>
.fin-acc__intro { margin: 0 0 var(--space-3); color: var(--color-text-muted); }
.fin-acc__header { display: flex; justify-content: flex-end; }
.fin-acc__card { margin-block-start: var(--space-4); }
.fin-acc__shared { display: inline-flex; align-items: center; gap: var(--space-1); }
.fin-acc__muted { color: var(--color-text-muted); }
</style>
