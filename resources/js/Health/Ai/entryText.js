// Pure formatting of one proposed entry (title, shown field rows). Shared by the single-document review
// (SuggestionEntry) and the batch review (BatchEntry). Everything returned is plain text for interpolation, never HTML.
// `i18n` is {t, te, locale} (locale is the vue-i18n locale string).

export const TYPE_SECTION = { visit: 'section_visits', medication: 'section_medications', allergy: 'section_allergies', vaccination: 'section_vaccinations' };

export const text = (value) => (typeof value === 'string' ? value.trim() : typeof value === 'number' ? String(value) : '');

export function formatDate(value, locale) {
    const raw = text(value);
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(raw);
    if (!m) return raw;
    return new Intl.DateTimeFormat(locale, { dateStyle: 'medium' }).format(new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])));
}

export function specialtyName(key, { t, te }) {
    const k = text(key);
    return k !== '' && te(`health.specialty.${k}`) ? t(`health.specialty.${k}`) : '';
}

export function visitSpecialty(fields, i18n) {
    const f = fields ?? {};
    return f.specialty_key === 'other' && text(f.specialty_other) !== '' ? text(f.specialty_other) : specialtyName(f.specialty_key, i18n);
}

export function entryTitle(type, fields, i18n) {
    const f = fields ?? {};
    switch (type) {
        case 'visit': return text(f.doctor) || visitSpecialty(f, i18n);
        case 'medication': return text(f.name);
        case 'allergy': return text(f.substance);
        default: return text(f.vaccine);
    }
}

// [{label, value}] of the non-empty fields, with the same labels as the Phase 7 forms.
export function entryRows(type, fields, i18n) {
    const { t, te, locale } = i18n;
    const f = fields ?? {};
    const list = [];
    const add = (label, value) => { if (text(value) !== '') list.push({ label: t(label), value: text(value) }); };
    const date = (value) => formatDate(value, locale);
    switch (type) {
        case 'visit':
            add('health.visit.date', date(f.visited_on));
            add('health.visit.doctor', f.doctor);
            add('health.visit.specialty', visitSpecialty(f, i18n));
            add('health.visit.diagnosis', f.diagnosis);
            add('health.visit.notes', f.notes);
            break;
        case 'medication':
            add('health.medication.dose', f.dose);
            add('health.medication.schedule', f.schedule);
            add('health.medication.start', date(f.started_on));
            add('health.medication.end', date(f.ended_on));
            add('health.visit.notes', f.notes);
            break;
        case 'allergy':
            add('health.allergy.reaction', f.reaction);
            add('health.allergy.severity', te(`health.severity.${text(f.severity)}`) ? t(`health.severity.${text(f.severity)}`) : '');
            break;
        default:
            add('health.vaccination.given_on', date(f.given_on));
            add('health.vaccination.next_due', date(f.next_due_on));
            add('health.visit.notes', f.notes);
    }
    return list;
}
