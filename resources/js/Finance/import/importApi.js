// Preview call of the import wizard. The browser re-sends the File held in memory on every settings change; the
// server decodes it and answers JSON, nothing is stored on either side. Called only from user actions, never on mount.

export const EXTENSIONS = ['csv', 'txt'];

function xsrfToken() {
    const match = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.slice('XSRF-TOKEN='.length)) : '';
}

// Client checks mirror the server and are never authoritative. Returns an error code or null.
export function checkFile(file, maxBytes) {
    const ext = (file?.name ?? '').split('.').pop().toLowerCase();
    if (!file || !EXTENSIONS.includes(ext)) return 'type';
    if (file.size > maxBytes) return 'size';
    return null;
}

// settings: { encoding?, delimiter?, headerRow? } (all optional). Tab is sent as the word "tab" because the
// request trims whitespace-only values.
export async function preview(file, settings = {}) {
    const body = new FormData();
    body.append('file', file);
    if (settings.encoding) body.append('encoding', settings.encoding);
    if (settings.delimiter) body.append('delimiter', settings.delimiter === '\t' ? 'tab' : settings.delimiter);
    if (settings.headerRow !== undefined && settings.headerRow !== null && settings.headerRow !== '') body.append('header_row', String(settings.headerRow));

    try {
        const response = await fetch('/finance/import/preview', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
            body,
        });
        const data = await response.json().catch(() => ({}));
        if (response.ok) return { ok: true, data };
        return { ok: false, error: data?.error ?? 'decode_failed' };
    } catch {
        return { ok: false, error: 'decode_failed' };
    }
}

// 'd.m.Y' -> 'DD.MM.YYYY' for the date format select.
export function dateFormatLabel(format) {
    const map = { d: 'DD', j: 'D', m: 'MM', n: 'M', Y: 'YYYY', y: 'YY' };
    return [...format].map((c) => map[c] ?? c).join('');
}

const DELIMITER_KEYS = { ';': 'sep_semicolon', ',': 'sep_comma', '\t': 'sep_tab', '|': 'sep_pipe' };
export const delimiterKey = (d) => DELIMITER_KEYS[d] ?? 'sep_semicolon';

const ENCODING_KEYS = { 'UTF-8': 'enc_utf8', 'UTF-16LE': 'enc_utf16', 'UTF-16BE': 'enc_utf16', 'WINDOWS-1250': 'enc_1250', 'ISO-8859-2': 'enc_88592', CP852: 'enc_852' };
export function encodingLabel(encoding, t) {
    const base = t(`finance.imp.${ENCODING_KEYS[encoding] ?? 'enc_utf8'}`);
    if (encoding === 'UTF-16LE') return `${base} LE`;
    if (encoding === 'UTF-16BE') return `${base} BE`;
    return base;
}

// Server messages come back as raw keys ("finance.imp.err_size"); anything else is shown generically.
export function serverMessage(value, t, params = {}) {
    return typeof value === 'string' && value.startsWith('finance.') ? t(value, params) : t('finance.imp.err_read');
}
