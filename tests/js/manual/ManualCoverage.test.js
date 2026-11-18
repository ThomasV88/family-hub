import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync } from 'node:fs';
import { MODULES } from '../../../resources/js/Shell/modules.js';
import { parseInline } from '../../../resources/js/Manual/inline.js';
import { LOCALES, readCategories, readTopic, topicFiles } from '../helpers/manual.js';

// Keeps the manual honest: it must follow the app. Adding a module or a Settings section without a manual topic fails here.
const ROOT = process.cwd();
const manual = Object.fromEntries(LOCALES.map((l) => [l, Object.fromEntries(topicFiles(l).map((f) => [f.replace(/\.json$/, ''), readTopic(l, f)]))]));
const en = manual.en;

const covered = (adminOnly) => new Set(
    Object.values(en).flatMap((tp) => [
        ...((tp.adminOnly === true) === adminOnly || !adminOnly ? (tp.covers ?? []).map((c) => ({ c, admin: tp.adminOnly === true })) : []),
        ...tp.sections.flatMap((s) => (s.covers ?? []).map((c) => ({ c, admin: tp.adminOnly === true || s.adminOnly === true }))),
    ]).filter((x) => !adminOnly || x.admin).map((x) => x.c),
);

const settingsPhp = readFileSync(`${ROOT}/app/Support/SettingsSections.php`, 'utf8');
const settingsKeys = [...settingsPhp.matchAll(/'key' => '(\w+)'/g)].map((m) => m[1]);
const adminSettingsKeys = [...settingsPhp.matchAll(/\$user->is_admin && [^{]*\{\s*\$sections\[\] = \['key' => '(\w+)'/g)].map((m) => m[1]);

// Features that are not a module or a Settings section but must be explained: the green question marks (issue 4).
const FEATURES = ['feature:help', 'feature:help-admin', 'feature:profile'];

describe('manual covers the app', () => {
    it('explains the green question marks to everyone and how administrators edit them in an administrator-only part', () => {
        expect(covered(false).has('feature:help'), 'no manual section covers the green question marks').toBe(true);
        expect(covered(true).has('feature:help-admin'), 'no administrator-only section covers editing the help texts').toBe(true);
        expect(covered(true).has('feature:help'), 'the question marks are for everyone, not administrator-only').toBe(false);
        expect(readFileSync(`${ROOT}/resources/help/topics.json`, 'utf8')).toContain('"topics"');
    });

    it('explains the profile page (name, photo, e-mail) to everyone and says who sees a photo', () => {
        expect(covered(false).has('feature:profile'), 'no manual topic covers the profile page').toBe(true);
        for (const l of LOCALES) {
            expect(manual[l].profile.sections.map((s) => s.id)).toEqual(['open-profile', 'change-name', 'photo', 'email']);
            expect(manual[l]['who-sees-what'].sections.some((s) => s.id === 'profile-photo'), `${l} who-sees-what profile-photo`).toBe(true);
        }
    });

    it('explains child accounts to administrators and a child list to everyone, with the same section ids in every language', () => {
        for (const l of LOCALES) {
            expect(manual[l].admin.sections.map((s) => s.id), `${l} admin`).toContain('children');
            expect(manual[l].wishlist.sections.map((s) => s.id), `${l} wishlist`).toContain('child-list');
        }
    });

    it('explains uploading many documents in the Health topic of every language, right after the documents section', () => {
        for (const l of LOCALES) {
            const ids = manual[l].health.sections.map((s) => s.id);
            expect(ids.indexOf('bulk-upload'), `${l} health bulk-upload`).toBe(ids.indexOf('documents') + 1);
        }
    });

    it('has a topic for every module in the navigation', () => {
        const all = covered(false);
        for (const m of MODULES) expect(all.has(`module:${m.key}`), `no manual topic covers module "${m.key}"`).toBe(true);
    });

    it('explains every Settings section, and every administrator section in an administrator-only part', () => {
        expect(settingsKeys.length).toBeGreaterThanOrEqual(8);
        expect(adminSettingsKeys.length).toBeGreaterThanOrEqual(5);
        const all = covered(false);
        for (const key of settingsKeys) expect(all.has(`settings:${key}`), `no manual topic covers Settings section "${key}"`).toBe(true);
        const admin = covered(true);
        for (const key of adminSettingsKeys) expect(admin.has(`settings:${key}`), `administrator section "${key}" is not in an administrator-only topic or section`).toBe(true);
    });

    it('only claims coverage of things that exist', () => {
        const valid = new Set([...MODULES.map((m) => `module:${m.key}`), ...settingsKeys.map((k) => `settings:${k}`), ...FEATURES]);
        for (const tp of Object.values(en)) for (const c of [...(tp.covers ?? []), ...tp.sections.flatMap((s) => s.covers ?? [])]) expect(valid.has(c), `${tp.id} covers unknown "${c}"`).toBe(true);
    });
});

describe('manual translations stay in step', () => {
    it('has the same files in every locale', () => {
        for (const l of LOCALES) expect(readdirSync(`${ROOT}/resources/manual/${l}`).sort()).toEqual(readdirSync(`${ROOT}/resources/manual/en`).sort());
    });

    it('has the same categories', () => {
        for (const l of LOCALES) expect(readCategories(l).map((c) => c.id)).toEqual(readCategories('en').map((c) => c.id));
    });

    it.each(Object.keys(en))('topic %s has the same structure in sk, pl and en', (id) => {
        const base = en[id];
        for (const l of ['sk', 'pl']) {
            const tr = manual[l][id];
            expect(tr.id, `${l}/${id}`).toBe(id);
            for (const key of ['category', 'order', 'adminOnly', 'covers'])
                expect(JSON.stringify(tr[key] ?? null), `${l}/${id}.${key}`).toBe(JSON.stringify(base[key] ?? null));
            expect(tr.sections.map((s) => [s.id, Boolean(s.adminOnly), s.blocks.length]), `${l}/${id} sections`).toEqual(base.sections.map((s) => [s.id, Boolean(s.adminOnly), s.blocks.length]));
            base.sections.forEach((s, i) =>
                s.blocks.forEach((b, j) => {
                    const t = tr.sections[i].blocks[j];
                    const where = `${l}/${id}/${s.id}[${j}]`;
                    expect(t.type, where).toBe(b.type);
                    expect(t.items?.length, `${where} items`).toBe(b.items?.length);
                    expect(t.rows?.length, `${where} rows`).toBe(b.rows?.length);
                    expect(t.columns?.length, `${where} columns`).toBe(b.columns?.length);
                    expect(Boolean(t.title), `${where} title`).toBe(Boolean(b.title));
                    // The same links, in the same order, so a translation can never point somewhere else.
                    expect(links(t), `${where} links`).toEqual(links(b));
                }),
            );
        }
    });
});

function strings(value, path = '') {
    if (typeof value === 'string') return [[path, value]];
    if (Array.isArray(value)) return value.flatMap((v, i) => strings(v, `${path}[${i}]`));
    if (value && typeof value === 'object') return Object.entries(value).flatMap(([k, v]) => strings(v, `${path}.${k}`));
    return [];
}
const blockText = (b) => [b.text, ...(b.items ?? []), ...(b.rows ?? []).flat(), ...(b.columns ?? [])].filter((s) => typeof s === 'string');
const links = (b) => blockText(b).flatMap((s) => parseInline(s).filter((x) => x.type === 'link').map((x) => JSON.stringify(x.target)));

describe('manual content is clean', () => {
    it.each(LOCALES)('%s has no empty strings, no HTML and no broken markup', (l) => {
        for (const [id, tp] of Object.entries(manual[l])) {
            for (const [path, s] of strings(tp)) {
                expect(s.trim(), `${l}/${id}${path} is empty`).not.toBe('');
                if (!/\.(id|type|category)$/.test(path)) expect(/<\/?[a-z][^>]*>/i.test(s), `${l}/${id}${path} contains HTML`).toBe(false);
            }
            for (const section of tp.sections)
                for (const block of section.blocks)
                    for (const s of blockText(block)) {
                        const leftovers = parseInline(s).filter((x) => x.type !== 'link' && (x.text.includes('**') || x.text.includes('](')));
                        expect(leftovers, `${l}/${id}/${section.id}: broken markup in "${s}"`).toEqual([]);
                    }
        }
        for (const c of readCategories(l)) expect(c.title.trim(), `${l} category ${c.id}`).not.toBe('');
    });

    it.each(LOCALES)('%s has unique ids, file names equal to topic ids and known block types', (l) => {
        const types = new Set(['p', 'steps', 'list', 'tip', 'warning', 'example', 'table']);
        const cats = new Set(readCategories(l).map((c) => c.id));
        for (const f of topicFiles(l)) {
            const tp = readTopic(l, f);
            expect(tp.id).toBe(f.replace(/\.json$/, ''));
            expect(cats.has(tp.category), `${l}/${tp.id} category`).toBe(true);
            expect(/^[a-z0-9-]+$/.test(tp.id)).toBe(true);
            const ids = tp.sections.map((s) => s.id);
            expect(new Set(ids).size, `${l}/${tp.id} duplicate section ids`).toBe(ids.length);
            for (const s of tp.sections) {
                expect(/^[a-z0-9-]+$/.test(s.id)).toBe(true);
                for (const b of s.blocks) expect(types.has(b.type), `${l}/${tp.id}/${s.id} type ${b.type}`).toBe(true);
            }
        }
    });

    it.each(LOCALES)('%s: every internal link resolves to an existing anchor or app page', (l) => {
        const routes = knownPaths();
        for (const [id, tp] of Object.entries(manual[l]))
            for (const section of tp.sections)
                for (const block of section.blocks)
                    for (const s of blockText(block))
                        for (const seg of parseInline(s).filter((x) => x.type === 'link')) {
                            const t = seg.target;
                            const where = `${l}/${id}/${section.id} -> ${JSON.stringify(t)}`;
                            if (t.kind === 'app') expect(routes.has(t.path), `${where}: no such page`).toBe(true);
                            else {
                                const target = manual[l][t.topic];
                                expect(target, `${where}: no such topic`).toBeTruthy();
                                if (t.section) expect(target.sections.some((x) => x.id === t.section), `${where}: no such section`).toBe(true);
                            }
                        }
    });

    it('links to administrator pages only from administrator-only parts', () => {
        const adminPaths = new Set(['/settings/members', '/settings/audit', '/settings/finance-accounts', '/settings/ai-calls', '/settings/ai-agents', '/settings/help']);
        for (const [id, tp] of Object.entries(en))
            for (const section of tp.sections)
                for (const block of section.blocks)
                    for (const s of blockText(block))
                        for (const seg of parseInline(s).filter((x) => x.type === 'link'))
                            if (seg.target.kind === 'app' && adminPaths.has(seg.target.path))
                                expect(tp.adminOnly === true || section.adminOnly === true, `${id}/${section.id} links to ${seg.target.path}`).toBe(true);
    });
});

// GET paths registered by the app (routes/web.php and routes/web/*.php), including those declared under Route::prefix(...).
function knownPaths() {
    const files = [`${ROOT}/routes/web.php`, ...readdirSync(`${ROOT}/routes/web`).map((f) => `${ROOT}/routes/web/${f}`)];
    const paths = new Set();
    for (const f of files) {
        const src = readFileSync(f, 'utf8');
        for (const m of src.matchAll(/Route::get\('([^']+)'/g)) paths.add(m[1]);
        for (const m of src.matchAll(/Route::prefix\('([^']+)'\)/g)) paths.add(m[1]);
    }
    return paths;
}
