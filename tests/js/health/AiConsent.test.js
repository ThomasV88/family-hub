import { describe, it, expect, vi, afterEach } from 'vitest';
import { nextTick } from 'vue';
import { expectNoAxeViolations } from '../helpers/axe.js';
import { mountUi } from '../helpers/mount.js';

const { router } = vi.hoisted(() => ({ router: { patch: vi.fn() } }));
vi.mock('@inertiajs/vue3', () => ({ router }));

import AiConsentControl from '../../../resources/js/Health/AiConsentControl.vue';

const ID = '11111111-1111-4111-8111-111111111111';
let current;
function mountControl(props = {}, locale = 'en') {
    current = mountUi(AiConsentControl, { props: { personId: ID, value: false, canEdit: true, ...props }, locale });
    return current;
}
const flush = async () => { await nextTick(); await nextTick(); };
const sw = () => document.querySelector('[data-testid="ai-switch"]');
const click = async (el) => { el.click(); await flush(); };
afterEach(() => { current?.unmount(); current = null; document.body.innerHTML = ''; document.documentElement.classList.remove('dark'); vi.clearAllMocks(); });

describe('AiConsentControl', () => {
    it('shows the labelled switch, explanation to editors', async () => {
        mountControl();
        expect(document.body.textContent).toContain('Allow AI to read documents of this person');
        expect(document.body.textContent).not.toContain('AI reading is not available yet');
        const text = document.getElementById(sw().getAttribute('aria-describedby'));
        expect(text.textContent).toContain('Off by default');
        expect(sw().checked).toBe(false);
        await expectNoAxeViolations(document.body);
    });

    it('opens the dialog on turn-on with focus on Keep AI off and sends nothing on cancel', async () => {
        mountControl();
        await click(sw());
        expect(document.body.textContent).toContain('Allow AI for this person?');
        expect(document.body.textContent).toContain('Only turn this on if this person, or their parent or guardian, agrees');
        expect(document.activeElement?.textContent.trim()).toBe('Keep AI off');
        await expectNoAxeViolations(document.body);
        await click(document.querySelector('[data-testid="ai-keep-off"]'));
        expect(router.patch).not.toHaveBeenCalled();
        expect(sw().checked).toBe(false);
    });

    it('sends value=true on confirm and marks the switch busy', async () => {
        mountControl();
        await click(sw());
        await click(document.querySelector('[data-testid="ai-allow"]'));
        expect(router.patch).toHaveBeenCalledTimes(1);
        const [url, body] = router.patch.mock.calls[0];
        expect(url).toBe(`/health/people/${ID}/ai-consent`);
        expect(body).toEqual({ value: true });
        expect(sw().getAttribute('aria-busy')).toBe('true');
        router.patch.mock.calls[0][2].onSuccess();
        router.patch.mock.calls[0][2].onFinish();
        await flush();
        expect(sw().checked).toBe(true);
        expect(sw().getAttribute('aria-busy')).toBeNull();
        await expectNoAxeViolations(document.body);
    });

    it('turns off immediately without a dialog', async () => {
        mountControl({ value: true });
        expect(sw().checked).toBe(true);
        await click(sw());
        expect(document.body.textContent).not.toContain('Allow AI for this person?');
        expect(router.patch.mock.calls[0][1]).toEqual({ value: false });
    });

    it('shows the failure alert and keeps the previous state', async () => {
        mountControl({ value: true });
        await click(sw());
        router.patch.mock.calls[0][2].onError({});
        router.patch.mock.calls[0][2].onFinish();
        await flush();
        const alert = document.querySelector('[data-testid="ai-failed"]');
        expect(alert.textContent).toContain('We could not save this setting. Try again.');
        expect(alert.getAttribute('role')).toBe('alert');
        expect(sw().checked).toBe(true);
        await expectNoAxeViolations(document.body);
    });

    it('gives view-only viewers a read-only tag and no switch', async () => {
        mountControl({ canEdit: false, value: true });
        expect(sw()).toBeNull();
        expect(document.querySelector('[data-testid="ai-tag"]').textContent.trim()).toBe('AI: on');
        current.unmount();
        mountControl({ canEdit: false, value: false });
        expect(document.querySelector('[data-testid="ai-tag"]').textContent.trim()).toBe('AI: off');
        expect(document.body.textContent).toContain('Off by default');
        await expectNoAxeViolations(document.body);
    });

    it.each(['sk', 'pl'])('is axe clean in %s, light and dark', async (locale) => {
        for (const dark of [false, true]) {
            document.documentElement.classList.toggle('dark', dark);
            mountControl({}, locale);
            await expectNoAxeViolations(document.body);
            current.unmount();
        }
    });
});
