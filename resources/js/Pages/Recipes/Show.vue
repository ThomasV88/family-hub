<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ChefHat, ChevronLeft, Clock, Ellipsis, ExternalLink, Flame, Heart, Lock, Pencil, RotateCcw, ShoppingCart, Trash2, UtensilsCrossed, Users } from '@lucide/vue';
import AppShell from '../../Layouts/AppShell.vue';
import UiAvatar from '../../Components/ui/UiAvatar.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiConfirmDialog from '../../Components/ui/UiConfirmDialog.vue';
import UiDialog from '../../Components/ui/UiDialog.vue';
import UiEmptyState from '../../Components/ui/UiEmptyState.vue';
import UiMenu from '../../Components/ui/UiMenu.vue';
import UiRadio from '../../Components/ui/UiRadio.vue';
import UiTabs from '../../Components/ui/UiTabs.vue';
import UiTag from '../../Components/ui/UiTag.vue';
import HelpTip from '../../Components/ui/HelpTip.vue';
import { useToast } from '../../Components/ui/useToast.js';
import AddToShoppingDialog from '../../Components/Recipes/AddToShoppingDialog.vue';
import PhoneProbe from '../../Components/Recipes/PhoneProbe.vue';
import RecipeIngredients from '../../Components/Recipes/RecipeIngredients.vue';
import RecipeNotes from '../../Components/Recipes/RecipeNotes.vue';
import RecipePhotoGallery from '../../Components/Recipes/RecipePhotoGallery.vue';
import RecipeSteps from '../../Components/Recipes/RecipeSteps.vue';
import { failureKey } from '../../Components/Recipes/recipeFailure.js';
import { displayQuantity, scaleQuantity } from '../../Components/Recipes/recipeScale.js';
import { useRecipeState } from '../../Components/Recipes/useRecipeState.js';
import { useWakeLock } from '../../Components/Recipes/useWakeLock.js';

// Recipe detail. The server decides who may see the recipe and what the member may do (`abilities`); this page only renders what
// is allowed. Ticks, favourite, notes and servings belong to the member and are saved at once, in order (useRecipeState).
// Servings recalculation is display only: nothing here ever changes the stored recipe. Text is always interpolated, never HTML.
const props = defineProps({
    recipe: { type: Object, default: null },
    state: { type: Object, default: () => ({}) },
    abilities: { type: Object, default: () => ({}) },
    units: { type: Array, default: () => [] },
});
const { t, locale } = useI18n();
const toast = useToast();
// Phone layout of the shell, reported by PhoneProbe from inside the AppShell slot.
const phone = ref(false);

const gone = ref(false);
const unavailable = computed(() => props.recipe === null || gone.value);
const recipeId = props.recipe?.id ?? 0;
const unitById = computed(() => Object.fromEntries(props.units.map((u) => [u.id, u])));

// A failed write: the shared recipe texts for 403, 404, 409, 419 and 429, the plain "Could not save" for anything else. A recipe
// that vanished leaves the page; a refusal or a changed recipe reloads what the server now says.
function failed(status, fallback = 'recipes.toast.state_failed') {
    toast.show(t(failureKey(status, fallback)));
    if (status === 404) gone.value = true;
    else if (status === 403 || status === 409) router.reload({ preserveScroll: true });
}
const queue = useRecipeState({
    recipeId,
    initial: { tickedIngredientIds: props.state?.tickedIngredientIds, doneStepIds: props.state?.doneStepIds, favourite: props.state?.favourite === true },
    onFail: (key, status) => failed(status),
});
const sendState = (path, method, body) => queue.send(`/recipes/${recipeId}/${path}`, method, body);

const isTicked = (id) => queue.state(`i:${id}`);
const isDone = (id) => queue.state(`s:${id}`);
const favourite = computed(() => queue.state('fav'));

// Servings: the member's own chosen number (or the recipe's), shown by rescaling the quantities.
const base = computed(() => props.recipe?.servings ?? null);
const servings = ref(Math.min(99, Math.max(1, Number(props.state?.servings ?? props.recipe?.servings ?? 1))));
const rows = computed(() => (props.recipe?.ingredients ?? []).map((ingredient) => {
    const unit = unitById.value[ingredient.unitId] ?? null;
    if (ingredient.quantity === null || ingredient.quantity === undefined) return { ingredient, quantityText: '' };
    const value = base.value === null ? Number(ingredient.quantity) : scaleQuantity(ingredient.quantity, base.value, servings.value);

    return { ingredient, quantityText: displayQuantity(value, unit, locale.value, t) };
}));
async function saveServings(value) {
    const result = await sendState('servings', 'PUT', { servings: value });
    if (!result.ok) failed(result.status);
}
async function saveNote(note) {
    const result = await sendState('note', 'PUT', { note });
    if (result.status === 404) gone.value = true;

    return result;
}

const prepared = computed(() => rows.value.filter((r) => isTicked(r.ingredient.id)).length);
const stepsDone = computed(() => (props.recipe?.steps ?? []).filter((s) => isDone(s.id)).length);

// Author actions.
const mine = computed(() => props.recipe?.author?.isMe === true);
const canEdit = computed(() => props.abilities.edit === true);
const canDelete = computed(() => props.abilities.delete === true);
const canChangeVisibility = computed(() => props.abilities.changeVisibility === true);
const canAddToShopping = computed(() => props.abilities.addToShopping === true);
const shoppingOpen = ref(false);
const shared = computed(() => props.recipe?.visibility === 'shared');
const sourceLink = computed(() => (/^https?:\/\//i.test(props.recipe?.sourceUrl ?? '') ? props.recipe.sourceUrl : null));
const sourceHost = computed(() => props.recipe?.sourceHost ?? '');

function onOpen(event, href) {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    router.visit(href);
}

// Layout: tabs below 840 px of the page's own width, two columns from 840 px.
const root = ref(null);
const wide = ref(false);
let observer = null;
function measure() {
    wide.value = (root.value?.clientWidth ?? 0) >= 840;
}
onMounted(() => {
    measure();
    if (typeof ResizeObserver !== 'undefined' && root.value) {
        observer = new ResizeObserver(measure);
        observer.observe(root.value);
    }
});
onBeforeUnmount(() => observer?.disconnect());

const TABS = ['ingredients', 'steps', 'notes'];
const initialTab = () => {
    const wanted = typeof window === 'undefined' ? null : new URLSearchParams(window.location.search).get('tab');

    return TABS.includes(wanted) ? wanted : 'ingredients';
};
const tab = ref(initialTab());
watch(tab, (value) => {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', value);
    window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
});
const tabs = computed(() => TABS.map((key) => ({ key, label: t(`recipes.detail.tab_${key}`) })));

// Cooking mode: local to this device, off by default. The screen stays on where the browser supports it.
const cooking = ref(false);
const wake = useWakeLock(cooking);
const showBar = computed(() => phone.value && !unavailable.value);
function jump(id) {
    const el = document.getElementById(id);
    const calm = typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    el?.scrollIntoView?.({ behavior: calm ? 'auto' : 'smooth', block: 'start' });
}

// The bar is 64 px high and sits where toasts appear: lift the toasts above it while it is shown.
const LIFT = '--fh-bar-lift';
watch(showBar, (on) => {
    if (on) document.documentElement.style.setProperty(LIFT, '64px');
    else document.documentElement.style.removeProperty(LIFT);
}, { immediate: true });
onBeforeUnmount(() => document.documentElement.style.removeProperty(LIFT));

// Favourite, reset, visibility, delete.
const resetOpen = ref(false);
const resetBusy = ref(false);
async function doReset() {
    if (resetBusy.value) return;
    resetBusy.value = true;
    const result = await queue.reset();
    resetBusy.value = false;
    resetOpen.value = false;
    if (result.ok) toast.show(t('recipes.toast.progress_reset'));
    else failed(result.status);
}

const visibilityOpen = ref(false);
const visibilityChoice = ref('private');
const privateOpen = ref(false);
const visibilityBusy = ref(false);
function openVisibility() {
    visibilityChoice.value = props.recipe.visibility;
    visibilityOpen.value = true;
}
function saveVisibility() {
    if (visibilityBusy.value) return;
    if (visibilityChoice.value === props.recipe.visibility) {
        visibilityOpen.value = false;

        return;
    }
    if (shared.value && visibilityChoice.value === 'private') {
        visibilityOpen.value = false;
        privateOpen.value = true;

        return;
    }
    submitVisibility();
}
function submitVisibility() {
    if (visibilityBusy.value) return;
    visibilityBusy.value = true;
    router.put(`/recipes/${recipeId}/visibility`, { visibility: visibilityChoice.value }, {
        preserveScroll: true,
        onSuccess: () => { visibilityOpen.value = false; },
        onError: () => toast.show(t('recipes.error.save_failed')),
        onHttpException: (response) => { failed(response?.status, 'recipes.error.save_failed'); visibilityOpen.value = false; return false; },
        onNetworkError: () => toast.show(t('recipes.error.save_failed')),
        onFinish: () => { visibilityBusy.value = false; },
    });
}

const deleteOpen = ref(false);
const deleting = ref(false);
function deleteRecipe() {
    if (deleting.value) return;
    deleting.value = true;
    router.delete(`/recipes/${recipeId}`, {
        onHttpException: (response) => { toast.show(t(failureKey(response?.status, 'recipes.error.save_failed'))); if (response?.status === 404) gone.value = true; return false; },
        onNetworkError: () => toast.show(t('recipes.error.save_failed')),
        onFinish: () => { deleting.value = false; },
    });
}

const menuItems = computed(() => {
    const entries = [];
    if (canChangeVisibility.value) entries.push({ type: 'item', key: 'visibility', label: t('recipes.detail.change_visibility'), icon: shared.value ? Users : Lock });
    entries.push({ type: 'item', key: 'reset', label: t('recipes.detail.reset_progress'), icon: RotateCcw });
    if (canDelete.value) entries.push({ type: 'item', key: 'delete', label: t('recipes.detail.delete'), icon: Trash2 });

    return entries;
});
function onMenu({ key }) {
    if (key === 'visibility') openVisibility();
    if (key === 'reset') resetOpen.value = true;
    if (key === 'delete') deleteOpen.value = true;
}
</script>

<template>
    <Head :title="recipe?.title ?? t('recipes.unavailable.title')" />
    <AppShell current="recipes">
        <PhoneProbe @change="(v) => (phone = v)" />
        <div v-if="unavailable" class="rec-show rec-show--empty" data-testid="recipe-unavailable">
            <UiEmptyState :heading-level="1" :icon="ChefHat" :title="t('recipes.unavailable.title')" :body="t('recipes.unavailable.body')">
                <template #action>
                    <UiButton variant="primary" href="/recipes" @click="(e) => onOpen(e, '/recipes')">{{ t('recipes.unavailable.back') }}</UiButton>
                </template>
            </UiEmptyState>
        </div>

        <div v-else ref="root" :class="['rec-show', { 'rec-show--cooking': cooking, 'rec-show--bar': showBar, 'rec-show--wide': wide }]" data-testid="recipe-detail">
            <header class="rec-head">
                <Link href="/recipes" class="rec-head__back"><ChevronLeft :size="20" :stroke-width="1.75" aria-hidden="true" />{{ t('recipes.overview.title') }}</Link>
                <div class="rec-head__titlerow">
                    <h1 class="rec-head__title" data-testid="recipe-title">{{ recipe.title }}</h1>
                    <UiButton
                        variant="ghost"
                        icon-only
                        :icon="Heart"
                        :label="favourite ? t('recipes.card.favourite_remove') : t('recipes.card.favourite_add')"
                        :aria-pressed="favourite ? 'true' : 'false'"
                        :class="['rec-head__fav', { 'is-on': favourite }]"
                        data-testid="favourite"
                        @click="queue.mark('fav', !favourite)"
                    />
                </div>
                <div class="rec-head__tags">
                    <UiTag :icon="shared ? Users : Lock" data-testid="visibility-tag">{{ shared ? t('recipes.visibility.shared') : t('recipes.visibility.private') }}</UiTag>
                    <span v-if="!mine" class="rec-head__by" data-testid="recipe-author"><UiAvatar :name="recipe.author.name" :user-id="recipe.author.id" :size="32" decorative class="rec-head__avatar" />{{ t('recipes.card.by', { name: recipe.author.name }) }}</span>
                </div>
                <p v-if="!mine" class="rec-head__readonly" data-testid="author-only">{{ t('recipes.detail.only_author', { name: recipe.author.name }) }}</p>

                <p class="rec-head__meta" data-testid="recipe-meta">
                    <span v-if="recipe.prepMinutes" class="rec-head__item"><Clock :size="16" :stroke-width="1.75" aria-hidden="true" />{{ t('recipes.detail.prep', { n: recipe.prepMinutes }) }}</span>
                    <span v-if="recipe.cookMinutes" class="rec-head__item"><Flame :size="16" :stroke-width="1.75" aria-hidden="true" />{{ t('recipes.detail.cook', { n: recipe.cookMinutes }) }}</span>
                    <UiTag v-for="category in recipe.categories" :key="category">{{ category }}</UiTag>
                    <a v-if="sourceLink" :href="sourceLink" class="rec-head__source" target="_blank" rel="noopener noreferrer nofollow" data-testid="source-link"><ExternalLink :size="16" :stroke-width="1.75" aria-hidden="true" />{{ t('recipes.detail.source', { host: sourceHost }) }}</a>
                </p>

                <div class="rec-head__actions">
                    <span class="rec-head__shopping-slot" data-slot="add-to-shopping">
                        <UiButton v-if="canAddToShopping && !phone" variant="primary" :icon="ShoppingCart" data-testid="add-to-shopping" @click="shoppingOpen = true">{{ t('recipes.shopping.button') }}</UiButton>
                    </span>
                    <UiButton v-if="canEdit" variant="secondary" :icon="Pencil" :href="`/recipes/${recipe.id}/edit`" data-testid="edit-recipe" @click="(e) => onOpen(e, `/recipes/${recipe.id}/edit`)">{{ t('recipes.detail.edit') }}</UiButton>
                    <template v-if="!phone">
                        <UiButton variant="secondary" :icon="UtensilsCrossed" :aria-pressed="cooking ? 'true' : 'false'" data-testid="cooking-toggle" @click="cooking = !cooking">{{ t('recipes.cooking.toggle') }}</UiButton>
                        <HelpTip topic="recipes.cooking" :label="t('recipes.cooking.toggle')" />
                    </template>
                    <UiMenu :label="t('recipes.detail.more')" :icon="Ellipsis" icon-only :items="menuItems" @select="onMenu" />
                </div>
                <p v-if="cooking && wake.supported" class="rec-head__hint" data-testid="wake-hint">{{ t('recipes.cooking.hint') }}</p>
            </header>

            <p v-if="recipe.description && !cooking" class="rec-show__description" data-testid="recipe-description">{{ recipe.description }}</p>

            <div v-if="wide" class="rec-cols">
                <div class="rec-cols__col">
                    <RecipePhotoGallery :photos="recipe.photos" :title="recipe.title" :compact="cooking" />
                    <RecipeIngredients :rows="rows" :is-checked="isTicked" :servings-base="base" :servings="servings" :save-servings="saveServings" :cooking="cooking" @update:servings="(v) => (servings = v)" @toggle="(id, v) => queue.mark(`i:${id}`, v)" />
                </div>
                <div class="rec-cols__col">
                    <RecipeSteps :steps="recipe.steps" :is-checked="isDone" :cooking="cooking" @toggle="(id, v) => queue.mark(`s:${id}`, v)" @reset="resetOpen = true" />
                    <RecipeNotes :model-value="state?.note ?? ''" :save="saveNote" />
                </div>
            </div>
            <template v-else>
                <RecipePhotoGallery :photos="recipe.photos" :title="recipe.title" :compact="cooking" />
                <template v-if="cooking">
                    <nav class="rec-anchors" :aria-label="t('recipes.cooking.anchors')" data-testid="cooking-anchors">
                        <UiButton variant="secondary" @click="jump('ings-heading')">{{ t('recipes.detail.ingredients') }}</UiButton>
                        <UiButton variant="secondary" @click="jump('steps-heading')">{{ t('recipes.detail.steps') }}</UiButton>
                    </nav>
                    <RecipeIngredients :rows="rows" :is-checked="isTicked" :servings-base="base" :servings="servings" :save-servings="saveServings" cooking @update:servings="(v) => (servings = v)" @toggle="(id, v) => queue.mark(`i:${id}`, v)" />
                    <RecipeSteps :steps="recipe.steps" :is-checked="isDone" cooking @toggle="(id, v) => queue.mark(`s:${id}`, v)" @reset="resetOpen = true" />
                    <RecipeNotes :model-value="state?.note ?? ''" :save="saveNote" />
                </template>
                <UiTabs v-else v-model="tab" :tabs="tabs" :label="t('recipes.detail.sections')">
                    <template #panel-ingredients>
                        <RecipeIngredients :rows="rows" :is-checked="isTicked" :servings-base="base" :servings="servings" :save-servings="saveServings" @update:servings="(v) => (servings = v)" @toggle="(id, v) => queue.mark(`i:${id}`, v)" />
                    </template>
                    <template #panel-steps>
                        <RecipeSteps :steps="recipe.steps" :is-checked="isDone" @toggle="(id, v) => queue.mark(`s:${id}`, v)" @reset="resetOpen = true" />
                    </template>
                    <template #panel-notes>
                        <RecipeNotes :model-value="state?.note ?? ''" :save="saveNote" />
                    </template>
                </UiTabs>
            </template>

            <div v-if="showBar" class="rec-bar" data-testid="recipe-bar">
                <template v-if="cooking">
                    <p class="rec-bar__progress" data-testid="cooking-progress">
                        <span>{{ t('recipes.progress.ingredients_bar', { x: prepared, y: rows.length }) }}</span>
                        <span>{{ t('recipes.progress.steps_bar', { x: stepsDone, y: recipe.steps.length }) }}</span>
                    </p>
                    <UiButton variant="ghost" data-testid="cooking-leave" @click="cooking = false">{{ t('recipes.cooking.leave') }}</UiButton>
                </template>
                <template v-else>
                    <span class="rec-bar__shopping-slot" data-slot="add-to-shopping-bar">
                        <UiButton v-if="canAddToShopping" variant="primary" :icon="ShoppingCart" data-testid="add-to-shopping-bar" @click="shoppingOpen = true">{{ t('recipes.shopping.button') }}</UiButton>
                    </span>
                    <UiButton variant="secondary" :icon="UtensilsCrossed" aria-pressed="false" data-testid="cooking-toggle-bar" @click="cooking = true">{{ t('recipes.cooking.toggle') }}</UiButton>
                    <HelpTip topic="recipes.cooking" :label="t('recipes.cooking.toggle')" />
                </template>
            </div>
        </div>

        <AddToShoppingDialog
            v-if="!unavailable && canAddToShopping"
            v-model:open="shoppingOpen"
            :recipe-id="recipeId"
            :recipe="recipe"
            :base-servings="base"
            :servings="servings"
            :units="units"
            @failed="(status) => failed(status, 'recipes.shopping.add_failed')"
        />
        <UiDialog v-if="!unavailable" :open="resetOpen" :title="t('recipes.reset.title')" :dismissable="!resetBusy" @update:open="(v) => (resetOpen = v)">
            <p class="rec-show__dialog-body">{{ t('recipes.reset.body') }}</p>
            <template #footer>
                <UiButton variant="secondary" :disabled="resetBusy" data-testid="reset-cancel" @click="resetOpen = false">{{ t('recipes.reset.cancel') }}</UiButton>
                <UiButton variant="primary" :loading="resetBusy" :disabled="resetBusy" data-testid="reset-confirm" @click="doReset">{{ t('recipes.reset.confirm') }}</UiButton>
            </template>
        </UiDialog>

        <template v-if="!unavailable && canChangeVisibility">
            <UiDialog :open="visibilityOpen" :title="t('recipes.visibility.label')" :dismissable="!visibilityBusy" @update:open="(v) => (visibilityOpen = v)">
                <fieldset class="rec-show__vis" data-testid="visibility-dialog">
                    <legend class="visually-hidden">{{ t('recipes.visibility.label') }}</legend>
                    <UiRadio v-model="visibilityChoice" value="private" name="detail-visibility" data-testid="visibility-private">
                        <span class="rec-show__vis-text"><span class="rec-show__vis-name">{{ t('recipes.visibility.option_private') }}</span><span class="rec-show__vis-sub">{{ t('recipes.visibility.private_hint') }}</span></span>
                    </UiRadio>
                    <UiRadio v-model="visibilityChoice" value="shared" name="detail-visibility" data-testid="visibility-shared">
                        <span class="rec-show__vis-text"><span class="rec-show__vis-name">{{ t('recipes.visibility.option_shared') }}</span><span class="rec-show__vis-sub">{{ t('recipes.visibility.shared_hint') }}</span></span>
                    </UiRadio>
                </fieldset>
                <template #footer>
                    <UiButton variant="secondary" :disabled="visibilityBusy" @click="visibilityOpen = false">{{ t('recipes.detail.visibility_cancel') }}</UiButton>
                    <UiButton variant="primary" :loading="visibilityBusy" :disabled="visibilityBusy" data-testid="visibility-save" @click="saveVisibility">{{ t('recipes.detail.visibility_save') }}</UiButton>
                </template>
            </UiDialog>
            <UiConfirmDialog
                v-model:open="privateOpen"
                :title="t('recipes.form.private_title')"
                :body="t('recipes.form.private_body')"
                :confirm-label="t('recipes.form.private_yes')"
                :cancel-label="t('recipes.form.private_no')"
                @confirm="submitVisibility"
            />
        </template>
        <UiConfirmDialog
            v-if="!unavailable && canDelete"
            v-model:open="deleteOpen"
            :title="t('recipes.delete.title', { title: recipe.title })"
            :body="t('recipes.delete.body')"
            :confirm-label="t('recipes.delete.confirm')"
            :cancel-label="t('recipes.delete.cancel')"
            @confirm="deleteRecipe"
        />
    </AppShell>
</template>

<style scoped>
.rec-show { container-type: inline-size; container-name: recshow; display: flex; flex-direction: column; gap: var(--space-4); max-width: var(--content-max); margin-inline: auto; width: 100%; }
.rec-show--bar { padding-block-end: 80px; }
.rec-show--empty { max-width: 640px; }
.rec-head { display: flex; flex-direction: column; gap: var(--space-2); }
.rec-head__back { display: inline-flex; align-items: center; align-self: flex-start; gap: var(--space-1); min-height: 44px; color: var(--color-text-muted); font-size: var(--text-sm); font-weight: 600; text-decoration: none; }
.rec-head__back:hover { color: var(--color-text); }
.rec-head__back:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; border-radius: var(--radius-sm); }
.rec-head__titlerow { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--space-3); }
.rec-head__title { margin: 0; font-size: var(--text-2xl); font-weight: 600; line-height: 1.2; overflow-wrap: anywhere; }
.rec-head__fav { flex: none; min-width: var(--control-touch); min-height: var(--control-touch); }
.rec-head__fav.is-on :deep(svg) { fill: currentColor; color: var(--color-primary); }
.rec-head__tags { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); }
.rec-head__by { display: inline-flex; align-items: center; gap: var(--space-2); font-size: var(--text-sm); color: var(--color-text-muted); }
.rec-head__avatar { --ui-avatar-size: 24px; }
.rec-head__readonly { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.rec-head__meta { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2) var(--space-4); margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.rec-head__item { display: inline-flex; align-items: center; gap: var(--space-1); }
.rec-head__source { display: inline-flex; align-items: center; gap: var(--space-1); color: var(--color-primary); }
.rec-head__actions { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); }
.rec-head__shopping-slot, .rec-bar__shopping-slot { display: contents; }
.rec-head__hint { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.rec-show__description { margin: 0; font-size: var(--text-base); line-height: 1.5; white-space: pre-line; overflow-wrap: anywhere; }
.rec-show__dialog-body { margin: 0; font-size: var(--text-base); }
.rec-show__vis { display: flex; flex-direction: column; gap: var(--space-3); margin: 0; padding: 0; border: 0; }
.rec-show__vis-text { display: flex; flex-direction: column; }
.rec-show__vis-name { font-weight: 600; }
.rec-show__vis-sub { font-size: var(--text-sm); color: var(--color-text-muted); }
.rec-cols { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: var(--space-6); align-items: start; }
.rec-cols__col { display: flex; flex-direction: column; gap: var(--space-6); min-width: 0; }
.rec-anchors { position: sticky; inset-block-start: 0; z-index: 2; display: flex; gap: var(--space-2); padding-block: var(--space-2); background: var(--color-bg); }
.rec-bar {
    position: fixed; inset-inline: 0; z-index: 20;
    bottom: calc(var(--shell-bottombar) + env(safe-area-inset-bottom));
    display: flex; align-items: center; justify-content: space-between; gap: var(--space-3);
    min-height: 64px; padding: var(--space-2) var(--space-4);
    background: var(--color-surface); border-block-start: 1px solid var(--color-border);
}
.rec-bar__progress { display: flex; flex-direction: column; margin: 0; font-size: var(--text-sm); font-weight: 600; }
.rec-bar :deep(.ui-button) { min-height: var(--control-touch); }
</style>
