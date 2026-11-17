<script setup>
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ExternalLink, Lock, Sparkles, Users } from '@lucide/vue';
import AppShell from '../../Layouts/AppShell.vue';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiConfirmDialog from '../../Components/ui/UiConfirmDialog.vue';
import UiField from '../../Components/ui/UiField.vue';
import UiInput from '../../Components/ui/UiInput.vue';
import UiTag from '../../Components/ui/UiTag.vue';
import UiRadio from '../../Components/ui/UiRadio.vue';
import UiTextarea from '../../Components/ui/UiTextarea.vue';
import HelpTip from '../../Components/ui/HelpTip.vue';
import { useToast } from '../../Components/ui/useToast.js';
import StickyActionBar from '../../Shopping/StickyActionBar.vue';
import { securityFetch } from '../../Security/securityFetch.js';
import CategoryField from '../../Components/Recipes/CategoryField.vue';
import IngredientRows from '../../Components/Recipes/IngredientRows.vue';
import RecipePhotosField from '../../Components/Recipes/RecipePhotosField.vue';
import StepRows from '../../Components/Recipes/StepRows.vue';
import { uploadQueue } from '../../Components/Recipes/recipePhotosApi.js';
import { useBackGuard } from '../../Components/Recipes/useBackGuard.js';
import { failureKey, firstMessage, isGone } from '../../Components/Recipes/recipeFailure.js';
import { formatQuantityInput, parseQuantity, quantityForRequest } from '../../Components/Recipes/quantityInput.js';

// Create and edit form of a recipe, plus review mode after an import. Create sends JSON and then lands on
// the detail; edit is an Inertia PUT that keeps the ids of the lines the member did not remove (so other members' ticks
// survive). The server validates everything again; this form only gives instant answers and maps the server's errors onto the fields.
const props = defineProps({
    recipe: { type: Object, default: null },
    defaults: { type: Object, default: () => ({ servings: 4, visibility: 'private' }) },
    units: { type: Array, default: () => [] },
    categorySuggestions: { type: Array, default: () => [] },
    starterCategories: { type: Array, default: () => [] },
    limits: { type: Object, default: () => ({}) },
    isShared: { type: Boolean, default: false },
    canAddToShopping: { type: Boolean, default: false },
    // From an import: { title, description, servings, prep_minutes, cook_minutes, categories, ingredients, steps, aiFields, sourceUrl,
    // sourceHost, textCut, importId }. With an importId the form is in review mode; with only a source (after "Type it in" on a
    // failed import) just the Source field is filled. Nothing is saved until the member presses Save recipe.
    prefill: { type: Object, default: null },
});
const { t, te, locale } = useI18n();
const { show: showToast } = useToast();

const LIMITS = {
    titleMax: 120, descriptionMax: 1000, ingredientNameMax: 120, ingredientNoteMax: 80, stepMax: 1000, ingredientsMax: 80, stepsMax: 60,
    categoriesMax: 3, categoryMax: 30, servingsMin: 1, servingsMax: 99, minutesMax: 1440, quantityMax: 9999, sourceUrlMax: 2000,
    photosMax: 10, photoMaxBytes: 5242880,
};
const limits = computed(() => ({ ...LIMITS, ...props.limits }));
const editing = computed(() => props.recipe !== null);
const review = computed(() => !editing.value && props.prefill?.importId != null && props.prefill.importId !== '');
const pre = props.recipe === null && props.prefill ? props.prefill : null;
const aiFields = new Set(review.value ? (pre?.aiFields ?? []) : []);

let serial = 0;
const newKey = (prefix) => `${prefix}${Date.now().toString(36)}-${serial++}`;
const ingredientRow = (i) => ({
    key: i?.id != null ? `i${i.id}` : newKey('n'),
    id: i?.id ?? null,
    quantity: i ? formatQuantityInput(i.quantity, locale.value) : '',
    unitId: (i?.unitId ?? i?.unit_id) == null ? '' : String(i.unitId ?? i.unit_id),
    name: i?.name ?? '',
    note: i?.note ?? '',
    // The line as written on the page (review mode only): shown as a hint, never sent.
    original: i?.original ?? null,
});
const stepRow = (s) => ({ key: s ? `s${s.id}` : newKey('t'), id: s?.id ?? null, body: s?.body ?? '' });
const text = (v) => (v === null || v === undefined ? '' : String(v));

const preIngredients = pre?.ingredients?.length ? pre.ingredients : null;
const preSteps = pre?.steps?.length ? pre.steps : null;
const form = reactive({
    title: text(props.recipe?.title ?? pre?.title),
    description: text(props.recipe?.description ?? pre?.description),
    // An import that did not find the servings leaves the field empty (the form saves "no servings") instead of guessing 4.
    servings: props.recipe ? text(props.recipe.servings) : review.value ? text(pre?.servings) : text(props.defaults?.servings ?? 4),
    prep: text(props.recipe?.prepMinutes ?? pre?.prep_minutes),
    cook: text(props.recipe?.cookMinutes ?? pre?.cook_minutes),
    categories: [...(props.recipe?.categories ?? pre?.categories ?? [])],
    ingredients: props.recipe?.ingredients?.length ? props.recipe.ingredients.map(ingredientRow) : preIngredients ? preIngredients.map(ingredientRow) : [ingredientRow(null)],
    steps: props.recipe?.steps?.length ? props.recipe.steps.map(stepRow) : preSteps ? preSteps.map(stepRow) : [stepRow(null)],
    sourceUrl: text(props.recipe?.sourceUrl ?? pre?.sourceUrl),
    visibility: props.recipe?.visibility ?? props.defaults?.visibility ?? 'private',
});

// ---- Review mode: which fields came from the page, until the member changes them.
const plainRows = (rows) => rows.map(({ key, original, ...rest }) => rest);
const valueOf = {
    title: () => form.title,
    description: () => form.description,
    servings: () => form.servings,
    prep_minutes: () => form.prep,
    cook_minutes: () => form.cook,
    categories: () => form.categories,
    ingredients: () => plainRows(form.ingredients),
    steps: () => plainRows(form.steps),
};
const startValue = Object.fromEntries(Object.entries(valueOf).map(([k, get]) => [k, JSON.stringify(get())]));
/** The field was filled in from the page and not touched since. */
const fromPage = (field) => review.value && aiFields.has(field) && JSON.stringify(valueOf[field]()) === startValue[field];
/** Review mode: the page had nothing for this field and it is still empty. */
const notFound = (field) => {
    if (!review.value || aiFields.has(field)) return false;
    const v = valueOf[field]();

    return Array.isArray(v) ? v.filter((r) => (typeof r === 'string' ? r.trim() !== '' : Object.values(r).some((x) => String(x ?? '').trim() !== ''))).length === 0 : String(v).trim() === '';
};
const hintFor = (field, normal = '') => (notFound(field) ? t('recipes.review.not_found') : normal);

const sourceEditing = ref(false);
const sourceHref = computed(() => (/^https?:\/\/\S+$/i.test(form.sourceUrl.trim()) ? form.sourceUrl.trim() : ''));
const sourceHost = computed(() => {
    try { return new URL(sourceHref.value).hostname; } catch { return pre?.sourceHost ?? ''; }
});
const showSourceLink = computed(() => review.value && sourceHref.value !== '' && !sourceEditing.value);

// ---- Errors: field -> message; lines by row key.
const errors = reactive({});
const ingredientErrors = reactive({});
const stepErrors = reactive({});
const alertText = ref('');
const saving = ref(false);
const formEl = ref(null);
const categoryField = ref(null);

const message = (value) => {
    const key = firstMessage(value);

    return key.startsWith('recipes.') && te(key) ? t(key) : t('recipes.error.save_failed');
};
const clear = (field) => { delete errors[field]; };
function setField(field, value, errorKey = field) {
    form[field] = value;
    clear(errorKey);
}
function clearAllErrors() {
    for (const k of Object.keys(errors)) delete errors[k];
    for (const k of Object.keys(ingredientErrors)) delete ingredientErrors[k];
    for (const k of Object.keys(stepErrors)) delete stepErrors[k];
    alertText.value = '';
}
const onIngredientEdit = ({ key, field }) => {
    const name = field === 'unitId' ? 'unit_id' : field;
    if (ingredientErrors[key]) delete ingredientErrors[key][name];
    clear('ingredients');
};
const onStepEdit = ({ key }) => { delete stepErrors[key]; clear('steps'); };

// ---- Idempotency of "create": one random token for the life of this form.
const clientToken = (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
    ? crypto.randomUUID()
    : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`).replace(/[^A-Za-z0-9_-]/g, '');

// ---- Dirty tracking and the leave guard.
const snapshot = () => JSON.stringify({
    ...form,
    ingredients: form.ingredients.map(({ key, ...rest }) => rest),
    steps: form.steps.map(({ key, ...rest }) => rest),
});
const initial = snapshot();
// Photos waiting for the first save (create mode only).
const photoQueue = ref([]);
// A reviewed draft is not saved yet, so leaving always asks.
const dirty = computed(() => review.value || snapshot() !== initial || photoQueue.value.length > 0);
let allowNext = false;
const leaveOpen = ref(false);
let pendingVisit = null;
let pendingBack = false;

const offBefore = router.on?.('before', (event) => {
    if (allowNext) { allowNext = false; return undefined; }
    if (!dirty.value) return undefined;
    const visit = event?.detail?.visit;
    pendingVisit = visit ? { url: String(visit.url?.href ?? visit.url), method: visit.method, data: visit.data } : null;
    pendingBack = false;
    leaveOpen.value = true;

    return false;
});
function onBeforeUnload(event) {
    if (!dirty.value || allowNext) return;
    event.preventDefault();
    event.returnValue = '';
}
window.addEventListener('beforeunload', onBeforeUnload);

// Browser Back and the iOS swipe-back are history steps, not Inertia visits (see useBackGuard for what that covers).
const backGuard = useBackGuard({
    active: () => dirty.value && !allowNext,
    onBlock: () => {
        pendingVisit = null;
        pendingBack = true;
        leaveOpen.value = true;
    },
});
watch(dirty, (isDirty) => { if (isDirty) backGuard.arm(); }, { immediate: true });
onBeforeUnmount(() => {
    offBefore?.();
    window.removeEventListener('beforeunload', onBeforeUnload);
    backGuard.dispose();
});

/** Visit options that swallow the guard's sentinel entry, so Back after saving does not land on this form twice. */
function visitOptions(extra = {}) {
    return { ...extra, replace: backGuard.armed() };
}

function leave() {
    const visit = pendingVisit;
    const back = pendingBack;
    pendingVisit = null;
    pendingBack = false;
    allowNext = true;
    if (back) backGuard.leave();
    else if (visit) router.visit(visit.url, visitOptions({ method: visit.method ?? 'get', data: visit.method && visit.method !== 'get' ? visit.data : undefined }));
}
function stay() {
    pendingVisit = null;
    if (pendingBack) backGuard.stay();
    pendingBack = false;
}

const backUrl = computed(() => (editing.value ? `/recipes/${props.recipe.id}` : '/recipes'));
function cancel() {
    router.visit(backUrl.value);
}

// ---- Derived display.
const total = computed(() => {
    const p = /^\d+$/.test(form.prep.trim()) ? Number(form.prep) : 0;
    const c = /^\d+$/.test(form.cook.trim()) ? Number(form.cook) : 0;

    return p + c;
});
const sharedHint = computed(() => editing.value && props.isShared);

// ---- Building and validating the request.
const isBlankIngredient = (r) => r.name.trim() === '' && r.quantity.trim() === '' && r.note.trim() === '';
const wholeNumber = (v, min, max) => /^\d+$/.test(v.trim()) && Number(v) >= min && Number(v) <= max;

/** The request body plus the row keys of the lines in it (to map the server's line errors back), or null after showing errors. */
function build() {
    clearAllErrors();
    const e = {};
    const ing = {};
    const stp = {};
    const title = form.title.trim().replace(/\s+/g, ' ');
    if (title === '' || title.length > limits.value.titleMax) e.title = t('recipes.error.title_invalid');
    if (form.description.length > limits.value.descriptionMax) e.description = t('recipes.error.description_invalid');
    if (form.servings.trim() !== '' && !wholeNumber(form.servings, limits.value.servingsMin, limits.value.servingsMax)) e.servings = t('recipes.error.servings_invalid');
    if (form.prep.trim() !== '' && !wholeNumber(form.prep, 0, limits.value.minutesMax)) e.prep_minutes = t('recipes.error.prep_minutes_invalid');
    if (form.cook.trim() !== '' && !wholeNumber(form.cook, 0, limits.value.minutesMax)) e.cook_minutes = t('recipes.error.cook_minutes_invalid');
    const source = form.sourceUrl.trim();
    if (source !== '' && (source.length > limits.value.sourceUrlMax || !/^https?:\/\/\S+$/i.test(source))) e.source_url = t('recipes.error.source_url_invalid');

    const ingredients = form.ingredients.filter((r) => !isBlankIngredient(r));
    if (ingredients.length === 0) e.ingredients = t('recipes.error.ingredients_required');
    const ingredientBody = ingredients.map((r) => {
        const lineErrors = {};
        if (r.name.trim() === '' || r.name.trim().length > limits.value.ingredientNameMax) lineErrors.name = t('recipes.error.ingredient_invalid');
        const q = parseQuantity(r.quantity, locale.value);
        if (!q.valid || (q.value !== null && q.value > limits.value.quantityMax)) lineErrors.quantity = t('recipes.error.quantity_invalid');
        if (r.note.trim().length > limits.value.ingredientNoteMax) lineErrors.note = t('recipes.error.ingredient_note_invalid');
        if (Object.keys(lineErrors).length > 0) ing[r.key] = lineErrors;
        const line = {
            name: r.name.trim(),
            quantity: quantityForRequest(r.quantity, locale.value),
            unit_id: r.unitId === '' ? null : Number(r.unitId),
            note: r.note.trim() === '' ? null : r.note.trim(),
        };
        if (editing.value && r.id !== null) line.id = r.id;

        return line;
    });

    const steps = form.steps.filter((s) => s.body.trim() !== '');
    if (steps.length === 0) e.steps = t('recipes.error.steps_required');
    const stepBody = steps.map((s) => {
        if (s.body.trim().length > limits.value.stepMax) stp[s.key] = t('recipes.error.step_invalid');
        const line = { body: s.body.trim() };
        if (editing.value && s.id !== null) line.id = s.id;

        return line;
    });

    if (Object.keys(e).length + Object.keys(ing).length + Object.keys(stp).length > 0) {
        showErrors(e, ing, stp);

        return null;
    }
    const data = {
        title,
        description: form.description.trim() === '' ? null : form.description.trim(),
        servings: form.servings.trim() === '' ? null : Number(form.servings),
        prep_minutes: form.prep.trim() === '' ? null : Number(form.prep),
        cook_minutes: form.cook.trim() === '' ? null : Number(form.cook),
        source_url: source === '' ? null : source,
        visibility: form.visibility,
        categories: [...form.categories],
        ingredients: ingredientBody,
        steps: stepBody,
    };
    // Saving a reviewed draft marks the import as used; the server ignores an id that is no longer usable.
    if (review.value) data.import_id = pre.importId;
    // One token per form session: a retry after a lost answer returns the recipe the first request created (no duplicate).
    if (!editing.value) data.client_token = clientToken;

    return { data, ingredientKeys: ingredients.map((r) => r.key), stepKeys: steps.map((s) => s.key) };
}

function showErrors(e, ing, stp, alert = t('recipes.error.save_failed')) {
    Object.assign(errors, e);
    for (const [k, v] of Object.entries(ing)) ingredientErrors[k] = v;
    Object.assign(stepErrors, stp);
    alertText.value = alert;
    focusFirstInvalid();
}
async function focusFirstInvalid() {
    await nextTick();
    const target = formEl.value?.querySelector('[aria-invalid="true"], [data-invalid="true"]');
    if (target) target.focus();
    else document.querySelector('[data-testid="form-alert"]')?.scrollIntoView?.({ block: 'nearest' });
}

/** Server error bag (keys like ingredients.2.name, values a message or a list) -> field errors; line errors via payload index. */
function applyServerErrors(bag, built) {
    const e = {};
    const ing = {};
    const stp = {};
    for (const [key, value] of Object.entries(bag ?? {})) {
        const text = message(value);
        let m;
        if ((m = key.match(/^ingredients\.(\d+)(?:\.(name|quantity|unit_id|note|id))?$/))) {
            const rowKey = built.ingredientKeys[Number(m[1])];
            if (rowKey) (ing[rowKey] ??= {})[m[2] === 'id' || !m[2] ? 'name' : m[2]] = text;
            else e.ingredients = text;
        } else if ((m = key.match(/^steps\.(\d+)(?:\.(body|id))?$/))) {
            const rowKey = built.stepKeys[Number(m[1])];
            if (rowKey) stp[rowKey] = text;
            else e.steps = text;
        } else if (key === 'ingredients' || key === 'steps') {
            e[key] = text;
        } else if (key === 'categories' || key.startsWith('categories.')) {
            e.categories = text;
        } else if (['title', 'description', 'servings', 'prep_minutes', 'cook_minutes', 'source_url', 'visibility'].includes(key)) {
            e[key] = text;
        }
    }
    showErrors(e, ing, stp);
}

function failed(status) {
    saving.value = false;
    alertText.value = t(failureKey(status));
    if (isGone(status)) {
        showToast(t('recipes.error.not_found'));
        allowNext = true;
        router.visit('/recipes', visitOptions());

        return;
    }
    nextTick(() => document.querySelector('[data-testid="form-alert"]')?.scrollIntoView?.({ block: 'nearest' }));
}

// ---- Saving.
const privateConfirmOpen = ref(false);

function submit() {
    if (saving.value) return;
    if (categoryField.value && categoryField.value.commitPending() === false) return;
    const built = build();
    if (!built) return;
    if (editing.value && props.isShared && form.visibility === 'private') {
        privateConfirmOpen.value = true;

        return;
    }
    send(built);
}
function confirmPrivate() {
    const built = build();
    if (built) send(built);
}

async function send(built) {
    if (saving.value) return;
    saving.value = true;
    alertText.value = '';
    if (editing.value) return sendEdit(built);

    const response = await securityFetch('/recipes', { method: 'POST', body: built.data });
    if (!response.ok) {
        saving.value = false;
        if (response.error === 'invalid') applyServerErrors(response.errors, built);
        else failed(response.status);

        return;
    }
    const id = response.data?.id;
    if (id === undefined || id === null || id === '') {
        // An answer without the id: never visit /recipes/undefined. The token makes the retry return the recipe if it exists.
        failed(0);

        return;
    }
    // The recipe exists now. Photos that fail do not lose it: the member is told and can add them again in Edit.
    const queue = photoQueue.value;
    const main = queue.find((q) => q.isMain)?.key ?? queue[0]?.key;
    const { failed: failedPhotos, mainFailed } = queue.length > 0 ? await uploadQueue(id, queue, main) : { failed: 0, mainFailed: false };
    showToast(failedPhotos > 0
        ? t('recipes.photos.some_failed', { n: failedPhotos }, failedPhotos)
        : mainFailed ? t('recipes.photos.main_failed') : t('recipes.toast.saved'));
    photoQueue.value = [];
    allowNext = true;
    router.visit(`/recipes/${id}`, visitOptions({ onFinish: () => { saving.value = false; } }));
}

function sendEdit(built) {
    allowNext = true;
    router.put(`/recipes/${props.recipe.id}`, built.data, visitOptions({
        preserveScroll: true,
        onError: (bag) => applyServerErrors(bag, built),
        onHttpException: (response) => { failed(response?.status); return false; },
        onNetworkError: () => failed(0),
        onFinish: () => { saving.value = false; allowNext = false; },
    }));
}

const pageTitle = computed(() => t(editing.value ? 'recipes.form.title_edit' : review.value ? 'recipes.review.title' : 'recipes.form.title_new'));
</script>

<template>
    <Head :title="pageTitle" />
    <AppShell current="recipes">
        <div class="rec-form" data-testid="recipe-form-page">
            <h1 class="rec-form__title">{{ pageTitle }}</h1>
            <p v-if="sharedHint" class="rec-form__muted" data-testid="shared-hint">{{ t('recipes.form.shared_edit_hint') }}</p>
            <UiAlert v-if="review" variant="info" data-testid="review-banner">{{ t('recipes.review.banner') }}</UiAlert>
            <p v-if="review && pre?.textCut" class="rec-form__muted" data-testid="text-cut">{{ t('recipes.review.text_cut') }}</p>
            <div aria-live="polite">
                <UiAlert v-if="alertText" variant="danger" data-testid="form-alert">{{ alertText }}</UiAlert>
            </div>

            <form id="recipe-form" ref="formEl" class="rec-form__grid" novalidate @submit.prevent="submit">
                <div class="rec-form__col">
                    <div class="rec-form__block">
                        <span class="rec-form__label">{{ t('recipes.photos.title') }}</span>
                        <RecipePhotosField
                            v-model:queue="photoQueue" :recipe-id="recipe?.id ?? null" :photos="recipe?.photos ?? []"
                            :max="limits.photosMax" :max-bytes="limits.photoMaxBytes" :disabled="saving" @gone="failed(404)"
                        />
                    </div>
                    <UiField :label="t('recipes.form.title')" :hint="hintFor('title')" :error="errors.title ?? ''" required>
                        <template #label-after><UiTag v-if="fromPage('title')" :icon="Sparkles" data-testid="from-page-title">{{ t('recipes.review.from_page') }}</UiTag></template>
                        <UiInput :model-value="form.title" autocomplete="off" :maxlength="limits.titleMax" :disabled="saving" data-testid="title-input" @update:model-value="setField('title', $event)" />
                    </UiField>
                    <UiField :label="t('recipes.form.description')" :hint="hintFor('description', t('recipes.form.description_hint'))" :error="errors.description ?? ''">
                        <template #label-after><UiTag v-if="fromPage('description')" :icon="Sparkles" data-testid="from-page-description">{{ t('recipes.review.from_page') }}</UiTag></template>
                        <UiTextarea :model-value="form.description" :rows="3" :maxlength="limits.descriptionMax" :disabled="saving" @update:model-value="setField('description', $event)" />
                    </UiField>

                    <div class="rec-form__basics">
                        <UiField :label="t('recipes.form.servings')" :hint="hintFor('servings', t('recipes.form.servings_hint'))" :error="errors.servings ?? ''" class="rec-form__servings">
                            <template #label-after><UiTag v-if="fromPage('servings')" :icon="Sparkles" data-testid="from-page-servings">{{ t('recipes.review.from_page') }}</UiTag></template>
                            <UiInput :model-value="form.servings" inputmode="numeric" autocomplete="off" :disabled="saving" @update:model-value="setField('servings', $event)" />
                        </UiField>
                        <UiField :label="t('recipes.form.prep')" :hint="hintFor('prep_minutes')" :error="errors.prep_minutes ?? ''">
                            <template #label-after><UiTag v-if="fromPage('prep_minutes')" :icon="Sparkles" data-testid="from-page-prep">{{ t('recipes.review.from_page') }}</UiTag></template>
                            <UiInput :model-value="form.prep" inputmode="numeric" autocomplete="off" :disabled="saving" @update:model-value="setField('prep', $event, 'prep_minutes')" />
                        </UiField>
                        <UiField :label="t('recipes.form.cook')" :hint="hintFor('cook_minutes')" :error="errors.cook_minutes ?? ''">
                            <template #label-after><UiTag v-if="fromPage('cook_minutes')" :icon="Sparkles" data-testid="from-page-cook">{{ t('recipes.review.from_page') }}</UiTag></template>
                            <UiInput :model-value="form.cook" inputmode="numeric" autocomplete="off" :disabled="saving" @update:model-value="setField('cook', $event, 'cook_minutes')" />
                        </UiField>
                    </div>
                    <p v-if="total > 0" class="rec-form__muted rec-form__total" data-testid="total-time">{{ t('recipes.form.total', { n: total }) }}</p>

                    <div class="rec-form__block">
                        <span class="rec-form__label">{{ t('recipes.form.categories') }}<UiTag v-if="fromPage('categories')" :icon="Sparkles" class="rec-form__tag" data-testid="from-page-categories">{{ t('recipes.review.from_page') }}</UiTag></span>
                        <CategoryField
                            ref="categoryField" v-model="form.categories" :suggestions="categorySuggestions" :starter="starterCategories"
                            :max="limits.categoriesMax" :item-max="limits.categoryMax" :error="errors.categories ?? ''" :disabled="saving" @edit="clear('categories')"
                        />
                        <p v-if="notFound('categories')" class="rec-form__muted">{{ t('recipes.review.not_found') }}</p>
                    </div>

                    <div v-if="showSourceLink" class="rec-form__block" data-testid="source-link-block">
                        <span class="rec-form__label">{{ t('recipes.form.source') }}</span>
                        <p class="rec-form__source">
                            <a :href="sourceHref" target="_blank" rel="noopener noreferrer" class="rec-form__source-link" data-testid="source-link">
                                <ExternalLink :size="16" :stroke-width="1.75" aria-hidden="true" />
                                <span>{{ sourceHost }}</span>
                            </a>
                            <UiButton variant="ghost" size="sm" :disabled="saving" data-testid="source-edit" @click="sourceEditing = true">{{ t('recipes.review.source_edit') }}</UiButton>
                        </p>
                    </div>
                    <UiField v-else :label="t('recipes.form.source')" :hint="t('recipes.form.source_hint')" :error="errors.source_url ?? ''">
                        <UiInput :model-value="form.sourceUrl" type="url" autocomplete="off" :maxlength="limits.sourceUrlMax" :disabled="saving" @update:model-value="setField('sourceUrl', $event, 'source_url')" />
                    </UiField>

                    <fieldset class="rec-form__visibility" :disabled="saving">
                        <legend class="rec-form__legend">
                            <span>{{ t('recipes.visibility.label') }}</span>
                            <HelpTip topic="recipes.sharing" :label="t('recipes.visibility.label')" />
                        </legend>
                        <UiRadio :model-value="form.visibility" value="private" name="recipe-visibility" data-testid="visibility-private" @update:model-value="setField('visibility', $event)">
                            <span class="rec-vis"><Lock :size="20" :stroke-width="1.75" aria-hidden="true" /><span class="rec-vis__text"><span class="rec-vis__name">{{ t('recipes.visibility.option_private') }}</span><span class="rec-vis__sub">{{ t('recipes.visibility.private_hint') }}</span></span></span>
                        </UiRadio>
                        <UiRadio :model-value="form.visibility" value="shared" name="recipe-visibility" data-testid="visibility-shared" @update:model-value="setField('visibility', $event)">
                            <span class="rec-vis"><Users :size="20" :stroke-width="1.75" aria-hidden="true" /><span class="rec-vis__text"><span class="rec-vis__name">{{ t('recipes.visibility.option_shared') }}</span><span class="rec-vis__sub">{{ t('recipes.visibility.shared_hint') }}</span></span></span>
                        </UiRadio>
                        <p v-if="errors.visibility" class="rec-form__error">{{ errors.visibility }}</p>
                    </fieldset>
                </div>

                <div class="rec-form__col">
                    <section class="rec-form__block" aria-labelledby="rec-h-ingredients">
                        <h2 id="rec-h-ingredients" class="rec-form__h2">{{ t('recipes.form.ingredients') }}<UiTag v-if="fromPage('ingredients')" :icon="Sparkles" class="rec-form__tag" data-testid="from-page-ingredients">{{ t('recipes.review.from_page') }}</UiTag></h2>
                        <p v-if="notFound('ingredients')" class="rec-form__muted">{{ t('recipes.review.not_found') }}</p>
                        <IngredientRows
                            v-model="form.ingredients" :units="units" :limits="limits" :errors="ingredientErrors" :list-error="errors.ingredients ?? ''"
                            :disabled="saving" @edit="onIngredientEdit"
                        />
                    </section>
                    <section class="rec-form__block" aria-labelledby="rec-h-steps">
                        <h2 id="rec-h-steps" class="rec-form__h2">{{ t('recipes.form.steps') }}<UiTag v-if="fromPage('steps')" :icon="Sparkles" class="rec-form__tag" data-testid="from-page-steps">{{ t('recipes.review.from_page') }}</UiTag></h2>
                        <p v-if="notFound('steps')" class="rec-form__muted">{{ t('recipes.review.not_found') }}</p>
                        <StepRows v-model="form.steps" :limits="limits" :errors="stepErrors" :list-error="errors.steps ?? ''" :disabled="saving" @edit="onStepEdit" />
                    </section>
                </div>
            </form>

            <StickyActionBar>
                <div class="rec-form__actions">
                    <UiButton variant="ghost" :disabled="saving" data-testid="cancel" @click="cancel">{{ t('recipes.form.cancel') }}</UiButton>
                    <UiButton type="submit" form="recipe-form" variant="primary" :loading="saving" :disabled="saving" data-testid="save">{{ t('recipes.form.save') }}</UiButton>
                </div>
            </StickyActionBar>
        </div>

        <UiConfirmDialog
            :open="privateConfirmOpen"
            :title="t('recipes.form.private_title')"
            :body="t('recipes.form.private_body')"
            :confirm-label="t('recipes.form.private_yes')"
            :cancel-label="t('recipes.form.private_no')"
            @update:open="privateConfirmOpen = $event"
            @confirm="confirmPrivate"
        />
        <UiConfirmDialog
            :open="leaveOpen"
            :title="t('recipes.form.leave_title')"
            :body="t(editing ? 'recipes.form.leave_body_edit' : 'recipes.form.leave_body_new')"
            :confirm-label="t('recipes.form.leave_yes')"
            :cancel-label="t('recipes.form.leave_no')"
            @update:open="leaveOpen = $event"
            @confirm="leave"
            @cancel="stay"
        />
    </AppShell>
</template>

<style scoped>
.rec-form { container-type: inline-size; container-name: recform; display: flex; flex-direction: column; gap: var(--space-4); max-width: var(--content-max); margin-inline: auto; width: 100%; padding-block-end: var(--space-6); }
.rec-form__title { margin: 0; font-size: var(--text-2xl); }
.rec-form__grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: var(--space-6); }
.rec-form__col { min-width: 0; }
.rec-form__basics { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 var(--space-3); }
.rec-form__servings { grid-column: 1 / -1; }
.rec-form__muted { margin: 0; color: var(--color-text-muted); font-size: var(--text-sm); }
.rec-form__total { margin-block-end: var(--space-4); }
.rec-form__block { margin-block-end: var(--space-4); }
.rec-form__label { display: block; margin-block-end: var(--space-2); font-size: var(--text-sm); font-weight: 600; }
.rec-form__h2 { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); margin: 0 0 var(--space-3); font-size: var(--text-xl); }
.rec-form__tag { margin-inline-start: var(--space-2); font-weight: 600; }
.rec-form__source { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); margin: 0; min-width: 0; }
.rec-form__source-link { display: inline-flex; align-items: center; gap: var(--space-1); color: var(--color-primary); overflow-wrap: anywhere; }
.rec-form__error { margin: 0; color: var(--color-danger); font-size: var(--text-sm); }
.rec-form__visibility { border: 0; margin: 0 0 var(--space-4); padding: 0; display: flex; flex-direction: column; gap: var(--space-2); min-width: 0; }
.rec-form__legend { display: flex; align-items: center; gap: var(--space-2); padding: 0; margin-block-end: var(--space-2); font-size: var(--text-sm); font-weight: 600; }
.rec-vis { display: inline-flex; align-items: flex-start; gap: var(--space-2); }
.rec-vis__text { display: flex; flex-direction: column; }
.rec-vis__name { font-weight: 600; }
.rec-vis__sub { color: var(--color-text-muted); font-size: var(--text-sm); }
.rec-form__actions { display: flex; gap: var(--space-2); width: 100%; justify-content: flex-end; }
/* On a phone the sticky action bar (64 px) covers the bottom of the page. */
@media (max-width: 639px) {
    .rec-form { padding-block-end: calc(64px + var(--space-6)); }
}
@container recform (min-width: 840px) {
    .rec-form__grid { grid-template-columns: minmax(0, 1fr) minmax(0, 1.2fr); align-items: start; }
    .rec-form__basics { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .rec-form__servings { grid-column: auto; }
}
</style>
