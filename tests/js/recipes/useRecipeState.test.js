import { describe, it, expect, vi } from 'vitest';
import { useRecipeState } from '../../../resources/js/Components/Recipes/useRecipeState.js';

function deferredRequest() {
    const calls = [];
    const request = vi.fn((url, init) => new Promise((resolve) => { calls.push({ url, init, resolve }); }));

    return { request, calls };
}
const tick = () => new Promise((r) => setTimeout(r, 0));
const ok = { ok: true, status: 204 };

describe('useRecipeState', () => {
    it('seeds the confirmed state from the server', () => {
        const s = useRecipeState({ recipeId: 5, initial: { tickedIngredientIds: [1], doneStepIds: [9], favourite: true }, request: vi.fn() });
        expect([s.state('i:1'), s.state('i:2'), s.state('s:9'), s.state('fav')]).toEqual([true, false, true, true]);
    });

    it('sends two quick ticks on different rows one after another, in tap order, with absolute states', async () => {
        const { request, calls } = deferredRequest();
        const s = useRecipeState({ recipeId: 5, request });
        s.mark('i:1', true);
        s.mark('i:2', true);
        expect(s.state('i:1')).toBe(true);
        expect(s.state('i:2')).toBe(true);
        expect(calls).toHaveLength(1);
        expect(calls[0].url).toBe('/recipes/5/ingredients/1/tick');
        expect(calls[0].init).toEqual({ method: 'PUT', body: { ticked: true } });
        calls[0].resolve(ok);
        await tick();
        expect(calls).toHaveLength(2);
        expect(calls[1].url).toBe('/recipes/5/ingredients/2/tick');
        calls[1].resolve(ok);
        await tick();
        expect(s.busy.value).toBe(false);
        expect([s.state('i:1'), s.state('i:2')]).toEqual([true, true]);
    });

    it('uses the step and favourite endpoints', async () => {
        const { request, calls } = deferredRequest();
        const s = useRecipeState({ recipeId: 5, request });
        s.mark('s:7', true);
        calls[0].resolve(ok);
        await tick();
        s.mark('fav', true);
        expect([calls[0].url, calls[0].init.body]).toEqual(['/recipes/5/steps/7/done', { done: true }]);
        expect([calls[1].url, calls[1].init.body]).toEqual(['/recipes/5/favourite', { favourite: true }]);
    });

    it('rolls back only the failed row and reports it', async () => {
        const { request, calls } = deferredRequest();
        const onFail = vi.fn();
        const s = useRecipeState({ recipeId: 5, request, onFail });
        s.mark('i:1', true);
        s.mark('i:2', true);
        calls[0].resolve({ ok: false, status: 500 });
        await tick();
        expect(s.state('i:1')).toBe(false);
        expect(s.state('i:2')).toBe(true);
        expect(onFail).toHaveBeenCalledWith('i:1', 500);
        calls[1].resolve(ok);
        await tick();
        expect(s.state('i:2')).toBe(true);
        expect(onFail).toHaveBeenCalledTimes(1);
    });

    it('rolls back a network failure to the last confirmed state', async () => {
        const onFail = vi.fn();
        const s = useRecipeState({ recipeId: 5, initial: { tickedIngredientIds: [1] }, request: vi.fn().mockRejectedValue(new Error('x')), onFail });
        s.mark('i:1', false);
        await tick();
        expect(s.state('i:1')).toBe(true);
        expect(onFail).toHaveBeenCalledWith('i:1', 0);
    });

    it('a second tap on a row whose request is in flight sends its final absolute state after it', async () => {
        const { request, calls } = deferredRequest();
        const s = useRecipeState({ recipeId: 5, request });
        s.mark('i:1', true);
        s.mark('i:1', false);
        s.mark('i:1', true);
        expect(calls).toHaveLength(1);
        calls[0].resolve(ok);
        await tick();
        expect(calls).toHaveLength(2);
        expect(calls[1].init.body).toEqual({ ticked: true });
        expect(s.state('i:1')).toBe(true);
        calls[1].resolve(ok);
        await tick();
        expect(s.busy.value).toBe(false);
        expect(s.state('i:1')).toBe(true);
    });

    it('keeps the later tap shown when the earlier one failed, and rolls back to the confirmed state if the later one fails too', async () => {
        const { request, calls } = deferredRequest();
        const s = useRecipeState({ recipeId: 5, request });
        s.mark('i:1', true);
        s.mark('i:1', false);
        calls[0].resolve({ ok: false, status: 500 });
        await tick();
        expect(s.state('i:1')).toBe(false);
        calls[1].resolve({ ok: false, status: 500 });
        await tick();
        expect(s.state('i:1')).toBe(false);
    });

    it('send runs in order after queued marks and resolves with the result', async () => {
        const { request, calls } = deferredRequest();
        const s = useRecipeState({ recipeId: 5, request });
        s.mark('i:1', true);
        const sent = s.send('/recipes/5/note', 'PUT', { note: 'x' });
        expect(calls).toHaveLength(1);
        calls[0].resolve(ok);
        await tick();
        expect(calls[1].url).toBe('/recipes/5/note');
        calls[1].resolve(ok);
        await expect(sent).resolves.toEqual(ok);
    });

    it('reset clears every tick and done step once the server confirms, and keeps them when it fails', async () => {
        const { request, calls } = deferredRequest();
        const s = useRecipeState({ recipeId: 5, initial: { tickedIngredientIds: [1], doneStepIds: [2], favourite: true }, request });
        const failed = s.reset();
        expect(calls[0]).toMatchObject({ url: '/recipes/5/progress', init: { method: 'DELETE' } });
        calls[0].resolve({ ok: false, status: 500 });
        expect((await failed).ok).toBe(false);
        expect(s.state('i:1')).toBe(true);
        const done = s.reset();
        await tick();
        calls[1].resolve(ok);
        await done;
        expect([s.state('i:1'), s.state('s:2'), s.state('fav')]).toEqual([false, false, true]);
    });

    it('drained resolves at once when idle and after the last request otherwise', async () => {
        const { request, calls } = deferredRequest();
        const s = useRecipeState({ recipeId: 5, request });
        await s.drained();
        s.mark('i:1', true);
        let done = false;
        s.drained().then(() => { done = true; });
        await tick();
        expect(done).toBe(false);
        calls[0].resolve(ok);
        await tick();
        expect(done).toBe(true);
    });
});
