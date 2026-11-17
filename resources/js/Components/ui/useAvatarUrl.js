import { usePage } from '@inertiajs/vue3';

/**
 * URL of a member's private photo, or null when the shared `avatars` map ({userId: version}) has no entry.
 * Size 96 asks for the full variant, everything smaller the thumbnail. Safe to call outside an Inertia page
 * (component tests, style guide): then there is simply no photo.
 */
export function useAvatarUrl(userId, size = 40) {
    if (userId === null || userId === undefined) return null;
    let version;
    try {
        version = usePage()?.props?.avatars?.[userId];
    } catch {
        return null;
    }
    if (version === undefined || version === null) return null;

    return `/people/${userId}/photo/${size >= 96 ? 'full' : 'thumb'}?v=${version}`;
}
