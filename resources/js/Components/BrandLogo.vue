<script setup>
// FamilyHUB logo: full-colour lockup in light theme, white mono lockup in dark theme.
// Both images are rendered and CSS hides one (display: none also removes it from the
// accessibility tree), so exactly one is exposed with the same text alternative.
// Served from public/; bound dynamically so the Vue compiler does not try to bundle them.
const LIGHT_SRC = '/icons/familyhub-logo.svg';
const DARK_SRC = '/icons/familyhub-logo-dark.svg';

defineProps({
    alt: { type: String, required: true },
    height: { type: Number, default: 32 },
});
</script>

<template>
    <span class="brand-logo">
        <img class="brand-logo__img brand-logo__img--light" :src="LIGHT_SRC" :alt="alt" :height="height" decoding="async">
        <img class="brand-logo__img brand-logo__img--dark" :src="DARK_SRC" :alt="alt" :height="height" decoding="async">
    </span>
</template>

<style>
.brand-logo { display: inline-flex; align-items: center; line-height: 0; }
.brand-logo__img { display: block; width: auto; max-width: 100%; }
.brand-logo__img--dark { display: none; }
:root[data-theme="dark"] .brand-logo__img--light { display: none; }
:root[data-theme="dark"] .brand-logo__img--dark { display: block; }
@media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) .brand-logo__img--light { display: none; }
    :root:not([data-theme="light"]) .brand-logo__img--dark { display: block; }
}
</style>
