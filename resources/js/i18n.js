import { createI18n } from 'vue-i18n';

export const SUPPORTED_LOCALES = ['sk', 'pl', 'en'];
export const INTL_LOCALES = { sk: 'sk-SK', pl: 'pl-PL', en: 'en-GB' };

// Order of plural forms in the message strings, mapped from Intl.PluralRules categories.
const FORM_ORDER = {
    en: ['one', 'other'],
    sk: ['one', 'few', 'many', 'other'],
    pl: ['one', 'few', 'many', 'other'],
};

const TIME_ZONE = 'Europe/Bratislava';

// vue-i18n hands its own locale key straight to Intl.*Format, so the instance is keyed by the
// region-specific Intl locale (en-GB, sk-SK, pl-PL); that is what makes en print "5 Mar 2026".
function pluralRule(code) {
    const rules = new Intl.PluralRules(INTL_LOCALES[code]);
    const order = FORM_ORDER[code];
    return (choice, choicesLength) => {
        const index = Math.max(0, order.indexOf(rules.select(choice)));
        return Math.min(index, choicesLength - 1);
    };
}

const datetimeFormats = {};
const numberFormats = {};
const pluralRules = {};
for (const code of SUPPORTED_LOCALES) {
    const key = INTL_LOCALES[code];
    datetimeFormats[key] = { short: { dateStyle: 'medium', timeStyle: 'short', timeZone: TIME_ZONE } };
    numberFormats[key] = {
        integer: { maximumFractionDigits: 0 },
        decimal: { minimumFractionDigits: 1, maximumFractionDigits: 2 },
        percent: { style: 'percent', minimumFractionDigits: 1, maximumFractionDigits: 1 },
    };
    pluralRules[key] = pluralRule(code);
}

const normalize = (locale) => (SUPPORTED_LOCALES.includes(locale) ? locale : 'en');

export function createAppI18n(locale, messages) {
    const code = normalize(locale);
    return createI18n({
        legacy: false,
        locale: INTL_LOCALES[code],
        fallbackLocale: INTL_LOCALES.en,
        messages: { [INTL_LOCALES[code]]: messages },
        pluralRules,
        datetimeFormats,
        numberFormats,
    });
}

// Switch the active locale of an existing instance (used on Inertia navigation).
export function setAppLocale(i18n, locale, messages) {
    const code = normalize(locale);
    const key = INTL_LOCALES[code];
    i18n.global.setLocaleMessage(key, messages);
    i18n.global.locale.value = key;
    if (typeof document !== 'undefined') document.documentElement.lang = code;
}
