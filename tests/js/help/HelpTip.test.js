import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import { mountUi, loadMessages } from '../helpers/mount.js';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { pseudoLong } from '../helpers/pseudoLong.js';
import { installHelp, helpPayload } from '../helpers/help.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { mount } from '@vue/test-utils';
import HelpTip from '../../../resources/js/Components/ui/HelpTip.vue';
import { editing, help, resetHelp, setHelp, setTopicText } from '../../../resources/js/Help/helpStore.js';
import { positionTip } from '../../../resources/js/Help/positionTip.js';

let wrapper;
const originalWidth = window.innerWidth;
beforeEach(() => installHelp('en'));
afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    delete document.documentElement.dataset.theme;
    window.innerWidth = originalWidth;
    resetHelp();
    vi.useRealTimers();
    vi.restoreAllMocks();
});

const TOPIC = 'tasks.recurrence';
const mountTip = (props = {}, opts = {}) => (wrapper = mountUi(HelpTip, { props: { topic: TOPIC, ...props }, ...opts }));
const button = () => document.body.querySelector('.help-tip__button');
const bubble = () => document.body.querySelector('[role="tooltip"]');
const visible = () => bubble() && bubble().style.display !== 'none';
const press = (el, type, init = {}) => el.dispatchEvent(Object.assign(new Event(type, { bubbles: true, cancelable: true }), init));

describe('HelpTip markup and wiring', () => {
    it('renders a button with a localized accessible name and a tooltip it is described by', () => {
        mountTip();
        const b = button();
        expect(b.getAttribute('type')).toBe('button');
        expect(b.getAttribute('aria-label')).toBe(`Help: ${help.topics[TOPIC].title}`);
        expect(b.getAttribute('aria-describedby')).toBe(bubble().id);
        expect(bubble().textContent).toContain(help.topics[TOPIC].text);
        expect(visible()).toBe(false);
    });

    it('lets a label replace the topic title in the accessible name', () => {
        mountTip({ label: 'Repeat every week' });
        expect(button().getAttribute('aria-label')).toBe('Help: Repeat every week');
    });

    it('uses the app language for the name', () => {
        installHelp('sk');
        mountTip({}, { locale: 'sk' });
        expect(button().getAttribute('aria-label')).toBe(`${loadMessages('sk').help.tip_label.replace('{topic}', help.topics[TOPIC].title)}`);
    });

    it('renders nothing for an unknown topic and warns once, but stays silent before any help was loaded', async () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        mountTip({ topic: 'no.such.topic' });
        expect(document.body.querySelector('.help-tip')).toBeNull();
        expect(warn).toHaveBeenCalledTimes(1);
        wrapper.unmount();
        warn.mockClear();
        resetHelp();
        mountTip({ topic: TOPIC });
        expect(document.body.querySelector('.help-tip')).toBeNull();
        expect(warn).not.toHaveBeenCalled();
    });

    it('does not make a surrounding label clickable', async () => {
        const Host = defineComponent({
            components: { HelpTip },
            template: '<label><input type="checkbox" id="c"> Remind me <HelpTip topic="tasks.recurrence" /></label>',
        });
        wrapper = mountUi(Host);
        button().click();
        await nextTick();
        expect(document.getElementById('c').checked).toBe(false);
    });
});

describe('HelpTip behaviour', () => {
    it('shows on hover and hides shortly after the pointer leaves', async () => {
        vi.useFakeTimers();
        mountTip();
        press(button().parentElement, 'pointerenter', { pointerType: 'mouse' });
        await nextTick();
        expect(visible()).toBe(true);
        press(button().parentElement, 'pointerleave', { pointerType: 'mouse' });
        await nextTick();
        expect(visible()).toBe(true); // grace period: the pointer may travel onto the text
        vi.advanceTimersByTime(200);
        await nextTick();
        expect(visible()).toBe(false);
    });

    it('stays open while the pointer is on the bubble itself', async () => {
        vi.useFakeTimers();
        mountTip();
        press(button().parentElement, 'pointerenter', { pointerType: 'mouse' });
        press(button().parentElement, 'pointerleave', { pointerType: 'mouse' });
        press(bubble(), 'pointerenter', { pointerType: 'mouse' });
        vi.advanceTimersByTime(300);
        await nextTick();
        expect(visible()).toBe(true);
    });

    it('opens on a tap (touch) and closes on a second tap; the emulated touch enter does not toggle it', async () => {
        mountTip();
        press(button().parentElement, 'pointerenter', { pointerType: 'touch' });
        await nextTick();
        expect(visible()).toBe(false);
        button().click();
        await nextTick();
        expect(visible()).toBe(true);
        expect(button().getAttribute('aria-expanded')).toBe('true');
        button().click();
        await nextTick();
        expect(visible()).toBe(false);
    });

    it('opens on keyboard focus and closes on blur', async () => {
        mountTip();
        button().focus();
        await nextTick();
        expect(visible()).toBe(true);
        button().blur();
        await nextTick();
        expect(visible()).toBe(false);
    });

    it('closes on Escape without letting the key reach a dialog around it', async () => {
        mountTip();
        const outer = vi.fn();
        document.body.addEventListener('keydown', outer);
        button().click();
        await nextTick();
        expect(visible()).toBe(true);
        button().dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
        await nextTick();
        expect(visible()).toBe(false);
        expect(outer).not.toHaveBeenCalled();
        // With nothing open the key is left alone.
        button().dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
        expect(outer).toHaveBeenCalledTimes(1);
        document.body.removeEventListener('keydown', outer);
    });

    it('closes on a tap outside, but not on a tap inside the bubble', async () => {
        mountTip();
        button().click();
        await nextTick();
        press(bubble(), 'pointerdown');
        await nextTick();
        expect(visible()).toBe(true);
        press(document.body, 'pointerdown');
        await nextTick();
        expect(visible()).toBe(false);
    });

    it('keeps one tip open at a time', async () => {
        const Two = defineComponent({
            components: { HelpTip },
            template: '<div><HelpTip topic="tasks.recurrence" /><HelpTip topic="tasks.reminder" /></div>',
        });
        wrapper = mountUi(Two);
        const [a, b] = document.body.querySelectorAll('.help-tip__button');
        a.click();
        await nextTick();
        b.click();
        await nextTick();
        const open = [...document.body.querySelectorAll('[role="tooltip"]')].filter((el) => el.style.display !== 'none');
        expect(open).toHaveLength(1);
        expect(open[0].id).toBe(b.getAttribute('aria-describedby'));
    });

    it('never traps focus: Tab leaves the button with the tip open', async () => {
        const Host = defineComponent({
            components: { HelpTip },
            template: '<div><HelpTip topic="tasks.recurrence" /><button id="next">next</button></div>',
        });
        wrapper = mountUi(Host);
        button().focus();
        await nextTick();
        document.getElementById('next').focus();
        await nextTick();
        expect(document.activeElement.id).toBe('next');
        expect(visible()).toBe(false);
    });

    it('shows a changed text without a reload', async () => {
        mountTip();
        setTopicText(TOPIC, { text: 'A brand new explanation.' });
        await nextTick();
        expect(bubble().textContent).toContain('A brand new explanation.');
    });

    it('follows a language change of the shared texts', async () => {
        mountTip();
        setHelp(helpPayload('pl'));
        await nextTick();
        expect(bubble().textContent).toContain(helpPayload('pl').topics[TOPIC].text);
    });
});

describe('HelpTip administrator editing', () => {
    it('Ctrl+click opens the editor for an administrator and does not toggle the tip', async () => {
        installHelp('en', { admin: true });
        mountTip();
        button().dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, ctrlKey: true }));
        await nextTick();
        expect(editing.value).toBe(TOPIC);
        expect(visible()).toBe(false);
    });

    it('Cmd+click opens the editor too', async () => {
        installHelp('en', { admin: true });
        mountTip();
        button().dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, metaKey: true }));
        expect(editing.value).toBe(TOPIC);
    });

    it('opens the editor from the context menu event macOS sends for Ctrl+click, only with Ctrl held', async () => {
        installHelp('en', { admin: true });
        mountTip();
        const plain = new MouseEvent('contextmenu', { bubbles: true, cancelable: true });
        button().dispatchEvent(plain);
        expect(editing.value).toBeNull();
        expect(plain.defaultPrevented).toBe(false);
        const ctrl = new MouseEvent('contextmenu', { bubbles: true, cancelable: true, ctrlKey: true });
        button().dispatchEvent(ctrl);
        expect(editing.value).toBe(TOPIC);
        expect(ctrl.defaultPrevented).toBe(true);
    });

    it('a normal click toggles the tip for an administrator', async () => {
        installHelp('en', { admin: true });
        mountTip();
        button().click();
        await nextTick();
        expect(visible()).toBe(true);
        expect(editing.value).toBeNull();
    });

    it('gives an ordinary user no editor: Ctrl+click, Cmd+click and the context menu behave like a normal click', async () => {
        mountTip();
        button().dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, ctrlKey: true }));
        await nextTick();
        expect(editing.value).toBeNull();
        expect(visible()).toBe(true);
        button().dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, metaKey: true }));
        await nextTick();
        expect(editing.value).toBeNull();
        const ctrl = new MouseEvent('contextmenu', { bubbles: true, cancelable: true, ctrlKey: true });
        button().dispatchEvent(ctrl);
        expect(editing.value).toBeNull();
        expect(ctrl.defaultPrevented).toBe(false);
        expect(bubble().textContent).not.toContain('Ctrl+click');
    });

    it('tells an administrator how to edit, inside the tip', async () => {
        installHelp('en', { admin: true });
        mountTip();
        expect(bubble().textContent).toContain(loadMessages('en').help.admin_hint);
    });
});

describe('positionTip', () => {
    const viewport = { width: 400, height: 600 };
    it('goes below the mark and is centred on it', () => {
        const p = positionTip({ anchor: { top: 100, left: 190, width: 20, height: 20 }, bubble: { width: 200, height: 60 }, viewport });
        expect(p).toEqual({ top: 128, left: 100, width: 200, placement: 'bottom' });
    });
    it('flips above when there is no room below', () => {
        const p = positionTip({ anchor: { top: 560, left: 190, width: 20, height: 20 }, bubble: { width: 200, height: 80 }, viewport });
        expect(p.placement).toBe('top');
        expect(p.top).toBe(560 - 8 - 80);
    });
    it('shifts to stay inside the viewport on both sides', () => {
        const left = positionTip({ anchor: { top: 10, left: 0, width: 20, height: 20 }, bubble: { width: 300, height: 40 }, viewport });
        expect(left.left).toBe(8);
        const right = positionTip({ anchor: { top: 10, left: 390, width: 20, height: 20 }, bubble: { width: 300, height: 40 }, viewport });
        expect(right.left).toBe(400 - 8 - 300);
    });
    it('narrows a bubble wider than the viewport (320 px phones)', () => {
        const p = positionTip({ anchor: { top: 10, left: 100, width: 20, height: 20 }, bubble: { width: 500, height: 40 }, viewport: { width: 320, height: 568 } });
        expect(p.width).toBe(304);
        expect(p.left).toBe(8);
    });
    it('keeps a tall bubble on screen when neither side fits', () => {
        const p = positionTip({ anchor: { top: 300, left: 100, width: 20, height: 20 }, bubble: { width: 200, height: 700 }, viewport });
        expect(p.top).toBe(8);
    });
});

describe('HelpTip accessibility', () => {
    const cases = [];
    for (const locale of ['en', 'sk', 'pl']) for (const theme of ['light', 'dark']) cases.push([locale, theme]);

    it.each(cases)('has no axe violations, closed and open (%s, %s)', async (locale, theme) => {
        installHelp(locale);
        document.documentElement.dataset.theme = theme;
        wrapper = mountUi(HelpTip, { props: { topic: TOPIC }, locale });
        await expectNoAxeViolations(document.body);
        button().click();
        await nextTick();
        await expectNoAxeViolations(document.body);
    });

    it('survives 320 px and 40% longer text without violations, with the text wrapping', async () => {
        const pad = (s) => s + 'ŵ'.repeat(Math.ceil(s.length * 0.4));
        installHelp('en', { pad });
        window.innerWidth = 320;
        wrapper = mount(HelpTip, { props: { topic: TOPIC }, attachTo: document.body, global: { plugins: [createAppI18n('en', pseudoLong(loadMessages('en')))] } });
        button().click();
        await nextTick();
        await expectNoAxeViolations(document.body);
        expect(bubble().textContent.length).toBeGreaterThan(help.topics[TOPIC].text.length - 1);
    });

    it('declares the style rules the design needs (44 px target, reduced motion, forced colours, tokens only)', async () => {
        const { readFileSync } = await import('node:fs');
        const src = readFileSync(`${process.cwd()}/resources/js/Components/ui/HelpTip.vue`, 'utf8');
        expect(src).toContain('var(--control-touch)');
        expect(src).toContain('prefers-reduced-motion');
        expect(src).toContain('forced-colors');
        expect(src).toContain('var(--color-success)');
        expect(src).not.toMatch(/#[0-9a-fA-F]{3,8}\b/);
    });
});
