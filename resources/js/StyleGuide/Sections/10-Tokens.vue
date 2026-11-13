<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Check, X } from '@lucide/vue';
import StyleSection from '../StyleSection.vue';
import { contrastRatio } from '../../design/contrast.js';
import { TOKEN_PAIRS } from '../../design/tokenPairs.js';

const { t, n } = useI18n();

const colorTokens = [
    'color-bg', 'color-surface', 'color-surface-2', 'color-border', 'color-border-strong', 'color-text', 'color-text-muted',
    'color-primary', 'color-primary-hover', 'color-primary-soft', 'color-on-primary', 'color-accent', 'color-danger',
    'color-danger-fill', 'color-success', 'color-warn-bg', 'color-warn-text', 'color-focus',
    'avatar-1', 'avatar-2', 'avatar-3', 'avatar-4', 'avatar-5', 'avatar-6',
];
const typeScale = [
    { token: 'text-sm', role: 'Label, secondary', size: '14 px', weight: 400 },
    { token: 'text-base', role: 'Body', size: '16 px', weight: 400 },
    { token: 'text-xl', role: 'Section heading', size: '20 px', weight: 600 },
    { token: 'text-2xl', role: 'Page heading', size: '24 px', weight: 600 },
];
const reservedType = ['text-xs', 'text-lg', 'text-3xl'];
const spacing = [[1, 4], [2, 8], [3, 12], [4, 16], [6, 24], [8, 32], [12, 48], [16, 64]].map(([k, px]) => ({ token: `space-${k}`, px }));
const radii = [['radius-sm', '4 px'], ['radius-md', '8 px'], ['radius-lg', '12 px'], ['radius-full', '9999 px']];
const shadows = ['shadow-sm', 'shadow-md', 'shadow-lg'];

const hex = ref({});

function read() {
    if (typeof document === 'undefined') return;
    const style = getComputedStyle(document.documentElement);
    const next = {};
    for (const token of colorTokens) next[token] = style.getPropertyValue(`--${token}`).trim();
    hex.value = next;
}

const pairs = ref([]);
function compute() {
    read();
    pairs.value = TOKEN_PAIRS.map((p) => {
        const a = hex.value[p.fg];
        const b = hex.value[p.bg];
        const ratio = a && b ? contrastRatio(a, b) : null;
        return { ...p, ratio, pass: ratio !== null && ratio >= p.min };
    });
}

let observer;
onMounted(() => {
    compute();
    observer = new MutationObserver(compute);
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
});
onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <StyleSection id="tokens" title-key="style.section.tokens">
        <h3 class="tokens__h">{{ t('style.section.colors') }}</h3>
        <ul class="tokens__swatches">
            <li v-for="token in colorTokens" :key="token" class="tokens__swatch">
                <span class="tokens__chip" :style="{ background: `var(--${token})` }" aria-hidden="true" />
                <span class="tokens__name">--{{ token }}</span>
                <span class="tokens__hex">{{ hex[token] }}</span>
            </li>
        </ul>

        <table class="tokens__table">
            <thead>
                <tr>
                    <th scope="col">{{ t('style.header.pair') }}</th>
                    <th scope="col">{{ t('style.header.contrast') }}</th>
                    <th scope="col">{{ t('style.header.result') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="p in pairs" :key="`${p.fg}-${p.bg}`">
                    <td>
                        <span class="tokens__sample" :style="{ color: `var(--${p.fg})`, background: `var(--${p.bg})` }">Aa</span>
                        {{ p.usage }} <span class="tokens__muted">(--{{ p.fg }} / --{{ p.bg }})</span>
                    </td>
                    <td>
                        <template v-if="p.ratio !== null">{{ t('style.contrast', { ratio: n(p.ratio, 'decimal') }) }}</template>
                        <span class="tokens__muted"> {{ t('style.minimum', { min: n(p.min, 'decimal') }) }}</span>
                    </td>
                    <td>
                        <span v-if="p.ratio !== null" :class="['tokens__result', p.pass ? 'is-pass' : 'is-fail']">
                            <component :is="p.pass ? Check : X" :size="16" :stroke-width="1.75" aria-hidden="true" />
                            {{ p.pass ? t('style.pass') : t('style.fail') }}
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>

        <h3 class="tokens__h">{{ t('style.section.typography') }}</h3>
        <ul class="tokens__list">
            <li v-for="row in typeScale" :key="row.token" :style="{ fontSize: `var(--${row.token})`, fontWeight: row.weight }">
                {{ row.role }} <span class="tokens__meta">--{{ row.token }}, {{ row.size }}, {{ row.weight }}</span>
            </li>
        </ul>
        <p class="tokens__muted">{{ reservedType.map((r) => `--${r}`).join(', ') }}: {{ t('style.reserved_note') }}</p>

        <h3 class="tokens__h">{{ t('style.section.spacing') }}</h3>
        <ul class="tokens__list">
            <li v-for="s in spacing" :key="s.token" class="tokens__space">
                <span class="tokens__bar" :style="{ width: `var(--${s.token})` }" aria-hidden="true" />
                <span class="tokens__meta">--{{ s.token }}, {{ s.px }} px</span>
                <span v-if="s.px === 12" class="tokens__muted"> {{ t('style.spacing_12_note') }}</span>
            </li>
        </ul>

        <h3 class="tokens__h">{{ t('style.section.radii') }}</h3>
        <ul class="tokens__row">
            <li v-for="[token, px] in radii" :key="token" class="tokens__tile">
                <span class="tokens__box" :style="{ borderRadius: `var(--${token})` }" aria-hidden="true" />
                <span class="tokens__meta">--{{ token }}, {{ px }}</span>
            </li>
        </ul>

        <h3 class="tokens__h">{{ t('style.section.shadows') }}</h3>
        <ul class="tokens__row">
            <li v-for="token in shadows" :key="token" class="tokens__tile">
                <span class="tokens__box" :style="{ boxShadow: `var(--${token})` }" aria-hidden="true" />
                <span class="tokens__meta">--{{ token }}</span>
            </li>
        </ul>
    </StyleSection>
</template>

<style scoped>
.tokens__h { margin: var(--space-6) 0 var(--space-3); font-size: var(--text-base); font-weight: 600; }
.tokens__h:first-of-type { margin-top: 0; }
ul { list-style: none; margin: 0; padding: 0; }
.tokens__swatches { display: grid; grid-template-columns: repeat(auto-fill, minmax(168px, 1fr)); gap: var(--space-3); margin-bottom: var(--space-6); }
.tokens__swatch { display: flex; flex-direction: column; gap: var(--space-1); font-size: var(--text-sm); }
.tokens__chip { height: 40px; border-radius: var(--radius-md); border: 1px solid var(--color-border-strong); }
.tokens__name { font-family: var(--font-mono); word-break: break-all; }
.tokens__hex, .tokens__muted, .tokens__meta { color: var(--color-text-muted); font-size: var(--text-sm); font-weight: 400; }
.tokens__table { width: 100%; border-collapse: collapse; font-size: var(--text-sm); }
.tokens__table th, .tokens__table td { text-align: left; padding: var(--space-2) var(--space-3); border-bottom: 1px solid var(--color-border); vertical-align: middle; }
.tokens__table th { font-weight: 600; }
.tokens__sample { display: inline-block; min-width: 32px; padding: 0 var(--space-2); margin-right: var(--space-2); border: 1px solid var(--color-border); border-radius: var(--radius-sm); text-align: center; }
.tokens__result { display: inline-flex; align-items: center; gap: var(--space-1); font-weight: 600; }
.tokens__result.is-pass { color: var(--color-success); }
.tokens__result.is-fail { color: var(--color-danger); }
.tokens__list { display: flex; flex-direction: column; gap: var(--space-2); }
.tokens__space { display: flex; align-items: center; gap: var(--space-3); flex-wrap: wrap; }
.tokens__bar { display: inline-block; height: 16px; background: var(--color-primary); border-radius: var(--radius-sm); }
.tokens__row { display: flex; flex-wrap: wrap; gap: var(--space-6); }
.tokens__tile { display: flex; flex-direction: column; gap: var(--space-2); align-items: flex-start; }
.tokens__box { width: 80px; height: 56px; background: var(--color-surface); border: 1px solid var(--color-border); }
</style>
