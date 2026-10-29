import { Head, Link, usePoll } from '@inertiajs/react';
import { FileSpreadsheet, Upload } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import {
    create,
    index as importsIndex,
    show,
} from '@/routes/marketing/imports';
import { ImportStatusBadge } from '@marketing/components/import-status-badge';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import type { ImportStatus, ImportSummary } from '@marketing/types';
import { formatDate, formatDateTime } from '@marketing/lib/format';

const iconTones: Record<ImportStatus, string> = {
    pending: 'text-amber-600 dark:text-amber-400',
    queued: 'text-muted-foreground',
    processing: 'text-sky-600 dark:text-sky-400',
    completed: 'text-emerald-600 dark:text-emerald-400',
    failed: 'text-red-600 dark:text-red-400',
};

export default function ImportsIndex({
    imports,
}: {
    imports: ImportSummary[];
}) {
    const running = imports.some((item) =>
        ['queued', 'processing'].includes(item.status),
    );

    usePoll(3000, { only: ['imports'] }, { autoStart: running });

    const days = Object.entries(
        Object.groupBy(
            imports,
            (item) => formatDate(item.created_at) ?? 'Earlier',
        ),
    );

    return (
        <>
            <Head title="Imports" />

            <PageBody>
                <PageHeader
                    title="Imports"
                    description="Bring contacts in from a CSV file. Existing contacts are updated, never duplicated."
                    actions={
                        <Button asChild>
                            <Link href={create()}>
                                <Upload />
                                Import CSV
                            </Link>
                        </Button>
                    }
                />

                {imports.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed bg-muted/40 px-6 py-14 text-center">
                        <FileSpreadsheet className="size-5 text-muted-foreground" />
                        <p className="text-sm text-muted-foreground">
                            No imports yet. Upload a CSV with an email column to
                            get started.
                        </p>
                    </div>
                ) : (
                    <div className="max-w-3xl space-y-8">
                        {days.map(([day, items]) => (
                            <section key={day} className="space-y-3">
                                <h2 className="text-13 font-medium text-muted-foreground">
                                    {day}
                                </h2>
                                <ol
                                    className={cn(
                                        'relative space-y-2',
                                        (items?.length ?? 0) > 1 &&
                                            'before:absolute before:top-5 before:bottom-5 before:left-[19px] before:w-px before:bg-border',
                                    )}
                                >
                                    {items?.map((item) => (
                                        <ImportEntry
                                            key={item.id}
                                            item={item}
                                        />
                                    ))}
                                </ol>
                            </section>
                        ))}
                    </div>
                )}
            </PageBody>
        </>
    );
}

function ImportEntry({ item }: { item: ImportSummary }) {
    const progress =
        item.total_rows > 0
            ? Math.round((item.processed_rows / item.total_rows) * 100)
            : 0;

    return (
        <li className="relative flex gap-4">
            <span className="relative z-10 flex size-10 shrink-0 items-center justify-center rounded-full border bg-card">
                <FileSpreadsheet
                    className={cn('size-4', iconTones[item.status])}
                />
            </span>

            <div className="flex min-w-0 flex-1 flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border bg-card px-4 py-3 shadow-xs">
                <div className="min-w-0 flex-1 space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <Link
                            href={show(item.id)}
                            className="truncate text-sm font-medium hover:underline"
                        >
                            {item.original_filename}
                        </Link>
                        <ImportStatusBadge status={item.status} />
                    </div>
                    <p className="text-13 text-muted-foreground">
                        {summary(item, progress)}
                    </p>
                    {item.status === 'processing' && (
                        <div
                            role="progressbar"
                            aria-valuenow={progress}
                            aria-valuemin={0}
                            aria-valuemax={100}
                            aria-label={`${item.original_filename} progress`}
                            className="h-1 overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                className="h-full rounded-full bg-sky-500 transition-[width]"
                                style={{ width: `${progress}%` }}
                            />
                        </div>
                    )}
                </div>

                <div className="flex shrink-0 items-center gap-3">
                    <time
                        dateTime={item.created_at}
                        className="text-xs text-muted-foreground tabular-nums"
                    >
                        {formatDateTime(item.created_at)}
                    </time>
                    {item.status === 'pending' ? (
                        <Button variant="outline" size="sm" asChild>
                            <Link href={show(item.id)}>Map columns</Link>
                        </Button>
                    ) : (
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={show(item.id)}>View</Link>
                        </Button>
                    )}
                </div>
            </div>
        </li>
    );
}

/** One line on what an import did, or is doing, in words. */
function summary(item: ImportSummary, progress: number): string {
    switch (item.status) {
        case 'pending':
            return 'Uploaded. Match its columns to contact fields to start.';
        case 'queued':
            return `Waiting to start · ${item.total_rows.toLocaleString()} rows`;
        case 'processing':
            return `${item.processed_rows.toLocaleString()} of ${item.total_rows.toLocaleString()} rows · ${progress}%`;
        case 'failed':
            return `Stopped after ${item.processed_rows.toLocaleString()} of ${item.total_rows.toLocaleString()} rows. Open it to see why.`;
        default:
            return [
                `${item.created_count.toLocaleString()} added`,
                `${item.updated_count.toLocaleString()} updated`,
                item.failed_count > 0 &&
                    `${item.failed_count.toLocaleString()} failed`,
            ]
                .filter(Boolean)
                .join(' · ');
    }
}

ImportsIndex.layout = {
    breadcrumbs: [{ title: 'Imports', href: importsIndex() }],
};
