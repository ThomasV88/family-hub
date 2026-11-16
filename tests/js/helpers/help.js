import { readFileSync } from 'node:fs';
import { resetHelp, setHelp, setHelpAdmin } from '../../../resources/js/Help/helpStore.js';

export const HELP_DIR = `${process.cwd()}/resources/help`;
export const readTopics = () => JSON.parse(readFileSync(`${HELP_DIR}/topics.json`, 'utf8')).topics;
export const readHelpLocale = (locale) => JSON.parse(readFileSync(`${HELP_DIR}/${locale}.json`, 'utf8'));

/** The shared prop the server sends for a language, built from the registry default texts. */
export function helpPayload(locale = 'en', { pad = null } = {}) {
    const file = readHelpLocale(locale);
    const topics = {};
    for (const topic of readTopics()) {
        const e = file.topics[topic.key];
        topics[topic.key] = { title: pad ? pad(e.title) : e.title, text: pad ? pad(e.text) : e.text };
    }
    return { version: 1, locale, topics };
}

export function installHelp(locale = 'en', { admin = false, pad = null } = {}) {
    resetHelp();
    setHelp(helpPayload(locale, { pad }));
    setHelpAdmin(admin);
}

/** The `topics` prop of Settings > Contextual help, built from the registry the way HelpTexts::all() does. */
export function helpRows(labelLocale = 'en', { edited = {} } = {}) {
    const files = { sk: readHelpLocale('sk'), pl: readHelpLocale('pl'), en: readHelpLocale('en') };
    return readTopics().map((topic) => {
        const texts = {};
        const defaults = {};
        const titles = {};
        for (const l of ['sk', 'pl', 'en']) {
            defaults[l] = files[l].topics[topic.key].text;
            texts[l] = edited[topic.key]?.[l] ?? defaults[l];
            titles[l] = files[l].topics[topic.key].title;
        }
        return {
            ...topic,
            location: { area: files[labelLocale].areas[topic.area], screen: files[labelLocale].screens[topic.screen], field: titles[labelLocale] },
            titles,
            texts,
            defaults,
            edited: { sk: Boolean(edited[topic.key]?.sk), pl: Boolean(edited[topic.key]?.pl), en: Boolean(edited[topic.key]?.en) },
            editedAt: null,
        };
    });
}

export const helpAreas = (locale = 'en') => Object.entries(readHelpLocale(locale).areas).map(([id, label]) => ({ id, label }));
