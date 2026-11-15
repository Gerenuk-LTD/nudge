import { Head } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { ScrollText } from 'lucide-react';
import { useEffect, useState } from 'react';
import ApiRequestController from '@/actions/Gerenuk/Marketing/Http/Controllers/ApiRequestController';
import { cn } from '@/lib/utils';
import { index as apiLogIndex } from '@/routes/marketing/api-log';
import {
    byId,
    DataTable,
    DataTableSkeleton,
} from '@marketing/components/data-table';
import { EmptyState } from '@marketing/components/empty-state';
import { Pagination } from '@marketing/components/pagination';
import {
    ChoiceFilter,
    FacetedFilter,
    ResetFilters,
    TableSearch,
} from '@marketing/components/table-toolbar';
import type { FacetOption } from '@marketing/components/table-toolbar';
import { useTableQuery } from '@marketing/hooks/use-table-query';
import { getJson } from '@marketing/lib/http';
import { formatDateTime } from '@marketing/lib/format';
import type { Paginated } from '@marketing/types';

type LogRow = {
    id: number;
    created_at: string | null;
    token_name: string;
    method: string;
    path: string;
    status: number;
    duration_ms: number;
};

type Detail = {
    request_body: string | null;
    response_body: string | null;
    ip: string | null;
    user_agent: string | null;
    route: string | null;
    bodies_missing: 'hidden' | 'not_kept' | 'cleared' | null;
};

const STATUSES: FacetOption[] = [
    { value: 'success', label: 'Success' },
    { value: 'client_error', label: 'Client error' },
    { value: 'server_error', label: 'Server error' },
];

const PERIODS: FacetOption[] = [
    { value: '24h', label: 'Last 24 hours' },
    { value: '7d', label: 'Last 7 days' },
    { value: '30d', label: 'Last 30 days' },
];

const DEFAULT_PERIOD = '7d';

function statusDot(status: number): string {
    return status >= 500
        ? 'bg-red-500 dark:bg-red-400'
        : status >= 400
          ? 'bg-amber-500 dark:bg-amber-400'
          : 'bg-emerald-500 dark:bg-emerald-400';
}

/** JSON is shown indented; anything else, such as a cut body, as it was sent. */
function pretty(body: string): string {
    try {
        return JSON.stringify(JSON.parse(body), null, 2);
    } catch {
        return body;
    }
}

const columns: ColumnDef<LogRow>[] = [
    {
        id: 'time',
        header: 'Time',
        cell: ({ row }) => (
            <span className="whitespace-nowrap text-muted-foreground">
                {formatDateTime(row.original.created_at)}
            </span>
        ),
    },
    {
        id: 'token',
        header: 'Token',
        cell: ({ row }) => row.original.token_name,
    },
    {
        id: 'request',
        header: 'Request',
        cell: ({ row }) => (
            <span className="flex items-center gap-2 font-mono text-xs">
                <span className="rounded border px-1.5 py-0.5 font-medium">
                    {row.original.method}
                </span>
                <span
                    className="max-w-72 truncate sm:max-w-md"
                    title={row.original.path}
                >
                    {row.original.path}
                </span>
            </span>
        ),
    },
    {
        id: 'status',
        header: 'Status',
        cell: ({ row }) => (
            <span className="flex items-center gap-2">
                <span
                    aria-hidden
                    className={cn(
                        'size-1.5 rounded-full',
                        statusDot(row.original.status),
                    )}
                />
                {row.original.status}
            </span>
        ),
    },
    {
        id: 'duration',
        header: 'Duration',
        meta: { numeric: true },
        cell: ({ row }) => `${row.original.duration_ms} ms`,
    },
];

export default function ApiLog({
    requests,
    tokens,
    routes,
    retentionDays,
    bodyRetentionDays,
}: {
    requests?: Paginated<LogRow>;
    tokens: FacetOption[];
    routes: FacetOption[];
    retentionDays: number;
    bodyRetentionDays: number;
    canViewBodies: boolean;
}) {
    const query = useTableQuery({ only: ['requests'] });
    const filtered =
        query.get('search') !== '' ||
        query.get('period') !== '' ||
        ['token', 'route', 'status'].some(
            (key) => query.values(key).length > 0,
        );

    return (
        <>
            <Head title="API log" />

            <section className="space-y-4">
                <header className="space-y-1">
                    <h2 className="text-base font-semibold">API log</h2>
                    <p className="text-sm text-muted-foreground">
                        Every request made with this workspace's API tokens.
                        Kept for {retentionDays} days; request and response
                        bodies for {bodyRetentionDays}.
                    </p>
                </header>

                <div className="flex flex-wrap items-center gap-2">
                    <TableSearch
                        value={query.get('search')}
                        onSearch={(search) => query.update({ search })}
                        placeholder="Search paths"
                    />
                    <FacetedFilter
                        title="Token"
                        options={tokens}
                        selected={query.values('token')}
                        onChange={(token) => query.update({ token })}
                    />
                    <FacetedFilter
                        title="Endpoint"
                        options={routes}
                        selected={query.values('route')}
                        onChange={(route) => query.update({ route })}
                    />
                    <FacetedFilter
                        title="Status"
                        options={STATUSES}
                        selected={query.values('status')}
                        onChange={(status) => query.update({ status })}
                    />
                    <ChoiceFilter
                        title="Period"
                        options={PERIODS}
                        value={query.get('period') || DEFAULT_PERIOD}
                        onChange={(period) =>
                            query.update({
                                period:
                                    period === DEFAULT_PERIOD ? null : period,
                            })
                        }
                    />
                    <ResetFilters
                        active={filtered}
                        onReset={() =>
                            query.update({
                                search: null,
                                token: null,
                                route: null,
                                status: null,
                                period: null,
                            })
                        }
                    />
                </div>

                {!requests ? (
                    <DataTableSkeleton columns={5} />
                ) : requests.total === 0 && !filtered ? (
                    <EmptyState
                        icon={ScrollText}
                        title="No API requests yet"
                        description="Requests made with your API tokens appear here."
                    />
                ) : (
                    <>
                        <DataTable
                            columns={columns}
                            data={requests.data}
                            getRowId={byId}
                            emptyMessage="No requests match."
                            renderExpanded={(row) => (
                                <RequestDetail
                                    id={row.id}
                                    bodyRetentionDays={bodyRetentionDays}
                                />
                            )}
                        />
                        <Pagination page={requests} query={query} />
                    </>
                )}
            </section>
        </>
    );
}

/** A request's bodies and connection details, fetched when its row is expanded rather than with the list. */
function RequestDetail({
    id,
    bodyRetentionDays,
}: {
    id: number;
    bodyRetentionDays: number;
}) {
    const [detail, setDetail] = useState<Detail | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        const controller = new AbortController();

        getJson<Detail>(ApiRequestController.show.url(id), controller.signal)
            .then(setDetail)
            .catch(() => !controller.signal.aborted && setFailed(true));

        return () => controller.abort();
    }, [id]);

    if (failed) {
        return (
            <p className="text-sm text-muted-foreground">
                The details could not be loaded.
            </p>
        );
    }

    if (!detail) {
        return <p className="text-sm text-muted-foreground">Loading…</p>;
    }

    const notes = {
        hidden: 'You need permission to view contacts to see request and response bodies.',
        not_kept: "Bodies aren't kept for successful reads.",
        cleared: `Bodies are kept for ${bodyRetentionDays} days.`,
    };

    return (
        <div className="space-y-3">
            {detail.bodies_missing ? (
                <p className="text-sm text-muted-foreground">
                    {notes[detail.bodies_missing]}
                </p>
            ) : (
                <div className="grid gap-4 lg:grid-cols-2">
                    <Body title="Request" body={detail.request_body} />
                    <Body title="Response" body={detail.response_body} />
                </div>
            )}
            <p className="text-xs text-muted-foreground">
                {[detail.ip, detail.user_agent].filter(Boolean).join(' · ') ||
                    'No connection details'}
            </p>
        </div>
    );
}

function Body({ title, body }: { title: string; body: string | null }) {
    return (
        <div className="min-w-0 space-y-1.5">
            <h3 className="text-xs font-medium text-muted-foreground">
                {title}
            </h3>
            <pre className="max-h-96 overflow-auto rounded-lg border bg-card p-3 font-mono text-xs">
                {body === null ? 'No body' : pretty(body)}
            </pre>
        </div>
    );
}

ApiLog.layout = {
    breadcrumbs: [{ title: 'API log', href: apiLogIndex() }],
};
