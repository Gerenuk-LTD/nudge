import { router, usePage } from '@inertiajs/react';

export type TableSort = {
    /** The sort key, or `-key` for descending; empty for the server's default order. */
    value: string;
    onChange: (value: string | null) => void;
};

/**
 * A paginated table's search, filters, sort and page size, kept in the page's query string.
 * Any change goes back to the first page. Pass `only` to reload just the table's props.
 */
export function useTableQuery({ only }: { only?: string[] } = {}) {
    const { url } = usePage();
    const [path, query = ''] = url.split('?');
    const params = new URLSearchParams(query);

    const get = (key: string): string => params.get(key) ?? '';

    const update = (changes: Record<string, string | string[] | null>) => {
        const next = new URLSearchParams(params);
        next.delete('page');

        for (const [key, value] of Object.entries(changes)) {
            const text = Array.isArray(value) ? value.join(',') : (value ?? '');

            if (text === '') {
                next.delete(key);
            } else {
                next.set(key, text);
            }
        }

        router.get(path, Object.fromEntries(next), {
            only,
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const sort: TableSort = {
        value: get('sort'),
        onChange: (value) => update({ sort: value }),
    };

    return {
        only,
        get,
        /** The values of a comma-separated filter. */
        values: (key: string): string[] => get(key).split(',').filter(Boolean),
        update,
        sort,
    };
}
