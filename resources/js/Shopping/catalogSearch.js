// Accent- and case-insensitive matching for the catalog picker ("mliecko" finds "Mliečko", "JABLK" finds "Jablká").
// The catalog is already in the browser, so the search is client side. The Polish "ł" has no decomposition in Unicode, so it is
// folded to "l" by hand.

/** Lower-case, accent-free, trimmed text. */
export function normalize(text) {
    return String(text ?? '').normalize('NFD').replace(/\p{M}/gu, '').replace(/ł/gi, 'l').toLowerCase().trim();
}

/** True when the normalised name contains the normalised query. An empty query matches everything. */
export function matches(name, query) {
    const needle = normalize(query);

    return needle === '' || normalize(name).includes(needle);
}
