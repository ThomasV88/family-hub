import { describe, it, expect } from 'vitest';
import { panelMode } from '../../resources/js/design/panelMode.js';

describe('panelMode', () => {
    it.each([
        [390, 390, 'sheet'],
        [639, 639, 'sheet'],
        [820, 756, 'overlay'],
        [1100, 868, 'overlay'],
        [1280, 1048, 'docked'],
        [1024, 900, 'docked'],
    ])('frame %i main %i -> %s', (frameWidth, mainWidth, expected) => {
        expect(panelMode({ frameWidth, mainWidth })).toBe(expected);
    });
});
