/**
 * Guards the browser's Back (and the iOS edge swipe) while a form holds unsaved work.
 *
 * Inertia's `before` event only sees visits the app starts, and `beforeunload` does not fire for a history step inside the
 * same document, so Back would drop a typed recipe or an unsaved import draft silently. The guard puts one sentinel entry
 * (the same URL and state) on top of the history as soon as the form is dirty. Pressing Back then only pops that sentinel:
 * the page stays, `onBlock` opens the "leave without saving?" dialog and nothing is lost.
 *   - `stay()` pushes the sentinel again, so the next Back is guarded too.
 *   - `leave()` goes one more step back, for real.
 * When the form is no longer dirty at the moment of Back, the step is simply repeated.
 *
 * Limits (browsers decide these, not this code): a tab that is closed, a reload, or a typed address is covered only by
 * `beforeunload`, which some mobile browsers (iOS Safari) show no prompt for; a Forward step or a long-press history jump
 * to an entry further away is not intercepted; and after a guarded form is left by a normal link or save, the visit
 * replaces the sentinel (`armed()` tells the caller to use `replace: true`) so the history does not gain a duplicate entry.
 */
export function useBackGuard({ active, onBlock, win = typeof window === 'undefined' ? null : window }) {
    let pushed = false;
    let bypass = false;

    function arm() {
        if (!win || pushed) return;
        win.history.pushState(win.history.state, '', win.location.href);
        pushed = true;
    }

    function onPop() {
        if (bypass || !pushed) return;
        // This Back consumed the sentinel: the page itself is still showing.
        pushed = false;
        if (active()) {
            onBlock();

            return;
        }
        bypass = true;
        win.history.go(-1);
    }

    function stay() {
        arm();
    }

    function leave() {
        bypass = true;
        win.history.go(-1);
    }

    /** True while the sentinel entry is on the history: the next visit should replace it. */
    const armed = () => pushed;

    function dispose() {
        win?.removeEventListener('popstate', onPop);
    }

    win?.addEventListener('popstate', onPop);

    return { arm, stay, leave, armed, dispose };
}
