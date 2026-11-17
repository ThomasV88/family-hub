// Pure helpers for the recipe overview filters. The server applies the filters; the client only builds the query and the chips.

export const emptyFilters = () => ({ q: '', ingredient: '', categories: [], author: null, show: 'all', favourites: false });

// Normalises whatever the server echoed back (or the address held) into a complete filter object.
export function normaliseFilters(raw = {}) {
    const f = raw ?? {};
    return {
        q: typeof f.q === 'string' ? f.q : '',
        ingredient: typeof f.ingredient === 'string' ? f.ingredient : '',
        categories: Array.isArray(f.categories) ? f.categories.map(String) : [],
        author: f.author === null || f.author === undefined || f.author === '' ? null : Number(f.author),
        show: ['mine', 'shared'].includes(f.show) ? f.show : 'all',
        favourites: f.favourites === true || f.favourites === 1 || f.favourites === '1',
    };
}

// Query parameters for the address: empty values are dropped, so the default view has a clean URL.
export function toQuery(filters, page = null) {
    const f = normaliseFilters(filters);
    const query = {};
    if (f.q.trim() !== '') query.q = f.q.trim();
    if (f.ingredient.trim() !== '') query.ingredient = f.ingredient.trim();
    if (f.categories.length > 0) query.categories = [...f.categories];
    if (f.author !== null && !Number.isNaN(f.author)) query.author = f.author;
    if (f.show !== 'all') query.show = f.show;
    if (f.favourites) query.favourites = 1;
    if (page !== null && page > 1) query.page = page;

    return query;
}

// The search box has its own field, so q is not counted; everything in the filter panel is.
export function activeCount(filters) {
    const f = normaliseFilters(filters);

    return f.categories.length + (f.author !== null ? 1 : 0) + (f.ingredient.trim() !== '' ? 1 : 0) + (f.show !== 'all' ? 1 : 0) + (f.favourites ? 1 : 0);
}

// One chip per active filter: { id, label, field, value }. `t` is the vue-i18n translate function.
export function activeChips(filters, options, t) {
    const f = normaliseFilters(filters);
    const chips = [];
    for (const name of f.categories) {
        chips.push({ id: `category:${name}`, label: t('recipes.filter.chip_category', { name }), field: 'categories', value: name });
    }
    if (f.author !== null) {
        const author = (options?.authors ?? []).find((a) => a.id === f.author);
        chips.push({ id: 'author', label: t('recipes.filter.chip_author', { name: author?.name ?? String(f.author) }), field: 'author', value: null });
    }
    if (f.ingredient.trim() !== '') {
        chips.push({ id: 'ingredient', label: t('recipes.filter.chip_ingredient', { name: f.ingredient.trim() }), field: 'ingredient', value: null });
    }
    if (f.show !== 'all') {
        chips.push({ id: 'show', label: t(f.show === 'mine' ? 'recipes.filter.show_mine' : 'recipes.filter.show_shared'), field: 'show', value: null });
    }
    if (f.favourites) {
        chips.push({ id: 'favourites', label: t('recipes.filter.favourites'), field: 'favourites', value: null });
    }

    return chips;
}

// The filters without the given chip.
export function withoutChip(filters, chip) {
    const f = normaliseFilters(filters);
    switch (chip.field) {
        case 'categories': return { ...f, categories: f.categories.filter((c) => c !== chip.value) };
        case 'author': return { ...f, author: null };
        case 'ingredient': return { ...f, ingredient: '' };
        case 'show': return { ...f, show: 'all' };
        case 'favourites': return { ...f, favourites: false };
        default: return f;
    }
}

// Clear filters keeps the search text out too: the whole view returns to its default.
export const cleared = () => emptyFilters();

// Minutes shown on a card: preparation plus cooking, or null when neither is set.
export function totalMinutes(recipe) {
    const prep = Number(recipe.prepMinutes) || 0;
    const cook = Number(recipe.cookMinutes) || 0;

    return prep + cook > 0 ? prep + cook : null;
}
