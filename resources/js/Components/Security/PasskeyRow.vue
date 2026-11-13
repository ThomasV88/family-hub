<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { KeyRound, Pencil, Trash2 } from '@lucide/vue';
import UiButton from '../ui/UiButton.vue';
import UiListRow from '../ui/UiListRow.vue';

// One passkey. The name is member text: rendered as escaped text only, never in a title attribute.
const props = defineProps({
    passkey: { type: Object, required: true },
});
defineEmits(['rename', 'remove']);
const { t, locale } = useI18n();

const formatter = computed(() => new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium' }));
const fmt = (iso) => (iso ? formatter.value.format(new Date(iso)) : '');
const meta = computed(() => {
    const added = t('passkey.added_on', { date: fmt(props.passkey.createdAt) });
    const used = props.passkey.lastUsedAt ? t('passkey.last_used', { date: fmt(props.passkey.lastUsedAt) }) : t('passkey.never_used');
    return `${added} · ${used}`;
});
</script>

<template>
    <UiListRow class="passkey-row" data-testid="passkey-row">
        <template #leading><KeyRound :size="20" :stroke-width="1.75" aria-hidden="true" class="passkey-row__icon" /></template>
        <span data-testid="passkey-name">{{ passkey.name }}</span>
        <template #meta>{{ meta }}</template>
        <template #trailing>
            <div class="passkey-row__actions">
                <UiButton
                    variant="ghost"
                    :icon="Pencil"
                    data-testid="rename"
                    :aria-label="t('passkey.rename_aria', { name: passkey.name })"
                    @click="$emit('rename', passkey)"
                >
                    <span class="passkey-row__short">{{ t('passkey.rename') }}</span>
                    <span class="passkey-row__long">{{ t('passkey.rename_long') }}</span>
                </UiButton>
                <UiButton
                    variant="ghost"
                    class="passkey-row__danger"
                    :icon="Trash2"
                    data-testid="remove"
                    :aria-label="t('passkey.remove_aria', { name: passkey.name })"
                    @click="$emit('remove', passkey)"
                >
                    <span class="passkey-row__short">{{ t('passkey.remove') }}</span>
                    <span class="passkey-row__long">{{ t('passkey.remove_long') }}</span>
                </UiButton>
            </div>
        </template>
    </UiListRow>
</template>

<style scoped>
.passkey-row { flex-wrap: wrap; padding-block: var(--space-2); }
.passkey-row__icon { color: var(--color-text-muted); }
.passkey-row__actions { display: flex; flex-wrap: wrap; gap: var(--space-1); }
.passkey-row__danger { color: var(--color-danger); }
.passkey-row__long { display: inline; }
.passkey-row__short { display: none; }
@media (min-width: 640px) {
    .passkey-row__long { display: none; }
    .passkey-row__short { display: inline; }
}
</style>
