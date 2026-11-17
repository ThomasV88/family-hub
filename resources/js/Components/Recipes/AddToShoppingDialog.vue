<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Link as LinkIcon, PencilLine } from '@lucide/vue';
import UiAlert from '../ui/UiAlert.vue';
import UiButton from '../ui/UiButton.vue';
import UiCheckbox from '../ui/UiCheckbox.vue';
import UiDialog from '../ui/UiDialog.vue';
import UiField from '../ui/UiField.vue';
import UiInput from '../ui/UiInput.vue';
import UiRadio from '../ui/UiRadio.vue';
import UiSelect from '../ui/UiSelect.vue';
import UiSkeleton from '../ui/UiSkeleton.vue';
import UiTag from '../ui/UiTag.vue';
import HelpTip from '../ui/HelpTip.vue';
import { useToast } from '../ui/useToast.js';
import { securityFetch } from '../../Security/securityFetch.js';
import { INTL_LOCALES } from '../../i18n.js';
import CatalogImage from '../../Shopping/CatalogImage.vue';
import { matches } from '../../Shopping/catalogSearch.js';
import { NAME_MAX, clip } from '../../Shopping/limits.js';
import { localeCode, unitLabel } from '../../Shopping/units.js';
import { parseQuantity } from './quantityInput.js';
import { SHOPPING_MAX, convertTo, formatForInput, toShopping } from './shoppingQuantity.js';
import ServingsControl from './ServingsControl.vue';

// "Add to shopping list" review dialog of the recipe detail. Opening asks the server for the member's open lists, the catalog
// matches and the units (GET /recipes/{id}/shopping); confirming sends the reviewed rows in ONE request that adds all of them or
// none (POST, JSON). Everything shown from the server (list, store, catalog and ingredient names) is interpolated as text.
// If the preview cannot be loaded the rows are built from the recipe itself and added as typed items to a new list.
const props = defineProps({
    open: { type: Boolean, default: false },
    recipeId: { type: Number, required: true },
    // { title, ingredients: [{ id, name, quantity, unitId }] } as the detail has them (the typed-rows fallback).
    recipe: { type: Object, required: true },
    // The recipe's own servings (null when it has none) and the number the detail currently shows.
    baseServings: { type: Number, default: null },
    servings: { type: Number, default: 1 },
    // Units of the detail page, used until (and when no) preview is available.
    units: { type: Array, default: () => [] },
});
const emit = defineEmits(['update:open', 'failed']);
const { t, te, locale } = useI18n();
const toast = useToast();

const loading = ref(false);
const previewFailed = ref(false);
const preview = ref(null);
const target = ref('new');
const listName = ref('');
const storeId = ref('');
const listId = ref('');
const dialogServings = ref(1);
const rows = ref([]);
const submitting = ref(false);
const errorKey = ref('');
const errorText = ref('');
const listSelect = ref(null);
const nameInput = ref(null);
let ticket = 0;
let counter = 0;

const units = computed(() => preview.value?.units ?? props.units);
const catalog = computed(() => preview.value?.catalog ?? []);
const lists = computed(() => preview.value?.lists ?? []);
const stores = computed(() => preview.value?.stores ?? []);
const itemsMax = computed(() => preview.value?.limits?.itemsMax ?? 80);
const nameMax = computed(() => Math.min(NAME_MAX, preview.value?.limits?.nameMax ?? NAME_MAX));
const unitOf = (id) => units.value.find((u) => String(u.id) === String(id)) ?? null;
const unitIdForKey = (key) => units.value.find((u) => u.key === key)?.id ?? null;
const catalogOf = (id) => catalog.value.find((c) => c.id === id) ?? null;
const unitOptions = computed(() => units.value.map((u) => ({ value: String(u.id), label: unitLabel(u, 1, t, locale.value) })));
const numberFormat = computed(() => new Intl.NumberFormat(INTL_LOCALES[localeCode(locale.value)], { maximumFractionDigits: 2, useGrouping: false }));

// A quantity text -> number, or null when it is empty, not a number or outside what Shopping accepts.
function quantityOf(text) {
    const { value, valid } = parseQuantity(text, locale.value);

    return valid && value !== null && value > 0 && value <= SHOPPING_MAX ? value : null;
}

function buildRow(ingredient, match) {
    const matchedId = match && match.catalogItemId != null ? match.catalogItemId : null;

    return reactive({
        key: `r${++counter}`,
        include: true,
        ingredientId: ingredient.ingredientId ?? ingredient.id,
        name: clip(ingredient.name ?? '', NAME_MAX),
        baseQuantity: ingredient.quantity ?? null,
        baseUnitId: ingredient.unitId ?? null,
        tier: matchedId === null ? 'none' : (match.tier === 'suggested' ? 'suggested' : 'exact'),
        catalogItemId: matchedId,
        text: '',
        unitId: '',
        noAmount: false,
        edited: false,
        picking: false,
        search: '',
    });
}

// The row's quantity and unit for the chosen servings (toShopping): g and ml of 1000 or more become kg and l, no amount is 1 pcs.
function applyScale(row) {
    const unit = unitOf(row.baseUnitId);
    const pcs = unitIdForKey('pcs');
    row.noAmount = row.baseQuantity === null || row.baseQuantity === undefined || row.baseQuantity === '';
    let result = toShopping(row.baseQuantity, unit?.key ?? null, props.baseServings, dialogServings.value);
    let unitId = row.baseUnitId;
    if (row.noAmount) unitId = pcs;
    else if (unit === null) unitId = pcs;
    else if (result.unitKey !== unit.key) {
        const converted = unitIdForKey(result.unitKey);
        if (converted === null) result = toShopping(row.baseQuantity, null, props.baseServings, dialogServings.value);
        else unitId = converted;
    }
    row.text = formatForInput(result.quantity, locale.value);
    row.unitId = unitId === null || unitId === undefined ? '' : String(unitId);
}

function reset() {
    errorKey.value = '';
    errorText.value = '';
    submitting.value = false;
    previewFailed.value = false;
    preview.value = null;
    rows.value = [];
    target.value = 'new';
    listName.value = clip(props.recipe.title ?? '', NAME_MAX);
    storeId.value = '';
    listId.value = '';
    dialogServings.value = props.servings;
}

function settle(data) {
    preview.value = data;
    rows.value = (data?.rows ?? []).map((r) => buildRow(r, r.match));
    previewFailed.value = data === null;
    if (data === null) rows.value = (props.recipe.ingredients ?? []).map((i) => buildRow(i, null));
    rows.value.forEach(applyScale);
    // The most recently changed open list comes first (server order); with none, a new list is the only choice.
    const first = data?.lists?.[0];
    listId.value = first ? String(first.id) : '';
    target.value = first ? 'existing' : 'new';
    reconcile();
}

async function load() {
    const mine = ++ticket;
    reset();
    loading.value = true;
    const result = await securityFetch(`/recipes/${props.recipeId}/shopping`);
    if (mine !== ticket) return;
    loading.value = false;
    if (result.ok && result.data && Array.isArray(result.data.rows)) {
        settle(result.data);

        return;
    }
    // A recipe that is gone or a refusal ends here; anything else (5xx, dropped connection, 429) continues with typed rows.
    if (result.status === 403 || result.status === 404) {
        emit('failed', result.status);
        emit('update:open', false);

        return;
    }
    settle(null);
}

watch(() => props.open, (open) => {
    if (open) load();
    else ticket += 1;
}, { immediate: true });

// Servings in the dialog: rows the member has not edited are recalculated; edited ones keep what was typed.
watch(dialogServings, () => {
    rows.value.filter((r) => !r.edited).forEach(applyScale);
    reconcile();
});

const chosenList = computed(() => (target.value === 'existing' ? lists.value.find((l) => String(l.id) === listId.value) ?? null : null));
watch([target, listId], reconcile);

// The pending lines of the chosen list that hold the same catalog item as the row.
const linesFor = (row) => (chosenList.value && row.catalogItemId !== null ? chosenList.value.pending.filter((p) => p.catalogItemId === row.catalogItemId) : []);
const sameUnit = (row, line) => String(line.unitId ?? '') === row.unitId;

// g <-> kg and ml <-> l: a row that cannot be added up with the line on the list as it is gets the line's unit, but only when that
// loses nothing (5 g is not turned into 0.01 kg). Anything else stays and says it is kept as a separate line.
function reconcile() {
    for (const row of rows.value) {
        const lines = linesFor(row);
        if (lines.length === 0 || lines.some((l) => sameUnit(row, l))) continue;
        const quantity = quantityOf(row.text);
        const from = unitOf(row.unitId)?.key;
        if (quantity === null || !from) continue;
        for (const line of lines) {
            const to = unitOf(line.unitId)?.key;
            const converted = to ? convertTo(quantity, from, to) : null;
            if (converted === null || converted > SHOPPING_MAX || convertTo(converted, to, from) !== quantity) continue;
            row.text = formatForInput(converted, locale.value);
            row.unitId = String(line.unitId);
            break;
        }
    }
}

function lineText(line) {
    const label = line.unitId == null ? '' : unitLabel(unitOf(line.unitId), Number(line.quantity), t, locale.value);
    const number = numberFormat.value.format(Number(line.quantity));

    return label ? `${number} ${label}` : number;
}
function mergeHint(row) {
    if (!row.include) return null;
    const lines = linesFor(row);
    if (lines.length === 0) return null;
    const same = lines.find((l) => sameUnit(row, l));

    return same ? t('recipes.shopping.already_on_list', { qty: lineText(same) }) : t('recipes.shopping.different_unit');
}

// Per-row validity of what will be sent.
function rowError(row) {
    if (!row.include) return '';
    if (row.catalogItemId === null && row.name.trim() === '') return t('shopping.error.name_invalid');
    if (quantityOf(row.text) !== null) return '';
    const typed = Number(String(row.text).trim().replace(',', '.'));

    return Number.isFinite(typed) && typed > SHOPPING_MAX ? t('shopping.error.quantity_invalid') : t('shopping.error.quantity');
}
const included = computed(() => rows.value.filter((r) => r.include));
const count = computed(() => included.value.length);
const invalid = computed(() => included.value.some((r) => rowError(r) !== ''));
const tooMany = computed(() => count.value > itemsMax.value);
const targetReady = computed(() => (target.value === 'new' ? listName.value.trim() !== '' : lists.value.some((l) => String(l.id) === listId.value)));
const canSubmit = computed(() => !loading.value && !submitting.value && count.value > 0 && !invalid.value && !tooMany.value && targetReady.value);

function typeQuantity(row, value) {
    row.text = value;
    row.edited = true;
}
function changeUnit(row, value) {
    row.unitId = value;
    row.edited = true;
}

// "Change": a small search plus a select of catalog items; the first option turns the row into a typed item.
const visibleCatalog = (row) => catalog.value.filter((c) => matches(c.name, row.search)).slice(0, 100);
function chooseCatalog(row, value) {
    row.catalogItemId = value === '' ? null : Number(value);
    row.tier = row.catalogItemId === null ? 'none' : 'exact';
    row.picking = false;
    row.search = '';
    reconcile();
}
const tagText = (row) => (row.tier === 'suggested' ? t('recipes.shopping.suggested', { name: catalogOf(row.catalogItemId)?.name ?? '' }) : t('recipes.shopping.matched'));

function failure(message) {
    errorKey.value = message;
    errorText.value = '';
}
const alertText = computed(() => errorText.value || (errorKey.value ? t(errorKey.value) : ''));

function body() {
    const items = included.value.map((row) => {
        const quantity = String(quantityOf(row.text));
        const unit = row.unitId === '' ? null : Number(row.unitId);

        return row.catalogItemId !== null
            ? { catalog_item_id: row.catalogItemId, quantity, unit_id: unit }
            : { name: clip(row.name.trim(), NAME_MAX), quantity, unit_id: unit };
    });

    return target.value === 'new'
        ? { target: 'new', name: clip(listName.value.trim(), nameMax.value), store_id: storeId.value === '' ? null : Number(storeId.value), items }
        : { target: 'existing', list_id: Number(listId.value), items };
}

async function focusList() {
    target.value = 'existing';
    await nextTick();
    listSelect.value?.focus?.();
}

// The first message of a 422 that is a known text; anything else is the plain "nothing was added".
function validationText(errors) {
    const first = Object.values(errors ?? {}).flat().find((m) => typeof m === 'string' && te(m));

    return first ? t(first) : '';
}

async function submit() {
    if (!canSubmit.value) return;
    submitting.value = true;
    errorKey.value = '';
    errorText.value = '';
    const result = await securityFetch(`/recipes/${props.recipeId}/shopping`, { method: 'POST', body: body() });
    submitting.value = false;
    if (result.ok) {
        const { listId: id, listName: name, count: n } = result.data;
        emit('update:open', false);
        toast.show(t('recipes.toast.items_added', { count: n, list: name }, n), {
            action: { label: t('recipes.shopping.open_list'), onSelect: () => router.visit(`/shopping/lists/${id}`) },
        });

        return;
    }
    switch (result.status) {
        case 403: failure('recipes.error.forbidden'); break;
        case 404:
            if (target.value === 'existing') failure('recipes.shopping.list_gone');
            else failure('recipes.error.not_found');
            break;
        case 409: failure('recipes.shopping.list_locked'); await focusList(); break;
        case 419: failure('recipes.error.session_expired'); break;
        case 429: failure('recipes.error.too_many_requests'); break;
        case 422: {
            const text = validationText(result.errors);
            if (text) errorText.value = text;
            else failure('recipes.shopping.add_failed');
            break;
        }
        default: failure('recipes.shopping.add_failed');
    }
}

const close = () => { if (!submitting.value) emit('update:open', false); };
const initialFocus = () => nameInput.value?.$el?.querySelector?.('input') ?? null;
const submitLabel = computed(() => t(target.value === 'new' ? 'recipes.shopping.create_add_n' : 'recipes.shopping.add_n', { n: count.value }, count.value));
</script>

<template>
    <UiDialog :open="open" :title="t('recipes.shopping.title')" :dismissable="!submitting" :initial-focus="initialFocus" @update:open="(v) => (v ? null : close())">
        <div class="rec-shop" data-testid="shopping-dialog" :aria-busy="loading || submitting ? 'true' : undefined">
            <UiAlert v-if="alertText" variant="danger" live data-testid="shopping-error">{{ alertText }}</UiAlert>

            <div v-if="loading" class="rec-shop__loading" data-testid="shopping-loading">
                <span class="visually-hidden" role="status">{{ t('recipes.shopping.loading') }}</span>
                <UiSkeleton v-for="n in 4" :key="n" height="56px" />
            </div>

            <template v-else>
                <UiAlert v-if="previewFailed" variant="warning" data-testid="shopping-preview-failed">{{ t('recipes.shopping.catalog_failed') }}</UiAlert>

                <fieldset class="rec-shop__target" data-testid="shopping-target">
                    <legend class="visually-hidden">{{ t('recipes.shopping.target') }}</legend>
                    <div class="rec-shop__option">
                        <UiRadio v-model="target" value="new" name="shop-target" data-testid="target-new">{{ t('recipes.shopping.new_list') }}</UiRadio>
                        <div v-if="target === 'new'" class="rec-shop__fields">
                            <UiField :label="t('recipes.shopping.list_name')" required>
                                <UiInput ref="nameInput" v-model="listName" autocomplete="off" :maxlength="nameMax" data-testid="list-name" />
                            </UiField>
                            <UiField v-if="stores.length > 0" :label="t('shopping.create.store')">
                                <UiSelect v-model="storeId" data-testid="list-store">
                                    <option value="">{{ t('shopping.create.store_none') }}</option>
                                    <option v-for="s in stores" :key="s.id" :value="String(s.id)">{{ s.name }}</option>
                                </UiSelect>
                            </UiField>
                        </div>
                    </div>
                    <div class="rec-shop__option">
                        <UiRadio v-model="target" value="existing" name="shop-target" :disabled="lists.length === 0" data-testid="target-existing">{{ t('recipes.shopping.open_list') }}</UiRadio>
                        <p v-if="lists.length === 0" class="rec-shop__muted" data-testid="no-open-list">{{ t('recipes.shopping.no_open_list') }}</p>
                        <div v-else class="rec-shop__fields">
                            <UiField :label="t('recipes.shopping.choose_list')">
                                <UiSelect ref="listSelect" v-model="listId" :disabled="target !== 'existing'" data-testid="list-select">
                                    <option v-for="l in lists" :key="l.id" :value="String(l.id)">{{ l.storeName ? `${l.name} (${l.storeName})` : l.name }}</option>
                                </UiSelect>
                            </UiField>
                        </div>
                    </div>
                </fieldset>

                <section v-if="baseServings !== null" class="rec-shop__servings" :aria-label="t('recipes.shopping.quantities_for_label')" data-testid="shopping-servings">
                    <p class="rec-shop__heading">{{ t('recipes.shopping.quantities_for', { n: dialogServings }, dialogServings) }}</p>
                    <ServingsControl v-model="dialogServings" :base="baseServings" />
                </section>

                <section class="rec-shop__rows" :aria-label="t('recipes.shopping.rows')">
                    <p class="rec-shop__hint">{{ t('recipes.shopping.review_hint') }}<HelpTip topic="recipes.shopping" :label="t('recipes.shopping.title')" /></p>
                    <ul class="rec-shop__list">
                        <li v-for="row in rows" :key="row.key" :class="['rec-shop__row', { 'is-off': !row.include }]" data-testid="shopping-row">
                            <div class="rec-shop__line">
                                <UiCheckbox v-model="row.include" :aria-label="t('recipes.shopping.include', { name: row.name })" data-testid="row-include" />
                                <UiInput v-model="row.name" class="rec-shop__name" autocomplete="off" :maxlength="nameMax" :disabled="!row.include" :aria-label="t('recipes.shopping.name_of', { name: row.name })" :aria-invalid="row.include && row.catalogItemId === null && row.name.trim() === '' ? 'true' : undefined" data-testid="row-name" />
                            </div>
                            <div class="rec-shop__line rec-shop__line--qty">
                                <UiInput :model-value="row.text" class="rec-shop__qty" inputmode="decimal" autocomplete="off" :disabled="!row.include" :aria-label="t('recipes.shopping.quantity_of', { name: row.name })" :aria-invalid="row.include && quantityOf(row.text) === null ? 'true' : undefined" data-testid="row-quantity" @update:model-value="(v) => typeQuantity(row, v)" />
                                <UiSelect :model-value="row.unitId" class="rec-shop__unit" :disabled="!row.include" :aria-label="t('recipes.shopping.unit_of', { name: row.name })" data-testid="row-unit" @update:model-value="(v) => changeUnit(row, v)">
                                    <option value="">-</option>
                                    <option v-for="o in unitOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                                </UiSelect>
                            </div>
                            <p v-if="row.noAmount && row.include" class="rec-shop__muted" data-testid="row-no-amount">{{ t('recipes.shopping.no_amount') }}</p>
                            <p v-if="rowError(row)" class="rec-shop__error" data-testid="row-error">{{ rowError(row) }}</p>
                            <div class="rec-shop__match">
                                <template v-if="row.catalogItemId !== null">
                                    <CatalogImage kind="catalog" :id="row.catalogItemId" :version="catalogOf(row.catalogItemId)?.imageVersion ?? null" :size="32" />
                                    <UiTag :icon="LinkIcon" data-testid="row-tag">{{ tagText(row) }}</UiTag>
                                    <span class="rec-shop__catalog-name" data-testid="row-catalog-name">{{ catalogOf(row.catalogItemId)?.name }}</span>
                                </template>
                                <UiTag v-else variant="warn" :icon="PencilLine" data-testid="row-tag">{{ t('recipes.shopping.not_in_catalog') }}</UiTag>
                                <UiButton v-if="catalog.length > 0" variant="ghost" :disabled="!row.include" :aria-expanded="row.picking ? 'true' : 'false'" :aria-label="t('recipes.shopping.change_of', { name: row.name })" data-testid="row-change" @click="row.picking = !row.picking">{{ t('recipes.shopping.change') }}</UiButton>
                            </div>
                            <div v-if="row.picking" class="rec-shop__picker" data-testid="row-picker">
                                <UiInput v-model="row.search" type="search" autocomplete="off" :aria-label="t('recipes.shopping.search_catalog')" data-testid="row-search" />
                                <UiSelect :model-value="row.catalogItemId === null ? '' : String(row.catalogItemId)" :aria-label="t('recipes.shopping.catalog_item_for', { name: row.name })" data-testid="row-catalog-select" @update:model-value="(v) => chooseCatalog(row, v)">
                                    <option value="">{{ t('recipes.shopping.as_typed') }}</option>
                                    <option v-for="c in visibleCatalog(row)" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
                                </UiSelect>
                            </div>
                            <p v-if="mergeHint(row)" class="rec-shop__muted" data-testid="row-merge">{{ mergeHint(row) }}</p>
                        </li>
                    </ul>
                    <p v-if="tooMany" class="rec-shop__error" data-testid="too-many">{{ t('recipes.shopping.too_many', { max: itemsMax }) }}</p>
                </section>
            </template>
        </div>
        <template #footer>
            <UiButton variant="ghost" :disabled="submitting" data-testid="shopping-cancel" @click="close">{{ t('recipes.shopping.cancel') }}</UiButton>
            <UiButton variant="primary" :loading="submitting" :disabled="!canSubmit" data-testid="shopping-submit" @click="submit">{{ submitLabel }}</UiButton>
        </template>
    </UiDialog>
</template>

<style scoped>
.rec-shop { display: flex; flex-direction: column; gap: var(--space-4); min-width: 0; }
.rec-shop__loading { display: flex; flex-direction: column; gap: var(--space-2); }
.rec-shop__target { display: flex; flex-direction: column; gap: var(--space-3); margin: 0; padding: 0; border: 0; min-width: 0; }
.rec-shop__option { display: flex; flex-direction: column; gap: var(--space-2); }
.rec-shop__fields { display: flex; flex-direction: column; gap: var(--space-3); padding-inline-start: var(--space-8, 32px); }
.rec-shop__servings { display: flex; flex-direction: column; gap: var(--space-2); }
.rec-shop__heading { margin: 0; font-weight: 600; }
.rec-shop__hint { display: flex; align-items: center; flex-wrap: wrap; gap: var(--space-1); margin: 0 0 var(--space-2); color: var(--color-text-muted); font-size: var(--text-sm); }
.rec-shop__list { display: flex; flex-direction: column; gap: var(--space-2); margin: 0; padding: 0; list-style: none; }
.rec-shop__row { display: flex; flex-direction: column; gap: var(--space-2); padding: var(--space-3); border: 1px solid var(--color-border); border-radius: var(--radius-md); background: var(--color-surface); }
.rec-shop__row.is-off { background: var(--color-surface-2); }
.rec-shop__line { display: flex; align-items: center; gap: var(--space-2); min-width: 0; }
.rec-shop__line :deep(.ui-choice) { min-width: 44px; min-height: 44px; }
.rec-shop__name { flex: 1; min-width: 0; }
.rec-shop__line--qty { flex-wrap: wrap; padding-inline-start: 52px; }
.rec-shop__qty { flex: 0 0 7.5rem; min-width: 0; }
.rec-shop__unit { flex: 1 1 8rem; min-width: 0; }
.rec-shop__match { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); padding-inline-start: 52px; }
.rec-shop__catalog-name { font-weight: 600; overflow-wrap: anywhere; }
.rec-shop__picker { display: flex; flex-direction: column; gap: var(--space-2); padding-inline-start: 52px; }
.rec-shop__muted { margin: 0; color: var(--color-text-muted); font-size: var(--text-sm); padding-inline-start: 52px; }
.rec-shop__option > .rec-shop__muted { padding-inline-start: var(--space-8, 32px); }
.rec-shop__error { margin: 0; color: var(--color-danger); font-size: var(--text-sm); padding-inline-start: 52px; }
@media (max-width: 640px) {
    .rec-shop__line--qty, .rec-shop__match, .rec-shop__picker, .rec-shop__muted, .rec-shop__error { padding-inline-start: 0; }
    .rec-shop__fields, .rec-shop__option > .rec-shop__muted { padding-inline-start: 0; }
    .rec-shop__line--qty { flex-direction: column; align-items: stretch; }
    .rec-shop__qty, .rec-shop__unit { flex: 1 1 auto; }
}
</style>

<style>
/* The shared dialog is 480 px wide; this one is a centred 720 px sheet (80% of the height) that scrolls inside and keeps its
   footer in view, and a full-height sheet on a phone. Matched by the content, so no other dialog changes. */
.ui-dialog:has(.rec-shop) { display: flex; flex-direction: column; width: calc(100% - 32px); max-width: 720px; max-height: 80vh; overflow: hidden; }
.ui-dialog:has(.rec-shop) .ui-dialog__body { flex: 1 1 auto; min-height: 0; overflow-y: auto; padding: 2px; }
.ui-dialog:has(.rec-shop) .ui-dialog__header, .ui-dialog:has(.rec-shop) .ui-dialog__footer { flex: none; }
@media (max-width: 640px) {
    .ui-dialog:has(.rec-shop) { width: 100%; max-width: none; height: 100%; max-height: 100%; border-radius: 0; padding: var(--space-4); }
}
</style>
