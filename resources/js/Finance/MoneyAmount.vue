<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatMinor } from './money.js';

const props = defineProps({
    minor: { type: String, required: true },
    currency: { type: String, required: true },
    signed: { type: Boolean, default: false },
});

const { locale } = useI18n();
const text = computed(() => formatMinor(props.minor, props.currency, locale.value, { signed: props.signed }));
</script>

<template>
    <span class="money" style="font-variant-numeric: tabular-nums; color: var(--color-text); overflow-wrap: anywhere">{{ text }}</span>
</template>
