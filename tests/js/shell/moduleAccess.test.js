import { describe, it, expect } from 'vitest';
import { MODULES, visibleModules } from '../../../resources/js/Shell/modules.js';

const keys = (access) => visibleModules(access).map((m) => m.key);

describe('visibleModules', () => {
    it('links Finance to /finance and Health to /health (both built)', () => {
        expect(MODULES.find((m) => m.key === 'finances').href).toBe('/finance');
        expect(MODULES.find((m) => m.key === 'health').href).toBe('/health');
    });

    it('shows overview and tasks for a view-only todo grant', () => {
        expect(keys({ todo: 'view', wishlist: 'none', finance: 'none', health: 'none' })).toEqual(['overview', 'tasks']);
    });

    it('maps frontend keys to server modules', () => {
        expect(keys({ todo: 'none', wishlist: 'edit', finance: 'view', health: 'none' })).toEqual(['overview', 'wishlist', 'finances']);
        expect(keys({ todo: 'edit', wishlist: 'edit', finance: 'edit', health: 'edit' })).toEqual(['overview', 'tasks', 'wishlist', 'finances', 'health']);
    });

    it('keeps only overview when access is missing or empty', () => {
        expect(keys(undefined)).toEqual(['overview']);
        expect(keys({})).toEqual(['overview']);
    });

    it('ignores unknown levels', () => {
        expect(keys({ todo: 'admin' })).toEqual(['overview']);
    });
});
