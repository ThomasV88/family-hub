import { registerSW } from 'virtual:pwa-register';

// Prompt-style updates: the banner (PwaUpdateBanner.vue) listens for this event and the user decides when to reload.
if (typeof window !== 'undefined' && window.isSecureContext && 'serviceWorker' in navigator) {
    const updateSW = registerSW({
        onNeedRefresh() {
            window.dispatchEvent(new CustomEvent('fh:pwa-need-refresh', { detail: { update: () => updateSW(true) } }));
        },
    });
}
