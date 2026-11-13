<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { CircleUser, LogOut, Palette } from '@lucide/vue';
import UiMenu from '../Components/ui/UiMenu.vue';
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

// Settings joins this menu in plan 04; no settings route exists yet.
const items = computed(() => {
    const user = page.props.auth?.user;
    const list = [
        ...(user ? [{ type: 'header', key: 'account-header', label: user.name, sublabel: user.email }] : []),
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
    } else if (key === 'style') router.visit('/_style');
    else if (key === 'logout') router.post('/logout');
}
</script>

<template>
    <UiMenu :label="t('nav.account')" :icon="CircleUser" :items="items" @select="onSelect" />
</template>
