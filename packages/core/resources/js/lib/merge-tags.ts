const PATTERN = /\{\{\s*([a-z][a-z0-9_.]*)\s*(?:\|\s*([^}]*?))?\s*\}\}/gi;

/** Campaign variables in these texts, in first-seen order: tags that aren't built in or custom fields. */
export function campaignVariables(
    texts: string[],
    builtIn: string[],
): { tag: string; optional: boolean; fallback: string | null }[] {
    const found = new Map<string, string | null>();

    for (const text of texts) {
        for (const match of text.matchAll(PATTERN)) {
            const tag = match[1].toLowerCase();

            if (builtIn.includes(tag) || tag.startsWith('custom.')) {
                continue;
            }

            const fallback = (match[2] ?? '').trim();

            found.set(
                tag,
                found.get(tag) ?? (fallback !== '' ? fallback : null),
            );
        }
    }

    return [...found].map(([tag, fallback]) => ({
        tag,
        optional: fallback !== null,
        fallback,
    }));
}

const ESCAPES: Record<string, string> = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
};

/**
 * Puts campaign variable values into a text the way sending does, for previews. Tags without a value are left as they
 * are, and so are built-in and custom field tags, which differ per person.
 */
export function fillVariables(
    text: string,
    values: Record<string, string>,
    { escapeHtml }: { escapeHtml: boolean },
): string {
    return text.replace(PATTERN, (whole, name: string) => {
        const tag = name.toLowerCase();
        const value = values[tag];

        if (!value || tag.startsWith('custom.')) {
            return whole;
        }

        return escapeHtml
            ? value.replace(/[&<>"']/g, (c) => ESCAPES[c])
            : value;
    });
}
