import { describe, expect, it } from 'vitest';
import { campaignVariables, fillVariables } from './merge-tags';

const builtIn = ['email', 'first_name', 'unsubscribe_url'];

describe('campaignVariables', () => {
    it('ignores built-in and custom field tags', () => {
        expect(
            campaignVariables(
                ['{{ first_name }} {{ custom.plan }} {{ email }}'],
                builtIn,
            ),
        ).toEqual([]);
    });

    it('counts a repeated tag once', () => {
        expect(
            campaignVariables(['{{ code }}', 'again {{code}}'], builtIn),
        ).toEqual([{ tag: 'code', optional: false }]);
    });

    it('marks a tag with a fallback as optional', () => {
        expect(
            campaignVariables(['{{ code | none }} and {{ date }}'], builtIn),
        ).toEqual([
            { tag: 'code', optional: true },
            { tag: 'date', optional: false },
        ]);
    });

    it('keeps first-seen order across texts', () => {
        expect(
            campaignVariables(['{{ b }}', '{{ a }} {{ b }}'], builtIn).map(
                (variable) => variable.tag,
            ),
        ).toEqual(['b', 'a']);
    });
});

describe('fillVariables', () => {
    const plain = { escapeHtml: false };

    it('fills a tag with its value', () => {
        expect(
            fillVariables(
                'Use {{ promo_code }} now',
                { promo_code: 'SPRING' },
                plain,
            ),
        ).toBe('Use SPRING now');
    });

    it('replaces a tag with a fallback whole', () => {
        expect(fillVariables('{{ code | none }}', { code: 'X' }, plain)).toBe(
            'X',
        );
    });

    it('leaves a tag with a missing or empty value', () => {
        expect(fillVariables('{{ code }}', {}, plain)).toBe('{{ code }}');
        expect(fillVariables('{{ code }}', { code: '' }, plain)).toBe(
            '{{ code }}',
        );
    });

    it('leaves contact tags alone', () => {
        expect(fillVariables('{{ first_name }}', {}, plain)).toBe(
            '{{ first_name }}',
        );
    });

    it('escapes values for HTML', () => {
        expect(
            fillVariables(
                '<p>{{ v }}</p>',
                { v: '<b>&' },
                { escapeHtml: true },
            ),
        ).toBe('<p>&lt;b&gt;&amp;</p>');
    });

    it('matches tags case-insensitively', () => {
        expect(
            fillVariables('{{ Promo_Code }}', { promo_code: 'A' }, plain),
        ).toBe('A');
    });
});
