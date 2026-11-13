// Shared, re-entrant body scroll lock. The original overflow value is saved on the 0 to 1 transition
// and restored on the 1 to 0 transition, so nested overlays cannot leave the page locked.
let count = 0;
let original = '';

export function lockScroll() {
    if (count === 0) {
        original = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
    }
    count += 1;
}

export function unlockScroll() {
    if (count === 0) return;
    count -= 1;
    if (count === 0) document.body.style.overflow = original;
}
