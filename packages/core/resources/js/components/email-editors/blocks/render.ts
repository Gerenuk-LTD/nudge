import type { CSSProperties } from 'react';
import { COLUMN_LAYOUTS } from './model';
import type { Block, EmailDocument, EmailSettings } from './model';

/** Space either side of the content, inside the email. */
export const GUTTER = 32;

/** Space between columns. */
export const COLUMN_GAP = 16;

/** The content width in pixels inside the email at full width. */
export function contentWidth(settings: EmailSettings): number {
    return settings.width - GUTTER * 2;
}

/** The width in pixels inside one column of a layout at full width. */
export function columnWidth(settings: EmailSettings, share: number): number {
    return Math.round(
        ((contentWidth(settings) + COLUMN_GAP) * share) / 100 - COLUMN_GAP,
    );
}

const MUTED = '#71717a';

/**
 * Each block's cell styles. The editing canvas and the sent HTML both use these, so what you edit is what is sent.
 */
export function cellStyle(block: Block, nested = false): CSSProperties {
    // Inside a column the column itself provides the side spacing.
    const side = nested ? 0 : GUTTER;
    const padding: Record<Block['type'], string> = {
        heading: `20px ${side}px 8px`,
        text: `8px ${side}px`,
        button: `16px ${side}px`,
        image: `12px ${side}px`,
        divider: `16px ${side}px`,
        spacer: '0',
        footer: `24px ${side}px 32px`,
        columns: `8px ${GUTTER - COLUMN_GAP / 2}px`,
        // Hand-written HTML brings its own spacing.
        html: '0',
    };

    return {
        padding: padding[block.type],
        textAlign: 'align' in block ? block.align : undefined,
    };
}

export function headingStyle(
    level: 1 | 2 | 3,
    settings: EmailSettings,
): CSSProperties {
    const sizes = { 1: [28, 1.25], 2: [22, 1.3], 3: [18, 1.4] } as const;

    return {
        margin: 0,
        fontFamily: settings.font,
        fontSize: sizes[level][0],
        lineHeight: sizes[level][1],
        fontWeight: 700,
        color: settings.text,
    };
}

export function textStyle(settings: EmailSettings): CSSProperties {
    return {
        fontFamily: settings.font,
        fontSize: 16,
        lineHeight: 1.6,
        color: settings.text,
    };
}

export function footerStyle(settings: EmailSettings): CSSProperties {
    return {
        fontFamily: settings.font,
        fontSize: 12,
        lineHeight: 1.6,
        color: MUTED,
    };
}

export function buttonStyle(
    block: Extract<Block, { type: 'button' }>,
    settings: EmailSettings,
): CSSProperties {
    return {
        display: 'inline-block',
        padding: '12px 24px',
        borderRadius: 6,
        background: block.background,
        color: block.color,
        fontFamily: settings.font,
        fontSize: 15,
        fontWeight: 700,
        lineHeight: 1.2,
        textDecoration: 'none',
    };
}

/** The image's width in pixels at the email's full width. */
export function imageWidth(
    block: Extract<Block, { type: 'image' }>,
    available: number,
): number {
    return Math.round((available * block.width) / 100);
}

/** The words a footer always ends with: the sender's postal address and an unsubscribe link, both required by law. */
export const FOOTER_TAGS =
    '{{ postal_address }}<br><a href="{{ unsubscribe_url }}">Unsubscribe</a>';

function escape(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function css(style: CSSProperties): string {
    return Object.entries(style)
        .filter(([, value]) => value !== undefined && value !== '')
        .map(([key, value]) => {
            const property = key.replace(
                /[A-Z]/g,
                (c) => `-${c.toLowerCase()}`,
            );
            const unitless = ['lineHeight', 'fontWeight'].includes(key);

            return `${property}:${typeof value === 'number' && !unitless ? `${value}px` : value}`;
        })
        .join(';');
}

/**
 * Inline styles for the editor's rich text, since many inboxes ignore style sheets: paragraph spacing, list indents
 * and link colours. The last paragraph has no space under it.
 */
function styleRichText(html: string, settings: EmailSettings): string {
    const styled = html
        .replace(/<p>/g, '<p style="margin:0 0 12px">')
        .replace(/<(ul|ol)>/g, '<$1 style="margin:0 0 12px;padding-left:24px">')
        .replace(/<li>/g, '<li style="margin:0 0 4px">')
        .replace(
            /<li style="margin:0 0 4px"><p style="margin:0 0 12px">/g,
            '<li style="margin:0 0 4px"><p style="margin:0">',
        )
        .replace(
            /<a /g,
            `<a style="color:${settings.link};text-decoration:underline" `,
        );
    const last = styled.lastIndexOf('<p style="margin:0 0 12px">');

    return last === -1
        ? styled
        : styled.slice(0, last) +
              '<p style="margin:0">' +
              styled.slice(last + '<p style="margin:0 0 12px">'.length);
}

function renderRows(
    blocks: Block[],
    settings: EmailSettings,
    available: number,
    nested: boolean,
): string {
    return blocks
        .map((block) => {
            const content = renderBlock(block, settings, available);

            return content === ''
                ? ''
                : `<tr><td style="${css(cellStyle(block, nested))}">${content}</td></tr>`;
        })
        .join('\n');
}

function renderBlock(
    block: Block,
    settings: EmailSettings,
    available: number,
): string {
    switch (block.type) {
        case 'columns': {
            const shares = COLUMN_LAYOUTS[block.layout];
            const cells = block.columns
                .map((column, index) => {
                    const share = shares[index] ?? 0;
                    const width = columnWidth(settings, share);

                    return `<td class="email-column" width="${share}%" valign="top" style="width:${share}%;padding:0 ${COLUMN_GAP / 2}px;vertical-align:top"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">${renderRows(column, settings, width, true)}</table></td>`;
                })
                .join('');

            return `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>${cells}</tr></table>`;
        }
        case 'heading':
            return `<h${block.level} style="${css(headingStyle(block.level, settings))}">${styleRichText(block.html, settings)}</h${block.level}>`;
        case 'text':
            return `<div style="${css(textStyle(settings))}">${styleRichText(block.html, settings)}</div>`;
        case 'footer':
            return `<div style="${css(footerStyle(settings))}">${styleRichText(`${block.html}<p>${FOOTER_TAGS}</p>`, { ...settings, link: MUTED })}</div>`;
        case 'button':
            return `<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="${block.align}" style="display:inline-table"><tr><td style="border-radius:6px;background:${escape(block.background)}"><a href="${escape(block.url)}" style="${css(buttonStyle(block, settings))}">${escape(block.label)}</a></td></tr></table>`;
        case 'image': {
            if (block.src === '') {
                return '';
            }

            const width = imageWidth(block, available);
            const image = `<img src="${escape(block.src)}" alt="${escape(block.alt)}" width="${width}" style="display:inline-block;width:100%;max-width:${width}px;height:auto;border:0">`;

            return block.url === ''
                ? image
                : `<a href="${escape(block.url)}">${image}</a>`;
        }
        case 'html':
            return block.html;
        case 'divider':
            return '<div style="height:1px;line-height:1px;font-size:1px;background:#e4e4e7">&nbsp;</div>';
        case 'spacer':
            return `<div style="height:${block.height}px;line-height:${block.height}px;font-size:1px">&nbsp;</div>`;
    }
}

/**
 * The document as email HTML: nested tables and inline styles, which render the same in Gmail, Outlook and Apple Mail,
 * and a single column that narrows to fit phones.
 */
export function renderEmail(document: EmailDocument): string {
    const { settings } = document;
    const rows = renderRows(
        document.blocks,
        settings,
        contentWidth(settings),
        false,
    );

    return `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>@media (max-width: ${settings.width + 32}px) { .email-container { width: 100% !important; } .email-column { display: block !important; width: 100% !important; box-sizing: border-box; } }</style>
${settings.head ?? ''}
</head>
<body style="margin:0;padding:0;background:${settings.backdrop}">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:${settings.backdrop}">
<tr><td align="center" style="padding:32px 12px">
<table role="presentation" class="email-container" width="${settings.width}" cellpadding="0" cellspacing="0" border="0" style="width:${settings.width}px;max-width:100%;background:${settings.canvas};border-radius:8px">
<tr><td style="height:16px;line-height:16px;font-size:1px">&nbsp;</td></tr>
${rows}
</table>
</td></tr>
</table>
</body>
</html>`;
}
