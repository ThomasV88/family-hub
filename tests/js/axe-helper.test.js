import { describe, it, expect, afterEach } from 'vitest';
import { expectNoAxeViolations } from './helpers/axe.js';

afterEach(() => {
    document.body.innerHTML = '';
});

describe('expectNoAxeViolations', () => {
    it('passes for a labelled button', async () => {
        document.body.innerHTML = '<main><button>Save</button></main>';
        await expect(expectNoAxeViolations(document.body)).resolves.toBeUndefined();
    });

    it('rejects for an unlabelled icon-only button', async () => {
        document.body.innerHTML = '<main><button></button></main>';
        await expect(expectNoAxeViolations(document.body)).rejects.toThrow(/button-name/);
    });
});
