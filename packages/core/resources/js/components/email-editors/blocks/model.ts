export type Align = 'left' | 'center' | 'right';

export type EmailSettings = {
    /** Behind the email. */
    backdrop: string;
    /** The email's own background. */
    canvas: string;
    /** Content width in pixels on wide screens; it shrinks to fit phones. */
    width: number;
    font: string;
    text: string;
    link: string;
    /** Markup from an imported HTML email's <head>, such as its styles, kept so editing it in the builder doesn't lose them. */
    head?: string;
};

/** How a columns block divides its width, as relative shares. */
export type ColumnLayout = '1-1' | '1-2' | '2-1' | '1-1-1';

export const COLUMN_LAYOUTS: Record<ColumnLayout, number[]> = {
    '1-1': [50, 50],
    '1-2': [33.33, 66.67],
    '2-1': [66.67, 33.33],
    '1-1-1': [33.33, 33.33, 33.34],
};

/** A block that can sit inside a column: anything except another set of columns. */
export type ContentBlock = Exclude<Block, { type: 'columns' }>;

export type Block =
    | {
          id: string;
          type: 'columns';
          layout: ColumnLayout;
          columns: ContentBlock[][];
      }
    | {
          id: string;
          type: 'heading';
          html: string;
          level: 1 | 2 | 3;
          align: Align;
      }
    | { id: string; type: 'text'; html: string; align: Align }
    | {
          id: string;
          type: 'button';
          label: string;
          url: string;
          align: Align;
          background: string;
          color: string;
      }
    | {
          id: string;
          type: 'image';
          src: string;
          alt: string;
          url: string;
          /** Percent of the content width. */
          width: number;
          align: Align;
      }
    | { id: string; type: 'html'; html: string }
    | { id: string; type: 'divider' }
    | { id: string; type: 'spacer'; height: number }
    | { id: string; type: 'footer'; html: string; align: Align };

export type BlockType = Block['type'];

export type EmailDocument = {
    version: 1;
    settings: EmailSettings;
    blocks: Block[];
};

export const FONTS = [
    { value: 'Arial, Helvetica, sans-serif', label: 'Sans serif' },
    { value: 'Georgia, "Times New Roman", serif', label: 'Serif' },
    { value: '"Courier New", Courier, monospace', label: 'Monospace' },
];

export const defaultSettings: EmailSettings = {
    backdrop: '#f4f4f5',
    canvas: '#ffffff',
    width: 600,
    font: FONTS[0].value,
    text: '#18181b',
    link: '#2563eb',
};

export function newId(): string {
    return Math.random().toString(36).slice(2, 10);
}

/** A fresh block of a type with sensible content, ready to edit. */
export function createBlock(type: BlockType): Block {
    const id = newId();

    switch (type) {
        case 'heading':
            return {
                id,
                type,
                html: 'A clear headline',
                level: 1,
                align: 'left',
            };
        case 'text':
            return {
                id,
                type,
                html: '<p>Write something worth reading.</p>',
                align: 'left',
            };
        case 'button':
            return {
                id,
                type,
                label: 'Learn more',
                url: 'https://',
                align: 'left',
                background: '#18181b',
                color: '#ffffff',
            };
        case 'image':
            return {
                id,
                type,
                src: '',
                alt: '',
                url: '',
                width: 100,
                align: 'center',
            };
        case 'divider':
            return { id, type };
        case 'spacer':
            return { id, type, height: 24 };
        case 'footer':
            return {
                id,
                type,
                html: '<p>You are receiving this because you signed up.</p>',
                align: 'center',
            };
        case 'columns':
            return { id, type, layout: '1-1', columns: [[], []] };
        case 'html':
            return {
                id,
                type,
                html: '<p style="margin:0">Your HTML here</p>',
            };
    }
}

/** A copy of a block with new ids, including every block inside its columns. */
export function cloneBlock<T extends Block>(block: T): T {
    if (block.type === 'columns') {
        return {
            ...block,
            id: newId(),
            columns: block.columns.map((column) => column.map(cloneBlock)),
        };
    }

    return { ...block, id: newId() };
}

/** Columns rearranged for a new layout. Blocks in columns that go away move into the last remaining one. */
export function withLayout(
    block: Extract<Block, { type: 'columns' }>,
    layout: ColumnLayout,
): Extract<Block, { type: 'columns' }> {
    const count = COLUMN_LAYOUTS[layout].length;
    const columns = Array.from(
        { length: count },
        (_, index) => block.columns[index] ?? [],
    );

    columns[count - 1] = [
        ...columns[count - 1],
        ...block.columns.slice(count).flat(),
    ];

    return { ...block, layout, columns };
}

export function defaultDocument(): EmailDocument {
    return {
        version: 1,
        settings: { ...defaultSettings },
        blocks: [
            {
                id: newId(),
                type: 'text',
                html: '<p>Hi {{ first_name | there }},</p><p>Write your message here.</p>',
                align: 'left',
            },
            createBlock('footer'),
        ],
    };
}

/** The stored state as a document, or null when it is missing or from an editor this one cannot read. */
export function asDocument(state: unknown): EmailDocument | null {
    if (
        typeof state === 'object' &&
        state !== null &&
        (state as EmailDocument).version === 1 &&
        Array.isArray((state as EmailDocument).blocks)
    ) {
        const document = state as EmailDocument;

        return {
            ...document,
            settings: { ...defaultSettings, ...document.settings },
        };
    }

    return null;
}

/**
 * A builder document holding an existing HTML email as one HTML block, so switching an email to the builder keeps
 * its content. White backgrounds let the email's own design show as it did.
 */
export function documentFromHtml(html: string): EmailDocument {
    const body = /<body[^>]*>([\s\S]*)<\/body>/i.exec(html)?.[1] ?? html;

    const head = (/<head[^>]*>([\s\S]*)<\/head>/i.exec(html)?.[1] ?? '')
        .replace(/<meta\s+charset[^>]*>/gi, '')
        .replace(/<meta\s+name=["']?viewport["']?[^>]*>/gi, '')
        .replace(
            /<style[^>]*>(?:(?!<\/style>)[\s\S])*\.email-container(?:(?!<\/style>)[\s\S])*<\/style>/gi,
            '',
        )
        .trim();

    return {
        version: 1,
        settings: {
            ...defaultSettings,
            backdrop: '#ffffff',
            canvas: '#ffffff',
            ...(head === '' ? {} : { head }),
        },
        blocks: [{ id: newId(), type: 'html', html: body.trim() }],
    };
}
