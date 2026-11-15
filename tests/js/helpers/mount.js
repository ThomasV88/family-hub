import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { createAppI18n } from '../../../resources/js/i18n.js';

export const loadMessages = (locale) => JSON.parse(readFileSync(`${process.cwd()}/lang/${locale}.json`, 'utf8'));

// Mounts with i18n installed and attached to the body (needed by axe and Teleport). Callers unmount after each case.
export function mountUi(component, { props = {}, slots = {}, locale = 'en', attachTo = document.body, global = {} } = {}) {
    return mount(component, {
        props,
        slots,
        attachTo,
        global: { ...global, plugins: [createAppI18n(locale, loadMessages(locale)), ...(global.plugins ?? [])] },
    });
}
