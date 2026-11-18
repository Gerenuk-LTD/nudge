import { describe, expect, it } from 'vitest';
import { formatDateTime, formatRelative } from './format';

const now = new Date('2026-11-15T12:00:00Z');
const ago = (seconds: number) =>
    new Date(now.getTime() - seconds * 1000).toISOString();

describe('formatRelative', () => {
    it('says just now for the last few seconds', () => {
        expect(formatRelative(ago(10), now)).toBe('just now');
    });

    it('counts minutes', () => {
        expect(formatRelative(ago(3 * 60), now)).toContain('3');
    });

    it('counts hours', () => {
        expect(formatRelative(ago(2 * 3600), now)).toContain('2');
    });

    it('describes yesterday', () => {
        const text = formatRelative(ago(86400), now);

        expect(text).toBeTruthy();
        expect(text).not.toBe('just now');
    });

    it('falls back to the date after a week', () => {
        const value = ago(10 * 86400);

        expect(formatRelative(value, now)).toBe(formatDateTime(value));
    });

    it('treats the future as just now', () => {
        expect(formatRelative(ago(-30), now)).toBe('just now');
    });

    it('returns null without a value', () => {
        expect(formatRelative(null, now)).toBeNull();
    });
});
