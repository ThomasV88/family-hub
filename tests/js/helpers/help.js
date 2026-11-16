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
