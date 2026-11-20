<script setup>
import { computed } from 'vue';
import { Settings, Users } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiTag from '../Components/ui/UiTag.vue';

// Header of a group list: name, mode, who is on it, a banner for members and the Manage list button.
// It reads only list.group and never any reservation data.
const props = defineProps({
    list: { type: Object, required: true },
});
const emit = defineEmits(['manage']);
const { t, locale } = useI18n();

const group = computed(() => props.list.group);
const names = computed(() => {
    const list = (group.value.members ?? []).map((m) => m.name);
    try {
        return new Intl.ListFormat(locale.value, { style: 'long', type: 'conjunction' }).format(list);
    } catch {
        return list.join(', ');
    }
});
const modeLabel = computed(() => t(`wishlist.group.mode_tag_${group.value.mode}`));
const showBanner = computed(() => group.value.is_member === true);
const bannerText = computed(() => t(`wishlist.group.banner_${group.value.mode}`));
</script>

<template>
    <header v-if="group" class="group-header" data-testid="group-header">
        <div class="group-header__top">
            <h2 class="group-header__title">{{ group.name }}</h2>
            <UiTag :icon="Users" data-testid="group-mode-tag">{{ modeLabel }}</UiTag>
            <UiButton v-if="group.can_manage" variant="secondary" size="sm" :icon="Settings" data-testid="group-manage" @click="emit('manage')">{{ t('wishlist.group.manage') }}</UiButton>
        </div>
        <p v-if="names" class="group-header__members" data-testid="group-members">{{ t('wishlist.group.header_members', { names }) }}</p>
        <UiAlert v-if="showBanner" variant="info" data-testid="group-banner">{{ bannerText }}</UiAlert>
    </header>
</template>

<style scoped>
.group-header { display: flex; flex-direction: column; gap: var(--space-2); min-width: 0; }
.group-header__top { display: flex; align-items: center; flex-wrap: wrap; gap: var(--space-3); }
.group-header__title { margin: 0; font-size: var(--text-xl); font-weight: 600; line-height: 1.2; overflow-wrap: anywhere; min-width: 0; }
.group-header__members { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); overflow-wrap: anywhere; }
</style>
