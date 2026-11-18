import { describe, expect, it } from 'vitest';
import { defaultDocument, documentFromHtml } from './model';
import { renderEmail } from './render';

const importedHtml =
    '<!DOCTYPE html><html><head><style>.cta{color:red}@media (max-width:480px){.cta{display:block}}</style></head><body><a class="cta">Go</a></body></html>';

describe('renderEmail', () => {
    it('renders a full document with the container and footer', () => {
        const output = renderEmail(defaultDocument());

        expect(output).toContain('<!DOCTYPE html>');
        expect(output).toContain('class="email-container"');
        expect(output).toContain('width="600"');
        expect(output).toContain('Write your message here.');
        expect(output).toContain('unsubscribe');
    });

    it('escapes text in plain-text fields', () => {
        const document = defaultDocument();
        document.blocks = [
            {
                id: 'b',
                type: 'button',
                label: 'Fish & <Chips>',
                url: 'https://example.com',
                align: 'left',
                background: '#000000',
                color: '#ffffff',
            },
        ];
        const output = renderEmail(document);

        expect(output).toContain('Fish &amp; &lt;Chips&gt;');
        expect(output).not.toContain('<Chips>');
    });

    it('keeps the head of an imported HTML email when it is rendered again', () => {
        const output = renderEmail(documentFromHtml(importedHtml));

        expect(output).toContain('.cta{color:red}');
        expect(output).toContain('@media (max-width:480px)');
        expect(output).toContain('<a class="cta">Go</a>');
    });

    it('does not duplicate the head when builder output is imported again', () => {
        const once = renderEmail(documentFromHtml(importedHtml));
        const twice = renderEmail(documentFromHtml(once));

        expect(twice.split('.cta{color:red}')).toHaveLength(2);
        expect(twice.split('.email-container {')).toHaveLength(2);
        expect(twice.split('name="viewport"')).toHaveLength(2);
        expect(twice.split('charset=')).toHaveLength(2);
    });
});
