// Minimal browser WebAuthn helper, hand-written so no npm package is needed. Server options carry base64url
// challenge and ids; results go back as base64url strings in the standard WebAuthn JSON shape.

export function b64uToBuf(value) {
    const s = String(value).replace(/-/g, '+').replace(/_/g, '/');
    const bin = atob(s.padEnd(Math.ceil(s.length / 4) * 4, '='));
    const bytes = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
    return bytes.buffer;
}

export function bufToB64u(buffer) {
    const bytes = new Uint8Array(buffer);
    let bin = '';
    for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
    return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

export const passkeysSupported = () =>
    typeof window !== 'undefined' && !!window.PublicKeyCredential && typeof navigator !== 'undefined' && !!navigator.credentials;

const toDescriptors = (list) => (list ?? []).map((c) => ({ ...c, id: b64uToBuf(c.id) }));

// A closed or declined browser prompt is a quiet cancel, not an error.
function normalize(error) {
    if (error && (error.name === 'NotAllowedError' || error.name === 'AbortError')) {
        const cancelled = new Error('cancelled');
        cancelled.name = 'cancelled';
        return cancelled;
    }
    return error;
}

// options: the server's WebAuthn request options (base64url challenge and credential ids).
export async function getAssertion(options) {
    const publicKey = { ...options, challenge: b64uToBuf(options.challenge), allowCredentials: toDescriptors(options.allowCredentials) };
    let cred;
    try {
        cred = await navigator.credentials.get({ publicKey });
    } catch (error) {
        throw normalize(error);
    }
    return {
        id: cred.id,
        rawId: bufToB64u(cred.rawId),
        type: cred.type,
        response: {
            clientDataJSON: bufToB64u(cred.response.clientDataJSON),
            authenticatorData: bufToB64u(cred.response.authenticatorData),
            signature: bufToB64u(cred.response.signature),
            userHandle: cred.response.userHandle ? bufToB64u(cred.response.userHandle) : null,
        },
    };
}

// options: the server's registration options (base64url challenge, user.id and excludeCredentials ids).
export async function createCredential(options) {
    const publicKey = {
        ...options,
        challenge: b64uToBuf(options.challenge),
        user: { ...options.user, id: b64uToBuf(options.user.id) },
        excludeCredentials: toDescriptors(options.excludeCredentials),
    };
    let cred;
    try {
        cred = await navigator.credentials.create({ publicKey });
    } catch (error) {
        throw normalize(error);
    }
    return {
        id: cred.id,
        rawId: bufToB64u(cred.rawId),
        type: cred.type,
        response: {
            clientDataJSON: bufToB64u(cred.response.clientDataJSON),
            attestationObject: bufToB64u(cred.response.attestationObject),
            transports: cred.response.getTransports?.() ?? [],
        },
    };
}
