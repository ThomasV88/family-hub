import { describe, it, expect, vi, afterEach } from 'vitest';
import { b64uToBuf, bufToB64u, getAssertion, createCredential, passkeysSupported } from '../../../resources/js/Security/webauthn.js';

const bytes = (...n) => new Uint8Array(n).buffer;
const b64u = (...n) => bufToB64u(bytes(...n));

afterEach(() => {
    vi.unstubAllGlobals();
    delete window.PublicKeyCredential;
    delete navigator.credentials;
});

describe('base64url', () => {
    it('round trips arbitrary bytes without padding or url-unsafe characters', () => {
        const all = Uint8Array.from({ length: 256 }, (_, i) => i);
        const text = bufToB64u(all.buffer);
        expect(text).not.toMatch(/[+/=]/);
        expect([...new Uint8Array(b64uToBuf(text))]).toEqual([...all]);
    });
    it('handles empty and short input', () => {
        expect(bufToB64u(new ArrayBuffer(0))).toBe('');
        expect([...new Uint8Array(b64uToBuf('AQ'))]).toEqual([1]);
    });
});

describe('passkeysSupported', () => {
    it('is false without PublicKeyCredential and true with it', () => {
        expect(passkeysSupported()).toBe(false);
        window.PublicKeyCredential = function () {};
        Object.defineProperty(navigator, 'credentials', { value: {}, configurable: true });
        expect(passkeysSupported()).toBe(true);
    });
});

describe('getAssertion', () => {
    it('converts options to buffers and returns base64url strings', async () => {
        const get = vi.fn().mockResolvedValue({
            id: 'cred', rawId: bytes(1, 2), type: 'public-key',
            response: { clientDataJSON: bytes(3), authenticatorData: bytes(4), signature: bytes(5), userHandle: null },
        });
        Object.defineProperty(navigator, 'credentials', { value: { get }, configurable: true });
        const out = await getAssertion({ challenge: b64u(9, 9), rpId: 'x', allowCredentials: [{ type: 'public-key', id: b64u(7) }] });
        const arg = get.mock.calls[0][0].publicKey;
        expect(arg.challenge).toBeInstanceOf(ArrayBuffer);
        expect([...new Uint8Array(arg.challenge)]).toEqual([9, 9]);
        expect([...new Uint8Array(arg.allowCredentials[0].id)]).toEqual([7]);
        expect(arg.rpId).toBe('x');
        expect(out).toEqual({
            id: 'cred', rawId: b64u(1, 2), type: 'public-key',
            response: { clientDataJSON: b64u(3), authenticatorData: b64u(4), signature: b64u(5), userHandle: null },
        });
    });
    it('rethrows NotAllowedError as a cancelled error', async () => {
        const get = vi.fn().mockRejectedValue(new DOMException('no', 'NotAllowedError'));
        Object.defineProperty(navigator, 'credentials', { value: { get }, configurable: true });
        await expect(getAssertion({ challenge: b64u(1) })).rejects.toMatchObject({ name: 'cancelled' });
    });
});

describe('createCredential', () => {
    it('converts challenge, user id and excludeCredentials, returns attestation and transports', async () => {
        const create = vi.fn().mockResolvedValue({
            id: 'new', rawId: bytes(8), type: 'public-key',
            response: { clientDataJSON: bytes(1), attestationObject: bytes(2), getTransports: () => ['internal'] },
        });
        Object.defineProperty(navigator, 'credentials', { value: { create }, configurable: true });
        const out = await createCredential({
            challenge: b64u(5), rp: { name: 'FH' }, user: { id: b64u(6), name: 'a', displayName: 'A' },
            excludeCredentials: [{ type: 'public-key', id: b64u(4) }],
        });
        const arg = create.mock.calls[0][0].publicKey;
        expect([...new Uint8Array(arg.user.id)]).toEqual([6]);
        expect([...new Uint8Array(arg.excludeCredentials[0].id)]).toEqual([4]);
        expect(arg.rp).toEqual({ name: 'FH' });
        expect(out.response).toEqual({ clientDataJSON: b64u(1), attestationObject: b64u(2), transports: ['internal'] });
        expect(out.rawId).toBe(b64u(8));
    });
    it('rethrows NotAllowedError as cancelled and keeps other errors', async () => {
        const create = vi.fn().mockRejectedValueOnce(new DOMException('no', 'NotAllowedError')).mockRejectedValueOnce(new DOMException('dup', 'InvalidStateError'));
        Object.defineProperty(navigator, 'credentials', { value: { create }, configurable: true });
        const opts = { challenge: b64u(1), user: { id: b64u(1) } };
        await expect(createCredential(opts)).rejects.toMatchObject({ name: 'cancelled' });
        await expect(createCredential(opts)).rejects.toMatchObject({ name: 'InvalidStateError' });
    });
});
