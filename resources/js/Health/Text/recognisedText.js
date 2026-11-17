// Text of a document as the member copies it: the whole text, each page after the first introduced by a blank line and
// its page label ("Page 2"), so pages stay distinguishable. A single page is just its text. Pure; nothing is stored or
// logged here, and the text only ever lives in the component that fetched it.
export function copyFormat(pages, pageLabel = (n) => `Page ${n}`) {
    const list = Array.isArray(pages) ? pages : [];
    if (list.length === 0) return '';
    return list
        .map((page, index) => (index === 0 ? String(page.text ?? '') : `\n\n${pageLabel(page.page ?? index + 1)}\n${String(page.text ?? '')}`))
        .join('');
}

// The language hint is passed to the text region only when it is one the app knows.
export const textLang = (language) => (['sk', 'pl', 'en'].includes(language) ? language : undefined);
