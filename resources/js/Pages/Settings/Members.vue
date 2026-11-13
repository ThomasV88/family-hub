<script setup>
import SettingsLayout from '../../Layouts/SettingsLayout.vue';

defineProps({ sections: { type: Array, default: () => [] } });
</script>

<template>
    <SettingsLayout :sections="sections" current="members" title="settings.section.members" />
</template>
