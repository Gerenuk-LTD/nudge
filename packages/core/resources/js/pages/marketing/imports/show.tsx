import type { ColumnDef } from '@tanstack/react-table';
import { Head, Link, router, useForm, usePoll } from '@inertiajs/react';
import {
    CircleAlert,
    CircleCheck,
    Download,
    FileQuestion,
    RefreshCw,
    Tag as TagIcon,
    Upload,
    UserPlus,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect } from 'react';
import type { FormEvent } from 'react';
import ImportController from '@/actions/Gerenuk/Marketing/Http/Controllers/ImportController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { index as contactsIndex } from '@/routes/marketing/contacts';
import { create, index as importsIndex } from '@/routes/marketing/imports';
import { DataTable } from '@marketing/components/data-table';
import { EditorBar } from '@marketing/components/editor-bar';
import { ImportStatusBadge } from '@marketing/components/import-status-badge';
import { ImportSteps } from '@marketing/components/import-steps';
import { PageBody } from '@marketing/components/page-header';
import { SettingsSection } from '@marketing/components/settings-section';
import { TagInput } from '@marketing/components/tag-input';
import type { ImportSummary, Option } from '@marketing/types';
import { Shout } from '@marketing/components/shout';

type FailedRow = {
    id: number;
    row_number: number;
    email: string | null;
    message: string;
};

type Props = {
    import: ImportSummary;
    headers: string[];
    sample: (string | null)[][];
    suggestedMapping: (string | null)[];
    targets: { value: string; label: string }[];
    lists: Option[];
    tags: string[];
    defaultList: number | null;
    fileMissing: boolean;
    destination: { list: Option | null; tags: string[] };
    failedRows: FailedRow[];
};

export default function ShowImport(props: Props) {
    const { import: item, fileMissing } = props;
    const pending = item.status === 'pending';

    return (
        <>
            <Head title={item.original_filename} />

            <PageBody>
                <EditorBar
                    back={{ href: importsIndex(), label: 'imports' }}
                    title={item.original_filename}
                    meta={
                        <>
                            <ImportStatusBadge status={item.status} />
                            {item.total_rows.toLocaleString()} rows
                        </>
                    }
                    stepper={
                        <ImportSteps
                            current={pending ? 'map' : 'import'}
                            finished={item.status === 'completed'}
                        />
                    }
                    actions={
                        pending &&
                        !fileMissing && (
                            <Button type="submit" form="import-mapping">
                                Start import
                            </Button>
                        )
                    }
                />

                {pending && fileMissing ? (
                    <FileMissing item={item} />
                ) : pending ? (
                    <MappingForm {...props} />
                ) : (
                    <Results {...props} />
                )}
            </PageBody>
        </>
    );
}

function FileMissing({ item }: { item: ImportSummary }) {
    return (
        <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed bg-muted/40 px-6 py-16 text-center">
            <span className="flex size-10 items-center justify-center rounded-full border bg-card text-muted-foreground shadow-xs">
                <FileQuestion className="size-5" />
            </span>
            <div className="space-y-1">
                <p className="font-medium">This file is no longer available</p>
                <p className="max-w-md text-sm text-muted-foreground">
                    The upload was removed before it was imported. Upload{' '}
                    {item.original_filename} again to carry on.
                </p>
            </div>
            <div className="flex gap-2 pt-1">
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() =>
                        router.delete(ImportController.destroy.url(item.id))
                    }
                >
                    Remove this import
                </Button>
                <Button size="sm" asChild>
                    <Link href={create()}>
                        <Upload />
                        Upload again
                    </Link>
                </Button>
            </div>
        </div>
    );
}

function MappingForm({
    import: item,
    headers,
    sample,
    suggestedMapping,
    targets,
    lists,
    tags,
    defaultList,
}: Props) {
    const form = useForm<{
        mapping: (string | null)[];
        contact_list_id: string;
        tags: string[];
    }>({
        mapping: suggestedMapping,
        contact_list_id: defaultList === null ? '' : String(defaultList),
        tags: [],
    });

    const setTarget = (index: number, target: string) =>
        form.setData(
            'mapping',
            form.data.mapping.map((existing, i) =>
                i === index ? target || null : existing,
            ),
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            contact_list_id:
                data.contact_list_id === ''
                    ? null
                    : Number(data.contact_list_id),
        }));
        form.submit(ImportController.start(item.id));
    };

    const errors = form.errors as Record<string, string | undefined>;
    const emailColumns = form.data.mapping.filter(
        (target) => target === 'email',
    ).length;
    const imported = form.data.mapping.filter(Boolean).length;
    const examples = (index: number) =>
        sample
            .map((row) => row[index])
            .filter(Boolean)
            .slice(0, 3)
            .join(', ');

    const columns: ColumnDef<string>[] = [
        {
            id: 'column',
            header: 'Column in your file',
            cell: ({ row }) => (
                <div className="grid max-w-xs leading-tight">
                    <span className="truncate font-medium">
                        {row.original || `Column ${row.index + 1}`}
                    </span>
                    <span className="truncate text-xs text-muted-foreground">
                        {examples(row.index) || 'No values in the first rows'}
                    </span>
                </div>
            ),
        },
        {
            id: 'target',
            header: 'Import as',
            meta: { className: 'w-64' },
            cell: ({ row }) => {
                const target = form.data.mapping[row.index];

                return (
                    <NativeSelect
                        aria-label={`Import ${row.original} as`}
                        value={target ?? ''}
                        onChange={(event) =>
                            setTarget(row.index, event.target.value)
                        }
                        className={cn(!target && 'text-muted-foreground')}
                    >
                        <option value="">Don't import</option>
                        {targets.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </NativeSelect>
                );
            },
        },
        {
            id: 'matched',
            header: () => <span className="sr-only">Match</span>,
            meta: { className: 'w-44' },
            cell: ({ row }) => {
                const target = form.data.mapping[row.index];

                return target === null ? (
                    <span className="text-xs text-muted-foreground">
                        Skipped
                    </span>
                ) : target === suggestedMapping[row.index] ? (
                    <span className="inline-flex items-center gap-1 text-xs text-emerald-700 dark:text-emerald-400">
                        <CircleCheck className="size-3.5" />
                        Matched automatically
                    </span>
                ) : (
                    <span className="text-xs text-muted-foreground">
                        Chosen by you
                    </span>
                );
            },
        },
    ];

    return (
        <form id="import-mapping" onSubmit={submit} className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-sm text-muted-foreground">
                    {item.total_rows.toLocaleString()} rows · {headers.length}{' '}
                    columns, {imported} imported
                </p>
                {emailColumns === 1 ? (
                    <span className="inline-flex items-center gap-1.5 text-13 text-emerald-700 dark:text-emerald-400">
                        <CircleCheck className="size-4" />
                        Email column found
                    </span>
                ) : (
                    <span className="inline-flex items-center gap-1.5 text-13 text-red-600 dark:text-red-400">
                        <CircleAlert className="size-4" />
                        {emailColumns === 0
                            ? 'Choose which column holds the email address'
                            : 'Only one column can be the email address'}
                    </span>
                )}
            </div>

            <DataTable columns={columns} data={headers} />
            <InputError message={errors.mapping} />

            <SettingsSection
                title="After importing"
                description="Put everyone from this file on a list, or tag them, to find them again later."
            >
                <div className="grid gap-6 sm:grid-cols-2">
                    <div className="grid content-start gap-2">
                        <Label htmlFor="contact_list_id">Add to a list</Label>
                        <NativeSelect
                            id="contact_list_id"
                            value={form.data.contact_list_id}
                            onChange={(event) =>
                                form.setData(
                                    'contact_list_id',
                                    event.target.value,
                                )
                            }
                        >
                            <option value="">No list</option>
                            {lists.map((list) => (
                                <option key={list.id} value={list.id}>
                                    {list.name}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError message={errors.contact_list_id} />
                    </div>
                    <div className="grid content-start gap-2">
                        <Label htmlFor="import-tags">Tag everyone</Label>
                        <TagInput
                            id="import-tags"
                            value={form.data.tags}
                            onChange={(value) => form.setData('tags', value)}
                            suggestions={tags}
                        />
                        <InputError message={errors.tags} />
                    </div>
                </div>
                <p className="text-xs text-muted-foreground">
                    Contacts already in the workspace are updated by email
                    address. An unsubscribed status in the file is applied, but
                    an import never subscribes anyone who opted out.
                </p>
            </SettingsSection>

            <div className="flex justify-end">
                <Button disabled={form.processing}>
                    {form.processing && <Spinner />}
                    Start import
                </Button>
            </div>
        </form>
    );
}

function Results({ import: item, destination, failedRows }: Props) {
    const running = ['queued', 'processing'].includes(item.status);
    const percentage =
        item.total_rows > 0
            ? Math.round((item.processed_rows / item.total_rows) * 100)
            : 100;
    const imported = item.created_count + item.updated_count;

    const { start, stop } = usePoll(
        2000,
        { only: ['import', 'failedRows'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (running) {
            start();
        } else {
            stop();
        }
    }, [running, start, stop]);

    return (
        <div className="space-y-6">
            {running ? (
                <div className="space-y-3 rounded-xl border bg-card p-5 shadow-xs">
                    <div className="flex items-center justify-between gap-3 text-sm">
                        <span className="flex items-center gap-2 font-medium">
                            <Spinner className="text-sky-600" />
                            {item.status === 'queued'
                                ? 'Waiting to start'
                                : `Importing ${item.processed_rows.toLocaleString()} of ${item.total_rows.toLocaleString()} rows`}
                        </span>
                        <span className="text-muted-foreground tabular-nums">
                            {percentage}%
                        </span>
                    </div>
                    <div className="h-2 overflow-hidden rounded-full bg-muted">
                        <div
                            className="h-full rounded-full bg-sky-500 transition-[width] duration-500"
                            style={{ width: `${percentage}%` }}
                        />
                    </div>
                    <p className="text-xs text-muted-foreground">
                        This page updates by itself. You can leave it; the
                        import carries on.
                    </p>
                </div>
            ) : item.status === 'failed' ? (
                <Shout
                    type="danger"
                    heading={`The import stopped after ${item.processed_rows.toLocaleString()} of ${item.total_rows.toLocaleString()} rows`}
                >
                    Rows before that were saved. Upload the file again to bring
                    in the rest; contacts already imported are updated, not
                    duplicated.
                </Shout>
            ) : (
                <div className="flex items-center gap-3 rounded-xl border bg-card p-4 shadow-xs">
                    <span className="flex size-9 items-center justify-center rounded-full bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-400">
                        <CircleCheck className="size-5" />
                    </span>
                    <div className="flex-1">
                        <p className="font-medium">
                            Imported {imported.toLocaleString()} of{' '}
                            {item.total_rows.toLocaleString()} rows
                        </p>
                        <Destination destination={destination} />
                    </div>
                    <Button variant="outline" asChild>
                        <Link
                            href={
                                destination.list
                                    ? contactsIndex({
                                          query: { list: destination.list.id },
                                      })
                                    : contactsIndex()
                            }
                        >
                            <Users />
                            View contacts
                        </Link>
                    </Button>
                </div>
            )}

            <dl className="grid gap-4 sm:grid-cols-3">
                <Stat
                    icon={UserPlus}
                    label="Added"
                    hint="New contacts"
                    value={item.created_count}
                />
                <Stat
                    icon={RefreshCw}
                    label="Updated"
                    hint="Already in the workspace"
                    value={item.updated_count}
                />
                <Stat
                    icon={CircleAlert}
                    label="Failed"
                    hint="Rows that could not be imported"
                    value={item.failed_count}
                    problem={item.failed_count > 0}
                />
            </dl>

            {failedRows.length > 0 && (
                <section className="space-y-3">
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 className="text-sm font-semibold">
                                Rows that failed
                            </h2>
                            <p className="text-13 text-muted-foreground">
                                {item.failed_count > failedRows.length
                                    ? `The first ${failedRows.length} of ${item.failed_count.toLocaleString()}. Download them all to fix and import again.`
                                    : 'Fix these in your file and import it again.'}
                            </p>
                        </div>
                        <Button variant="outline" size="sm" asChild>
                            <a href={ImportController.errors.url(item.id)}>
                                <Download />
                                Download failed rows
                            </a>
                        </Button>
                    </div>
                    <DataTable
                        columns={failedColumns}
                        data={failedRows}
                        getRowId={(row) => String(row.id)}
                    />
                </section>
            )}
        </div>
    );
}

const failedColumns: ColumnDef<FailedRow>[] = [
    {
        id: 'row',
        header: 'Row',
        meta: { className: 'w-20 tabular-nums text-muted-foreground' },
        cell: ({ row }) => row.original.row_number.toLocaleString(),
    },
    {
        id: 'email',
        header: 'Email',
        cell: ({ row }) =>
            row.original.email || (
                <span className="text-muted-foreground">Missing</span>
            ),
    },
    {
        id: 'message',
        header: 'Problem',
        meta: { className: 'text-red-700 dark:text-red-400' },
        cell: ({ row }) => row.original.message,
    },
];

function Destination({ destination }: { destination: Props['destination'] }) {
    if (!destination.list && destination.tags.length === 0) {
        return (
            <p className="text-13 text-muted-foreground">
                Added to your contacts.
            </p>
        );
    }

    return (
        <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-13 text-muted-foreground">
            {destination.list && (
                <span>
                    On the list{' '}
                    <Link
                        href={contactsIndex({
                            query: { list: destination.list.id },
                        })}
                        className="font-medium text-foreground hover:underline"
                    >
                        {destination.list.name}
                    </Link>
                </span>
            )}
            {destination.tags.length > 0 && (
                <span className="inline-flex flex-wrap items-center gap-1">
                    Tagged
                    {destination.tags.map((tag) => (
                        <span
                            key={tag}
                            className="inline-flex items-center gap-1 rounded-md border bg-muted/40 px-1.5 text-xs text-foreground"
                        >
                            <TagIcon className="size-3 text-muted-foreground" />
                            {tag}
                        </span>
                    ))}
                </span>
            )}
        </p>
    );
}

function Stat({
    icon: Icon,
    label,
    hint,
    value,
    problem = false,
}: {
    icon: LucideIcon;
    label: string;
    hint: string;
    value: number;
    problem?: boolean;
}) {
    return (
        <div className="rounded-xl border bg-card p-4 shadow-xs">
            <dt className="flex items-center gap-2 text-sm text-muted-foreground">
                <Icon
                    className={cn(
                        'size-4',
                        problem && 'text-red-600 dark:text-red-400',
                    )}
                />
                {label}
            </dt>
            <dd
                className={cn(
                    'mt-1 text-2xl font-semibold tabular-nums',
                    problem && 'text-red-700 dark:text-red-400',
                )}
            >
                {value.toLocaleString()}
            </dd>
            <dd className="text-xs text-muted-foreground">{hint}</dd>
        </div>
    );
}

ShowImport.layout = {
    breadcrumbs: [{ title: 'Imports', href: importsIndex() }],
};
