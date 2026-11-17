import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { createAppI18n } from '../../../resources/js/i18n.js';
import { loadMessages } from '../helpers/mount.js';
import { installHelp } from '../helpers/help.js';
import { pseudoLong } from '../helpers/pseudoLong.js';
import { resetHelp } from '../../../resources/js/Help/helpStore.js';

const { forms, behaviour } = vi.hoisted(() => ({ forms: [], behaviour: { next: null } }));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    return {
        useForm: (data) => {
            const form = reactive({
                ...data,
                processing: false,
                errors: {},
                post: vi.fn((url, opts) => {
                    const run = behaviour.next ?? ((o) => o.onSuccess?.());
                    behaviour.next = null;
                    run(opts, form);
                    opts.onFinish?.();
                }),
                clearErrors() { form.errors = {}; },
            });
            forms.push(form);
            return form;
        },
    };
});

import EmailListDialog from '../../../resources/js/Shopping/EmailListDialog.vue';

let current;
let closed;
function mountDialog({ locale = 'en', messages = null, open = true } = {}) {
    installHelp(locale);
    closed = false;
    current = mount(EmailListDialog, {
        props: { open, list: { id: 7, name: 'Weekly' }, 'onUpdate:open': () => { closed = true; } },
        attachTo: document.body,
        global: { plugins: [createAppI18n(locale, messages ?? loadMessages(locale))] },
    });
    return current;
}
const dialog = () => document.body.querySelector('[role="dialog"]');
const input = () => dialog().querySelector('[data-testid="mail-input"]');
const chips = () => [...dialog().querySelectorAll('[data-testid="mail-chip"]')].map((c) => c.querySelector('.mail__address').textContent);
const send = () => dialog().querySelector('[data-testid="mail-send"]');
async function type(text, key = null) {
    input().value = text;
    input().dispatchEvent(new Event('input'));
    await nextTick();
    if (key) input().dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }));
    await nextTick();
}
async function paste(text) {
    const event = new Event('paste', { bubbles: true, cancelable: true });
    event.clipboardData = { getData: () => text };
    input().dispatchEvent(event);
    await nextTick();
}
const submit = async () => {
    dialog().querySelector('form#email-list-form').dispatchEvent(new Event('submit'));
    await flushPromises();
};

beforeEach(() => { forms.length = 0; behaviour.next = null; });
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; resetHelp(); delete document.documentElement.dataset.theme; });

describe('EmailListDialog', () => {
    it('turns typed and pasted addresses into chips and ignores duplicates', async () => {
        mountDialog();
        expect(send().disabled).toBe(true);
        await type('a@example.test,', ',');
        await paste('b@example.test c@example.test');
        await paste('A@example.test');
        expect(chips()).toEqual(['a@example.test', 'b@example.test', 'c@example.test']);
        expect(send().disabled).toBe(false);
    });

    it('commits on blur and removes a chip', async () => {
        mountDialog();
        await type('a@example.test');
        input().dispatchEvent(new Event('blur'));
        await nextTick();
        expect(chips()).toEqual(['a@example.test']);
        dialog().querySelector('button[aria-label="Remove a@example.test"]').click();
        await nextTick();
        expect(chips()).toEqual([]);
        expect(send().disabled).toBe(true);
    });

    it('accepts no sixth address, hides the field and shows the hint', async () => {
        mountDialog();
        await paste('a@example.test b@example.test c@example.test d@example.test e@example.test f@example.test');
        expect(chips()).toHaveLength(5);
        expect(input()).toBeNull();
        expect(dialog().textContent).toContain('You can send to at most 5 addresses at a time.');
    });

    it('keeps an invalid address in the field with an error', async () => {
        mountDialog();
        await type('foo', 'Enter');
        expect(chips()).toEqual([]);
        expect(input().value).toBe('foo');
        expect(dialog().textContent).toContain('“foo” is not a valid e-mail address.');
        expect(send().disabled).toBe(true);
        await type('foo@example.test', 'Enter');
        expect(chips()).toEqual(['foo@example.test']);
    });

    it('posts the addresses and closes on success', async () => {
        mountDialog();
        await paste('a@example.test b@example.test');
        await submit();
        expect(forms[0].post).toHaveBeenCalledTimes(1);
        expect(forms[0].post.mock.calls[0][0]).toBe('/shopping/lists/7/mail');
        expect(forms[0].addresses).toEqual(['a@example.test', 'b@example.test']);
        expect(closed).toBe(true);
    });

    it('commits a valid address still in the field when sending', async () => {
        mountDialog();
        await type('x@example.test');
        expect(send().disabled).toBe(false);
        await submit();
        expect(forms[0].addresses).toEqual(['x@example.test']);
    });

    it('shows the limit message for a 429 and does not close', async () => {
        mountDialog();
        await paste('a@example.test');
        behaviour.next = (opts) => opts.onHttpException({ status: 429, data: { error: 'shopping.mail.err_limit' } });
        await submit();
        expect(dialog().querySelector('[data-testid="mail-limited"]').textContent).toBe('You have sent too many lists today. Try again later.');
        expect(closed).toBe(false);
        expect(send().disabled).toBe(false);
    });

    it('does not let Inertia show its error window for the 429 (the callback returns false)', async () => {
        mountDialog();
        await paste('a@example.test');
        let returned;
        behaviour.next = (opts) => { returned = opts.onHttpException({ status: 429, data: {} }); };
        await submit();
        expect(returned).toBe(false);
    });

    it('maps per-address server errors to the chip and shows a generic failure for other statuses', async () => {
        mountDialog();
        await paste('a@example.test b@example.test');
        behaviour.next = (opts) => opts.onError({ 'addresses.1': 'shopping.mail.err_invalid' });
        await submit();
        expect(dialog().textContent).toContain('“b@example.test” is not a valid e-mail address.');
        expect(dialog().querySelectorAll('.mail__chip--bad')).toHaveLength(1);
        behaviour.next = (opts) => opts.onHttpException({ status: 500, data: '' });
        await submit();
        expect(dialog().querySelector('[data-testid="mail-failed"]')).not.toBeNull();
    });

    it('shows the list-level server errors translated', async () => {
        mountDialog();
        await paste('a@example.test');
        behaviour.next = (opts) => opts.onError({ addresses: 'shopping.mail.err_too_many' });
        await submit();
        expect(dialog().textContent).toContain('You can send to at most 5 addresses at a time.');
    });

    it('explains itself with the help tip next to the preview line', () => {
        mountDialog();
        expect(dialog().querySelectorAll('.help-tip__button')).toHaveLength(1);
        expect(dialog().textContent).toContain('The e-mail lists the items with quantities, the store and the responsible person.');
    });

    it.each(['en', 'sk', 'pl'])('has no axe violations (%s, light and dark, long text, with chips, error and limit)', async (locale) => {
        for (const theme of ['light', 'dark']) {
            document.documentElement.dataset.theme = theme;
            const messages = JSON.parse(JSON.stringify(loadMessages(locale)));
            for (const key of ['title', 'field', 'preview', 'send', 'cancel']) messages.shopping.mail[key] = pseudoLong(messages.shopping.mail[key]);
            mountDialog({ locale, messages });
            await paste('a@example.test b@example.test');
            behaviour.next = (opts) => opts.onHttpException({ status: 429, data: {} });
            await submit();
            await expectNoAxeViolations(document.body);
            await type('foo', 'Enter');
            await expectNoAxeViolations(document.body);
            current.unmount();
            document.body.innerHTML = '';
        }
    });
});
