<script setup>
// One snippet of a search result: plain `{text, hit}` segments from the server, drawn with interpolation only (never as
// HTML). A hit is a <mark>, everything else a <span>. The ellipses mark that the snippet is a cut from the page text.
defineProps({
    segments: { type: Array, default: () => [] },
});
</script>

<template>
    <span class="search-snippet" data-testid="search-snippet"><span aria-hidden="true">…</span><template v-for="(segment, index) in segments" :key="index"><mark v-if="segment.hit" class="search-snippet__hit">{{ segment.text }}</mark><span v-else>{{ segment.text }}</span></template><span aria-hidden="true">…</span></span>
</template>

<style scoped>
.search-snippet {
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 3;
    line-clamp: 3;
    overflow: hidden;
    overflow-wrap: anywhere;
    font-size: var(--text-sm);
    font-weight: 400;
    line-height: 1.5;
    color: var(--color-text);
}
.search-snippet__hit {
    padding: 0 2px;
    border-radius: var(--radius-sm);
    background: var(--color-warn-bg);
    color: var(--color-warn-text);
    font-weight: 600;
}
</style>
