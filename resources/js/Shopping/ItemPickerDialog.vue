<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Check, Minus, Package, PencilLine, Plus, Search, X } from '@lucide/vue';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiCheckbox from '../Components/ui/UiCheckbox.vue';
import UiDialog from '../Components/ui/UiDialog.vue';
import UiEmptyState from '../Components/ui/UiEmptyState.vue';
import UiField from '../Components/ui/UiField.vue';
import UiInput from '../Components/ui/UiInput.vue';
import UiSelect from '../Components/ui/UiSelect.vue';
import UiTag from '../Components/ui/UiTag.vue';
import { INTL_LOCALES } from '../i18n.js';
import CatalogImage from './CatalogImage.vue';
import { matches } from './catalogSearch.js';
import { httpHandlers } from './failure.js';
import { MAX_ITEMS, NAME_MAX, clip } from './limits.js';
import { localeCode, unitLabel } from './units.js';

// "Add items" pop-up: pick several catalog items (picture tiles) with quantity and unit, add your own free-text items, and send
// everything in one request. catalog: [{ id, name, defaultUnitId, imageVersion }] or null when it could not be loaded.
// onListIds: catalog ids already pending on the list (the server raises their quantity instead of adding a second row).
const props = defineProps({
    open: { type: Boolean, default: false },
    listId: { type: Number, required: true },
    catalog: { type: Array, default: null },
    onListIds: { type: Array, default: () => [] },
    units: { type: Array, default: () => [] },
    canManageCatalog: { type: Boolean, default: false },
    nameMax: { type: Number, default: NAME_MAX },
    maxItems: { type: Number, default: MAX_ITEMS },
});
const emit = defineEmits(['update:open']);
const { t, locale, te } = useI18n();

const QUANTITY_MAX = 999.99;
const form = useForm({ items: [] });
const query = ref('');
const search = ref(null);
const httpError = ref('');
// key -> { key, catalogItemId|null, name, quantity (number, null while the typed text is not a valid quantity), text, unitId ('' = none), saveToCatalog }
const selection = reactive({});
const own = reactive({ open: false, name: '', quantity: '1', unitId: '', save: false, errors: {} });
let counter = 0;

const unitByKey = (key) => props.units.find((u) => u.key === key);
const pcsId = () => String(unitByKey('pcs')?.id ?? '');
const unitOf = (id) => props.units.find((u) => String(u.id) === String(id)) ?? null;
const unitOptions = computed(() => props.units.map((u) => ({ value: String(u.id), label: unitLabel(u, 1, t, locale.value) })));
const onList = computed(() => new Set(props.onListIds));

const number = computed(() => new Intl.NumberFormat(INTL_LOCALES[localeCode(locale.value)], { maximumFractionDigits: 2 }));
const fmt = (n) => number.value.format(n);
// One step of the - and + buttons: whole pieces, 0.1 for kg and l, 10 for g and ml.
const STEPS = { g: 10, ml: 10, kg: 0.1, l: 0.1 };
const stepFor = (unitId) => STEPS[unitOf(unitId)?.key] ?? 1;
const FRACTIONAL = ['kg', 'l'];
// g <-> kg and ml <-> l: the quantity keeps its meaning when the unit is switched.
const CONVERT = { 'g>kg': 0.001, 'kg>g': 1000, 'ml>l': 0.001, 'l>ml': 1000 };
const round = (n) => Math.round(n * 100) / 100;
const textOf = (n) => (n === null ? '' : fmt(n));
const invalidCount = computed(() => Object.values(selection).filter((e) => e.quantity === null).length);

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        form.clearErrors();
        httpError.value = '';
        Object.keys(selection).forEach((k) => delete selection[k]);
        query.value = '';
        Object.assign(own, { open: false, name: '', quantity: '1', unitId: pcsId(), save: false, errors: {} });
    },
    { immediate: true },
);

const filtered = computed(() => (props.catalog ?? []).filter((c) => matches(c.name, query.value)));
const text = computed(() => query.value.trim());
const entries = computed(() => Object.values(selection));
const ownEntries = computed(() => entries.value.filter((e) => e.catalogItemId === null));
const count = computed(() => entries.value.length);
const tooMany = computed(() => count.value > props.maxItems);

const isSelected = (id) => Object.prototype.hasOwnProperty.call(selection, `c${id}`);
function toggle(item) {
    const key = `c${item.id}`;
    if (selection[key]) {
        delete selection[key];
        return;
    }
    selection[key] = { key, catalogItemId: item.id, name: item.name, quantity: 1, text: '1', unitId: item.defaultUnitId == null ? '' : String(item.defaultUnitId), saveToCatalog: false };
}

function setQuantity(entry, n) {
    entry.quantity = n;
    entry.text = textOf(n);
}
function change(entry, direction) {
    const step = stepFor(entry.unitId);
    const base = entry.quantity ?? 0;
    setQuantity(entry, Math.min(QUANTITY_MAX, Math.max(step, round(base + direction * step))));
}
// Typed quantity: a decimal comma is fine. While the text is not a valid quantity the entry has none and cannot be added.
function typeQuantity(entry, raw) {
    entry.text = raw;
    entry.quantity = parseQuantity(raw);
}
// A unit change converts g <-> kg and ml <-> l, and never leaves a fraction on a unit that has none (pieces, packs...).
function changeUnit(entry, unitId) {
    const from = unitOf(entry.unitId)?.key;
    const to = unitOf(unitId)?.key;
    entry.unitId = unitId;
    if (entry.quantity === null) return;
    let n = entry.quantity;
    const factor = CONVERT[`${from}>${to}`];
    if (factor) n = round(n * factor);
    if (!FRACTIONAL.includes(to) && !Number.isInteger(n)) n = Math.round(n);
    setQuantity(entry, Math.min(QUANTITY_MAX, Math.max(FRACTIONAL.includes(to) ? 0.01 : stepFor(unitId), n)));
}

function addOwn({ name, quantity, unitId, save }) {
    const key = `f${++counter}`;
    selection[key] = { key, catalogItemId: null, name, quantity, text: textOf(quantity), unitId, saveToCatalog: save };
}
function addTyped() {
    // Cut by characters, never inside an emoji (a lone half of a surrogate pair would make the whole request invalid).
    addOwn({ name: clip(text.value, props.nameMax), quantity: 1, unitId: pcsId(), save: false });
    query.value = '';
}

// Decimal comma allowed ("1,5"); same rule as the server (0.01 to 999.99, two decimals at most).
function parseQuantity(raw) {
    const clean = String(raw ?? '').replace(/\s+/g, '').replace(',', '.');
    if (!/^\d{1,3}(\.\d{1,2})?$/.test(clean)) return null;
    const n = Number(clean);

    return n > 0 && n <= QUANTITY_MAX ? n : null;
}
function submitOwn() {
    const name = own.name.trim();
    const quantity = parseQuantity(own.quantity);
    own.errors = {
        ...(name === '' ? { name: t('shopping.error.name_invalid') } : {}),
        ...(quantity === null ? { quantity: t('shopping.error.quantity') } : {}),
    };
    if (Object.keys(own.errors).length > 0) return;
    addOwn({ name, quantity, unitId: own.unitId, save: props.canManageCatalog && own.save });
    Object.assign(own, { open: false, name: '', quantity: '1', unitId: pcsId(), save: false, errors: {} });
}

const translate = (message) => (String(message).startsWith('shopping.') ? (te(message) ? t(message) : t('shopping.error.save_failed')) : String(message));
const errorText = computed(() => {
    if (tooMany.value) return t('shopping.error.items_count');
    if (invalidCount.value > 0) return t('shopping.error.quantity');
    if (httpError.value) return httpError.value;
    const first = Object.values(form.errors ?? {})[0];

    return first ? translate(first) : '';
});
const close = () => emit('update:open', false);

function submit() {
    if (count.value === 0 || tooMany.value || form.processing || invalidCount.value > 0) return;
    httpError.value = '';
    form.items = entries.value.map((e) => (e.catalogItemId === null
        ? { name: e.name, quantity: String(e.quantity), unit_id: e.unitId === '' ? null : Number(e.unitId), ...(e.saveToCatalog ? { save_to_catalog: true } : {}) }
        : { catalog_item_id: e.catalogItemId, quantity: String(e.quantity), unit_id: e.unitId === '' ? null : Number(e.unitId) }));
    form.post(`/shopping/lists/${props.listId}/items`, {
        preserveScroll: true,
        onSuccess: close,
        ...httpHandlers((key) => { httpError.value = t(key); }),
    });
}

// Autofocus the search on desktop only: on a phone the keyboard would cover the grid.
const initialFocus = () => (typeof window.matchMedia === 'function' && window.matchMedia('(min-width: 641px)').matches ? search.value?.$el?.querySelector?.('input') ?? null : null);
</script>

<template>
    <UiDialog :open="open" :title="t('shopping.picker.title')" :initial-focus="initialFocus" @update:open="emit('update:open', $event)">
        <div class="shop-pick__body">
            <UiAlert v-if="errorText" variant="danger" live data-testid="picker-error">{{ errorText }}</UiAlert>
            <UiAlert v-if="catalog === null" variant="danger" data-testid="catalog-error">{{ t('shopping.picker.load_error') }}</UiAlert>

            <UiField v-if="catalog !== null && catalog.length > 0" :label="t('shopping.picker.search')" label-visually-hidden>
                <UiInput ref="search" v-model="query" type="search" :icon="Search" autocomplete="off" />
            </UiField>

            <button v-if="text !== ''" type="button" class="shop-pick__free-row" data-testid="free-row" @click="addTyped">
                <PencilLine :size="20" :stroke-width="1.75" aria-hidden="true" />
                <span>{{ t('shopping.picker.free_row', { text }) }}</span>
            </button>

            <template v-if="catalog !== null">
                <UiEmptyState v-if="catalog.length === 0" :heading-level="3" :icon="Package" :title="t('shopping.picker.empty_title')" :body="t('shopping.picker.empty_body')">
                    <template v-if="canManageCatalog" #action>
                        <Link href="/settings/shopping" class="shop-pick__link">{{ t('shopping.picker.empty_settings') }}</Link>
                    </template>
                </UiEmptyState>
                <p v-else-if="filtered.length === 0" class="shop-pick__none" data-testid="no-match">{{ t('shopping.picker.no_match', { text }) }}</p>
                <ul v-else class="shop-pick__grid" :aria-label="t('shopping.picker.grid_label')">
                    <li v-for="c in filtered" :key="c.id" :class="['shop-pick__tile', { 'is-selected': isSelected(c.id) }]" data-testid="tile">
                        <button type="button" class="shop-pick__tile-btn" :aria-pressed="isSelected(c.id) ? 'true' : 'false'" @click="toggle(c)">
                            <CatalogImage kind="catalog" :id="c.id" :version="c.imageVersion" :size="72" />
                            <span class="shop-pick__name">{{ c.name }}</span>
                            <span v-if="isSelected(c.id)" class="shop-pick__badge" aria-hidden="true"><Check :size="14" :stroke-width="2.5" />{{ selection[`c${c.id}`].text }}</span>
                        </button>
                        <UiTag v-if="onList.has(c.id)" variant="neutral" class="shop-pick__on-list">{{ t('shopping.picker.on_list') }}</UiTag>
                        <div v-if="isSelected(c.id)" class="shop-pick__stepper">
                            <UiButton variant="secondary" icon-only :icon="Minus" :label="t('shopping.picker.less', { name: c.name })" @click="change(selection[`c${c.id}`], -1)" />
                            <input class="shop-pick__qty" inputmode="decimal" autocomplete="off" :value="selection[`c${c.id}`].text" :aria-label="t('shopping.picker.quantity_of', { name: c.name })" :aria-invalid="selection[`c${c.id}`].quantity === null ? 'true' : undefined" data-testid="qty-input" @input="typeQuantity(selection[`c${c.id}`], $event.target.value)" />
                            <UiButton variant="secondary" icon-only :icon="Plus" :label="t('shopping.picker.more', { name: c.name })" @click="change(selection[`c${c.id}`], 1)" />
                            <UiSelect :model-value="selection[`c${c.id}`].unitId" class="shop-pick__unit" :aria-label="t('shopping.picker.unit_of', { name: c.name })" @update:model-value="changeUnit(selection[`c${c.id}`], $event)">
                                <option value="">-</option>
                                <option v-for="o in unitOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </UiSelect>
                        </div>
                    </li>
                </ul>
            </template>

            <section v-if="ownEntries.length > 0" class="shop-pick__own" :aria-label="t('shopping.picker.own_heading')">
                <h3 class="shop-pick__own-title">{{ t('shopping.picker.own_heading') }}</h3>
                <ul class="shop-pick__own-list">
                    <li v-for="e in ownEntries" :key="e.key" class="shop-pick__own-row" data-testid="own-row">
                        <span class="shop-pick__own-name">{{ e.name }}</span>
                        <div class="shop-pick__stepper">
                            <UiButton variant="secondary" icon-only :icon="Minus" :label="t('shopping.picker.less', { name: e.name })" @click="change(e, -1)" />
                            <input class="shop-pick__qty" inputmode="decimal" autocomplete="off" :value="e.text" :aria-label="t('shopping.picker.quantity_of', { name: e.name })" :aria-invalid="e.quantity === null ? 'true' : undefined" data-testid="qty-input" @input="typeQuantity(e, $event.target.value)" />
                            <UiButton variant="secondary" icon-only :icon="Plus" :label="t('shopping.picker.more', { name: e.name })" @click="change(e, 1)" />
                            <UiSelect :model-value="e.unitId" class="shop-pick__unit" :aria-label="t('shopping.picker.unit_of', { name: e.name })" @update:model-value="changeUnit(e, $event)">
                                <option value="">-</option>
                                <option v-for="o in unitOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </UiSelect>
                            <UiButton variant="ghost" icon-only :icon="X" :label="t('shopping.picker.remove_own', { name: e.name })" @click="delete selection[e.key]" />
                        </div>
                    </li>
                </ul>
            </section>

            <div class="shop-pick__type">
                <UiButton v-if="!own.open && text === ''" variant="secondary" :icon="PencilLine" data-testid="type-own" @click="own.open = true">{{ t('shopping.picker.type_own') }}</UiButton>
                <form v-if="own.open" class="shop-pick__form" novalidate data-testid="own-form" @submit.prevent="submitOwn">
                    <UiField :label="t('shopping.picker.free_name')" :error="own.errors.name" required>
                        <UiInput v-model="own.name" autocomplete="off" :maxlength="nameMax" />
                    </UiField>
                    <div class="shop-pick__form-pair">
                        <UiField :label="t('shopping.picker.free_quantity')" :error="own.errors.quantity" required class="shop-pick__form-qty">
                            <UiInput v-model="own.quantity" inputmode="decimal" autocomplete="off" />
                        </UiField>
                        <UiField :label="t('shopping.picker.free_unit')" class="shop-pick__form-unit">
                            <UiSelect v-model="own.unitId">
                                <option value="">-</option>
                                <option v-for="o in unitOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </UiSelect>
                        </UiField>
                    </div>
                    <UiCheckbox v-if="canManageCatalog" v-model="own.save">{{ t('shopping.picker.save_catalog') }}</UiCheckbox>
                    <div class="shop-pick__form-actions">
                        <UiButton type="submit" variant="secondary">{{ t('shopping.picker.free_add') }}</UiButton>
                    </div>
                </form>
            </div>
            <p class="visually-hidden" aria-live="polite" data-testid="picker-live">{{ count > 0 ? t('shopping.picker.selected', { n: count }) : '' }}</p>
        </div>
        <template #footer>
            <UiButton variant="ghost" @click="close">{{ t('shopping.picker.cancel') }}</UiButton>
            <UiButton variant="primary" data-testid="picker-add" :disabled="count === 0 || tooMany || form.processing || invalidCount > 0" :loading="form.processing" @click="submit">{{ count === 0 ? t('shopping.detail.add_items') : t('shopping.picker.add_n', { n: count }, count) }}</UiButton>
        </template>
    </UiDialog>
</template>

<style scoped>
.shop-pick__body { display: flex; flex-direction: column; gap: var(--space-4); min-width: min(100%, 560px); }
.shop-pick__free-row { display: flex; align-items: center; gap: var(--space-2); min-height: 44px; padding: var(--space-2) var(--space-3); border: 1px dashed var(--color-border); border-radius: var(--radius-md); background: var(--color-surface); color: var(--color-text); font: inherit; text-align: start; cursor: pointer; overflow-wrap: anywhere; }
.shop-pick__free-row:hover { background: var(--color-surface-2); }
.shop-pick__free-row:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.shop-pick__none { margin: 0; color: var(--color-text-muted); overflow-wrap: anywhere; }
.shop-pick__link { color: var(--color-primary); font-weight: 600; }
.shop-pick__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(104px, 1fr)); gap: var(--space-2); margin: 0; padding: 0; list-style: none; }
.shop-pick__tile { position: relative; display: flex; flex-direction: column; gap: var(--space-1); min-width: 0; border: 2px solid var(--color-border); border-radius: var(--radius-md); background: var(--color-surface); }
.shop-pick__tile.is-selected { border-color: var(--color-primary); }
.shop-pick__tile-btn { display: flex; flex-direction: column; align-items: center; gap: var(--space-1); min-height: 120px; padding: var(--space-2); border: 0; border-radius: var(--radius-md); background: none; color: var(--color-text); font: inherit; cursor: pointer; }
.shop-pick__tile-btn:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.shop-pick__name { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; font-size: var(--text-sm); line-height: 1.3; text-align: center; overflow-wrap: anywhere; }
.shop-pick__badge { position: absolute; top: var(--space-1); right: var(--space-1); display: inline-flex; align-items: center; gap: 2px; padding: 2px var(--space-2); border-radius: 999px; background: var(--color-primary); color: var(--color-on-primary, #fff); font-size: var(--text-sm); font-weight: 600; }
.shop-pick__on-list { align-self: center; margin-block-end: var(--space-1); }
.shop-pick__stepper { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: var(--space-1); padding: 0 var(--space-1) var(--space-2); }
.shop-pick__qty { width: 5.5ch; min-height: 44px; padding-inline: var(--space-1); border: 1px solid var(--color-border-strong); border-radius: var(--radius-md); background: var(--color-surface); color: var(--color-text); text-align: center; font: inherit; font-weight: 600; }
.shop-pick__qty:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.shop-pick__qty[aria-invalid='true'] { border-color: var(--color-danger); }
.shop-pick__unit { flex: 1 1 100%; }
.shop-pick__own-title { margin: 0 0 var(--space-2); font-size: var(--text-base); font-weight: 600; }
.shop-pick__own-list { display: flex; flex-direction: column; gap: var(--space-2); margin: 0; padding: 0; list-style: none; }
.shop-pick__own-row { display: flex; flex-direction: column; gap: var(--space-1); padding: var(--space-2); border: 2px solid var(--color-primary); border-radius: var(--radius-md); }
.shop-pick__own-name { font-weight: 600; overflow-wrap: anywhere; }
.shop-pick__own-row .shop-pick__stepper { justify-content: flex-start; padding: 0; }
.shop-pick__own-row .shop-pick__unit { flex: 1 1 8rem; }
.shop-pick__form { display: flex; flex-direction: column; gap: var(--space-3); padding: var(--space-3); border: 1px solid var(--color-border); border-radius: var(--radius-md); }
.shop-pick__form-pair { display: flex; gap: var(--space-3); }
.shop-pick__form-qty { flex: 1 1 40%; min-width: 0; }
.shop-pick__form-unit { flex: 1 1 60%; min-width: 0; }
.shop-pick__form-actions { display: flex; justify-content: flex-end; }
</style>
