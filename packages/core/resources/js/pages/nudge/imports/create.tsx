import { Head, useForm } from '@inertiajs/react';
import { Download, FileSpreadsheet, List, Upload, X } from 'lucide-react';
import { useRef, useState } from 'react';
import type { DragEvent, FormEvent } from 'react';
import ImportController from '@/actions/Gerenuk/Nudge/Http/Controllers/ImportController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { index as importsIndex } from '@/routes/nudge/imports';
import { EditorBar } from '@nudge/components/editor-bar';
import { ImportSteps } from '@nudge/components/import-steps';
import { PageBody } from '@nudge/components/page-header';
import type { Option } from '@nudge/types';

/** A small CSV with the columns this workspace recognises, to fill in and import. */
function sampleCsv(columns: string[]): string {
    const example: Record<string, string> = {
        email: 'jane@example.com',
        first_name: 'Jane',
        last_name: 'Doe',
        status: 'subscribed',
        tags: 'customer;newsletter',
    };

    return [
        columns.join(','),
        columns.map((column) => example[column] ?? '').join(','),
    ].join('\n');
}

function fileSize(bytes: number): string {
    return bytes < 1024 * 1024
        ? `${Math.max(1, Math.round(bytes / 1024))} KB`
        : `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

export default function CreateImport({
    maxFileKilobytes,
    columns,
    list,
}: {
    maxFileKilobytes: number;
    columns: string[];
    list: Option | null;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [over, setOver] = useState(false);
    const form = useForm<{ file: File | null; list: number | null }>({
        file: null,
        list: list?.id ?? null,
    });
    const tooBig =
        form.data.file !== null &&
        form.data.file.size > maxFileKilobytes * 1024;

    const choose = (file: File | undefined) => {
        if (file) {
            form.setData('file', file);
            form.clearErrors('file');
        }
    };

    const onDrop = (event: DragEvent) => {
        event.preventDefault();
        setOver(false);
        choose(event.dataTransfer.files[0]);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(ImportController.store());
    };

    const sampleHref = `data:text/csv;charset=utf-8,${encodeURIComponent(sampleCsv(columns))}`;

    return (
        <>
            <Head title="Import contacts" />

            <PageBody>
                <EditorBar
                    back={{ href: importsIndex(), label: 'imports' }}
                    title="Import contacts"
                    meta="Upload a CSV with a header row"
                    stepper={<ImportSteps current="upload" />}
                />

                <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
                    <form onSubmit={submit} className="space-y-4">
                        {list && (
                            <p className="flex items-center gap-2 rounded-lg border bg-card px-3.5 py-2.5 text-sm shadow-xs">
                                <List className="size-4 shrink-0 text-muted-foreground" />
                                <span>
                                    Everyone in this file joins{' '}
                                    <span className="font-medium">
                                        {list.name}
                                    </span>
                                    .
                                </span>
                            </p>
                        )}

                        {form.data.file ? (
                            <div className="flex items-center gap-4 rounded-xl border bg-card p-4 shadow-xs">
                                <span className="flex size-10 shrink-0 items-center justify-center rounded-lg border bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-400">
                                    <FileSpreadsheet className="size-5" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">
                                        {form.data.file.name}
                                    </p>
                                    <p
                                        className={cn(
                                            'text-xs',
                                            tooBig
                                                ? 'text-red-600 dark:text-red-400'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {fileSize(form.data.file.size)}
                                        {tooBig &&
                                            ` · over the ${Math.round(maxFileKilobytes / 1024)} MB limit`}
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Choose a different file"
                                    disabled={form.processing}
                                    onClick={() => form.setData('file', null)}
                                >
                                    <X />
                                </Button>
                            </div>
                        ) : (
                            <button
                                type="button"
                                onClick={() => input.current?.click()}
                                onDragOver={(event) => {
                                    event.preventDefault();
                                    setOver(true);
                                }}
                                onDragLeave={() => setOver(false)}
                                onDrop={onDrop}
                                className={cn(
                                    'flex w-full flex-col items-center gap-3 rounded-xl border border-dashed bg-muted/40 px-6 py-16 text-center transition-colors outline-none hover:border-foreground/30 focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                    over &&
                                        'border-sky-500 bg-sky-50 dark:bg-sky-400/10',
                                )}
                            >
                                <span className="flex size-10 items-center justify-center rounded-full border bg-card text-muted-foreground shadow-xs">
                                    <Upload className="size-5" />
                                </span>
                                <span className="space-y-1">
                                    <span className="block font-medium">
                                        Drop a CSV file here
                                    </span>
                                    <span className="block text-sm text-muted-foreground">
                                        or click to choose one. Up to{' '}
                                        {Math.round(maxFileKilobytes / 1024)}{' '}
                                        MB.
                                    </span>
                                </span>
                            </button>
                        )}
                        <input
                            ref={input}
                            type="file"
                            accept=".csv,text/csv"
                            className="hidden"
                            aria-label="CSV file"
                            onChange={(event) => {
                                choose(event.target.files?.[0]);
                                event.target.value = '';
                            }}
                        />
                        <InputError message={form.errors.file} />

                        {form.progress && (
                            <div
                                role="progressbar"
                                aria-label="Upload progress"
                                aria-valuenow={form.progress.percentage}
                                className="h-1.5 overflow-hidden rounded-full bg-muted"
                            >
                                <div
                                    className="h-full rounded-full bg-sky-500 transition-[width]"
                                    style={{
                                        width: `${form.progress.percentage ?? 0}%`,
                                    }}
                                />
                            </div>
                        )}

                        <div className="flex justify-end">
                            <Button
                                disabled={
                                    form.data.file === null ||
                                    tooBig ||
                                    form.processing
                                }
                            >
                                {form.processing && <Spinner />}
                                Upload and continue
                            </Button>
                        </div>
                    </form>

                    <aside className="space-y-4 rounded-xl border bg-card p-5 text-sm shadow-xs">
                        <div className="space-y-1">
                            <h2 className="font-semibold">
                                What the file needs
                            </h2>
                            <p className="text-muted-foreground">
                                A header row, then one contact per row. Only an
                                email column is required.
                            </p>
                        </div>
                        <div className="space-y-2">
                            <p className="text-xs font-medium text-muted-foreground">
                                Columns matched automatically
                            </p>
                            <div className="flex flex-wrap gap-1">
                                {columns.map((column) => (
                                    <code
                                        key={column}
                                        className="rounded-md border bg-muted/40 px-1.5 py-0.5 font-mono text-xs"
                                    >
                                        {column}
                                    </code>
                                ))}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                You can match other names on the next step.
                            </p>
                        </div>
                        <ul className="space-y-1.5 text-13 text-muted-foreground">
                            <li>
                                Existing contacts are updated by email, never
                                duplicated.
                            </li>
                            <li>
                                People who unsubscribed are never subscribed
                                again by an import.
                            </li>
                        </ul>
                        <Button
                            variant="outline"
                            size="sm"
                            className="w-full"
                            asChild
                        >
                            <a href={sampleHref} download="contacts-sample.csv">
                                <Download />
                                Download a sample CSV
                            </a>
                        </Button>
                    </aside>
                </div>
            </PageBody>
        </>
    );
}

CreateImport.layout = {
    breadcrumbs: [{ title: 'Imports', href: importsIndex() }],
};
