// Limits and text helpers shared by every Shopping screen. The server enforces the same numbers (config/shopping.php).

/** Longest list name, catalog item name and free-text item name, in characters (code points). */
export const NAME_MAX = 80;
/** Longest item note. */
export const NOTE_MAX = 200;
/** Most items one "Add items" request may carry. */
export const MAX_ITEMS = 50;

/** Length in characters (code points), the way the server counts them. An emoji is one, not two. */
export const charLength = (text) => Array.from(String(text ?? '')).length;

/** The first `max` characters, cut between code points so a surrogate pair is never split. */
export const clip = (text, max) => Array.from(String(text ?? '')).slice(0, Math.max(0, max)).join('');
