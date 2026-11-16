// Client state of the contextual help ("?" tips). The texts arrive as an Inertia shared prop (`help`, the active language
// only, sent once and remembered by Inertia) and are copied here, so a tip needs no Inertia import and keeps working on
// pages and in tests that mock the router. Nothing here is stored in the browser beyond the page's own memory.
import { reactive, ref } from 'vue';

export const help = reactive({
    // false until the shared prop was applied: tips render nothing and do not warn before that (tests, bare pages).
    loaded: false,
    locale: 'en',
    version: 0,
    // key -> { title, text }
    topics: {},
    // The server enforces edit rights on every request; this only decides whether Ctrl+click opens the editor.
    admin: false,
});

// Which tip is open (one at a time). Tips watch this and close when another one takes it.
export const activeTip = ref(null);

// The topic key the editor dialog shows, or null.
export const editing = ref(null);

export function setHelp(payload) {
    if (!payload || typeof payload !== 'object' || typeof payload.topics !== 'object') return;
    help.locale = payload.locale ?? help.locale;
    help.version = payload.version ?? help.version;
    help.topics = payload.topics;
    help.loaded = true;
}

export function setHelpAdmin(isAdmin) {
    help.admin = Boolean(isAdmin);
}

// After a save the visible tip changes without a reload.
export function setTopicText(key, entry) {
    if (!entry || typeof entry.text !== 'string') return;
    help.topics = { ...help.topics, [key]: { title: entry.title ?? help.topics[key]?.title ?? key, text: entry.text } };
}

export function openHelpEditor(key) {
    editing.value = key;
}

export function closeHelpEditor() {
    editing.value = null;
}

export function resetHelp() {
    help.loaded = false;
    help.locale = 'en';
    help.version = 0;
    help.topics = {};
    help.admin = false;
    activeTip.value = null;
    editing.value = null;
}
