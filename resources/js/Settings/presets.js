// The grant table lives on the server (Preset::grants(), sent as the `presets` prop).
// This only reads it: no module levels are defined in JS.
export const MODULE_KEYS = ['todo', 'wishlist', 'finance', 'health'];

export function applyPreset(presetKey, presets) {
    const grants = presets?.[presetKey];
    if (!grants) return null;
    return Object.fromEntries(MODULE_KEYS.map((key) => [key, grants[key] ?? 'none']));
}
