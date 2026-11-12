<script setup>
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const page = usePage();
const current = computed(() => page.props.locale ?? 'en');

// Native names, deliberately not translated so everyone can find their own language.
const options = [
    { code: 'sk', name: 'Slovenčina' },
    { code: 'pl', name: 'Polski' },
    { code: 'en', name: 'English' },
];

function change(event) {
    router.post('/locale', { locale: event.target.value }, { preserveScroll: true });
}
</script>

<template>
    <label class="language-switcher">
        <span class="language-switcher__label">{{ t('locale.label') }}</span>
        <select data-testid="language-switcher" :value="current" @change="change">
            <option v-for="o in options" :key="o.code" :value="o.code" :selected="o.code === current">
                {{ o.name }}
            </option>
        </select>
    </label>
</template>
