<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { CircleUser, Palette } from '@lucide/vue';
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

// Phase 2 adds Settings and Log out here; no authentication or settings route exists until then.
const items = computed(() => {
    const list = [
        { type: 'radio-group', key: 'language', label: t('nav.language'), value: page.props.locale ?? 'en', options: LANGUAGES },
        { type: 'radio-group', key: 'theme', label: t('theme.label'), value: theme.value, options: THEME_OPTIONS.map((value) => ({ value, label: t(`theme.${value}`) })) },
    ];
    if (page.props.app?.styleGuide === true) {
        list.push({ type: 'item', key: 'style', label: t('nav.style_guide'), icon: Palette });
    }
    return list;
});

function onSelect({ key, value }) {
    if (key === 'language') router.post('/locale', { locale: value }, { preserveScroll: true });
    else if (key === 'theme') {
        setThemePreference(value);
        theme.value = value;
    } else if (key === 'style') router.visit('/_style');
}
</script>

<template>
    <UiMenu :label="t('nav.account')" :icon="CircleUser" :items="items" @select="onSelect" />
</template>
