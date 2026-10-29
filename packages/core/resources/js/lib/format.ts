const dateTimeFormat = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});

const dateFormat = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' });

/**
 * A date and time as people write it, such as "7 Oct 2026, 10:42", in the viewer's locale and time zone.
 */
export function formatDateTime(
    value: string | null | undefined,
): string | null {
    return value ? dateTimeFormat.format(new Date(value)) : null;
}

/**
 * A date alone, such as "7 Oct 2026", in the viewer's locale.
 */
export function formatDate(value: string | null | undefined): string | null {
    return value ? dateFormat.format(new Date(value)) : null;
}

/**
 * A share of a total as a percentage with one decimal place, or a dash when there is no total.
 */
export function percent(part: number, total: number): string {
    return total > 0 ? `${((part / total) * 100).toFixed(1)}%` : '—';
}
