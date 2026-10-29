import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { useTableQuery } from '@marketing/hooks/use-table-query';
import type { Paginated } from '@marketing/types';

/** The page sizes the server accepts, matching `TableQuery::PAGE_SIZES`. */
const PAGE_SIZES = [10, 25, 50, 100];

/**
 * The row count, a rows-per-page picker and previous and next links. Hidden while everything fits on the smallest page.
 */
export function Pagination<T>({
    page,
    query,
}: {
    page: Paginated<T>;
    query: ReturnType<typeof useTableQuery>;
}) {
    if (page.total <= PAGE_SIZES[0]) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center justify-between gap-4 text-sm text-muted-foreground">
            <span>
                {page.from?.toLocaleString() ?? 0}–
                {page.to?.toLocaleString() ?? 0} of{' '}
                {page.total.toLocaleString()}
            </span>
            <div className="flex items-center gap-4">
                <div className="flex items-center gap-2">
                    <span className="hidden sm:inline">Rows per page</span>
                    <Select
                        value={String(page.per_page)}
                        onValueChange={(value) =>
                            query.update({ per_page: value })
                        }
                    >
                        <SelectTrigger
                            size="sm"
                            className="w-18"
                            aria-label="Rows per page"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {PAGE_SIZES.map((size) => (
                                <SelectItem key={size} value={String(size)}>
                                    {size}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div className="flex gap-2">
                    <PageLink url={page.prev_page_url} only={query.only}>
                        Previous
                    </PageLink>
                    <PageLink url={page.next_page_url} only={query.only}>
                        Next
                    </PageLink>
                </div>
            </div>
        </div>
    );
}

function PageLink({
    url,
    only,
    children,
}: {
    url: string | null;
    only?: string[];
    children: string;
}) {
    return (
        <Button variant="outline" size="sm" disabled={!url} asChild={!!url}>
            {url ? (
                <Link href={url} only={only} preserveScroll preserveState>
                    {children}
                </Link>
            ) : (
                children
            )}
        </Button>
    );
}
