// The manual's text is data, never HTML. Inline markup is limited to **bold** and [label](target):
// a target is an in-manual anchor ("topic" or "topic#section") or an in-app path that starts with a single "/".
// Anything else is shown as plain text, so content can never inject markup or an outside link.

const TOKEN = /\*\*([^*]+)\*\*|\[([^\]]+)\]\(([^)\s]+)\)/g;

/** @returns {{kind: 'anchor', topic: string, section: string|null} | {kind: 'app', path: string} | null} */
export function parseTarget(target) {
    if (/^\/(?!\/)[A-Za-z0-9/_-]*$/.test(target)) return { kind: 'app', path: target };
    const m = /^([a-z0-9-]+)(?:#([a-z0-9-]+))?$/.exec(target);
    return m ? { kind: 'anchor', topic: m[1], section: m[2] ?? null } : null;
}

export const topicAnchor = (topic) => `m-${topic}`;
export const sectionAnchor = (topic, section) => `m-${topic}--${section}`;

/** @returns {Array<{type: 'text'|'bold'|'link', text: string, target?: object}>} */
export function parseInline(text) {
    const out = [];
    let last = 0;
    for (const m of String(text).matchAll(TOKEN)) {
        if (m.index > last) out.push({ type: 'text', text: text.slice(last, m.index) });
        if (m[1] !== undefined) out.push({ type: 'bold', text: m[1] });
        else {
            const target = parseTarget(m[3]);
            out.push(target ? { type: 'link', text: m[2], target } : { type: 'text', text: m[2] });
        }
        last = m.index + m[0].length;
    }
    if (last < text.length) out.push({ type: 'text', text: text.slice(last) });
    return out;
}

/** Plain text of a string with the markup removed (for search). */
export const stripInline = (text) => parseInline(text).map((s) => s.text).join('');

/** All strings of a block, in reading order. */
export function blockStrings(block) {
    const parts = [];
    if (block.text) parts.push(block.text);
    if (block.title) parts.push(block.title);
    if (block.caption) parts.push(block.caption);
    for (const item of block.items ?? []) parts.push(item);
    for (const col of block.columns ?? []) parts.push(col);
    for (const row of block.rows ?? []) parts.push(...row);
    return parts;
}

// Lower-case and drop accents, so "zdravie" finds "Zdravie" and "zaciname" finds "Začíname".
export function fold(text) {
    return String(text).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/ł/g, 'l').replace(/đ/g, 'd');
}

export function sectionHaystack(section) {
    return fold([section.title, ...section.blocks.flatMap(blockStrings)].map(stripInline).join(' \n '));
}

/**
 * Filters topics by a search phrase. Every word must occur in the topic title or summary, or in the section.
 * @returns {Array<{topic: object, sections: object[]}>} topics that still have something to show.
 */
export function filterTopics(topics, query) {
    const words = fold(query).split(/\s+/).filter(Boolean);
    if (!words.length) return topics.map((topic) => ({ topic, sections: topic.sections }));
    const result = [];
    for (const topic of topics) {
        const head = fold(`${topic.title} ${topic.summary}`);
        const sections = topic.sections.filter((s) => {
            const hay = `${head} ${sectionHaystack(s)}`;
            return words.every((w) => hay.includes(w));
        });
        if (sections.length) result.push({ topic, sections });
    }
    return result;
}
