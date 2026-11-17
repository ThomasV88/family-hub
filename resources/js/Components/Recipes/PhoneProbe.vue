<script setup>
import { watch } from 'vue';
import { useShell } from '../../Shell/shellState.js';

// The shell state is provided by AppShell to the components inside its slot, not to the page that renders AppShell. This renders
// nothing; it only tells the page (inside the slot) whether the shell is in phone layout.
const emit = defineEmits(['change']);
const shell = useShell();
watch(() => Boolean(shell?.isPhone?.value), (phone) => emit('change', phone), { immediate: true });
</script>

<template>
    <span hidden />
</template>
