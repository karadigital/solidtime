import { describe, expect, it } from 'vitest';
import { getPlaneIdentifier } from './plane';

describe('getPlaneIdentifier', () => {
    it('extracts the Plane work item identifier from a task name', () => {
        expect(getPlaneIdentifier('[KARA-47] Auto change')).toBe('KARA-47');
        expect(getPlaneIdentifier('[A1B-2] x')).toBe('A1B-2');
        expect(getPlaneIdentifier('[SUBE-12] Something')).toBe('SUBE-12');
    });

    it('returns null for task names without a leading identifier', () => {
        expect(getPlaneIdentifier('KARA-47 no brackets')).toBeNull();
        expect(getPlaneIdentifier('[-35] x')).toBeNull();
        expect(getPlaneIdentifier('Finish migration')).toBeNull();
    });
});
