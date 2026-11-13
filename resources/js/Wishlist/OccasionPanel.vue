<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import UiAlert from '../Components/ui/UiAlert.vue';
import UiButton from '../Components/ui/UiButton.vue';
import UiConfirmDialog from '../Components/ui/UiConfirmDialog.vue';
import UiDetailPanel from '../Components/ui/UiDetailPanel.vue';
import UiField from '../Components/ui/UiField.vue';
import UiInput from '../Components/ui/UiInput.vue';
import UiRadio from '../Components/ui/UiRadio.vue';
import UiSelect from '../Components/ui/UiSelect.vue';
import UiSwitch from '../Components/ui/UiSwitch.vue';
import { formatDayMonth } from './occasionIcons.js';

// Two modes: 'dates' (birth date and name day of one member) and 'custom' (create or edit a custom occasion).
const props = defineProps({
    open: { type: Boolean, default: false },
    mode: { type: String, default: 'custom' },
    // dates mode: the members row being edited
    member: { type: Object, default: null },
    // custom mode: the custom row being edited, or null to create
    occasion: { type: Object, default: null },
    members: { type: Array, default: () => [] },
    today: { type: String, default: '' },
});
const emit = defineEmits(['close']);
const { t, locale } = useI18n();

const dates = computed(() => props.mode === 'dates');
const editing = computed(() => !dates.value && props.occasion !== null);
const heading = computed(() => {
    if (dates.value) return t('occasions.dates.edit');
    return editing.value ? t('occasions.custom.edit') : t('occasions.add');
});

// A yearly occasion stores no year; the date input needs a concrete year, so use this year (or a leap year for Feb 29).
function ymd(year, month, day) {
    return `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}
function yearlyDate(o) {
    const base = Number((props.today || '2024').slice(0, 4));
    const valid = (y) => { const d = new Date(Date.UTC(y, o.month - 1, o.day)); return d.getUTCMonth() === o.month - 1; };
    let year = base;
    while (!valid(year)) year += 1;
    return ymd(year, o.month, o.day);
}
const key = (n) => `${n.name}|${n.month}|${n.day}`;

function initial() {
    if (dates.value) {
        const nd = props.member?.nameday ?? null;
        return {
            birth_date: props.member?.birth_date ?? '',
            calendar: nd?.calendar ?? 'none',
            query: '',
            selected: nd ? key(nd) : '',
        };
    }
    const o = props.occasion;
    return {
        title: o?.title ?? '',
        date: o ? (o.year ? ymd(o.year, o.month, o.day) : yearlyDate(o)) : '',
        yearly: o ? o.year === null : true,
        owner_id: o?.owner ? String(o.owner.id) : '',
    };
}

const form = ref(initial());
const snapshot = ref(JSON.stringify(form.value));
const errors = ref({});
const serverError = ref(false);
const processing = ref(false);
const body = ref(null);
const firstField = ref(null);
const discardOpen = ref(false);
const deleteOpen = ref(false);
let pendingAfterDiscard = null;

// Name day search state
const results = ref([]);
const searched = ref(false);
const kept = ref(props.member?.nameday ? { ...props.member.nameday } : null);
let timer = null;
let seq = 0;

const dirty = computed(() => JSON.stringify(form.value) !== snapshot.value);

function reset() {
    form.value = initial();
    snapshot.value = JSON.stringify(form.value);
    errors.value = {};
    serverError.value = false;
    results.value = [];
    searched.value = false;
    kept.value = props.member?.nameday ? { ...props.member.nameday } : null;
}
watch(() => [props.mode, props.member?.id, props.occasion?.id], reset);
watch(() => props.open, async (open) => {
    if (open && !dates.value && !editing.value) {
        await nextTick();
        setTimeout(() => firstField.value?.focus(), 50);
    }
}, { immediate: true });
onBeforeUnmount(() => clearTimeout(timer));

const calendarOptions = computed(() => [
    { value: 'none', label: t('occasions.dates.cal_none') },
    { value: 'sk', label: t('occasions.dates.cal_sk') },
    { value: 'pl', label: t('occasions.dates.cal_pl') },
]);
const ownerOptions = computed(() => [
    { value: '', label: t('occasions.custom.whole_family') },
    ...props.members.map((m) => ({ value: String(m.id), label: m.name })),
]);

// Debounced, same-origin name search.
watch(() => [form.value.query, form.value.calendar], () => {
    if (!dates.value) return;
    clearTimeout(timer);
    const { query, calendar } = form.value;
    if (calendar === 'none' || query.trim().length < 2) {
        results.value = [];
        searched.value = false;
        seq += 1;
        return;
    }
    timer = setTimeout(() => search(calendar, query.trim()), 250);
});
watch(() => form.value.calendar, (calendar, before) => {
    if (calendar !== before) {
        form.value.selected = calendar === props.member?.nameday?.calendar && props.member?.nameday ? key(props.member.nameday) : '';
        form.value.query = '';
        results.value = [];
        searched.value = false;
    }
});

async function search(calendar, q) {
    const mine = ++seq;
    try {
        const response = await fetch(`/wishlist/namedays?${new URLSearchParams({ calendar, q })}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error('search failed');
        const data = await response.json();
        if (mine !== seq) return;
        results.value = Array.isArray(data.results) ? data.results : [];
    } catch {
        if (mine !== seq) return;
        results.value = [];
    }
    searched.value = true;
}

// The current name day stays pickable even when it is not in the latest results.
const choices = computed(() => {
    const list = [...results.value];
    const current = kept.value;
    if (current && current.calendar === form.value.calendar && !list.some((r) => key(r) === key(current))) {
        list.unshift({ name: current.name, month: current.month, day: current.day });
    }
    return list;
});
const label = (n) => `${n.name}, ${formatDayMonth(n.month, n.day, locale.value)}`;

const message = (value) => (typeof value === 'string' && value.startsWith('occasions.') ? t(value) : t('occasions.dates.save_failed'));
const focusFirstInvalid = () => nextTick(() => body.value?.querySelector('[aria-invalid="true"], [data-invalid]')?.focus());

const isDate = (v) => /^\d{4}-\d{2}-\d{2}$/.test(v) && !Number.isNaN(Date.parse(`${v}T00:00:00Z`)) && new Date(`${v}T00:00:00Z`).toISOString().startsWith(v);

function validate() {
    const next = {};
    const f = form.value;
    if (dates.value) {
        if (f.birth_date !== '') {
            if (!isDate(f.birth_date)) next.birth_date = t('occasions.error.date_invalid');
            else if (props.today && f.birth_date > props.today) next.birth_date = t('occasions.error.birth_future');
        }
        if (f.calendar !== 'none' && f.selected === '') next.nameday = t('occasions.error.nameday_required');
    } else {
        if (f.title.trim().length === 0 || f.title.length > 100) next.title = t('occasions.error.title_required');
        if (!isDate(f.date)) next.date = t('occasions.error.date_invalid');
    }
    errors.value = next;
    return Object.keys(next).length === 0;
}

function payload() {
    const f = form.value;
    if (dates.value) {
        let nameday = null;
        if (f.calendar !== 'none') {
            const pick = choices.value.find((n) => key(n) === f.selected);
            if (pick) nameday = { calendar: f.calendar, name: pick.name, month: pick.month, day: pick.day };
        }
        return { birth_date: f.birth_date === '' ? null : f.birth_date, nameday };
    }
    return { title: f.title.trim(), date: f.date, yearly: f.yearly, owner_id: f.owner_id === '' ? null : Number(f.owner_id) };
}

function visitOptions(onOk) {
    return {
        preserveScroll: true,
        onStart: () => { processing.value = true; serverError.value = false; errors.value = {}; },
        onSuccess: onOk,
        onError: (errs) => {
            const mapped = {};
            for (const [field, value] of Object.entries(errs ?? {})) {
                if (field === 'title') mapped.title = message(value);
                else if (field === 'date') mapped.date = message(value);
                else if (field === 'birth_date') mapped.birth_date = message(value);
                else if (field.startsWith('nameday')) mapped.nameday = message(value);
                else serverError.value = true;
            }
            errors.value = mapped;
            if (Object.keys(mapped).length === 0) serverError.value = true;
            focusFirstInvalid();
        },
        onHttpException: () => { serverError.value = true; },
        onNetworkError: () => { serverError.value = true; },
        onFinish: () => { processing.value = false; },
    };
}

function save() {
    if (processing.value || !validate()) {
        focusFirstInvalid();
        return;
    }
    const sent = JSON.stringify(form.value);
    const done = () => {
        snapshot.value = sent;
        emit('close');
    };
    if (dates.value) router.put(`/wishlist/members/${props.member.id}/dates`, payload(), visitOptions(done));
    else if (editing.value) router.patch(`/wishlist/occasions/${props.occasion.id}`, payload(), visitOptions(done));
    else router.post('/wishlist/occasions', payload(), visitOptions(done));
}

function confirmDelete() {
    router.delete(`/wishlist/occasions/${props.occasion.id}`, visitOptions(() => emit('close')));
}

// Runs `then` now, or after the member confirms discarding unsaved edits.
function guard(then) {
    if (props.open && dirty.value) {
        pendingAfterDiscard = then;
        discardOpen.value = true;
    } else {
        then();
    }
}
const requestClose = () => guard(() => emit('close'));
function discard() {
    const then = pendingAfterDiscard;
    pendingAfterDiscard = null;
    reset();
    then?.();
}
const keepEditing = () => { pendingAfterDiscard = null; };
const onEnter = (event) => { if (event.target?.tagName === 'INPUT' && event.target.type !== 'radio') event.preventDefault(); };

defineExpose({ guard, dirty });
</script>

<template>
    <UiDetailPanel :open="open" :title="heading" @update:open="(v) => (v ? null : requestClose())">
        <div ref="body" data-testid="occasion-panel">
            <form novalidate @submit.prevent="save" @keydown.enter="onEnter">
                <UiAlert v-if="serverError" variant="danger" live class="occ-panel__alert">{{ t('occasions.dates.save_failed') }}</UiAlert>

                <div v-if="dates" class="occ-panel__fields">
                    <UiField :label="t('occasions.dates.birth')" :error="errors.birth_date">
                        <UiInput ref="firstField" v-model="form.birth_date" type="date" :max="today" />
                    </UiField>

                    <UiField :label="t('occasions.dates.calendar')">
                        <UiSelect v-model="form.calendar" :options="calendarOptions" />
                    </UiField>

                    <template v-if="form.calendar !== 'none'">
                        <UiField :label="t('occasions.dates.search')">
                            <UiInput v-model="form.query" autocomplete="off" maxlength="40" />
                        </UiField>
                        <fieldset class="occ-panel__results" :aria-describedby="errors.nameday ? 'occ-nameday-error' : undefined">
                            <legend class="occ-panel__legend">{{ t('occasions.dates.pick') }}</legend>
                            <p class="occ-panel__hint">{{ t('occasions.dates.pick_hint', { name: member?.name ?? '' }) }}</p>
                            <UiRadio
                                v-for="n in choices"
                                :key="key(n)"
                                v-model="form.selected"
                                name="nameday"
                                :value="key(n)"
                            >{{ label(n) }}</UiRadio>
                            <p v-if="searched && results.length === 0" class="occ-panel__hint" role="status">{{ t('occasions.dates.no_match') }}</p>
                            <p v-if="errors.nameday" id="occ-nameday-error" class="occ-panel__error" role="alert" data-invalid tabindex="-1">{{ errors.nameday }}</p>
                        </fieldset>
                        <p v-if="form.calendar === 'pl'" class="occ-panel__hint" data-testid="pl-attribution">
                            {{ t('occasions.dates.pl_attribution') }}.
                            <a href="https://pl.wikipedia.org/wiki/Imieniny" target="_blank" rel="noopener noreferrer">{{ t('occasions.dates.pl_attribution_source') }}</a>,
                            <a href="https://creativecommons.org/licenses/by-sa/4.0/" target="_blank" rel="noopener noreferrer">{{ t('occasions.dates.pl_attribution_licence') }}</a>.
                            {{ t('occasions.dates.pl_attribution_modified') }}
                        </p>
                    </template>
                </div>

                <div v-else class="occ-panel__fields">
                    <UiField :label="t('occasions.custom.field_title')" required :error="errors.title">
                        <UiInput ref="firstField" v-model="form.title" maxlength="100" autocomplete="off" />
                    </UiField>
                    <UiField :label="t('occasions.custom.field_date')" required :error="errors.date">
                        <UiInput v-model="form.date" type="date" />
                    </UiField>
                    <UiSwitch v-model="form.yearly">{{ t('occasions.custom.field_yearly') }}</UiSwitch>
                    <UiField :label="t('occasions.custom.field_for')">
                        <UiSelect v-model="form.owner_id" :options="ownerOptions" />
                    </UiField>
                </div>
            </form>
        </div>

        <template #footer>
            <UiButton v-if="editing" variant="ghost" :icon="Trash2" class="occ-panel__delete" @click="deleteOpen = true">{{ t('occasions.custom.delete') }}</UiButton>
            <UiButton variant="secondary" @click="requestClose">{{ t('occasions.close') }}</UiButton>
            <UiButton variant="primary" :loading="processing" @click="save">{{ dates ? t('occasions.dates.save') : t('occasions.custom.save') }}</UiButton>
        </template>
    </UiDetailPanel>

    <UiConfirmDialog
        v-model:open="discardOpen"
        :title="t('occasions.discard.title')"
        :body="t('occasions.discard.body')"
        :confirm-label="t('occasions.discard.confirm')"
        :cancel-label="t('occasions.discard.keep')"
        @confirm="discard"
        @cancel="keepEditing"
    />
    <UiConfirmDialog
        v-if="editing"
        v-model:open="deleteOpen"
        :title="t('occasions.custom.delete')"
        :body="t('occasions.custom.delete_body', { title: occasion?.title ?? '' })"
        :confirm-label="t('occasions.custom.delete')"
        :cancel-label="t('occasions.custom.keep')"
        @confirm="confirmDelete"
    />
</template>

<style scoped>
.occ-panel__alert { margin-bottom: var(--space-4); }
.occ-panel__fields { display: flex; flex-direction: column; gap: var(--space-4); }
.occ-panel__results { min-width: 0; margin: 0; padding: 0; border: 0; display: flex; flex-direction: column; gap: var(--space-2); }
.occ-panel__legend { padding: 0; font-size: var(--text-sm); font-weight: 600; line-height: 1.4; }
.occ-panel__hint { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.occ-panel__error { margin: 0; font-size: var(--text-sm); color: var(--color-danger); }
.occ-panel__delete { margin-inline-end: auto; color: var(--color-danger); }
</style>
