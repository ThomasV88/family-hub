<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { CircleUser, LogOut, Palette, Settings } from '@lucide/vue';
import UiMenu from '../Components/ui/UiMenu.vue';
import { currentSubscription, disable } from '../push.js';
import { THEME_OPTIONS, getThemePreference, setThemePreference } from '../theme.js';

const { t } = useI18n();
const page = usePage();
const theme = ref(getThemePreference());

// Native names, deliberately not translated so everyone can find their own language.
const LANGUAGES = [
    { value: 'sk', label: 'Slovenčina' },
    { value: 'pl', label: 'Polski' },
    { value: 'en', label: 'English' },
];

const items = computed(() => {
    const user = page.props.auth?.user;
    const list = [
        ...(user ? [{ type: 'header', key: 'account-header', label: user.name, sublabel: user.email }] : []),
        ...(user ? [{ type: 'item', key: 'settings', label: t('account.settings'), icon: Settings }] : []),
        { type: 'radio-group', key: 'language', label: t('nav.language'), value: page.props.locale ?? 'en', options: LANGUAGES },
        { type: 'radio-group', key: 'theme', label: t('theme.label'), value: theme.value, options: THEME_OPTIONS.map((value) => ({ value, label: t(`theme.${value}`) })) },
    ];
    if (page.props.app?.styleGuide === true) {
        list.push({ type: 'item', key: 'style', label: t('nav.style_guide'), icon: Palette });
    }
    if (user) {
        list.push({ type: 'separator', key: 'account-sep' });
        list.push({ type: 'item', key: 'logout', label: t('account.logout'), icon: LogOut });
    }
    return list;
});

function onSelect({ key, value }) {
    if (key === 'language') router.post('/locale', { locale: value }, { preserveScroll: true });
    else if (key === 'theme') {
        setThemePreference(value);
        theme.value = value;
    } else if (key === 'settings') router.visit('/settings');
    else if (key === 'style') router.visit('/_style');
    else if (key === 'logout') logout();
}

// Drop this device's push subscription first so reminders stop reaching a shared device after logout.
// The endpoint also travels with the logout request as a server-side fallback. Never blocks logout.
async function logout() {
    let data = null;
    try {
        const work = (async () => {
            const subscription = await currentSubscription();
            if (!subscription) return null;
            const endpoint = subscription.endpoint;
            await disable().catch(() => {});

            return { push_endpoint: endpoint };
        })();
        data = await Promise.race([work, new Promise((resolve) => setTimeout(() => resolve(null), 3000))]);
    } catch {
        data = null;
    }
    if (data) router.post('/logout', data);
    else router.post('/logout');
}
</script>

<template>
    <UiMenu :label="t('nav.account')" :icon="CircleUser" :items="items" @select="onSelect" />
</template>
