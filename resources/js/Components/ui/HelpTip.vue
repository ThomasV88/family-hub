<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { activeTip, help, openHelpEditor } from '../../Help/helpStore.js';
import { positionTip } from '../../Help/positionTip.js';

// A small green, bordered "?" that explains a section, field or button. The text comes from the contextual help registry
// (database, editable by administrators), in the language the app currently shows. `topic` is the registry key. `label`
// replaces the short topic title in the accessible name. An unknown topic renders nothing.
const props = defineProps({
    topic: { type: String, required: true },
    label: { type: String, default: '' },
});

const { t } = useI18n();
const uid = useId();
const bubbleId = `help-${uid}`;
const entry = computed(() => help.topics[props.topic] ?? null);
const name = computed(() => t('help.tip_label', { topic: props.label || entry.value?.title || props.topic }));

const hovered = ref(false);
const focused = ref(false);
const pinned = ref(false);
const shown = computed(() => Boolean(entry.value) && (hovered.value || focused.value || pinned.value));

const button = ref(null);
const bubble = ref(null);
const place = ref({ top: 0, left: 0, width: 0, placement: 'bottom', ready: false });
let leaveTimer = null;

const warned = new Set();
watch(
    () => [help.loaded, entry.value, props.topic],
    () => {
        if (help.loaded && !entry.value && !warned.has(props.topic)) {
            warned.add(props.topic);
            if (import.meta.env?.DEV !== false) console.warn(`[HelpTip] unknown help topic "${props.topic}"`);
        }
    },
    { immediate: true },
);

function closeAll() {
    hovered.value = false;
    focused.value = false;
    pinned.value = false;
}

function reposition() {
    if (!button.value || !bubble.value) return;
    const a = button.value.getBoundingClientRect();
    const b = bubble.value.getBoundingClientRect();
    place.value = {
        ...positionTip({
            anchor: { top: a.top, left: a.left, width: a.width, height: a.height },
            bubble: { width: b.width, height: b.height },
            viewport: { width: window.innerWidth, height: window.innerHeight },
        }),
        ready: true,
    };
}

function onDocumentPointer(event) {
    const target = event.target;
    if (button.value?.contains(target) || bubble.value?.contains(target)) return;
    closeAll();
}
function onDocumentKey(event) {
    if (event.key !== 'Escape') return;
    // Close the tip first; a dialog around it must not close with the same key press.
    event.stopPropagation();
    closeAll();
}

function listen() {
    document.addEventListener('pointerdown', onDocumentPointer, true);
    document.addEventListener('keydown', onDocumentKey, true);
    window.addEventListener('resize', reposition);
    window.addEventListener('scroll', reposition, true);
}
function unlisten() {
    document.removeEventListener('pointerdown', onDocumentPointer, true);
    document.removeEventListener('keydown', onDocumentKey, true);
    window.removeEventListener('resize', reposition);
    window.removeEventListener('scroll', reposition, true);
}

watch(shown, async (now) => {
    if (now) {
        activeTip.value = uid;
        listen();
        place.value = { ...place.value, ready: false };
        await nextTick();
        reposition();
    } else {
        unlisten();
        if (activeTip.value === uid) activeTip.value = null;
    }
});
// Another tip opened: close this one.
watch(activeTip, (id) => {
    if (id !== uid) closeAll();
});
onBeforeUnmount(() => {
    unlisten();
    clearTimeout(leaveTimer);
    if (activeTip.value === uid) activeTip.value = null;
});

function enter(event) {
    // A touch tap also reports a pointer enter; on touch only the tap toggles.
    if (event.pointerType === 'touch') return;
    clearTimeout(leaveTimer);
    hovered.value = true;
}
function leave(event) {
    if (event.pointerType === 'touch') return;
    clearTimeout(leaveTimer);
    // A short grace period lets the pointer travel from the mark onto the bubble (the text stays hoverable).
    leaveTimer = setTimeout(() => { hovered.value = false; }, 120);
}
function onFocus(event) {
    let visible = true;
    try { visible = event.target.matches(':focus-visible'); } catch { visible = true; }
    // Only keyboard focus opens the tip; a mouse or tap focuses the button too and then toggles through click.
    if (visible) focused.value = true;
}
function onBlur() {
    closeAll();
}

const isEditClick = (event) => help.admin && (event.ctrlKey || event.metaKey);

function onClick(event) {
    event.preventDefault();
    event.stopPropagation();
    if (isEditClick(event)) {
        closeAll();
        openHelpEditor(props.topic);
        return;
    }
    if (pinned.value) {
        pinned.value = false;
        hovered.value = false;
        focused.value = false;
    } else {
        pinned.value = true;
    }
}
// macOS reports Ctrl+click as a context menu, not as a click.
function onContextMenu(event) {
    if (!help.admin || !event.ctrlKey) return;
    event.preventDefault();
    closeAll();
    openHelpEditor(props.topic);
}

const style = computed(() => ({
    top: `${place.value.top}px`,
    left: `${place.value.left}px`,
    ...(place.value.width ? { maxWidth: `${Math.max(place.value.width, 0)}px` } : {}),
}));
</script>

<template>
    <span v-if="entry" class="help-tip" :data-help-topic="topic" @pointerenter="enter" @pointerleave="leave">
        <button
            ref="button"
            type="button"
            class="help-tip__button"
            :aria-label="name"
            :aria-describedby="bubbleId"
            :aria-expanded="shown ? 'true' : 'false'"
            @click="onClick"
            @contextmenu="onContextMenu"
            @focus="onFocus"
            @blur="onBlur"
        >
            <span class="help-tip__mark" aria-hidden="true">?</span>
        </button>
        <Teleport to="body">
            <span
                v-show="shown"
                :id="bubbleId"
                ref="bubble"
                role="tooltip"
                class="help-tip__bubble"
                :class="[`help-tip__bubble--${place.placement}`, { 'is-ready': place.ready }]"
                :style="style"
                @pointerenter="enter"
                @pointerleave="leave"
            >
                <span class="help-tip__text">{{ entry.text }}</span>
                <span v-if="help.admin" class="help-tip__admin">{{ t('help.admin_hint') }}</span>
            </span>
        </Teleport>
    </span>
</template>

<style scoped>
.help-tip { display: inline-flex; align-items: center; vertical-align: middle; flex: none; }
/* The hit area is 44 x 44 px; the negative margin keeps the visible 18 px mark from pushing the line apart. */
.help-tip__button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: var(--control-touch);
    height: var(--control-touch);
    margin: -13px -10px;
    padding: 0;
    border: 0;
    background: transparent;
    color: var(--color-success);
    font: inherit;
    cursor: help;
    -webkit-tap-highlight-color: transparent;
}
.help-tip__mark {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
    width: 18px;
    height: 18px;
    border: 1.5px solid var(--color-success);
    border-radius: var(--radius-full);
    color: var(--color-success);
    font-size: 12px;
    font-weight: 700;
    line-height: 1;
    transition: box-shadow 150ms;
}
/* A ring, not a fill: the green mark keeps its full contrast on every surface. */
.help-tip__button:hover .help-tip__mark,
.help-tip__button[aria-expanded='true'] .help-tip__mark { box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-success) 22%, transparent); }
.help-tip__button:focus-visible { outline: none; }
.help-tip__button:focus-visible .help-tip__mark { outline: 2px solid var(--color-focus); outline-offset: 2px; }

.help-tip__bubble {
    position: fixed;
    z-index: 70;
    display: block;
    box-sizing: border-box;
    width: max-content;
    max-width: min(20rem, calc(100vw - 16px));
    padding: var(--space-2) var(--space-3);
    border: 1px solid var(--color-success);
    border-radius: var(--radius-md);
    background: var(--color-surface);
    color: var(--color-text);
    box-shadow: var(--shadow-md);
    font-size: var(--text-sm);
    font-weight: 400;
    line-height: 1.45;
    text-align: start;
    overflow-wrap: anywhere;
    white-space: pre-line;
    visibility: hidden;
    opacity: 0;
    transition: opacity 120ms;
}
.help-tip__bubble.is-ready { visibility: visible; opacity: 1; }
.help-tip__text { display: block; }
.help-tip__admin { display: block; margin-top: var(--space-2); color: var(--color-text-muted); font-size: var(--text-xs); }

@media (prefers-reduced-motion: reduce) {
    .help-tip__mark, .help-tip__bubble { transition: none; }
}
@media (forced-colors: active) {
    .help-tip__button { color: ButtonText; }
    .help-tip__mark { border-color: ButtonText; color: ButtonText; }
    .help-tip__button:focus-visible .help-tip__mark { outline-color: Highlight; }
    .help-tip__bubble { border-color: CanvasText; background: Canvas; color: CanvasText; box-shadow: none; }
}
</style>
