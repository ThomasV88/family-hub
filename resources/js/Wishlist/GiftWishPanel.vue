<script setup>
import { computed } from 'vue';
import { ExternalLink, Star } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiButton from '../Components/ui/UiButton.vue';
import UiDetailPanel from '../Components/ui/UiDetailPanel.vue';
import UiTag from '../Components/ui/UiTag.vue';
import WishImage from './WishImage.vue';
import { formatMoney } from './money.js';

// Read-only wish for givers. Plan 04-10 adds the reservation box between the priority tag and the links.
const props = defineProps({
    open: { type: Boolean, default: false },
    wish: { type: Object, default: null },
});
const emit = defineEmits(['close']);
const { t, locale } = useI18n();

const price = computed(() => (props.wish ? formatMoney(props.wish.price, props.wish.currency, locale.value) : ''));
const urls = computed(() => props.wish?.urls ?? []);
const label = (link) => (link.label && link.label.trim() !== '' ? link.label : link.host);
</script>

<template>
    <UiDetailPanel :open="open && wish !== null" :title="t('wishlist.panel.edit')" @update:open="(v) => (v ? null : emit('close'))">
        <div v-if="wish" class="gift-panel" data-testid="gift-wish-panel">
            <WishImage :image="wish.image" size="panel" />
            <h3 class="gift-panel__title">{{ wish.title }}</h3>
            <div v-if="price || wish.size_notes" class="gift-panel__price">
                <p v-if="price" class="gift-panel__amount">{{ price }}</p>
                <p v-if="wish.size_notes" class="gift-panel__variant">{{ t('wishlist.field.variant_line', { value: wish.size_notes }) }}</p>
            </div>
            <div>
                <UiTag :icon="wish.priority === 'top' ? Star : null">{{ t(`wishlist.priority.${wish.priority}`) }}</UiTag>
            </div>

            <section v-if="urls.length" class="gift-panel__section">
                <h4 class="gift-panel__heading">{{ t('wishlist.field.links') }}</h4>
                <ul class="gift-panel__links">
                    <li v-for="link in urls" :key="link.id">
                        <a :href="link.url" target="_blank" rel="noopener noreferrer" :aria-label="t('wishlist.link.opens_new', { label: label(link) })" class="gift-panel__link">
                            <ExternalLink :size="20" :stroke-width="1.75" aria-hidden="true" />
                            <span class="gift-panel__link-text">
                                <span class="gift-panel__link-label">{{ label(link) }}</span>
                                <span v-if="link.label && link.label.trim() !== ''" class="gift-panel__link-host">{{ link.host }}</span>
                            </span>
                        </a>
                    </li>
                </ul>
            </section>

            <section v-if="wish.notes" class="gift-panel__section">
                <h4 class="gift-panel__heading">{{ t('wishlist.field.notes') }}</h4>
                <p class="gift-panel__notes">{{ wish.notes }}</p>
            </section>
        </div>

        <template #footer>
            <UiButton variant="secondary" @click="emit('close')">{{ t('wishlist.close') }}</UiButton>
        </template>
    </UiDetailPanel>
</template>

<style scoped>
.gift-panel { display: flex; flex-direction: column; gap: var(--space-4); min-width: 0; }
.gift-panel__title { margin: 0; font-size: var(--text-xl); font-weight: 600; line-height: 1.2; overflow-wrap: anywhere; }
.gift-panel__price { display: flex; flex-direction: column; gap: var(--space-1); }
.gift-panel__amount { margin: 0; font-weight: 600; }
.gift-panel__variant { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); overflow-wrap: anywhere; }
.gift-panel__section { display: flex; flex-direction: column; gap: var(--space-2); }
.gift-panel__heading { margin: 0; font-size: var(--text-sm); font-weight: 600; line-height: 1.4; }
.gift-panel__links { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: var(--space-2); }
.gift-panel__link { display: flex; align-items: flex-start; gap: var(--space-2); min-height: 44px; color: var(--color-primary); text-decoration: none; }
.gift-panel__link:hover .gift-panel__link-label { text-decoration: underline; }
.gift-panel__link:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; border-radius: var(--radius-sm); }
.gift-panel__link-text { display: flex; flex-direction: column; min-width: 0; }
.gift-panel__link-label, .gift-panel__link-host { overflow-wrap: anywhere; }
.gift-panel__link-host { font-size: var(--text-sm); color: var(--color-text-muted); }
.gift-panel__notes { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; }
</style>
