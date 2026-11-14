// Hands an in-memory blob to the browser as a download. The object URL is revoked late: iPad and iPhone Safari resolve
// the blob lazily, so revoking right after click() makes the download fail or open an empty tab.
export const REVOKE_DELAY_MS = 30_000;

export function saveBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const link = window.document.createElement('a');
    link.href = url;
    link.download = filename ?? 'download';
    link.rel = 'noopener';
    window.document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), REVOKE_DELAY_MS);
}
