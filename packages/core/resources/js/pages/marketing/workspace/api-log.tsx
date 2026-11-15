import { Head, router } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { Check, Copy, RefreshCw, ScrollText } from 'lucide-react';
import { useEffect, useState } from 'react';
import ApiRequestController from '@/actions/Gerenuk/Marketing/Http/Controllers/ApiRequestController';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useClipboard } from '@/hooks/use-clipboard';
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
import { formatDateTime, formatRelative } from '@marketing/lib/format';
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

const STATUS_TEXT: Record<number, string> = {
    200: 'OK',
    201: 'Created',
    202: 'Accepted',
    204: 'No content',
    400: 'Bad request',
    401: 'Not signed in',
    403: 'Missing permission',
    404: 'Not found',
    405: 'Method not allowed',
    409: 'Conflict',
    413: 'Too large',
    422: 'Validation failed',
    429: 'Rate limited',
    500: 'Server error',
    502: 'Bad gateway',
    503: 'Unavailable',
};

/** What a status code means, in a few words. Codes the API doesn't return today fall back to their class. */
function statusText(status: number): string {
    return (
        STATUS_TEXT[status] ??
        (status >= 500
            ? 'Server error'
            : status >= 400
              ? 'Client error'
              : status >= 300
                ? 'Redirect'
                : 'Success')
    );
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
            <Tooltip>
                <TooltipTrigger asChild>
                    <time
                        dateTime={row.original.created_at ?? undefined}
                        className="whitespace-nowrap text-muted-foreground"
                    >
                        {formatRelative(row.original.created_at)}
                    </time>
                </TooltipTrigger>
                <TooltipContent>
                    {formatDateTime(row.original.created_at)}
                </TooltipContent>
            </Tooltip>
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
            <Tooltip>
                <TooltipTrigger asChild>
                    <span tabIndex={0} className="flex items-center gap-2">
                        <span
                            aria-hidden
                            className={cn(
                                'size-1.5 rounded-full',
                                statusDot(row.original.status),
                            )}
                        />
                        {row.original.status}
                    </span>
                </TooltipTrigger>
                <TooltipContent>
                    {row.original.status} · {statusText(row.original.status)}
                </TooltipContent>
            </Tooltip>
        ),
    },
    {
        id: 'duration',
        header: 'Duration',
        meta: { numeric: true },
        cell: ({ row }) =>
            row.original.duration_ms >= 1000 ? (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <span className="text-amber-600 dark:text-amber-400">
                            {(row.original.duration_ms / 1000).toFixed(1)} s
                        </span>
                    </TooltipTrigger>
                    <TooltipContent>Slow request</TooltipContent>
                </Tooltip>
            ) : (
                `${row.original.duration_ms} ms`
            ),
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
}) {
    const query = useTableQuery({ only: ['requests'] });
    const [refreshing, setRefreshing] = useState(false);
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
                        placeholder="Search paths or #ID"
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
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Button
                                variant="outline"
                                size="icon"
                                className="ml-auto size-8"
                                aria-label="Refresh"
                                disabled={refreshing}
                                onClick={() =>
                                    router.reload({
                                        only: ['requests', 'routes'],
                                        onStart: () => setRefreshing(true),
                                        onFinish: () => setRefreshing(false),
                                    })
                                }
                            >
                                <RefreshCw
                                    className={cn(refreshing && 'animate-spin')}
                                />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>Refresh</TooltipContent>
                    </Tooltip>
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
                            rowClassName={(row) =>
                                row.status >= 500
                                    ? 'bg-destructive/5 hover:bg-destructive/10'
                                    : undefined
                            }
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
            <p className="flex flex-wrap items-center gap-1 text-xs text-muted-foreground">
                Request #{id}
                <CopyButton
                    text={String(id)}
                    label="Copy request ID"
                    className="size-5"
                />
                {[detail.ip, detail.user_agent].filter(Boolean).length > 0 &&
                    ` · ${[detail.ip, detail.user_agent].filter(Boolean).join(' · ')}`}
            </p>
        </div>
    );
}

/** A small icon button that copies `text`, showing a tick for a moment afterwards. */
function CopyButton({
    text,
    label,
    className,
}: {
    text: string;
    label: string;
    className?: string;
}) {
    const [copied, copy] = useClipboard();
    const done = copied === text;

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className={cn('text-muted-foreground', className)}
                    aria-label={label}
                    onClick={() => copy(text)}
                >
                    {done ? (
                        <Check className="size-3.5" />
                    ) : (
                        <Copy className="size-3.5" />
                    )}
                </Button>
            </TooltipTrigger>
            <TooltipContent>{done ? 'Copied' : 'Copy'}</TooltipContent>
        </Tooltip>
    );
}

function Body({ title, body }: { title: string; body: string | null }) {
    const text = body === null ? null : pretty(body);

    return (
        <div className="min-w-0 space-y-1.5">
            <h3 className="text-xs font-medium text-muted-foreground">
                {title}
            </h3>
            <div className="group relative">
                <pre className="max-h-96 overflow-auto rounded-lg border bg-card p-3 font-mono text-xs">
                    {text ?? 'No body'}
                </pre>
                {text !== null && (
                    <CopyButton
                        text={text}
                        label={`Copy ${title.toLowerCase()} body`}
                        className="absolute top-2 right-2 size-7"
                    />
                )}
            </div>
        </div>
    );
}

ApiLog.layout = {
    breadcrumbs: [{ title: 'API log', href: apiLogIndex() }],
};
