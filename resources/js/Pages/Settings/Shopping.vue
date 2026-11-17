<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import SettingsLayout from '../../Layouts/SettingsLayout.vue';
import UiTabs from '../../Components/ui/UiTabs.vue';
import CatalogTab from '../../Shopping/Settings/CatalogTab.vue';
import StoresTab from '../../Shopping/Settings/StoresTab.vue';
import UnitsTab from '../../Shopping/Settings/UnitsTab.vue';

// Settings > Shopping: the item catalog, the stores and the units the family shares. Server-side access is Shopping edit.
defineProps({
    sections: { type: Array, default: () => [] },
    catalog: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    units: { type: Array, default: () => [] },
    canManageUnits: { type: Boolean, default: false },
});

const { t } = useI18n();
const tab = ref('catalog');
const tabs = () => [
    { key: 'catalog', label: t('shopping.settings.tab_catalog') },
    { key: 'stores', label: t('shopping.settings.tab_stores') },
    { key: 'units', label: t('shopping.settings.tab_units') },
];
</script>

<template>
    <Head :title="t('shopping.settings.title')" />
    <SettingsLayout :sections="sections" current="shopping" title="shopping.settings.title">
        <UiTabs v-model="tab" :tabs="tabs()" :label="t('shopping.settings.tabs_label')">
            <template #panel-catalog><CatalogTab :items="catalog" :units="units" /></template>
            <template #panel-stores><StoresTab :items="stores" /></template>
            <template #panel-units><UnitsTab :units="units" :can-manage="canManageUnits" /></template>
        </UiTabs>
    </SettingsLayout>
</template>
