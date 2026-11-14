<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { I18nT, useI18n } from 'vue-i18n';
import UiAlert from '../../Components/ui/UiAlert.vue';
import UiButton from '../../Components/ui/UiButton.vue';
import UiCard from '../../Components/ui/UiCard.vue';
import UiDialog from '../../Components/ui/UiDialog.vue';
import UiField from '../../Components/ui/UiField.vue';
import UiInput from '../../Components/ui/UiInput.vue';
import { useToast } from '../../Components/ui/useToast.js';
import { securityFetch } from '../../Security/securityFetch.js';
import BulkHistoryTable from './BulkHistoryTable.vue';
import BulkProgress from './BulkProgress.vue';
import { useBulkStatus } from './useBulkStatus.js';

// Screen 3: plan block (scope, cap, mandatory dry run, freshness rule, guarded Start) or the active run, plus
// counts-only history. The dry-run token lives in component memory only; a reload discards it. Nothing is kept
// in any browser storage. No person, document or member name is read.
const props = defineProps({
    // { active, history } from the page, or null while it has not arrived (history skeleton).
    bulk: { type: Object, default: null },
    bulkConfig: { type: Object, default: () => ({}) },
    providers: { type: Array, default: () => ['codex'] },
    // Agent list (to know whether any enabled agent is signed in); null = unknown, no note.
    agents: { type: Array, default: null },
    loadFailed: { type: Boolean, default: false },
});

const { t, locale } = useI18n();
const toast = useToast();
const uid = useId();

const DRY_URL = '/settings/ai-agents/bulk/dry-run';
const START_URL = '/settings/ai-agents/bulk';
const SCOPES = ['no_proposal', 'last_failed', 'all'];

const config = computed(() => ({
    defaultCap: Number(props.bulkConfig?.defaultCap) || 20,
    maxCap: Number(props.bulkConfig?.maxCap) || 30,
    startHour: Number.isFinite(Number(props.bulkConfig?.startHour)) ? Number(props.bulkConfig.startHour) : 2,
    timezone: props.bulkConfig?.timezone || 'Europe/Bratislava',
    validMinutes: Number(props.bulkConfig?.validMinutes) || 15,
}));

const announcement = ref('');
async function say(text) {
    announcement.value = '';
    await nextTick();
    announcement.value = text;
}

const status = useBulkStatus({
    initial: props.bulk,
    onTransition(change) {
        if (change.kind === 'paused') say(t('settings.ai_bulk.toast_paused') + '.');
        else if (change.kind === 'resumed') say(t('settings.ai_bulk.toast_resumed') + '.');
        else if (change.kind === 'finished') {
            finished.value = change.summary ? { read: change.summary.read, failed: change.summary.failed, skipped: change.summary.skipped } : { read: 0, failed: 0, skipped: 0 };
            say(t('settings.ai_bulk.finished'));
        } else if (change.kind === 'waiting') {
            say(t('health.ai.status.waiting_announce', { when: stamp(change.availableAt)?.text ?? '' }));
        }
    },
});
watch(() => props.bulk, (next) => { if (next) status.adopt(next, { quiet: true }); });

const active = computed(() => status.panel.value?.active ?? null);
const history = computed(() => status.panel.value?.history ?? []);
const historyFailed = ref(false);

// ---- plan block state
const scope = ref('no_proposal');
const capText = ref(String(config.value.defaultCap));
const capError = ref('');
const result = ref(null); // { token, checkedAt, validMinutes, counts, scope, cap }
const checking = ref(false);
const serverStale = ref(false);
const expired = ref(false);
const problem = ref(''); // '' | 'dry_run' | 'start' | 'control'
const confirmOpen = ref(false);
const starting = ref(false);
const finished = ref(null);
const pending = ref(null);

const legend = ref(null);
const resultHeading = ref(null);
const progress = ref(null);
const radios = ref([]);
const safe = ref(null);
let expiryTimer = null;

const capValue = computed(() => (/^\d+$/.test(String(capText.value).trim()) ? Number.parseInt(String(capText.value).trim(), 10) : Number.NaN));
const capValid = computed(() => Number.isInteger(capValue.value) && capValue.value >= 1 && capValue.value <= config.value.maxCap);

const fresh = computed(() => Boolean(result.value)
    && !serverStale.value
    && !expired.value
    && result.value.scope === scope.value
    && result.value.cap === capValue.value);
const eligible = computed(() => result.value?.counts?.eligible ?? 0);
const stale = computed(() => Boolean(result.value) && !fresh.value);
const reasonKey = computed(() => {
    if (!result.value) return 'start_reason_none';
    if (stale.value) return 'start_reason_stale';
    if (eligible.value === 0) return 'zero_eligible';
    return '';
});
const reason = computed(() => (reasonKey.value ? t(`settings.ai_bulk.${reasonKey.value}`) : ''));
const ready = computed(() => fresh.value && eligible.value > 0 && !starting.value);
const reasonId = `bulk-reason-${uid}`;
const capId = `bulk-cap-${uid}`;
const legendId = `bulk-legend-${uid}`;
const resultId = `bulk-result-${uid}`;

function stamp(iso) {
    const date = iso ? new Date(iso) : null;
    if (!date || Number.isNaN(date.getTime())) return null;
    const day = (d) => new Intl.DateTimeFormat('en-CA', { timeZone: config.value.timezone }).format(d);
    const options = day(date) === day(new Date()) ? { timeStyle: 'short' } : { weekday: 'short', hour: 'numeric', minute: '2-digit' };
    return { iso: date.toISOString(), text: new Intl.DateTimeFormat(locale.value, { ...options, timeZone: config.value.timezone }).format(date) };
}
// The start hour is a wall-clock hour in the family time zone, so it is formatted as a plain clock time.
const hour = computed(() => new Intl.DateTimeFormat(locale.value, { hour: 'numeric', minute: '2-digit', timeZone: 'UTC' })
    .format(new Date(Date.UTC(2000, 0, 1, config.value.startHour))));
// A 409 answer carries the enabled set as it is now; it wins over the page-load prop until the prop changes.
const providersLocal = ref(null);
watch(() => props.providers, () => { providersLocal.value = null; });
const effectiveProviders = computed(() => (Array.isArray(providersLocal.value) && providersLocal.value.length > 0 ? providersLocal.value : (props.providers ?? [])));
// The set the confirm dialog named when it opened; Start sends exactly this.
const shownProviders = ref([]);
const providerWords = computed(() => {
    const keys = effectiveProviders.value;
    const both = keys.includes('codex') && keys.includes('copilot');
    return t(`settings.ai_bulk.providers_${both ? 'both' : (keys.includes('copilot') ? 'copilot' : 'codex')}`);
});
const noAgentSignedIn = computed(() => Array.isArray(props.agents)
    && !props.agents.some((a) => a.enabled && a.health?.state === 'logged_in'));
const checkedAt = computed(() => (result.value ? stamp(result.value.checkedAt) : null));
const days = computed(() => (result.value && eligible.value > 0 ? Math.ceil(eligible.value / Math.max(1, result.value.cap)) : 0));
const rows = computed(() => {
    const c = result.value?.counts ?? {};
    return [
        ['row_eligible', c.eligible, true], ['row_consent', c.skipped_consent], ['row_unsupported', c.skipped_unsupported],
        ['row_decided', c.skipped_decided], ['row_active', c.skipped_active],
    ].map(([key, value, strong]) => ({ key, label: t(`settings.ai_bulk.${key}`), value: Number(value) || 0, strong: Boolean(strong) }));
});

const scopeLabels = computed(() => SCOPES.map((key) => ({ key, label: t(`settings.ai_bulk.scope_${key}`), hint: key === 'all' ? t('settings.ai_bulk.scope_all_hint') : '' })));

// ---- scope radiogroup (arrow keys move and select, one Tab stop)
function choose(key) {
    if (checking.value) return;
    scope.value = key;
}
function onRadioKey(event, index) {
    const step = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 }[event.key];
    if (!step) return;
    event.preventDefault();
    if (checking.value) return;
    const next = (index + step + SCOPES.length) % SCOPES.length;
    scope.value = SCOPES[next];
    nextTick(() => radios.value[next]?.focus());
}

// ---- cap
function validateCap() {
    capError.value = capValid.value ? '' : t('settings.ai_bulk.cap_error', { max: config.value.maxCap });
    return capValid.value;
}
watch(capText, () => { if (capError.value) capError.value = ''; });

// ---- dry run
function armExpiry(minutes) {
    clearTimeout(expiryTimer);
    expired.value = false;
    expiryTimer = setTimeout(() => { expired.value = true; }, Math.max(1, minutes) * 60000);
}
onBeforeUnmount(() => clearTimeout(expiryTimer));

async function check() {
    if (checking.value || starting.value) return;
    if (!validateCap()) return;
    checking.value = true;
    problem.value = '';
    finished.value = null;
    say(t('settings.ai_bulk.checking'));
    const body = { scope: scope.value, cap: capValue.value };
    const response = await securityFetch(DRY_URL, { method: 'POST', body });
    checking.value = false;
    const data = response.data ?? {};
    if (response.ok && typeof data.token === 'string' && data.counts) {
        result.value = {
            token: data.token,
            checkedAt: data.checkedAt,
            validMinutes: Number(data.validMinutes) || config.value.validMinutes,
            counts: data.counts,
            scope: body.scope,
            cap: body.cap,
        };
        serverStale.value = false;
        armExpiry(result.value.validMinutes);
        await nextTick();
        resultHeading.value?.focus();
        say(t('settings.ai_bulk.check_announce', { n: Number(data.counts.eligible) || 0 }));
        return;
    }
    if (response.status === 422 && response.errors?.cap) {
        capError.value = t('settings.ai_bulk.cap_error', { max: config.value.maxCap });
        say(t('settings.ai_bulk.dry_run_error'));
        return;
    }
    problem.value = 'dry_run';
}

// ---- start
function pressStart() {
    if (!ready.value) {
        say(reason.value);
        return;
    }
    shownProviders.value = [...effectiveProviders.value];
    confirmOpen.value = true;
}

async function start() {
    confirmOpen.value = false;
    if (!ready.value || !result.value) return;
    starting.value = true;
    problem.value = '';
    const response = await securityFetch(START_URL, { method: 'POST', body: { token: result.value.token, acknowledged: true, providers: [...shownProviders.value] } });
    starting.value = false;
    const data = response.data ?? {};
    if (response.ok && response.status === 201 && data.panel) {
        result.value = null;
        clearTimeout(expiryTimer);
        status.adopt(data.panel, { quiet: true });
        toast.show(t('settings.ai_bulk.toast_started'));
        say(t('settings.ai_bulk.toast_started'));
        await nextTick();
        progress.value?.focusHeading();
        return;
    }
    if (response.status === 409 && data.reason === 'active') {
        if (data.panel) status.adopt(data.panel, { quiet: true });
        result.value = null;
        say(t('settings.ai_bulk.already_active'));
        return;
    }
    if (response.status === 409 && data.reason === 'providers_changed') {
        // An agent was enabled or disabled after the dialog opened. Nothing was started; ask again with the current set.
        if (Array.isArray(data.providers) && data.providers.length > 0) providersLocal.value = data.providers;
        shownProviders.value = [...effectiveProviders.value];
        confirmOpen.value = true;
        return;
    }
    if (response.status === 410 || (response.status === 409 && data.reason === 'stale')) {
        serverStale.value = true;
        return;
    }
    problem.value = 'start';
}

// ---- controls on the active run
async function control(action) {
    if (pending.value || !active.value) return;
    pending.value = action;
    problem.value = '';
    const response = await securityFetch(`${START_URL}/${encodeURIComponent(active.value.id)}/${action}`, { method: 'POST' });
    pending.value = null;
    const data = response.data ?? {};
    if (response.ok && data.panel) {
        status.adopt(data.panel, { quiet: true });
        const text = t(`settings.ai_bulk.toast_${{ pause: 'paused', resume: 'resumed', cancel: 'cancelled' }[action]}`);
        toast.show(text);
        say(text);
        if (action === 'cancel') {
            await nextTick();
            legend.value?.focus();
        }
        return;
    }
    if (response.status === 409 && data.panel) {
        status.adopt(data.panel);
        return;
    }
    problem.value = 'control';
}

async function retryHistory() {
    historyFailed.value = false;
    historyFailed.value = !(await status.refresh());
}

const problemText = computed(() => ({
    dry_run: t('settings.ai_bulk.dry_run_error'),
    start: t('settings.ai_bulk.start_error'),
    control: t('settings.ai_agents.save_error'),
}[problem.value] ?? ''));
</script>

<template>
    <UiCard :title="t('settings.ai_bulk.heading')" :heading-level="2">
        <section class="bulk" :aria-busy="checking || starting || status.refreshing.value ? 'true' : undefined" data-testid="bulk-panel">
            <p class="bulk__intro">{{ t('settings.ai_bulk.intro') }}</p>

            <!-- B. Active run -->
            <template v-if="active">
                <p class="bulk__active-note">{{ t('settings.ai_bulk.active_note') }}</p>
                <UiAlert v-if="problem === 'control'" variant="danger" live>{{ problemText }}</UiAlert>
                <BulkProgress
                    ref="progress"
                    :run="active"
                    :pending="pending"
                    :poll-failed="status.pollFailed.value"
                    :timezone="config.timezone"
                    @pause="control('pause')"
                    @resume="control('resume')"
                    @cancel="control('cancel')"
                    @retry="status.retry"
                />
            </template>

            <!-- A. Plan block -->
            <template v-else>
                <UiAlert v-if="finished" variant="success" live data-testid="bulk-finished">
                    {{ t('settings.ai_bulk.finished') }}
                    <dl class="bulk-rows bulk-rows--inline">
                        <div class="bulk-rows__row"><dt>{{ t('settings.ai_bulk.count_read') }}</dt><dd>{{ Number(finished.read) || 0 }}</dd></div>
                        <div class="bulk-rows__row"><dt>{{ t('settings.ai_bulk.count_failed') }}</dt><dd>{{ Number(finished.failed) || 0 }}</dd></div>
                        <div class="bulk-rows__row"><dt>{{ t('settings.ai_bulk.count_skipped') }}</dt><dd>{{ Number(finished.skipped) || 0 }}</dd></div>
                    </dl>
                </UiAlert>

                <div class="bulk__group" role="radiogroup" :aria-labelledby="legendId" :aria-disabled="checking ? 'true' : undefined">
                    <span :id="legendId" ref="legend" tabindex="-1" class="bulk__legend">{{ t('settings.ai_bulk.scope_legend') }}</span>
                    <div
                        v-for="(option, index) in scopeLabels"
                        :key="option.key"
                        :ref="(el) => (radios[index] = el)"
                        class="bulk-radio"
                        :class="{ 'is-checked': scope === option.key }"
                        role="radio"
                        :aria-checked="scope === option.key ? 'true' : 'false'"
                        :aria-labelledby="`${legendId}-${option.key}`"
                        :aria-describedby="option.hint ? `${legendId}-${option.key}-hint` : undefined"
                        :tabindex="scope === option.key ? 0 : -1"
                        :data-scope="option.key"
                        @click="choose(option.key)"
                        @keydown="onRadioKey($event, index)"
                    >
                        <span class="bulk-radio__dot" aria-hidden="true" />
                        <span class="bulk-radio__text">
                            <span :id="`${legendId}-${option.key}`" class="bulk-radio__label">{{ option.label }}</span>
                            <span v-if="option.hint" :id="`${legendId}-${option.key}-hint`" class="bulk-radio__hint">{{ option.hint }}</span>
                        </span>
                    </div>
                </div>

                <UiField
                    :id="capId"
                    :label="t('settings.ai_bulk.cap_label')"
                    :hint="t('settings.ai_bulk.cap_helper', { max: config.maxCap, hour })"
                    :error="capError"
                >
                    <UiInput
                        v-model="capText"
                        type="number"
                        inputmode="numeric"
                        min="1"
                        :max="config.maxCap"
                        step="1"
                        :readonly="checking"
                        :aria-disabled="checking ? 'true' : undefined"
                        class="bulk__cap"
                        @blur="validateCap"
                    />
                </UiField>

                <div class="bulk__row">
                    <UiButton
                        variant="secondary"
                        data-control="check"
                        :aria-busy="checking ? 'true' : undefined"
                        :aria-disabled="checking ? 'true' : undefined"
                        @click="check"
                    >{{ t('settings.ai_bulk.check') }}</UiButton>
                    <span v-if="checking" class="bulk__checking">{{ t('settings.ai_bulk.checking') }}</span>
                </div>

                <section v-if="result" class="bulk-result" :class="{ 'is-stale': stale }" :aria-labelledby="resultId" data-testid="bulk-result">
                    <h3 :id="resultId" ref="resultHeading" tabindex="-1" class="bulk-result__heading">{{ t('settings.ai_bulk.result_heading') }}</h3>
                    <dl class="bulk-rows">
                        <div v-for="row in rows" :key="row.key" class="bulk-rows__row" :class="{ 'is-strong': row.strong }">
                            <dt>{{ row.label }}</dt>
                            <dd>{{ row.value }}</dd>
                        </div>
                    </dl>
                    <p v-if="eligible > 0" class="bulk-result__line">{{ t('settings.ai_bulk.days_estimate', { n: days, cap: result.cap }, days) }}</p>
                    <p v-else class="bulk-result__line">{{ t('settings.ai_bulk.zero_eligible') }}</p>
                    <p v-if="checkedAt" class="bulk-result__meta">
                        <I18nT keypath="settings.ai_bulk.checked_at" tag="span" scope="global">
                            <template #time><time :datetime="checkedAt.iso">{{ checkedAt.text }}</time></template>
                        </I18nT>
                        {{ t('settings.ai_bulk.valid_minutes', { n: result.validMinutes }, result.validMinutes) }}
                    </p>
                </section>

                <UiAlert v-if="stale" variant="warning">{{ t('settings.ai_bulk.stale_alert') }}</UiAlert>
                <UiAlert v-if="problem === 'dry_run' || problem === 'start'" variant="danger" live>{{ problemText }}</UiAlert>

                <div class="bulk__start">
                    <UiButton
                        variant="primary"
                        data-control="start"
                        :aria-disabled="ready ? undefined : 'true'"
                        :aria-describedby="reason ? reasonId : undefined"
                        :aria-busy="starting ? 'true' : undefined"
                        @click="pressStart"
                    >{{ t('settings.ai_bulk.start') }}</UiButton>
                    <p v-if="reason" :id="reasonId" class="bulk__reason">{{ reason }}</p>
                </div>
            </template>

            <UiDialog :open="confirmOpen" :title="t('settings.ai_bulk.confirm_title')" :initial-focus="() => safe" @update:open="(v) => (confirmOpen = v)">
                <p class="bulk-dialog__body">{{ t('settings.ai_bulk.confirm_body', { n: eligible, providers: providerWords, cap: result?.cap ?? capValue, hour }, eligible) }}</p>
                <p v-if="noAgentSignedIn" class="bulk-dialog__note">{{ t('settings.ai_bulk.no_agent_note') }}</p>
                <template #footer>
                    <UiButton ref="safe" variant="secondary" @click="confirmOpen = false">{{ t('settings.ai_bulk.confirm_safe') }}</UiButton>
                    <UiButton variant="primary" @click="start">{{ t('settings.ai_bulk.start') }}</UiButton>
                </template>
            </UiDialog>

            <BulkHistoryTable
                :rows="history"
                :loading="status.panel.value === null"
                :error="historyFailed || loadFailed"
                :timezone="config.timezone"
                @retry="retryHistory"
            />

            <p class="visually-hidden" role="status">{{ announcement }}</p>
        </section>
    </UiCard>
</template>

<style scoped>
.bulk { display: flex; flex-direction: column; gap: var(--space-4); }
.bulk__intro, .bulk__active-note { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.bulk__group { display: flex; flex-direction: column; gap: var(--space-1); }
.bulk__legend { font-size: var(--text-sm); font-weight: 600; }
.bulk__legend:focus { outline: none; }
.bulk__legend:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.bulk-radio { display: flex; align-items: flex-start; gap: var(--space-3); min-height: 44px; padding: var(--space-2) var(--space-3); border-radius: var(--radius-md); border: 1px solid var(--color-border); cursor: pointer; }
.bulk-radio.is-checked { background: var(--color-primary-soft); border-color: var(--color-primary); }
.bulk-radio:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.bulk-radio__dot { flex: none; width: 18px; height: 18px; margin-top: 2px; border: 2px solid currentColor; border-radius: 50%; box-shadow: inset 0 0 0 3px var(--color-surface); }
.bulk-radio.is-checked .bulk-radio__dot { background: var(--color-primary); border-color: var(--color-primary); }
.bulk-radio__text { display: flex; flex-direction: column; gap: var(--space-1); min-width: 0; }
.bulk-radio__label { font-size: var(--text-base); overflow-wrap: anywhere; }
.bulk-radio__hint { font-size: var(--text-sm); color: var(--color-text-muted); overflow-wrap: anywhere; }
.bulk__cap { max-width: 160px; }
.bulk__row { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-3); }
.bulk__checking { font-size: var(--text-sm); color: var(--color-text-muted); }
.bulk-result { display: flex; flex-direction: column; gap: var(--space-3); padding: var(--space-4); border-radius: var(--radius-md); background: var(--color-surface-2); }
.bulk-result.is-stale { opacity: 0.6; }
.bulk-result__heading { margin: 0; font-size: var(--text-lg); font-weight: 600; }
.bulk-result__heading:focus { outline: none; }
.bulk-result__heading:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 2px; }
.bulk-result__line { margin: 0; font-size: var(--text-base); }
.bulk-result__meta { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.bulk-rows { display: flex; flex-direction: column; gap: var(--space-2); margin: 0; }
.bulk-rows__row { display: flex; flex-wrap: wrap; justify-content: space-between; gap: var(--space-1) var(--space-3); }
.bulk-rows dt { min-width: 0; overflow-wrap: anywhere; }
.bulk-rows dd { margin: 0; font-variant-numeric: tabular-nums; }
.bulk-rows__row.is-strong dd, .bulk-rows__row.is-strong dt { font-size: var(--text-base); font-weight: 600; }
.bulk-rows--inline { margin-top: var(--space-2); }
.bulk__start { display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-2); }
.bulk__start :deep(.ui-button), .bulk__row :deep(.ui-button) { min-height: 44px; max-width: 100%; }
.bulk__start :deep(.ui-button__label), .bulk__row :deep(.ui-button__label) { overflow-wrap: anywhere; }
.bulk__reason { margin: 0; font-size: var(--text-sm); color: var(--color-text-muted); }
.bulk-dialog__body { margin: 0; font-size: var(--text-base); }
.bulk-dialog__note { margin: var(--space-3) 0 0; font-size: var(--text-sm); color: var(--color-text-muted); }
@media (prefers-reduced-motion: no-preference) {
    .bulk-result { transition: opacity 175ms; }
}
</style>
