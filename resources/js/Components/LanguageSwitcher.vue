<script setup>
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UiField from './ui/UiField.vue';
import UiSelect from './ui/UiSelect.vue';

const { t } = useI18n();
const page = usePage();
const current = computed(() => page.props.locale ?? 'en');

// Native names, deliberately not translated so everyone can find their own language.
const options = [
    { value: 'sk', label: 'Slovenčina' },
    { value: 'pl', label: 'Polski' },
    { value: 'en', label: 'English' },
];

function change(locale) {
    router.post('/locale', { locale }, { preserveScroll: true });
}
</script>

<template>
    <UiField :label="t('locale.label')" class="language-switcher">
        <UiSelect data-testid="language-switcher" :model-value="current" :options="options" @update:model-value="change" />
    </UiField>
</template>
