const FILLER = 'ŵ';

function pad(text) {
    // Pad each plain-text run (outside {tokens}) so the string grows by at least 40%.
    return text.replace(/(\{[^}]*\})|([^{]+)/g, (match, token, plain) => {
        if (token) return token;
        const extra = Math.max(1, Math.ceil(plain.trim().length * 0.4));
        return plain + FILLER.repeat(extra);
    });
}

function longer(text) {
    const out = text.includes(' | ') ? text.split(' | ').map(pad).join(' | ') : pad(text);
    return out;
}

// Deep copy of a messages object where every string is at least 40% longer.
export function pseudoLong(messages) {
    if (typeof messages === 'string') return longer(messages);
    if (Array.isArray(messages)) return messages.map(pseudoLong);
    if (messages && typeof messages === 'object') {
        return Object.fromEntries(Object.entries(messages).map(([k, v]) => [k, pseudoLong(v)]));
    }
    return messages;
}
