<template>
    <ul class="ui-list" role="list"><slot /></ul>
</template>

<style scoped>
.ui-list { margin: 0; padding: 0; list-style: none; }
.ui-list > :deep(li + li) { border-top: 1px solid var(--color-border); }
</style>
