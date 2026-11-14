// Small pure helpers for the document screens. Nothing here stores or fetches anything.

// "1.2 MB" style size in the active locale (decimal units, like the server limit in MB).
export function formatSize(bytes, locale = 'en') {
    const n = Number(bytes) || 0;
    const fmt = (v, unit) => `${new Intl.NumberFormat(locale, { maximumFractionDigits: 1 }).format(v)} ${unit}`;
    if (n >= 1048576) return fmt(n / 1048576, 'MB');
    if (n >= 1024) return fmt(n / 1024, 'KB');
    return fmt(n, 'B');
}

export const documentUrl = (personId, documentId, tail) => `/health/people/${personId}/documents/${documentId}/${tail}`;

// "Dr. Kova, Dentist" style option text for the visit selects.
export function visitLabel(visit, t) {
    const specialty = visit.specialtyKey === 'other' && visit.specialtyOther ? visit.specialtyOther : t(`health.specialty.${visit.specialtyKey}`);
    const [y, m, d] = visit.visitedOn.split('-').map(Number);
    const date = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(y, m - 1, d));
    return `${date}, ${visit.doctor || specialty}`;
}
