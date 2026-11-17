<script setup>
import { computed, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ChefHat, Heart, Lock, Users } from '@lucide/vue';
import UiAvatar from '../ui/UiAvatar.vue';
import UiTag from '../ui/UiTag.vue';
import { securityFetch } from '../../Security/securityFetch.js';
import { useToast } from '../ui/useToast.js';
import { totalMinutes } from './recipeFilters.js';

// One recipe on the overview grid. The whole card is one link (name = full title); the favourite button follows the link in the
// DOM and sits visually over the photo. Every text is interpolated, never rendered as HTML.
const props = defineProps({
    recipe: { type: Object, required: true },
});
const { t } = useI18n();
const { show: showToast } = useToast();

const favourite = ref(Boolean(props.recipe.favourite));
watch(() => props.recipe.favourite, (v) => { favourite.value = Boolean(v); });
let saving = false;

const href = computed(() => `/recipes/${props.recipe.id}`);
const photo = computed(() => {
    const p = props.recipe.mainPhoto;

    return p ? `/recipes/${props.recipe.id}/photos/${p.id}/thumb?v=${p.version}` : null;
});
const meta = computed(() => {
    const parts = [];
    const minutes = totalMinutes(props.recipe);
    if (minutes !== null) parts.push(t('recipes.card.minutes', { n: minutes }));
    if (props.recipe.servings) parts.push(t('recipes.card.servings', { n: props.recipe.servings }, props.recipe.servings));

    return parts.join(' · ');
});
const firstCategory = computed(() => props.recipe.categories?.[0] ?? null);
const moreCategories = computed(() => Math.max(0, (props.recipe.categories?.length ?? 0) - 1));
const byOther = computed(() => props.recipe.author && !props.recipe.author.isMe);
const authorFirst = computed(() => (props.recipe.author?.name ?? '').trim().split(/\s+/)[0] ?? '');
const isShared = computed(() => props.recipe.visibility === 'shared');

// Optimistic toggle: flips at once, rolls back and tells the member when the server refuses. Sent with a plain request, not an
// Inertia visit: a visit is cancelled by the next visit (a search, a filter, Load more) and would leave the heart on although
// nothing was saved.
async function toggle() {
    if (saving) return;
    const next = !favourite.value;
    favourite.value = next;
    saving = true;
    let result;
    try {
        result = await securityFetch(`/recipes/${props.recipe.id}/favourite`, { method: 'PUT', body: { favourite: next } });
    } catch {
        result = { ok: false };
    }
    saving = false;
    if (!result?.ok) rollback(next);
}
function rollback(attempted) {
    if (favourite.value === attempted) favourite.value = !attempted;
    showToast(t('recipes.toast.favourite_failed'));
}
</script>

<template>
    <article class="recipe-card" data-testid="recipe-card">
        <Link :href="href" class="recipe-card__link">
            <span class="recipe-card__photo">
                <img v-if="photo" :src="photo" alt="" loading="lazy" class="recipe-card__img" />
                <ChefHat v-else :size="32" :stroke-width="1.75" aria-hidden="true" class="recipe-card__placeholder" data-testid="recipe-placeholder" />
            </span>
            <span class="recipe-card__body">
                <span class="recipe-card__title">{{ recipe.title }}</span>
                <span v-if="meta || byOther" class="recipe-card__meta">
                    <span v-if="meta">{{ meta }}</span>
                    <span v-if="byOther" class="recipe-card__by" data-testid="recipe-author">
                        <UiAvatar :name="authorFirst || recipe.author.name" :user-id="recipe.author.id" :size="32" decorative />
                        {{ t('recipes.card.by', { name: authorFirst }) }}
                    </span>
                </span>
                <span class="recipe-card__tags">
                    <UiTag :icon="isShared ? Users : Lock">{{ t(isShared ? 'recipes.visibility.shared' : 'recipes.visibility.private') }}</UiTag>
                    <UiTag v-if="firstCategory">{{ firstCategory }}</UiTag>
                    <span v-if="moreCategories > 0" class="recipe-card__more">{{ t('recipes.card.more_categories', { n: moreCategories }) }}</span>
                </span>
            </span>
        </Link>
        <button
            type="button"
            class="recipe-card__fav"
            :aria-pressed="favourite ? 'true' : 'false'"
            :aria-label="t('recipes.card.favourite_of', { title: recipe.title })"
            :title="t('recipes.card.favourite')"
            data-testid="recipe-favourite"
            @click="toggle"
        >
            <Heart :size="22" :stroke-width="1.75" aria-hidden="true" :fill="favourite ? 'currentColor' : 'none'" />
        </button>
    </article>
</template>

<style scoped>
.recipe-card {
    position: relative;
    display: flex;
    flex-direction: column;
    min-width: 0;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm, none);
    overflow: hidden;
}
.recipe-card:focus-within:has(.recipe-card__link:focus-visible) { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.recipe-card__link { display: flex; flex-direction: column; flex: 1; color: inherit; text-decoration: none; outline: none; }
.recipe-card:hover { border-color: var(--color-border-strong); }
.recipe-card__photo { display: flex; align-items: center; justify-content: center; aspect-ratio: 4 / 3; background: var(--color-surface-2); color: var(--color-text-muted); overflow: hidden; }
.recipe-card__img { width: 100%; height: 100%; object-fit: cover; display: block; }
.recipe-card__body { display: flex; flex-direction: column; gap: var(--space-2); padding: var(--space-3) var(--space-4) var(--space-4); min-width: 0; }
.recipe-card__title {
    font-size: var(--text-base);
    font-weight: 600;
    line-height: 1.3;
    overflow-wrap: anywhere;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.recipe-card__meta { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-1) var(--space-3); font-size: var(--text-sm); color: var(--color-text-muted); overflow-wrap: anywhere; }
.recipe-card__by { display: inline-flex; align-items: center; gap: var(--space-1); }
.recipe-card__by :deep(.ui-avatar) { width: 24px; height: 24px; font-size: var(--text-xs); }
.recipe-card__tags { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-1) var(--space-2); }
.recipe-card__tags :deep(.ui-tag) { white-space: normal; overflow-wrap: anywhere; min-width: 0; }
.recipe-card__more { font-size: var(--text-sm); color: var(--color-text-muted); }
.recipe-card__fav {
    position: absolute;
    inset-block-start: var(--space-2);
    inset-inline-end: var(--space-2);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border: 0;
    border-radius: var(--radius-full);
    background: var(--color-surface);
    color: var(--color-text-muted);
    box-shadow: 0 1px 3px rgb(0 0 0 / 0.2);
    cursor: pointer;
}
.recipe-card__fav[aria-pressed='true'] { color: var(--color-danger); }
.recipe-card__fav:hover { background: var(--color-surface-2); }
.recipe-card__fav:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
</style>
