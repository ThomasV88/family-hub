import { readFileSync, readdirSync } from 'node:fs';

export const MANUAL_DIR = `${process.cwd()}/resources/manual`;
export const LOCALES = ['en', 'sk', 'pl'];

export const topicFiles = (locale) => readdirSync(`${MANUAL_DIR}/${locale}`).filter((f) => f.endsWith('.json') && !f.startsWith('_')).sort();
export const readTopic = (locale, file) => JSON.parse(readFileSync(`${MANUAL_DIR}/${locale}/${file}`, 'utf8'));
export const readCategories = (locale) => JSON.parse(readFileSync(`${MANUAL_DIR}/${locale}/_categories.json`, 'utf8')).categories;

/** The same shape the server sends: no internal metadata, admin-only text only for an administrator. */
export function loadManual(locale, { admin = true } = {}) {
    const topics = topicFiles(locale)
        .map((f) => readTopic(locale, f))
        .filter((tp) => admin || !tp.adminOnly)
        .map((tp) => ({
            id: tp.id,
            category: tp.category,
            order: tp.order ?? 100,
            title: tp.title,
            summary: tp.summary,
            adminOnly: Boolean(tp.adminOnly),
            sections: tp.sections
                .filter((s) => admin || !s.adminOnly)
                .map((s) => ({ id: s.id, title: s.title, adminOnly: Boolean(s.adminOnly), blocks: s.blocks })),
        }))
        .sort((a, b) => a.order - b.order || a.id.localeCompare(b.id));
    const used = new Set(topics.map((tp) => tp.category));
    return { categories: readCategories(locale).filter((c) => used.has(c.id)), topics };
}

// Makes every visible string at least 40% longer, to prove the layout survives larger or longer text.
const pad = (s) => s + 'ŵ'.repeat(Math.max(1, Math.ceil(s.length * 0.4)));
export function padManual(manual) {
    const block = (b) => ({
        ...b,
        ...(b.text ? { text: pad(b.text) } : {}),
        ...(b.items ? { items: b.items.map(pad) } : {}),
        ...(b.rows ? { rows: b.rows.map((r) => r.map(pad)) } : {}),
    });
    return {
        categories: manual.categories.map((c) => ({ ...c, title: pad(c.title) })),
        topics: manual.topics.map((tp) => ({
            ...tp,
            title: pad(tp.title),
            summary: pad(tp.summary),
            sections: tp.sections.map((s) => ({ ...s, title: pad(s.title), blocks: s.blocks.map(block) })),
        })),
    };
}
