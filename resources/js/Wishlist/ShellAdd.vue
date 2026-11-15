<script setup>
import { onBeforeUnmount, onMounted, watch } from 'vue';
import { useShell } from '../Shell/shellState.js';

// Renderless: hooks the shell's topbar and phone FAB add action to this page while `enabled`.
const props = defineProps({
    enabled: { type: Boolean, default: false },
    label: { type: String, default: '' },
});
const emit = defineEmits(['add']);
const shell = useShell();
let unregister = null;

function sync() {
    unregister?.();
    unregister = props.enabled ? (shell?.registerAddHandler(() => emit('add'), props.label || null) ?? null) : null;
}
onMounted(sync);
watch(() => [props.enabled, props.label], sync);
onBeforeUnmount(() => unregister?.());
</script>

<template>
    <span v-if="false" />
</template>
