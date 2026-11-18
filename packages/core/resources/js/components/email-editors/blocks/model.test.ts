import { describe, expect, it } from 'vitest';
import { asDocument, defaultSettings, documentFromHtml } from './model';

describe('asDocument', () => {
    it('returns null for a missing state', () => {
        expect(asDocument(null)).toBeNull();
    });

    it('fills in default settings', () => {
        const document = asDocument({ version: 1, blocks: [], settings: {} });

        expect(document?.settings).toEqual(defaultSettings);
    });
});

describe('documentFromHtml', () => {
    it('holds the body as one html block', () => {
        const document = documentFromHtml(
            '<html><body><p>Hi</p></body></html>',
        );

        expect(document.blocks).toHaveLength(1);
        expect(document.blocks[0]).toMatchObject({
            type: 'html',
            html: '<p>Hi</p>',
        });
    });

    it('leaves out head settings when there is no head', () => {
        const document = documentFromHtml('<p>Hi</p>');

        expect(document.settings.head).toBeUndefined();
    });
});
