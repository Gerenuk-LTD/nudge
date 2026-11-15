import type {
    Column,
    ColumnDef,
    ExpandedState,
    RowData,
    SortingState,
} from '@tanstack/react-table';
import {
    flexRender,
    getCoreRowModel,
    getExpandedRowModel,
    getSortedRowModel,
    useReactTable,
} from '@tanstack/react-table';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    ChevronRight,
    Inbox,
} from 'lucide-react';
import { Fragment, useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type { TableSort } from '@marketing/hooks/use-table-query';

declare module '@tanstack/react-table' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface ColumnMeta<TData extends RowData, TValue> {
        /** Classes for both the column's header and its cells, such as `text-right`. */
        className?: string;
        /** Whether the server can sort by this column's ID, for tables given a `sort`. */
        sortable?: boolean;
        /** Counts, rates and money: right-aligned so digits line up down the column. */
        numeric?: boolean;
    }
}

/**
 * A TanStack table rendered with shadcn's table, in a bordered card that scrolls sideways on narrow screens.
 *
 * Pass `sortable` only when `data` is the whole set: a paginated page would only sort the rows it holds.
 * Columns sort by their accessor; display-only columns, without one, never sort.
 *
 * For a paginated table pass `sort` instead, from `useTableQuery`: the server sorts by the column's ID,
 * for columns marked `meta.sortable`.
 */
export function DataTable<TData>({
    columns,
    data,
    getRowId,
    emptyMessage,
    sortable = false,
    sort,
    renderExpanded,
}: {
    columns: ColumnDef<TData>[];
    data: TData[];
    getRowId?: (row: TData, index: number) => string;
    emptyMessage?: string;
    sortable?: boolean;
    sort?: TableSort;
    /** When given, each row gets a chevron that shows this under it. Only the row's own content is rendered, on demand. */
    renderExpanded?: (row: TData) => ReactNode;
}) {
    'use no memo'; // The table instance is mutable, so React Compiler must not memoize around it.

    const [sorting, setSorting] = useState<SortingState>([]);
    const [expanded, setExpanded] = useState<ExpandedState>({});
    const columnCount = columns.length + (renderExpanded ? 1 : 0);

    const table = useReactTable({
        data,
        columns,
        getRowId,
        state: { sorting, expanded },
        onSortingChange: setSorting,
        onExpandedChange: setExpanded,
        enableSorting: sortable,
        getRowCanExpand: () => renderExpanded !== undefined,
        getCoreRowModel: getCoreRowModel(),
        getSortedRowModel: getSortedRowModel(),
        getExpandedRowModel: getExpandedRowModel(),
    });

    const sortedBy = (column: Column<TData>): false | 'asc' | 'desc' => {
        if (!sort) {
            return column.getIsSorted();
        }

        return sort.value === column.id
            ? 'asc'
            : sort.value === `-${column.id}`
              ? 'desc'
              : false;
    };

    /** Server sorting goes ascending, then descending, then back to the default order. */
    const toggleSort = (column: Column<TData>) => {
        const sorted = sortedBy(column);

        sort?.onChange(
            sorted === false
                ? column.id
                : sorted === 'asc'
                  ? `-${column.id}`
                  : null,
        );
    };

    return (
        <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
            <Table className="text-13 tabular-nums">
                <TableHeader className="bg-muted/50">
                    {table.getHeaderGroups().map((headerGroup) => (
                        <TableRow
                            key={headerGroup.id}
                            className="hover:bg-transparent"
                        >
                            {renderExpanded && (
                                <TableHead className="w-10 px-2">
                                    <span className="sr-only">Details</span>
                                </TableHead>
                            )}
                            {headerGroup.headers.map((header) => {
                                const label = flexRender(
                                    header.column.columnDef.header,
                                    header.getContext(),
                                );
                                const sorted = sortedBy(header.column);
                                const canSort = sort
                                    ? !!header.column.columnDef.meta?.sortable
                                    : header.column.getCanSort();
                                const SortIcon =
                                    sorted === 'asc'
                                        ? ArrowUp
                                        : sorted === 'desc'
                                          ? ArrowDown
                                          : ArrowUpDown;

                                return (
                                    <TableHead
                                        key={header.id}
                                        aria-sort={
                                            sorted === 'asc'
                                                ? 'ascending'
                                                : sorted === 'desc'
                                                  ? 'descending'
                                                  : undefined
                                        }
                                        className={cn(
                                            'h-9 px-4 text-xs text-muted-foreground',
                                            header.column.columnDef.meta
                                                ?.numeric && 'text-right',
                                            header.column.columnDef.meta
                                                ?.className,
                                        )}
                                    >
                                        {canSort ? (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className={cn(
                                                    'h-7 px-2.5 text-xs font-medium text-muted-foreground',
                                                    header.column.columnDef.meta
                                                        ?.numeric
                                                        ? '-mr-2.5 flex-row-reverse'
                                                        : '-ml-2.5',
                                                )}
                                                onClick={
                                                    sort
                                                        ? () =>
                                                              toggleSort(
                                                                  header.column,
                                                              )
                                                        : header.column.getToggleSortingHandler()
                                                }
                                            >
                                                {label}
                                                <SortIcon
                                                    className={cn(
                                                        'size-3.5',
                                                        !sorted && 'opacity-50',
                                                    )}
                                                />
                                            </Button>
                                        ) : (
                                            label
                                        )}
                                    </TableHead>
                                );
                            })}
                        </TableRow>
                    ))}
                </TableHeader>
                <TableBody>
                    {table.getRowModel().rows.length > 0 ? (
                        table.getRowModel().rows.map((row) => (
                            <Fragment key={row.id}>
                                <TableRow>
                                    {renderExpanded && (
                                        <TableCell className="w-10 px-2 py-2.5">
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="size-7 text-muted-foreground"
                                                aria-label="Show details"
                                                aria-expanded={row.getIsExpanded()}
                                                onClick={() =>
                                                    row.toggleExpanded()
                                                }
                                            >
                                                <ChevronRight
                                                    className={cn(
                                                        'size-4 transition-transform',
                                                        row.getIsExpanded() &&
                                                            'rotate-90',
                                                    )}
                                                />
                                            </Button>
                                        </TableCell>
                                    )}
                                    {row.getVisibleCells().map((cell) => (
                                        <TableCell
                                            key={cell.id}
                                            className={cn(
                                                'px-4 py-2.5 whitespace-normal',
                                                cell.column.columnDef.meta
                                                    ?.numeric && 'text-right',
                                                cell.column.columnDef.meta
                                                    ?.className,
                                            )}
                                        >
                                            {flexRender(
                                                cell.column.columnDef.cell,
                                                cell.getContext(),
                                            )}
                                        </TableCell>
                                    ))}
                                </TableRow>
                                {renderExpanded && row.getIsExpanded() && (
                                    <TableRow className="hover:bg-transparent">
                                        <TableCell
                                            colSpan={columnCount}
                                            className="bg-muted/30 p-4 whitespace-normal"
                                        >
                                            {renderExpanded(row.original)}
                                        </TableCell>
                                    </TableRow>
                                )}
                            </Fragment>
                        ))
                    ) : (
                        <TableRow className="hover:bg-transparent">
                            <TableCell
                                colSpan={columnCount}
                                className="px-4 py-12 whitespace-normal"
                            >
                                <div className="flex flex-col items-center gap-3 text-center">
                                    <div className="flex size-9 items-center justify-center rounded-lg border bg-muted/40">
                                        <Inbox className="size-4 text-muted-foreground" />
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        {emptyMessage ?? 'Nothing here yet.'}
                                    </p>
                                </div>
                            </TableCell>
                        </TableRow>
                    )}
                </TableBody>
            </Table>
        </div>
    );
}

/**
 * Row IDs from the record's own ID, so rows keep their identity when sorted or refreshed.
 */
export const byId = <TData extends { id: number | string }>(row: TData) =>
    String(row.id);

/**
 * A table's placeholder while its rows load: the same card and header height, with pulsing bars in place of rows.
 */
export function DataTableSkeleton({
    columns,
    rows = 5,
}: {
    columns: number;
    rows?: number;
}) {
    return (
        <div
            className="overflow-hidden rounded-xl border bg-card shadow-xs"
            aria-busy="true"
            aria-label="Loading"
        >
            <div className="h-9 border-b bg-muted/50" />
            {Array.from({ length: rows }, (_, row) => (
                <div
                    key={row}
                    className="flex items-center gap-6 border-b px-4 py-3.5 last:border-b-0"
                >
                    {Array.from({ length: columns }, (_, column) => (
                        <Skeleton
                            key={column}
                            className={cn(
                                'h-3.5 flex-1',
                                column === 0 && 'max-w-56 flex-[2]',
                            )}
                        />
                    ))}
                </div>
            ))}
        </div>
    );
}
