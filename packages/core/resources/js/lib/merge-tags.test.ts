import { describe, expect, it } from 'vitest';
import { campaignVariables } from './merge-tags';

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
