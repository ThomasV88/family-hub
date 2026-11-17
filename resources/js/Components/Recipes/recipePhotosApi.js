import { securityFetch } from '../../Security/securityFetch.js';
import { failureKey, firstMessage } from './recipeFailure.js';

// JSON endpoints of the recipe photos (12-04). Every call resolves and never throws:
//   { ok: true, status, photos: [{ id, position, isMain, version }], photo? }
//   { ok: false, status, messageKey } (messageKey is a lang key: the server's image message on 422, a recipes.error.* key otherwise)
// Same-origin only; the XSRF header comes from the cookie, as in securityFetch.

export const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

const base = (recipeId) => `/recipes/${Number(recipeId)}/photos`;
export const photoUrl = (recipeId, photo, size = 'thumb') => `${base(recipeId)}/${photo.id}/${size}?v=${photo.version}`;

function xsrfToken() {
    const match = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));

    return match ? decodeURIComponent(match.slice('XSRF-TOKEN='.length)) : '';
}

function failure(status, data) {
    const image = firstMessage(data?.errors?.image);
    const known = status === 422 && image.startsWith('recipes.image.') ? image : null;

    return { ok: false, status, messageKey: known ?? (status === 422 ? 'recipes.image.err_type' : failureKey(status, 'recipes.image.err_write')) };
}

/** Multipart upload of one image (field `image`). 201 gives the stored photo and the whole list. */
export async function uploadPhoto(recipeId, file) {
    const body = new FormData();
    body.append('image', file);
    let response;
    try {
        response = await fetch(base(recipeId), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
            body,
        });
    } catch {
        return failure(0, null);
    }
    const data = await response.json().catch(() => ({}));
    if (!response.ok) return failure(response.status, data);

    return { ok: true, status: response.status, photo: data.photo, photos: data.photos ?? [] };
}

async function call(url, method, body) {
    const result = await securityFetch(url, { method, body });
    if (!result.ok) return failure(result.status, result.data);

    return { ok: true, status: result.status, photos: result.data?.photos ?? [] };
}

export const makeMain = (recipeId, photoId) => call(`${base(recipeId)}/${Number(photoId)}/main`, 'PUT');
/** direction: 'earlier' or 'later'. */
export const movePhoto = (recipeId, photoId, direction) => call(`${base(recipeId)}/${Number(photoId)}/position`, 'PUT', { direction });
export const removePhoto = (recipeId, photoId) => call(`${base(recipeId)}/${Number(photoId)}`, 'DELETE');

/**
 * Uploads queued files one by one in order (create flow, after the recipe exists), then makes `mainKey` main when it is not the
 * first stored photo. A failure of one file does not stop the others, except when the server refuses everything
 * (403, 404, 429), which counts the rest as failed. Resolves { failed, mainFailed }: the number of files not stored, and whether
 * the member's chosen main photo could not be made main although every file was stored (the first stored photo is main then).
 */
export async function uploadQueue(recipeId, queue, mainKey) {
    const stored = [];
    let failed = 0;
    for (let i = 0; i < queue.length; i += 1) {
        const result = await uploadPhoto(recipeId, queue[i].file);
        if (result.ok) {
            stored.push({ key: queue[i].key, id: result.photo?.id });
        } else {
            failed += 1;
            if ([403, 404, 429].includes(result.status)) {
                failed += queue.length - i - 1;
                break;
            }
        }
    }
    const chosen = stored.find((s) => s.key === mainKey);
    let mainFailed = false;
    if (chosen && stored[0] && chosen.key !== stored[0].key && chosen.id) {
        const result = await makeMain(recipeId, chosen.id);
        mainFailed = !result?.ok;
    }

    return { failed, mainFailed };
}
