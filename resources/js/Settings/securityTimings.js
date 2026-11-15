// Friendly input for the two re-confirmation windows: the form shows minutes or hours, the server stores seconds.

// Whole hours are shown in hours, everything else in minutes.
export function toDisplay(seconds) {
    const n = Number(seconds);
    if (!Number.isFinite(n) || n <= 0) return { value: '', unit: 'minutes' };
    return n % 3600 === 0 ? { value: String(n / 3600), unit: 'hours' } : { value: String(Math.round(n / 60)), unit: 'minutes' };
}

// A whole positive number in the given unit to seconds, or null when it is not a plain whole number.
export function fromDisplay(value, unit) {
    const text = String(value ?? '').trim();
    if (!/^\d{1,6}$/.test(text)) return null;
    const n = Number(text);
    if (n <= 0) return null;
    return n * (unit === 'hours' ? 3600 : 60);
}
