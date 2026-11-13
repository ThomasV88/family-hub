export const FRAME_TABLET = 640;
export const FRAME_DESKTOP = 1024;
export const DOCK_MIN_MAIN = 900;

// Pure choice of detail panel presentation from the shell frame width and the main content width.
export function panelMode({ frameWidth, mainWidth }) {
    if (frameWidth < FRAME_TABLET) return 'sheet';
    if (frameWidth >= FRAME_DESKTOP && mainWidth >= DOCK_MIN_MAIN) return 'docked';
    return 'overlay';
}
