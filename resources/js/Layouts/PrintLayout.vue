<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { ArrowLeft, Printer } from '@lucide/vue';
import UiButton from '../Components/ui/UiButton.vue';
import { INTL_LOCALES } from '../i18n.js';

// Bare layout for printable pages (export, emergency card): no app shell, navigation, FAB, toast region or theme
// switch. It always renders light (screen and print) so a printout is never dark, and the toolbar is hidden in print.
// The print button calls window.print() from a click handler (no inline script, so the CSP is respected).
defineProps({
    backHref: { type: String, required: true },
    backLabel: { type: String, required: true },
    printLabel: { type: String, required: true },
});

const page = usePage();
const lang = computed(() => INTL_LOCALES[page.props?.locale] ?? INTL_LOCALES.en ?? 'en');
const print = () => window.print();
</script>

<template>
    <div class="print-layout" :lang="lang" data-testid="print-layout">
        <div class="print-toolbar" data-testid="print-toolbar">
            <UiButton variant="ghost" :icon="ArrowLeft" :href="backHref" data-testid="print-back">{{ backLabel }}</UiButton>
            <UiButton variant="primary" :icon="Printer" data-testid="print-button" @click="print">{{ printLabel }}</UiButton>
        </div>
        <main class="print-sheet" data-testid="print-sheet">
            <slot />
        </main>
    </div>
</template>

<style>
@page { size: A4 portrait; margin: 15mm; }

.print-layout {
    /* Forced light tokens: the user's dark theme never reaches a printable page. */
    --color-bg: #ffffff;
    --color-surface: #ffffff;
    --color-surface-2: #f4f4f2;
    --color-border: #e8e8e5;
    --color-border-strong: #8a8a86;
    --color-text: #1f1f1d;
    --color-text-muted: #55554f;
    --color-primary: #3b6ea8;
    --color-primary-hover: #2f5c8f;
    --color-on-primary: #ffffff;
    --color-danger: #b4432f;
    --color-success: #3d7a54;
    --color-warn-bg: #f6ecd9;
    --color-warn-text: #7f5614;
    --color-focus: #3b6ea8;
    color-scheme: light;
    min-height: 100dvh;
    background: #ffffff;
    color: var(--color-text);
    font-family: var(--font-sans);
}
.print-toolbar { display: flex; flex-wrap: wrap; justify-content: space-between; gap: var(--space-2); max-width: 720px; margin: 0 auto; padding: var(--space-4); }
.print-sheet { max-width: 720px; margin: 0 auto; padding: 0 var(--space-4) var(--space-12); min-width: 0; }

@media print {
    html, body { background: #ffffff !important; }
    .print-layout { min-height: 0; color: #000000; --color-text: #000000; --color-text-muted: #000000; }
    .print-toolbar { display: none !important; }
    .print-sheet { max-width: none; padding: 0; font-size: 11pt; }
    .print-sheet h1 { font-size: 20pt; }
    .print-sheet h2 { font-size: 14pt; }
    .print-sheet a { color: #000000; text-decoration: none; }
    .print-sheet a[href]::after { content: none; }
    .print-sheet section, .print-sheet li, .print-sheet tr { break-inside: avoid; }
    .print-sheet table, .print-sheet th, .print-sheet td { border-color: #000000; }
}
</style>
