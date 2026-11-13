const PATTERN = /\{\{\s*([a-z][a-z0-9_.]*)\s*(?:\|\s*([^}]*?))?\s*\}\}/gi;

/** Campaign variables in these texts, in first-seen order: tags that aren't built in or custom fields. */
export function campaignVariables(
    texts: string[],
    builtIn: string[],
): { tag: string; optional: boolean }[] {
    const found = new Map<string, boolean>();

    for (const text of texts) {
        for (const match of text.matchAll(PATTERN)) {
            const tag = match[1].toLowerCase();

            if (builtIn.includes(tag) || tag.startsWith('custom.')) {
                continue;
            }

            found.set(
                tag,
                (found.get(tag) ?? false) || (match[2] ?? '').trim() !== '',
            );
        }
    }

    return [...found].map(([tag, optional]) => ({ tag, optional }));
}
