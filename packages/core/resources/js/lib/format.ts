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

const relativeFormat = new Intl.RelativeTimeFormat(undefined, {
    numeric: 'auto',
    style: 'short',
});

/**
 * How long ago something happened, such as "3 min. ago" or "yesterday", up to a week; older times fall back to
 * formatDateTime. Pass `now` in tests.
 */
export function formatRelative(
    value: string | null | undefined,
    now: Date = new Date(),
): string | null {
    if (!value) {
        return null;
    }

    const seconds = (now.getTime() - new Date(value).getTime()) / 1000;

    if (seconds < 45) {
        return 'just now';
    }

    const minutes = Math.max(1, Math.floor(seconds / 60));

    if (minutes < 60) {
        return relativeFormat.format(-minutes, 'minute');
    }

    const hours = Math.max(1, Math.floor(seconds / 3600));

    if (hours < 24) {
        return relativeFormat.format(-hours, 'hour');
    }

    const days = Math.max(1, Math.floor(seconds / 86400));

    return days < 7
        ? relativeFormat.format(-days, 'day')
        : formatDateTime(value);
}
