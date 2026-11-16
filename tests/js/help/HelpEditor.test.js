import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest';
import { defineComponent, nextTick } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { mountUi, loadMessages } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { pseudoLong } from '../helpers/pseudoLong.js';
import { installHelp } from '../helpers/help.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import HelpTextEditor from '../../../resources/js/Help/HelpTextEditor.vue';
import HelpEditorHost from '../../../resources/js/Help/HelpEditorHost.vue';
import HelpTip from '../../../resources/js/Components/ui/HelpTip.vue';
import UiToastRegion from '../../../resources/js/Components/ui/UiToastRegion.vue';
import { useToast } from '../../../resources/js/Components/ui/useToast.js';
import { editing, help, openHelpEditor, resetHelp } from '../../../resources/js/Help/helpStore.js';

const KEY = 'tasks.recurrence';
const topic = (over = {}) => ({
    key: KEY,
    location: { area: 'Tasks', screen: 'Task panel', field: 'Repeat' },
    titles: { sk: 'Opakovanie', pl: 'Powtarzanie', en: 'Repeat' },
    texts: { sk: 'Slovenský predvolený.', pl: 'Polski tekst.', en: 'English text.' },
    defaults: { sk: 'Slovenský predvolený.', pl: 'Polski domyślny.', en: 'English default.' },
    edited: { sk: false, pl: false, en: true },
    ...over,
});

let wrapper;
let calls;
let handler;
function fakeFetch(fn) {
    calls = [];
    handler = fn;
    vi.stubGlobal('fetch', vi.fn(async (url, init = {}) => {
        const body = init.body ? JSON.parse(init.body) : undefined;
        calls.push({ url, method: init.method ?? 'GET', body });
        const { status = 200, data = {} } = await handler(url, init.method ?? 'GET', body);
        return { ok: status >= 200 && status < 300, status, headers: new Headers(), json: async () => data };
    }));
}
const originalWidth = window.innerWidth;
beforeEach(() => installHelp('en', { admin: true }));
afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    delete document.documentElement.dataset.theme;
    window.innerWidth = originalWidth;
    resetHelp();
    vi.unstubAllGlobals();
    useToast().toasts.splice(0);
});
const q = (sel) => document.body.querySelector(sel);
const mountEditor = (props = {}, opts = {}) => (wrapper = mountUi(HelpTextEditor, { props: { topic: topic(), activeLocale: 'en', ...props }, ...opts }));
const type = async (locale, value) => {
    const area = q(`[data-testid="help-text-${locale}"]`);
    area.value = value;
    area.dispatchEvent(new Event('input', { bubbles: true }));
    await nextTick();
};

describe('HelpTextEditor', () => {
    it('shows the location read only, three language fields with counters and the current language highlighted', () => {
        mountEditor();
        expect(q('[data-testid="help-editor-location"]').textContent).toContain('Tasks');
        expect(q('[data-testid="help-editor-location"]').textContent).toContain('Task panel');
        expect(q('[data-testid="help-editor-location"]').textContent).toContain('Repeat');
        expect(document.body.querySelectorAll('textarea')).toHaveLength(3);
        expect(q('[data-locale="en"]').classList.contains('is-active')).toBe(true);
        expect(q('[data-locale="sk"]').classList.contains('is-active')).toBe(false);
        expect(q('[data-locale="en"]').textContent).toContain('Your current language');
        expect(q('[data-locale="en"]').textContent).toContain('Edited');
        expect(q('[data-locale="sk"]').textContent).not.toContain('Edited');
        expect(q('[data-locale="en"]').textContent).toContain('13 / 400');
        expect(q('[data-help-editor-location]')).toBeNull();
    });

    it('counts while typing, and blocks saving an empty, over-long or markup text', async () => {
        mountEditor();
        expect(q('[data-testid="help-save"]').disabled).toBe(true);
        await type('sk', 'Nový text.');
        expect(q('[data-locale="sk"]').textContent).toContain('10 / 400');
        expect(q('[data-testid="help-save"]').disabled).toBe(false);
        await type('sk', 'x'.repeat(401));
        expect(q('[data-locale="sk"]').textContent).toContain('Use at most 400 characters.');
        expect(q('[data-testid="help-save"]').disabled).toBe(true);
        await type('sk', '   ');
        expect(q('[data-locale="sk"]').textContent).toContain('Write at least one character.');
        await type('sk', 'a <b> c');
        expect(q('[data-locale="sk"]').textContent).toContain('Do not use angle brackets.');
        expect(q('[data-testid="help-save"]').disabled).toBe(true);
        await type('sk', 'x'.repeat(400));
        expect(q('[data-testid="help-save"]').disabled).toBe(false);
    });

    it('saves only the changed languages and reports the answer', async () => {
        fakeFetch(() => ({ data: { topic: topic(), current: { title: 'Repeat', text: 'Changed.' } } }));
        mountEditor();
        await type('en', '  Changed.  ');
        q('[data-testid="help-save"]').click();
        await flushPromises();
        expect(calls).toHaveLength(1);
        expect(calls[0]).toMatchObject({ url: '/settings/help/tasks.recurrence', method: 'PUT', body: { texts: { en: 'Changed.' } } });
        expect(wrapper.emitted('saved')[0][0].current.text).toBe('Changed.');
    });

    it('resets a language to its default and sends it as a reset', async () => {
        fakeFetch(() => ({ data: { topic: topic(), current: { title: 'Repeat', text: 'English default.' } } }));
        mountEditor();
        expect(q('[data-testid="help-reset-sk"]').disabled).toBe(true); // already the default
        expect(q('[data-testid="help-reset-en"]').disabled).toBe(false); // edited
        q('[data-testid="help-reset-en"]').click();
        await nextTick();
        expect(q('[data-testid="help-text-en"]').value).toBe('English default.');
        q('[data-testid="help-save"]').click();
        await flushPromises();
        expect(calls[0].body).toEqual({ reset: ['en'] });
    });

    it('typing after a reset turns it back into an edit', async () => {
        fakeFetch(() => ({ data: { topic: topic(), current: { title: 'Repeat', text: 'Own.' } } }));
        mountEditor();
        q('[data-testid="help-reset-en"]').click();
        await nextTick();
        await type('en', 'Own.');
        q('[data-testid="help-save"]').click();
        await flushPromises();
        expect(calls[0].body).toEqual({ texts: { en: 'Own.' } });
    });

    it('cancel closes without any request', async () => {
        fakeFetch(() => ({}));
        mountEditor();
        await type('en', 'Something else.');
        q('[data-testid="help-cancel"]').click();
        expect(wrapper.emitted('cancel')).toHaveLength(1);
        expect(calls).toHaveLength(0);
    });

    it('shows a message for a rejected text and for a server failure, and keeps the typed text', async () => {
        fakeFetch(() => ({ status: 422, data: { errors: { 'texts.en': ['bad'] } } }));
        mountEditor();
        await type('en', 'Typed text.');
        q('[data-testid="help-save"]').click();
        await flushPromises();
        expect(q('[data-testid="help-editor-error"]').textContent).toContain('The text was not accepted');
        expect(q('[data-testid="help-text-en"]').value).toBe('Typed text.');
        fakeFetch(() => ({ status: 500 }));
        q('[data-testid="help-save"]').click();
        await flushPromises();
        expect(q('[data-testid="help-editor-error"]').textContent).toContain('could not be saved');
    });

    it('is translated', () => {
        mountEditor({ activeLocale: 'sk' }, { locale: 'sk' });
        expect(q('[data-locale="sk"]').textContent).toContain(loadMessages('sk').help.editor.current_language);
        expect(document.body.textContent).toContain(loadMessages('sk').help.editor.save);
    });
});

describe('HelpEditorHost (Ctrl+click dialog)', () => {
    const Page = defineComponent({
        components: { HelpTip, HelpEditorHost, UiToastRegion },
        template: '<div><HelpTip topic="tasks.recurrence" /><HelpEditorHost /><UiToastRegion /></div>',
    });
    const mountPage = () => (wrapper = mountUi(Page));
    const serve = (over = {}) => fakeFetch((url, method, body) => {
        if (method === 'GET') return { data: { topic: topic(over) } };
        return { data: { topic: topic(), current: { title: 'Repeat', text: body.texts?.en ?? 'x' } } };
    });

    it('opens on Ctrl+click for an administrator, loads the topic and saves; the visible tip changes without a reload', async () => {
        serve();
        mountPage();
        q('.help-tip__button').dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, ctrlKey: true }));
        await flushPromises();
        expect(calls[0]).toMatchObject({ url: '/settings/help/tasks.recurrence', method: 'GET' });
        expect(q('[role="dialog"]')).not.toBeNull();
        expect(q('[data-testid="help-editor"]')).not.toBeNull();
        await type('en', 'Fresh wording for everyone.');
        q('[data-testid="help-save"]').click();
        await flushPromises();
        expect(editing.value).toBeNull();
        expect(q('[role="dialog"]')).toBeNull();
        expect(help.topics[KEY].text).toBe('Fresh wording for everyone.');
        expect(q('[role="tooltip"]').textContent).toContain('Fresh wording for everyone.');
        expect(document.body.textContent).toContain('Help text saved');
    });

    it('cancel closes the dialog and sends nothing', async () => {
        serve();
        mountPage();
        openHelpEditor(KEY);
        await flushPromises();
        q('[data-testid="help-cancel"]').click();
        await flushPromises();
        expect(q('[role="dialog"]')).toBeNull();
        expect(calls.map((c) => c.method)).toEqual(['GET']);
    });

    it('never opens for an ordinary user, even if asked to', async () => {
        installHelp('en', { admin: false });
        serve();
        mountPage();
        openHelpEditor(KEY);
        await flushPromises();
        expect(q('[role="dialog"]')).toBeNull();
        expect(calls).toHaveLength(0);
        expect(editing.value).toBeNull();
    });

    it('offers a retry when the topic cannot be loaded', async () => {
        fakeFetch(() => ({ status: 500 }));
        mountPage();
        openHelpEditor(KEY);
        await flushPromises();
        expect(q('[role="dialog"]').textContent).toContain('could not be loaded');
        serve();
        [...document.body.querySelectorAll('[role="dialog"] button')].find((b) => b.textContent.trim() === 'Try again').click();
        await flushPromises();
        expect(q('[data-testid="help-editor"]')).not.toBeNull();
    });
});

describe('HelpTextEditor accessibility', () => {
    const cases = [];
    for (const locale of ['en', 'sk', 'pl']) for (const theme of ['light', 'dark']) cases.push([locale, theme]);

    it.each(cases)('has no axe violations (%s, %s)', async (locale, theme) => {
        document.documentElement.dataset.theme = theme;
        mountEditor({ activeLocale: locale }, { locale });
        await expectNoAxeViolations(document.body);
        await type(locale === 'en' ? 'en' : locale, 'x'.repeat(401));
        await expectNoAxeViolations(document.body);
    });

    it('survives 320 px and 40% longer interface text without violations', async () => {
        window.innerWidth = 320;
        wrapper = mount(HelpTextEditor, {
            props: { topic: topic(), activeLocale: 'en' },
            attachTo: document.body,
            global: { plugins: [createAppI18n('en', pseudoLong(loadMessages('en')))] },
        });
        await expectNoAxeViolations(document.body);
    });
});
