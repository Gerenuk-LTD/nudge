export function formatDateTime(
    value: string | null | undefined,
): string | null {
    return value ? new Date(value).toLocaleString() : null;
}

export function formatDate(value: string | null | undefined): string | null {
    return value ? new Date(value).toLocaleDateString() : null;
}

/**
 * A share of a total as a percentage with one decimal place, or a dash when there is no total.
 */
export function percent(part: number, total: number): string {
    return total > 0 ? `${((part / total) * 100).toFixed(1)}%` : '—';
}
